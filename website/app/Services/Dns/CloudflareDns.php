<?php

namespace App\Services\Dns;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * رکوردِ DNS زیردامنه‌های رایگان روی Cloudflare.
 *
 * چرا لازم است: nameserverهای servernet.cloud روی Cloudflare است، پس zoneای که
 * WHM محلی می‌سازد را دنیا نمی‌بیند. بدونِ این سرویس، «زیردامنهٔ رایگان» فقط یک
 * رشته بود و سایتِ مشتری هرگز بالا نمی‌آمد.
 *
 * ⚠️ مثلِ OpenProvider و زحل، Cloudflare روی خطا هم **HTTP 200** می‌دهد و نتیجهٔ
 * واقعی در فیلدِ `success` بدنه است. هرگز به کدِ HTTP تکیه نکن.
 *
 * ⚠️ توکن را مدیر خودش در تنظیماتِ پنل وارد می‌کند و رمزنگاری‌شده ذخیره می‌شود
 * (Setting::putSecret). در .env نمی‌رود و در فرم هم برنمی‌گردد.
 */
class CloudflareDns
{
    private const API = 'https://api.cloudflare.com/client/v4';

    /** توکن ست شده؟ اگر نه، همه‌چیز بی‌صدا رد می‌شود (تحویل نباید بشکند) */
    public function isConfigured(): bool
    {
        return filled(Setting::getSecret('cloudflare_token'));
    }

    /**
     * رکوردِ A زیردامنه را به IP می‌نشاند (اگر بود، به‌روزرسانی می‌کند).
     *
     * @return array{ok:bool, reason:?string}
     */
    public function pointSubdomain(string $fqdn, string $ip): array
    {
        $token = Setting::getSecret('cloudflare_token');

        if (blank($token)) {
            return ['ok' => false, 'reason' => 'توکنِ Cloudflare در تنظیمات وارد نشده است.'];
        }

        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return ['ok' => false, 'reason' => 'IP معتبر نیست: '.$ip];
        }

        $zoneId = $this->zoneId($token);
        if ($zoneId === null) {
            return ['ok' => false, 'reason' => 'Zone در Cloudflare پیدا نشد (Zone ID را در تنظیمات وارد کنید).'];
        }

        // رکوردِ موجود؟ → به‌روزرسانی، وگرنه ساخت. (idempotent: اجرای دوباره
        // رکوردِ تکراری نمی‌سازد، چون Cloudflare چند A همنام را قبول می‌کند.)
        $existing = $this->findRecord($token, $zoneId, $fqdn);

        $payload = [
            'type'    => 'A',
            'name'    => $fqdn,
            'content' => $ip,
            'ttl'     => 1,          // 1 = خودکار
            // proxied=false عمدی: پروکسیِ Cloudflare هم AutoSSLِ cPanel را
            // می‌شکند و هم FTP/ایمیل را. برای هاست باید DNS-only باشد.
            'proxied' => false,
        ];

        $res = $existing === null
            ? $this->request($token, 'post', "/zones/{$zoneId}/dns_records", $payload)
            : $this->request($token, 'patch', "/zones/{$zoneId}/dns_records/{$existing}", $payload);

        if (! $res['ok']) {
            Log::warning('cloudflare dns failed', ['fqdn' => $fqdn, 'reason' => $res['reason']]);
        }

        return $res;
    }

    /**
     * 🔴 برداشتنِ رکوردِ A — نیمهٔ دومِ `pointSubdomain()`.
     *
     * ⚠️ بی‌این، هر سرویسِ خاتمه‌یافته یک رکوردِ زندهٔ DNS جا می‌گذارد که به
     * IP عمومیِ مشترک اشاره می‌کند. آن نام بعداً روی پروکسی به میزبانِ دیگری
     * می‌خورد یا در فهرستِ زون انباشته می‌شود — و بدتر، نامِ مشتریِ رفته برای
     * همیشه در DNS عمومی می‌مانَد.
     *
     * ⚠️ نبودنِ رکورد **موفقیت** است نه خطا: کرونِ آزادسازی ممکن است دوباره
     * بدود و هشدارِ دروغ، هشدارهای واقعی را زیرِ خود دفن می‌کند.
     *
     * @return array{ok:bool, reason:?string}
     */
    public function removeSubdomain(string $fqdn): array
    {
        $token = Setting::getSecret('cloudflare_token');

        if (blank($token)) {
            return ['ok' => false, 'reason' => 'توکنِ Cloudflare در تنظیمات وارد نشده است.'];
        }

        $zoneId = $this->zoneId($token);

        if ($zoneId === null) {
            return ['ok' => false, 'reason' => 'Zone در Cloudflare پیدا نشد.'];
        }

        $id = $this->findRecord($token, $zoneId, $fqdn);

        if ($id === null) {
            return ['ok' => true, 'reason' => null];      // از قبل نبود
        }

        $res = $this->request($token, 'delete', "/zones/{$zoneId}/dns_records/{$id}");

        if (! $res['ok']) {
            Log::warning('cloudflare dns delete failed', ['fqdn' => $fqdn, 'reason' => $res['reason']]);
        }

        return ['ok' => $res['ok'], 'reason' => $res['reason']];
    }

    /** Zone ID از تنظیمات، وگرنه با نامِ دامنه پیدا و ذخیره می‌شود */
    private function zoneId(string $token): ?string
    {
        if (filled($cached = Setting::get('cloudflare_zone_id'))) {
            return $cached;
        }

        $zone = (string) config('servernet.subdomain_zone', 'servernet.cloud');
        $res = $this->request($token, 'get', '/zones', ['name' => $zone]);

        $id = $res['data'][0]['id'] ?? null;

        if (is_string($id) && $id !== '') {
            Setting::put('cloudflare_zone_id', $id);       // دفعهٔ بعد پرس‌وجو لازم نیست

            return $id;
        }

        return null;
    }

    /** شناسهٔ رکوردِ A همین نام، اگر باشد */
    private function findRecord(string $token, string $zoneId, string $fqdn): ?string
    {
        $res = $this->request($token, 'get', "/zones/{$zoneId}/dns_records", ['type' => 'A', 'name' => $fqdn]);

        $id = $res['data'][0]['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /** @return array{ok:bool, reason:?string, data:array} */
    private function request(string $token, string $method, string $path, array $params = []): array
    {
        try {
            $req = Http::withToken($token)->acceptJson()->timeout(15)->retry(1, 400, throw: false);

            $resp = $method === 'get'
                ? $req->get(self::API.$path, $params)
                : $req->{$method}(self::API.$path, $params);
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => 'ارتباط با Cloudflare برقرار نشد: '.mb_substr($e->getMessage(), 0, 120), 'data' => []];
        }

        $json = $resp->json();

        if (! is_array($json)) {
            return ['ok' => false, 'reason' => 'پاسخِ نامعتبر از Cloudflare (HTTP '.$resp->status().')', 'data' => []];
        }

        // نتیجهٔ واقعی در بدنه است، نه در کدِ HTTP
        $ok = ($json['success'] ?? false) === true;
        $reason = $ok ? null : (string) ($json['errors'][0]['message'] ?? 'خطای نامشخصِ Cloudflare');

        return ['ok' => $ok, 'reason' => $reason, 'data' => $json['result'] ?? []];
    }
}

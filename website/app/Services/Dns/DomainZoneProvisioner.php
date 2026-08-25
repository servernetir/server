<?php

namespace App\Services\Dns;

use App\Models\Domain;
use App\Models\Server;
use App\Models\Setting;
use App\Services\Provisioning\WhmClient;
use App\Support\ErrorTracker;

/**
 * ساختِ خودکارِ DNS zone برای دامنه‌هایی که روی نیم‌سرورهای خودِ ما می‌نشینند.
 *
 * ═══ بزرگ‌ترین یافتهٔ ممیزیِ شهریور ۱۴۰۵ ═══
 *
 * 🔴 هر ثبت با نیم‌سرورِ پیش‌فرض (`ns1/ns2.servernet.cloud`) انجام می‌شد ولی
 * **هیچ zone‌ای هیچ‌جا ساخته نمی‌شد**: دامنهٔ مشتری «فعال» بود و به هیچ‌جا
 * resolve نمی‌شد تا کسی دستی در WHM بسازد. مشتری پول داده بود و سایتش بالا
 * نمی‌آمد — بی‌هیچ خطایی.
 *
 * ═══ زیرساختِ واقعی (شناساییِ ۳ شهریور) ═══
 *
 * `ns1` و `ns2.servernet.cloud` هر دو به سرورِ cPanelِ «core» اشاره می‌کنند و
 * BIND همان سرور پاسخ‌گوی DNS است. پس zone یعنی یک `adddns` روی WHM همان
 * سرور — که قالبِ استانداردِ cPanel (@، www، mail و MX) را هم خودش می‌سازد.
 *
 * ═══ قواعد ═══
 *
 * • فقط وقتی نیم‌سرورهای دامنه **زیرمجموعهٔ پیش‌فرض‌های ما** باشند. دامنه‌ای
 *   که مشتری NS خودش را داده، zone ما را نمی‌خواهد.
 * • شکستِ zone هرگز موفقیتِ ثبت/انتقال را خراب نمی‌کند — دامنه ثبت شده و
 *   مالِ مشتری است؛ zone نساخته یک کارِ دستیِ اعلام‌شده است، نه فاجعه.
 * • «zone از قبل هست» موفقیت است، نه خطا — idempotent.
 * • انتخابِ سرور: تنظیمِ `domain_zone_server_id`؛ اگر خالی بود، **خودیاب**:
 *   سرورِ WHMای که hostnameاش به همان IPِ ns1 می‌رسد. IPِ رکوردِ A هم از
 *   `domain_zone_ip` یا (پیش‌فرض) IPِ همان سرور.
 */
class DomainZoneProvisioner
{
    /** آیا این دامنه اصلاً zone ما را می‌خواهد؟ */
    public function wanted(Domain $domain): bool
    {
        $defaults = array_map('strtolower', Domain::defaultNameServers());

        if ($defaults === []) {
            return false;
        }

        $ns = array_map('strtolower', $domain->effectiveNameServers());

        return $ns !== [] && array_diff($ns, $defaults) === [];
    }

    /**
     * ساختن (یا اطمینان از وجودِ) zone — بی‌صدا موفق، پرصدا ناموفق.
     *
     * @return array{ok:bool, message:string}
     */
    public function ensure(Domain $domain): array
    {
        try {
            if (! $this->wanted($domain)) {
                return ['ok' => true, 'message' => 'NS سفارشی — zone ما لازم نیست.'];
            }

            $server = $this->server();

            if ($server === null) {
                ErrorTracker::noteOnce('domain',
                    'zone خودکارِ دامنه خاموش است — سرورِ WHMِ نیم‌سرورها پیدا/تنظیم نشد (تنظیمِ domain_zone_server_id).',
                    21600);

                return ['ok' => false, 'message' => 'سرورِ DNS تعیین نشده است.'];
            }

            $ip = $this->zoneIp($server);

            if ($ip === null) {
                ErrorTracker::noteOnce('domain',
                    'zone خودکارِ دامنه خاموش است — IPِ رکوردِ A پیدا نشد (تنظیمِ domain_zone_ip).', 21600);

                return ['ok' => false, 'message' => 'IPِ مقصد تعیین نشده است.'];
            }

            $res = (new WhmClient($server))->call('adddns', [
                'domain' => (string) $domain->domain,
                'ip'     => $ip,
            ]);

            // «از قبل هست» = موفق؛ ادبِ WHM در این پیام پایدار نیست، پس هر دو واژه
            if ($res['ok'] || preg_match('/already|exist/i', (string) $res['reason'])) {
                return ['ok' => true, 'message' => ''];
            }

            $this->complain($domain, (string) $res['reason']);

            return ['ok' => false, 'message' => (string) $res['reason']];
        } catch (\Throwable $e) {
            $this->complain($domain, $e->getMessage());

            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    private function server(): ?Server
    {
        $id = (int) (Setting::get('domain_zone_server_id') ?: config('services.domain_zone.server_id', 0));

        if ($id > 0) {
            return Server::find($id);
        }

        /*
        | خودیاب — صفر-پیکربندی: بینِ سرورهای WHM، آن‌که hostnameاش به همان
        | IPی می‌رسد که ns1 به آن اشاره می‌کند. در محیطِ تست عمداً خاموش است
        | (gethostbyname تماسِ شبکهٔ واقعی است و تست نباید به اینترنت بند باشد).
        */
        if (app()->environment('testing')) {
            return null;
        }

        $ns = Domain::defaultNameServers()[0] ?? null;

        if ($ns === null) {
            return null;
        }

        $target = gethostbyname($ns);

        if ($target === $ns) {
            return null;       // resolve نشد
        }

        foreach (Server::where('type', 'whm')->get() as $server) {
            if (gethostbyname((string) $server->hostname) === $target) {
                return $server;
            }
        }

        return null;
    }

    private function zoneIp(Server $server): ?string
    {
        $ip = trim((string) (Setting::get('domain_zone_ip') ?: config('services.domain_zone.ip', '')));

        if ($ip !== '') {
            return $ip;
        }

        if (app()->environment('testing')) {
            return null;
        }

        $resolved = gethostbyname((string) $server->hostname);

        return $resolved === $server->hostname ? null : $resolved;
    }

    /**
     * شکستِ zone بی‌صدا نمی‌مانَد: دامنهٔ ثبت‌شده‌ای که resolve نمی‌شود همان
     * «مشتری پول داده و سایتش بالا نمی‌آید» است — فقط این بار می‌دانیم.
     */
    private function complain(Domain $domain, string $why): void
    {
        ErrorTracker::noteOnce('domain', 'ساختِ DNS zone شکست خورد: '.$domain->domain, 900, [
            'domain' => $domain->domain,
            'why'    => mb_substr($why, 0, 160),
        ]);

        try {
            app(\App\Services\Notify\AdminNotifier::class)->event(
                'DNS zone ساخته نشد — دستی بسازید',
                ['دامنه' => $domain->domain, 'علت' => mb_substr($why, 0, 160)],
                url('/admin/domains'),
                '🌐',
            );
        } catch (\Throwable) {
            // اعلان هرگز مسیرِ تحویل را نمی‌شکند
        }
    }
}

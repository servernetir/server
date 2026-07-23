<?php

namespace App\Services\Identity;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * زحل (zohal.io) — احراز هویت و استعلام بانکی.
 *
 * ⚠️ مستندات زحل پشت داشبورد است و از بیرون قابل خواندن نبود، پس مسیرها و
 * نگاشت فیلدها **از کانفیگ** خوانده می‌شوند نه هاردکد. وقتی مستندات واقعی را
 * دیدید، فقط config/services.php را پر می‌کنید و این کلاس دست‌نخورده می‌ماند.
 *
 * تا وقتی کانفیگ کامل نشده، enabled() فالس است و هیچ ادعای دروغی نمی‌کنیم.
 */
class ZohalProvider implements IdentityProvider
{
    private array $cfg;

    public function __construct(?array $cfg = null)
    {
        $this->cfg = $cfg ?? config('services.zohal', []);
    }

    public function enabled(): bool
    {
        return filled($this->cfg['token'] ?? null) && filled($this->cfg['base_url'] ?? null);
    }

    public function shahkar(string $nationalId, string $mobile): ShahkarResult
    {
        $nationalId = $this->digits($nationalId);
        $mobile     = $this->normalizeMobile($mobile);

        if (! $this->validNationalId($nationalId)) {
            return new ShahkarResult(false, 'کد ملی معتبر نیست');
        }
        if ($mobile === null) {
            return new ShahkarResult(false, 'شمارهٔ موبایل معتبر نیست');
        }
        if (! $this->enabled()) {
            return new ShahkarResult(false, 'سرویس احراز هویت پیکربندی نشده است', serviceDown: true);
        }

        $res = $this->call('shahkar', [
            'nationalId' => $nationalId,
            'mobile'     => $mobile,
        ]);

        if ($res === null) {
            return new ShahkarResult(false, 'سرویس احراز هویت پاسخ نداد', serviceDown: true);
        }

        $matched = (bool) $this->pluck($res, 'shahkar.matched');

        return new ShahkarResult(
            $matched,
            $matched ? null : 'این شمارهٔ موبایل به نام صاحب این کد ملی ثبت نشده است',
        );
    }

    public function identity(string $nationalId, string $birthDate): IdentityResult
    {
        $nationalId = $this->digits($nationalId);
        $birthDate  = $this->digits($birthDate);

        if (! $this->validNationalId($nationalId)) {
            return new IdentityResult(false, error: 'کد ملی معتبر نیست');
        }
        if (! $this->enabled()) {
            return new IdentityResult(false, error: 'سرویس احراز هویت پیکربندی نشده است', serviceDown: true);
        }

        $res = $this->call('identity', [
            'nationalId' => $nationalId,
            'birthDate'  => $birthDate,
        ]);

        if ($res === null) {
            return new IdentityResult(false, error: 'سرویس احراز هویت پاسخ نداد', serviceDown: true);
        }

        $first = $this->pluck($res, 'identity.first_name');
        $last  = $this->pluck($res, 'identity.last_name');

        if (blank($first) && blank($last)) {
            return new IdentityResult(false, error: 'اطلاعات هویتی با این کد ملی و تاریخ تولد پیدا نشد');
        }

        return new IdentityResult(
            ok: true,
            firstName: $first,
            lastName: $last,
            fatherName: $this->pluck($res, 'identity.father_name'),
            alive: $this->pluck($res, 'identity.alive'),
        );
    }

    public function cardOwner(string $cardNumber): CardResult
    {
        $card = $this->digits($cardNumber);

        if (! $this->validCard($card)) {
            return new CardResult(false, error: 'شمارهٔ کارت معتبر نیست');
        }
        if (! $this->enabled()) {
            return new CardResult(false, error: 'سرویس استعلام بانکی پیکربندی نشده است', serviceDown: true);
        }

        $res = $this->call('card', ['card' => $card]);

        if ($res === null) {
            return new CardResult(false, error: 'سرویس استعلام بانکی پاسخ نداد', serviceDown: true);
        }

        $owner = $this->pluck($res, 'card.owner_name');

        if (blank($owner)) {
            return new CardResult(false, error: 'اطلاعات این کارت دریافت نشد');
        }

        return new CardResult(
            ok: true,
            ownerName: $owner,
            bankName: $this->pluck($res, 'card.bank_name'),
            accountNumber: $this->pluck($res, 'card.account_number'),
            iban: $this->normalizeIban($this->pluck($res, 'card.iban')),
        );
    }

    /* ============================ زیرساخت ============================ */

    /**
     * فراخوانی یک عملیات. مسیر و روش و نگاشت پارامترها از کانفیگ می‌آید.
     * null یعنی سرویس در دسترس نبود یا پاسخ نامعتبر داد.
     */
    private function call(string $op, array $params): ?array
    {
        $endpoint = $this->cfg['endpoints'][$op] ?? null;
        if (! is_array($endpoint) || blank($endpoint['path'] ?? null)) {
            Log::warning('Zohal endpoint not configured', ['op' => $op]);
            return null;
        }

        // نگاشت نام پارامترهای ما به نام‌های سرویس
        $body = [];
        foreach ($params as $key => $value) {
            $field = $endpoint['fields'][$key] ?? $key;
            $body[$field] = $value;
        }

        try {
            $req = Http::acceptJson()->asJson()->timeout(25)->retry(2, 500, throw: false);

            $auth = $this->cfg['auth_style'] ?? 'bearer';
            $req = $auth === 'header'
                ? $req->withHeaders([($this->cfg['auth_header'] ?? 'Authorization') => $this->cfg['token']])
                : $req->withToken($this->cfg['token']);

            $url = rtrim($this->cfg['base_url'], '/').'/'.ltrim($endpoint['path'], '/');
            $method = strtoupper($endpoint['method'] ?? 'POST');

            $response = $method === 'GET'
                ? $req->get($url, $body)
                : $req->send($method, $url, ['json' => $body]);
        } catch (\Throwable $e) {
            Log::warning('Zohal transport error', ['op' => $op, 'err' => $e->getMessage()]);
            return null;
        }

        $json = $response->json();

        if (! is_array($json)) {
            Log::warning('Zohal non-JSON response', ['op' => $op, 'status' => $response->status()]);
            return null;
        }

        // هرگز پاسخ خام را لاگ نمی‌کنیم — دادهٔ هویتی و بانکی دارد
        if (! $response->successful()) {
            Log::info('Zohal API error', ['op' => $op, 'status' => $response->status()]);
        }

        return $json;
    }

    /**
     * خواندن یک مقدار از پاسخ، با مسیر قابل‌تنظیم.
     * مثال کانفیگ: 'map' => ['identity.first_name' => 'data.result.firstName']
     */
    private function pluck(array $res, string $logicalKey): mixed
    {
        $path = $this->cfg['map'][$logicalKey] ?? null;

        if ($path !== null) {
            return data_get($res, $path);
        }

        // بدون نگاشت، چند مسیر متداول را امتحان می‌کنیم
        $leaf = substr($logicalKey, strrpos($logicalKey, '.') + 1);
        $camel = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $leaf))));

        foreach (["data.$leaf", "data.$camel", "result.$leaf", "result.$camel", $leaf, $camel] as $try) {
            $v = data_get($res, $try);
            if ($v !== null) {
                return $v;
            }
        }

        return null;
    }

    /* ============================ اعتبارسنجی ============================ */

    /** ارقام فارسی/عربی به لاتین و حذف جداکننده‌ها */
    private function digits(string $s): string
    {
        $s = strtr($s, [
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        ]);

        return preg_replace('/[^0-9]/', '', $s) ?? '';
    }

    /** الگوریتم رسمی رقم کنترلی کد ملی ایران */
    public function validNationalId(string $id): bool
    {
        if (! preg_match('/^\d{10}$/', $id)) {
            return false;
        }
        // کدهای تکراری مثل 1111111111 معتبر نیستند
        if (preg_match('/^(\d)\1{9}$/', $id)) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += ((int) $id[$i]) * (10 - $i);
        }
        $r = $sum % 11;
        $check = (int) $id[9];

        return $r < 2 ? $check === $r : $check === 11 - $r;
    }

    /** الگوریتم Luhn برای کارت ۱۶ رقمی */
    public function validCard(string $card): bool
    {
        if (! preg_match('/^\d{16}$/', $card)) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 16; $i++) {
            $d = (int) $card[$i];
            if ($i % 2 === 0) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
        }

        return $sum % 10 === 0;
    }

    /** 09121234567 یا +989121234567 یا 9121234567 → 09121234567 */
    private function normalizeMobile(string $m): ?string
    {
        $d = $this->digits($m);
        $d = preg_replace('/^0098/', '', $d);
        $d = preg_replace('/^98(?=9\d{9}$)/', '', $d);

        if (preg_match('/^9\d{9}$/', $d)) {
            $d = '0'.$d;
        }

        return preg_match('/^09\d{9}$/', $d) ? $d : null;
    }

    private function normalizeIban(?string $iban): ?string
    {
        if (blank($iban)) {
            return null;
        }
        $s = strtoupper(preg_replace('/\s+/', '', $iban));
        // بعضی سرویس‌ها شبا را بدون IR برمی‌گردانند
        if (preg_match('/^\d{24}$/', $s)) {
            $s = 'IR'.$s;
        }

        return preg_match('/^IR\d{24}$/', $s) ? $s : null;
    }
}

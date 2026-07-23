<?php

namespace App\Services\Identity;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * زحل (zohal.io) — احراز هویت و استعلام بانکی.
 *
 * مشخصات از خود API استخراج شد (GET /api/v0/services/ و صفحهٔ هر سرویس)،
 * و فرمت احراز هویت از Postman collection رسمی تأیید شد.
 *
 * قرارداد پاسخ زحل:
 *   { "result": 1, "response_body": { "data": {...}, "message": "...", "error_code": null } }
 *
 * کدهای result:  ۱ موفق · ۴ توکن غیرفعال · ۵ سرویس در دسترس نیست · ۶ پارامتر نادرست
 * ⚠️ HTTP ممکن است ۲۰۰ باشد ولی result چیز دیگری — همیشه result را بخوان.
 *
 * هزینهٔ هر استعلام (تومان):
 *   shahkar ۱۳٬۰۰۰ · هویت ۶۸٬۰۰۰ · کارت‌به‌شبا ۶٬۰۰۰ · کارت‌به‌حساب ۶٬۰۰۰
 */
class ZohalProvider implements IdentityProvider
{
    /** مسیرهای تأییدشده از خود سرویس */
    private const PATHS = [
        'shahkar'         => '/api/v0/services/inquiry/shahkar',
        'identity'        => '/api/v0/services/inquiry/national_identity_inquiry',
        'card_to_iban'    => '/api/v0/services/inquiry/card_to_iban',
        'card_to_account' => '/api/v0/services/inquiry/card_to_account',
    ];

    private array $cfg;

    public function __construct(?array $cfg = null)
    {
        $this->cfg = $cfg ?? config('services.zohal', []);
    }

    public function enabled(): bool
    {
        return filled($this->cfg['token'] ?? null);
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
            'mobile'        => $mobile,
            'national_code' => $nationalId,
        ]);

        if (! $res->ok) {
            return new ShahkarResult(false, $res->error, serviceDown: $res->serviceDown);
        }

        $matched = (bool) data_get($res->data, 'matched');

        return new ShahkarResult(
            $matched,
            $matched ? null : 'این شمارهٔ موبایل به نام صاحب این کد ملی ثبت نشده است',
        );
    }

    public function identity(string $nationalId, string $birthDate): IdentityResult
    {
        $nationalId = $this->digits($nationalId);

        if (! $this->validNationalId($nationalId)) {
            return new IdentityResult(false, error: 'کد ملی معتبر نیست');
        }
        if (! $this->enabled()) {
            return new IdentityResult(false, error: 'سرویس احراز هویت پیکربندی نشده است', serviceDown: true);
        }

        $res = $this->call('identity', [
            'national_code' => $nationalId,
            'birth_date'    => $this->normalizeBirthDate($birthDate),
        ]);

        if (! $res->ok) {
            return new IdentityResult(false, error: $res->error, serviceDown: $res->serviceDown);
        }

        // زحل خودش matched برمی‌گرداند: کد ملی و تاریخ تولد با هم می‌خوانند یا نه
        if (data_get($res->data, 'matched') === false) {
            return new IdentityResult(false, error: 'کد ملی و تاریخ تولد با هم مطابقت ندارند');
        }

        $first = data_get($res->data, 'first_name');
        $last  = data_get($res->data, 'last_name');

        if (blank($first) && blank($last)) {
            return new IdentityResult(false, error: 'اطلاعات هویتی با این کد ملی و تاریخ تولد پیدا نشد');
        }

        // اگر ثبت احوال فوت را اعلام کند، حساب باز نمی‌شود
        if (data_get($res->data, 'is_dead') === true) {
            return new IdentityResult(false, error: 'بر اساس استعلام ثبت احوال، امکان ثبت‌نام با این کد ملی نیست');
        }

        return new IdentityResult(
            ok: true,
            firstName: $first,
            lastName: $last,
            fatherName: data_get($res->data, 'father_name'),
            alive: data_get($res->data, 'alive'),
        );
    }

    /**
     * صاحب کارت + شبا + نام بانک، و در صورت نیاز شماره حساب.
     *
     * صرفه‌جویی عمدی: card_to_iban خودش نام صاحب کارت را هم می‌دهد، پس
     * استعلام جداگانهٔ card-inquiry (۴٬۰۰۰ تومان) لازم نیست.
     * شماره حساب فقط وقتی گرفته می‌شود که کارت واقعاً به نام کاربر باشد —
     * چون ۶٬۰۰۰ تومان دیگر خرج دارد و برای کارت غیر بی‌فایده است.
     */
    public function cardOwner(string $cardNumber): CardResult
    {
        $card = $this->digits($cardNumber);

        if (! $this->validCard($card)) {
            return new CardResult(false, error: 'شمارهٔ کارت معتبر نیست');
        }
        if (! $this->enabled()) {
            return new CardResult(false, error: 'سرویس استعلام بانکی پیکربندی نشده است', serviceDown: true);
        }

        $res = $this->call('card_to_iban', ['card_number' => $card]);

        if (! $res->ok) {
            return new CardResult(false, error: $res->error, serviceDown: $res->serviceDown);
        }

        $owner = data_get($res->data, 'name');

        if (blank($owner)) {
            return new CardResult(false, error: 'اطلاعات این کارت دریافت نشد');
        }

        return new CardResult(
            ok: true,
            ownerName: trim((string) $owner),
            bankName: data_get($res->data, 'bank_name'),
            accountNumber: null,   // با fetchAccountNumber() جدا گرفته می‌شود
            iban: $this->normalizeIban(data_get($res->data, 'IBAN')),
        );
    }

    /** شماره حساب — فقط بعد از تأیید تطابق نام صدا زده شود (۶٬۰۰۰ تومان) */
    public function accountNumber(string $cardNumber): ?string
    {
        $card = $this->digits($cardNumber);

        if (! $this->validCard($card) || ! $this->enabled()) {
            return null;
        }

        $res = $this->call('card_to_account', ['card_number' => $card]);

        return $res->ok ? data_get($res->data, 'bank_account') : null;
    }

    /* ============================ زیرساخت ============================ */

    private function call(string $op, array $body): ZohalResponse
    {
        $base = rtrim($this->cfg['base_url'] ?? 'https://service.zohal.io', '/');
        $url  = $base.self::PATHS[$op];

        try {
            $response = Http::acceptJson()->asJson()
                ->withToken($this->cfg['token'])
                ->timeout(25)->retry(2, 500, throw: false)
                ->post($url, $body);
        } catch (\Throwable $e) {
            Log::warning('Zohal transport error', ['op' => $op, 'err' => $e->getMessage()]);
            return ZohalResponse::fail('سرویس استعلام پاسخ نداد', serviceDown: true);
        }

        $json = $response->json();

        if (! is_array($json)) {
            return ZohalResponse::fail('پاسخ سرویس نامعتبر بود', serviceDown: true);
        }

        // ⚠️ HTTP ممکن است ۲۰۰ باشد ولی result خطا — result حرف آخر است
        $result = (int) ($json['result'] ?? 0);

        if ($result !== 1) {
            // پیام خام ممکن است دادهٔ هویتی داشته باشد؛ فقط کد را لاگ می‌کنیم
            Log::info('Zohal inquiry failed', ['op' => $op, 'result' => $result, 'http' => $response->status()]);

            return ZohalResponse::fail(
                match ($result) {
                    4       => 'توکن سرویس استعلام غیرفعال است',
                    5       => 'سرویس استعلام موقتاً در دسترس نیست',
                    6       => 'اطلاعات ورودی برای استعلام درست نیست',
                    default => (string) (data_get($json, 'response_body.message') ?: 'استعلام ناموفق بود'),
                },
                // ۴ و ۵ مشکل ما/سرویس است نه کاربر — نباید به کاربر بگوییم «رد شدی»
                serviceDown: in_array($result, [4, 5], true),
            );
        }

        return ZohalResponse::ok((array) data_get($json, 'response_body.data', []));
    }

    /* ============================ اعتبارسنجی ============================ */

    private function digits(string $s): string
    {
        $s = strtr($s, [
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        ]);

        return preg_replace('/[^0-9]/', '', $s) ?? '';
    }

    /** تاریخ تولد شمسی به شکل 1370/05/12 که زحل انتظار دارد */
    private function normalizeBirthDate(string $d): string
    {
        $n = $this->digits($d);

        if (strlen($n) === 8) {
            return substr($n, 0, 4).'/'.substr($n, 4, 2).'/'.substr($n, 6, 2);
        }

        return str_replace('-', '/', trim($d));
    }

    /** الگوریتم رسمی رقم کنترلی کد ملی ایران */
    public function validNationalId(string $id): bool
    {
        $id = $this->digits($id);

        if (! preg_match('/^\d{10}$/', $id) || preg_match('/^(\d)\1{9}$/', $id)) {
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

    /** Luhn برای کارت ۱۶ رقمی */
    public function validCard(string $card): bool
    {
        $card = $this->digits($card);

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

        if (preg_match('/^\d{24}$/', $s)) {
            $s = 'IR'.$s;
        }

        return preg_match('/^IR\d{24}$/', $s) ? $s : null;
    }
}

/** پاسخ نرمال‌شدهٔ زحل */
final readonly class ZohalResponse
{
    private function __construct(
        public bool $ok,
        public array $data = [],
        public ?string $error = null,
        public bool $serviceDown = false,
    ) {}

    public static function ok(array $data): self
    {
        return new self(true, $data);
    }

    public static function fail(string $error, bool $serviceDown = false): self
    {
        return new self(false, [], $error, $serviceDown);
    }
}

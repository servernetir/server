<?php

namespace App\Models\Concerns;

use App\Services\Security\Totp;
use Illuminate\Support\Str;

/**
 * ورودِ دومرحله‌ای با اپلیکیشنِ احرازِ هویت — مشترکِ `Customer` و `User`.
 *
 * چرا یک trait و نه دو پیاده‌سازی: قواعدِ امنیتیِ این‌جا (ضدِّ تکرارِ کد،
 * مصرفِ کدِ بازیابی، ترتیبِ فعال‌سازی) دقیقاً همان‌هایی هستند که اگر در یکی
 * از دو مسیر جا بیفتند، کسی متوجه نمی‌شود — چون هر دو مسیر «کار می‌کنند» و
 * فقط یکی‌شان امن است. یک نسخه یعنی یک جا برای درست‌بودن.
 *
 * جریانِ فعال‌سازی عمداً دومرحله‌ای است:
 *
 *   startTwoFactorSetup()  → راز ساخته و ذخیره می‌شود، ولی **تأییدنشده**
 *   confirmTwoFactor(code) → تازه این‌جا فعال می‌شود و کدهای بازیابی می‌آیند
 *
 * 🔴 اگر مرحلهٔ دوم نبود — یعنی اگر با ساختنِ راز بلافاصله فعال می‌شد — کاربری
 * که QR را اسکن نکرده یا اپلیکیشنش را اشتباه تنظیم کرده، **در همان لحظه از
 * حسابِ خودش بیرون می‌ماند** و هیچ راهی هم برای برگشت ندارد. مرحلهٔ تأیید
 * دقیقاً همین است: اثباتِ اینکه گوشیِ کاربر واقعاً کدِ درست می‌سازد، *قبل* از
 * اینکه ورود به آن گوشی وابسته شود.
 */
trait HasTwoFactor
{
    /** چند کدِ بازیابی ساخته شود */
    public const RECOVERY_COUNT = 8;

    /** دومرحله‌ای روشن و تأییدشده است */
    public function hasTwoFactor(): bool
    {
        return ! empty($this->two_factor_secret) && $this->two_factor_confirmed_at !== null;
    }

    /** راز ساخته شده ولی کاربر هنوز با یک کد تأییدش نکرده */
    public function twoFactorPending(): bool
    {
        return ! empty($this->two_factor_secret) && $this->two_factor_confirmed_at === null;
    }

    /**
     * شروعِ راه‌اندازی — رازِ تازه می‌سازد و برمی‌گرداند.
     *
     * ⚠️ هر بار صدا زدنش رازِ قبلیِ **تأییدنشده** را دور می‌ریزد. این عمدی
     * است: کاربری که صفحه را دوباره باز می‌کند باید همان چیزی را ببیند که
     * اسکن می‌کند. ولی اگر دومرحله‌ای از قبل **فعال** باشد کاری نمی‌کند و
     * رازِ فعلی را برمی‌گرداند — وگرنه یک بازدیدِ ساده از صفحهٔ امنیت،
     * اپلیکیشنِ کاربر را بی‌صدا باطل می‌کرد.
     */
    public function startTwoFactorSetup(): string
    {
        if ($this->hasTwoFactor()) {
            return (string) $this->two_factor_secret;
        }

        $secret = Totp::generateSecret();

        $this->forceFill([
            'two_factor_secret'       => $secret,
            'two_factor_recovery'     => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step'    => null,
        ])->save();

        return $secret;
    }

    /**
     * تأییدِ راه‌اندازی با کدِ اپلیکیشن.
     *
     * خروجی: فهرستِ کدهای بازیابی در صورتِ موفقیت، وگرنه `null`.
     * این تنها لحظه‌ای است که کدهای بازیابی به شکلِ خام دیده می‌شوند.
     *
     * @return array<int,string>|null
     */
    public function confirmTwoFactor(string $code): ?array
    {
        if (! $this->twoFactorPending()) {
            return null;
        }

        $step = Totp::matchingTimestep((string) $this->two_factor_secret, $code);

        if ($step === null) {
            return null;
        }

        $codes = $this->freshRecoveryCodes();

        $this->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery'     => json_encode($codes),
            'two_factor_last_step'    => $step,
        ])->save();

        return $codes;
    }

    /**
     * بررسیِ کدِ دومرحله‌ای در لحظهٔ ورود — هم کدِ اپلیکیشن، هم کدِ بازیابی.
     *
     * 🔴 گاردِ تکرار: کدِ TOTPِ یک بازه چند بار «درست» است. اگر همین‌جا آخرین
     * بازهٔ مصرف‌شده را نگه نداریم، کدی که یک بار دیده شود تا ۹۰ ثانیه (پنجرهٔ
     * ±۱) برای مهاجم هم کار می‌کند. با این گارد، کدِ دزدیده‌شده در بارِ دوم
     * ردّ می‌شود.
     *
     * `$reason` می‌گوید **چرا** رد شد: `replay` (کدِ مصرف‌شده) یا `invalid`
     * (کدِ غلط) یا `off` (دومرحله‌ای روشن نیست). لایهٔ نمایش با همین تصمیم
     * می‌گیرد چه پیامی بدهد.
     */
    public function verifyTwoFactorCode(string $code, ?string &$reason = null): bool
    {
        $reason = null;

        if (! $this->hasTwoFactor()) {
            $reason = 'off';

            return false;
        }

        $step = Totp::matchingTimestep((string) $this->two_factor_secret, $code);

        if ($step !== null) {
            if ($this->two_factor_last_step !== null && $step <= (int) $this->two_factor_last_step) {
                /*
                | ⚠️ «تکراری» باید از «نادرست» جدا گزارش شود.
                |
                | این حالت واقعاً پیش می‌آید: کاربر همان کدی را می‌زند که چند
                | ثانیه پیش برای فعال‌سازی استفاده کرده. اگر پیام بگوید «کد
                | نادرست است»، کاربر ساعتِ گوشی و تنظیماتِ اپلیکیشن را می‌گردد
                | و دوباره همان کد را می‌زند — چون کدی که روی صفحه‌اش است
                | همان است. تنها راهنماییِ مفید «تا کدِ بعدی صبر کن» است.
                */
                $reason = 'replay';

                return false;
            }

            $this->forceFill(['two_factor_last_step' => $step])->save();

            return true;
        }

        if ($this->consumeRecoveryCode($code)) {
            return true;
        }

        $reason = 'invalid';

        return false;
    }

    /**
     * مصرفِ کدِ بازیابی — یک‌بارمصرفِ واقعی: بعد از استفاده از فهرست حذف می‌شود.
     *
     * ⚠️ مقایسه با `hash_equals` روی **همهٔ** کدها انجام می‌شود و از حلقه زود
     * بیرون نمی‌آییم؛ وگرنه زمانِ پاسخ می‌گوید چند کد باقی مانده و کدامش
     * نزدیک‌تر بوده.
     */
    public function consumeRecoveryCode(string $code): bool
    {
        $code = $this->normalizeRecoveryCode($code);

        if ($code === '') {
            return false;
        }

        $codes = $this->twoFactorRecoveryCodes();
        $matched = null;

        foreach ($codes as $i => $candidate) {
            if (hash_equals($candidate, $code)) {
                $matched = $i;
            }
        }

        if ($matched === null) {
            return false;
        }

        unset($codes[$matched]);

        $this->forceFill(['two_factor_recovery' => json_encode(array_values($codes))])->save();

        return true;
    }

    /**
     * ساختِ دوبارهٔ کدهای بازیابی — کدهای قبلی همان لحظه باطل می‌شوند.
     *
     * @return array<int,string>
     */
    public function regenerateRecoveryCodes(): array
    {
        $codes = $this->freshRecoveryCodes();

        $this->forceFill(['two_factor_recovery' => json_encode($codes)])->save();

        return $codes;
    }

    /** کدهای بازیابیِ باقی‌مانده @return array<int,string> */
    public function twoFactorRecoveryCodes(): array
    {
        $raw = $this->two_factor_recovery;

        if (empty($raw)) {
            return [];
        }

        $codes = json_decode((string) $raw, true);

        return is_array($codes) ? array_values(array_filter($codes, 'is_string')) : [];
    }

    /** خاموش‌کردنِ کامل — هیچ باقی‌مانده‌ای نمی‌مانَد */
    public function disableTwoFactor(): void
    {
        $this->forceFill([
            'two_factor_secret'       => null,
            'two_factor_recovery'     => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step'    => null,
        ])->save();
    }

    /** نشانیِ otpauth برای QR */
    public function twoFactorUri(): string
    {
        return Totp::uri((string) $this->two_factor_secret, $this->twoFactorLabel(), $this->twoFactorIssuer());
    }

    /**
     * برچسبی که در اپلیکیشنِ کاربر زیرِ کد نوشته می‌شود.
     *
     * ⚠️ باید کاربر را بشناساند: آدم معمولاً چند حساب در Google Authenticator
     * دارد و «ServerNet» تنها، وقتی دو حساب داشته باشد بی‌فایده است.
     */
    public function twoFactorLabel(): string
    {
        return (string) ($this->email ?: ($this->phone ?? ('#'.$this->getKey())));
    }

    /** نامِ صادرکننده — همان چیزی که در فهرستِ اپلیکیشن دیده می‌شود */
    public function twoFactorIssuer(): string
    {
        return (string) config('app.name', 'ServerNet');
    }

    // ───────────────────────────── درونی ─────────────────────────────

    /**
     * الفبای کدِ بازیابی عمداً حروفِ اشتباه‌گرفتنی ندارد (`i l o 0 1`).
     *
     * این کد را کاربر روی کاغذ می‌نویسد و ماه‌ها بعد، در بدترین لحظهٔ ممکن —
     * وقتی گوشی‌اش را گم کرده — تایپش می‌کند. یک `0` که `o` خوانده شود یعنی
     * حسابِ ازدست‌رفته و یک تیکتِ پشتیبانی.
     *
     * @return array<int,string>
     */
    private function freshRecoveryCodes(): array
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $codes = [];

        while (count($codes) < self::RECOVERY_COUNT) {
            $code = '';

            for ($i = 0; $i < 10; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }

            $code = substr($code, 0, 5).'-'.substr($code, 5);

            // تکراری تقریباً ناممکن است، ولی اگر بیفتد یک کد کمتر داریم
            if (! in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /** کدِ بازیابی بدونِ حساسیت به بزرگی/کوچکی و فاصله و خط تیره */
    private function normalizeRecoveryCode(string $code): string
    {
        $code = Str::lower(trim($code));
        $code = preg_replace('/[^a-z0-9]/', '', $code) ?? '';

        if (strlen($code) !== 10) {
            return '';
        }

        return substr($code, 0, 5).'-'.substr($code, 5);
    }
}

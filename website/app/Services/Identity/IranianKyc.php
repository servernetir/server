<?php

namespace App\Services\Identity;

use App\Models\BankAccount;
use App\Models\Customer;
use App\Models\IdentityVerification;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * قوانین احراز هویت مشتری ایرانی.
 *
 * جریان ثبت‌نام:
 *   کد ملی + تاریخ تولد + موبایل
 *     → شاهکار (آیا موبایل به این کد ملی است؟)
 *     → استعلام هویت (نام رسمی از ثبت احوال)
 *     → نام ذخیره می‌شود، از کاربر پرسیده نمی‌شود
 *
 * جریان حساب بانکی:
 *   شمارهٔ کارت ۱۶ رقمی
 *     → استعلام صاحب کارت
 *     → تطبیق با نام رسمی کاربر
 *     → اگر نخواند: رد، اگر خواند: شماره حساب و شبا ذخیره می‌شود
 *
 * قاعدهٔ قفل: بعد از تأیید اولین حساب بانکی، نام دیگر تغییر نمی‌کند.
 */
class IranianKyc
{
    public function __construct(private IdentityProvider $provider) {}

    /**
     * احراز هویت در ثبت‌نام.
     * موفقیت یعنی: شاهکار تطابق داشت و نام رسمی گرفته شد.
     */
    public function verifyIdentity(
        Customer $customer,
        string $nationalId,
        string $birthDate,
        string $mobile,
    ): IdentityOutcome {
        // ۱) شاهکار — اگر موبایل مال این کد ملی نیست، جلوتر نمی‌رویم
        $shahkar = $this->provider->shahkar($nationalId, $mobile);

        if (! $shahkar->matched) {
            return new IdentityOutcome(
                false,
                $shahkar->error ?? 'تطابق کد ملی و شمارهٔ موبایل تأیید نشد',
                serviceDown: $shahkar->serviceDown,
            );
        }

        // ۲) استعلام هویت — نام رسمی
        $identity = $this->provider->identity($nationalId, $birthDate);

        if (! $identity->ok) {
            return new IdentityOutcome(
                false,
                $identity->error ?? 'استعلام اطلاعات هویتی ناموفق بود',
                serviceDown: $identity->serviceDown,
            );
        }

        $hash = $this->hash($nationalId);

        // یک کد ملی روی چند حساب ننشیند
        $existing = IdentityVerification::where('national_id_hash', $hash)
            ->where('customer_id', '!=', $customer->id)
            ->where('status', 'verified')
            ->exists();

        if ($existing) {
            return new IdentityOutcome(false, 'این کد ملی قبلاً روی حساب دیگری تأیید شده است');
        }

        $record = IdentityVerification::updateOrCreate(
            ['customer_id' => $customer->id],
            [
                'national_id_enc'  => Crypt::encryptString($this->digits($nationalId)),
                'national_id_hash' => $hash,
                'birth_date'       => $birthDate,
                'mobile'           => $mobile,
                'shahkar_matched'  => true,
                'shahkar_at'       => now(),
                'first_name'       => $identity->firstName,
                'last_name'        => $identity->lastName,
                'father_name'      => $identity->fatherName,
                'status'           => 'verified',
                'fail_reason'      => null,
                'verified_at'      => now(),
            ],
        );

        return new IdentityOutcome(true, null, verification: $record);
    }

    /**
     * افزودن حساب بانکی از روی شمارهٔ کارت.
     *
     * کارت فقط وسیله است: بعد از تطبیق نام، آنچه نگه می‌داریم شبا و شماره
     * حساب است. شمارهٔ کارت کامل هرگز ذخیره نمی‌شود.
     */
    public function addBankAccount(Customer $customer, string $cardNumber): BankOutcome
    {
        $identity = IdentityVerification::where('customer_id', $customer->id)
            ->where('status', 'verified')->first();

        if ($identity === null) {
            return new BankOutcome(false, 'اول باید احراز هویت را کامل کنید');
        }

        $card = $this->digits($cardNumber);
        $result = $this->provider->cardOwner($card);

        if (! $result->ok) {
            return new BankOutcome(false, $result->error ?? 'استعلام کارت ناموفق بود', serviceDown: $result->serviceDown);
        }

        $official = trim($identity->first_name.' '.$identity->last_name);
        $matched  = $this->namesMatch($official, (string) $result->ownerName);

        if (! $matched) {
            // عمداً ذخیره نمی‌کنیم — کارت غیر، یعنی نه
            return new BankOutcome(
                false,
                'این کارت به نام شما نیست. فقط کارتی که به نام «'.$official.'» باشد قابل ثبت است.',
            );
        }

        if (blank($result->iban)) {
            return new BankOutcome(false, 'شمارهٔ شبای این کارت دریافت نشد؛ لطفاً بعداً تلاش کنید');
        }

        // شماره حساب استعلام جداگانه و پولی است (۶٬۰۰۰ تومان)، پس فقط حالا که
        // مطمئنیم کارت به نام کاربر است می‌گیریمش — نه قبل از تطبیق نام.
        $accountNumber = $result->accountNumber;
        if (blank($accountNumber) && method_exists($this->provider, 'accountNumber')) {
            $accountNumber = $this->provider->accountNumber($card);
        }

        // یک شبا روی چند حساب ننشیند
        $taken = BankAccount::where('iban', $result->iban)
            ->where('customer_id', '!=', $customer->id)->exists();

        if ($taken) {
            return new BankOutcome(false, 'این حساب بانکی قبلاً روی حساب کاربری دیگری ثبت شده است');
        }

        $account = DB::transaction(function () use ($customer, $card, $result, $accountNumber) {
            $isFirst = ! BankAccount::where('customer_id', $customer->id)
                ->where('status', 'verified')->exists();

            return BankAccount::updateOrCreate(
                ['customer_id' => $customer->id, 'iban' => $result->iban],
                [
                    'card_bin'   => substr($card, 0, 6),
                    'card_last4' => substr($card, -4),
                    // ⚠ PAN کامل — به درخواست صریح کارفرما. cast مدل آن را
                    // رمزنگاری می‌کند، پس مقدار خام وارد دیتابیس نمی‌شود.
                    'card_number_enc' => $card,
                    'bank_name'       => $result->bankName,
                    // تشخیص محلی از BIN: املایش یکدست است و به سرویس وابسته نیست
                    'bank_slug'       => \App\Support\IranianBank::fromBin($card)['slug'] ?? null,
                    'account_number'  => $accountNumber,
                    'owner_name'      => $result->ownerName,
                    'name_matched'    => true,
                    'status'          => 'verified',
                    'reject_reason'   => null,
                    'is_default'      => $isFirst,
                    'verified_at'     => now(),
                ],
            );
        });

        return new BankOutcome(true, null, account: $account);
    }

    /**
     * آیا نام کاربر قفل است؟
     * بعد از ثبت حساب بانکی تأییدشده، نام نباید عوض شود — چون حساب بانکی
     * به آن نام تأیید شده و تغییرش یعنی شکستن آن تطابق.
     */
    public function isNameLocked(Customer $customer): bool
    {
        return BankAccount::where('customer_id', $customer->id)
            ->where('status', 'verified')->exists();
    }

    /**
     * مقایسهٔ نام‌ها با تحمل تفاوت‌های املایی رایج فارسی.
     *
     * بانک ممکن است «محمدرضا احمدي» بدهد و ثبت احوال «محمد رضا احمدی» —
     * این‌ها یک نفرند. ی/ك عربی، نیم‌فاصله، و فاصله‌های اضافه یکسان می‌شوند.
     */
    public function namesMatch(string $a, string $b): bool
    {
        return $this->normalizeName($a) === $this->normalizeName($b);
    }

    private function normalizeName(string $s): string
    {
        $s = strtr($s, [
            'ي' => 'ی', 'ك' => 'ک', 'ؤ' => 'و', 'إ' => 'ا', 'أ' => 'ا', 'آ' => 'ا',
            'ة' => 'ه', 'ۀ' => 'ه',
        ]);
        // نیم‌فاصله و نویسه‌های نامرئی حذف، فاصله‌ها یکسان
        $s = preg_replace('/[\x{200B}-\x{200F}\x{FEFF}]/u', '', $s) ?? $s;
        $s = preg_replace('/\s+/u', '', $s) ?? $s;

        return trim($s);
    }

    private function digits(string $s): string
    {
        $s = strtr($s, [
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        ]);

        return preg_replace('/[^0-9]/', '', $s) ?? '';
    }

    private function hash(string $nationalId): string
    {
        return hash_hmac('sha256', $this->digits($nationalId), config('app.key'));
    }
}

final readonly class IdentityOutcome
{
    public function __construct(
        public bool $ok,
        public ?string $error = null,
        public ?IdentityVerification $verification = null,
        public bool $serviceDown = false,
    ) {}
}

final readonly class BankOutcome
{
    public function __construct(
        public bool $ok,
        public ?string $error = null,
        public ?BankAccount $account = null,
        public bool $serviceDown = false,
    ) {}
}

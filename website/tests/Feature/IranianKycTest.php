<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Customer;
use App\Models\IdentityVerification;
use App\Services\Identity\CardResult;
use App\Services\Identity\IdentityProvider;
use App\Services\Identity\IdentityResult;
use App\Services\Identity\IranianKyc;
use App\Services\Identity\ShahkarResult;
use App\Services\Identity\ZohalProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IranianKycTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        return Customer::create(['email' => 'u'.random_int(1, 99999).'@example.com', 'password' => 'x']);
    }

    /** ارائه‌دهندهٔ ساختگی با پاسخ‌های دلخواه */
    private function provider(
        bool $shahkar = true,
        ?IdentityResult $identity = null,
        ?CardResult $card = null,
    ): IdentityProvider {
        return new class($shahkar, $identity, $card) implements IdentityProvider {
            public function __construct(
                private bool $s,
                private ?IdentityResult $i,
                private ?CardResult $c,
            ) {}

            public function enabled(): bool { return true; }

            public function shahkar(string $n, string $m): ShahkarResult
            {
                return new ShahkarResult($this->s, $this->s ? null : 'عدم تطابق');
            }

            public function identity(string $n, string $b): IdentityResult
            {
                return $this->i ?? new IdentityResult(true, 'علی', 'محمدی');
            }

            public function cardOwner(string $c): CardResult
            {
                return $this->c ?? new CardResult(true, 'علی محمدی', 'ملت', '1234567', 'IR060540105180021273113007');
            }
        };
    }

    public function test_identity_is_verified_and_name_comes_from_the_registry(): void
    {
        $c = $this->customer();
        $kyc = new IranianKyc($this->provider());

        $out = $kyc->verifyIdentity($c, '0084575948', '1370-05-12', '09121234567');

        $this->assertTrue($out->ok, $out->error ?? '');
        // نام از سرویس آمده، نه از ورودی کاربر
        $this->assertSame('علی', $out->verification->first_name);
        $this->assertSame('محمدی', $out->verification->last_name);
        $this->assertTrue($out->verification->shahkar_matched);
    }

    public function test_registration_stops_when_shahkar_does_not_match(): void
    {
        $c = $this->customer();
        $kyc = new IranianKyc($this->provider(shahkar: false));

        $out = $kyc->verifyIdentity($c, '0084575948', '1370-05-12', '09121234567');

        $this->assertFalse($out->ok);
        // و هیچ رکوردی ساخته نشده
        $this->assertSame(0, IdentityVerification::count());
    }

    public function test_national_id_is_encrypted_not_stored_raw(): void
    {
        $c = $this->customer();
        (new IranianKyc($this->provider()))->verifyIdentity($c, '0084575948', '1370-05-12', '09121234567');

        $raw = \DB::table('identity_verifications')->first();
        $this->assertStringNotContainsString('0084575948', (string) $raw->national_id_enc);

        // ولی خودمان می‌توانیم بخوانیم
        $this->assertSame('0084575948', IdentityVerification::first()->nationalId());
    }

    public function test_bank_card_belonging_to_someone_else_is_rejected(): void
    {
        $c = $this->customer();
        $kyc = new IranianKyc($this->provider(
            card: new CardResult(true, 'رضا کریمی', 'ملت', '999', 'IR060540105180021273113007'),
        ));
        $kyc->verifyIdentity($c, '0084575948', '1370-05-12', '09121234567');

        $out = $kyc->addBankAccount($c, '6037991234567893');

        $this->assertFalse($out->ok);
        $this->assertStringContainsString('به نام شما نیست', $out->error);
        // حیاتی: کارت غیر هرگز ذخیره نمی‌شود
        $this->assertSame(0, BankAccount::count());
    }

    public function test_matching_card_is_saved_with_iban_and_never_the_full_pan(): void
    {
        $c = $this->customer();
        $kyc = new IranianKyc($this->provider());
        $kyc->verifyIdentity($c, '0084575948', '1370-05-12', '09121234567');

        $out = $kyc->addBankAccount($c, '6037991234567893');

        $this->assertTrue($out->ok, $out->error ?? '');
        $acc = $out->account;
        $this->assertSame('IR060540105180021273113007', $acc->iban);
        $this->assertTrue($acc->is_default);

        // شمارهٔ کارت کامل نباید هیچ‌جای دیتابیس باشد
        $row = json_encode(\DB::table('bank_accounts')->first());
        $this->assertStringNotContainsString('6037991234567893', $row);
        $this->assertSame('603799', $acc->card_bin);
        $this->assertSame('7893', $acc->card_last4);
    }

    public function test_name_locks_after_a_bank_account_is_verified(): void
    {
        $c = $this->customer();
        $kyc = new IranianKyc($this->provider());
        $kyc->verifyIdentity($c, '0084575948', '1370-05-12', '09121234567');

        $this->assertFalse($kyc->isNameLocked($c));

        $kyc->addBankAccount($c, '6037991234567893');

        $this->assertTrue($kyc->isNameLocked($c));
    }

    public function test_persian_spelling_variants_still_match(): void
    {
        $kyc = new IranianKyc($this->provider());

        // ي عربی در برابر ی فارسی، و نیم‌فاصله
        $this->assertTrue($kyc->namesMatch('علی محمدی', 'علي محمدي'));
        $this->assertTrue($kyc->namesMatch('محمدرضا احمدی', 'محمد رضا احمدی'));
        $this->assertTrue($kyc->namesMatch('آرش کریمی', 'ارش کریمی'));
        // ولی آدم دیگر نه
        $this->assertFalse($kyc->namesMatch('علی محمدی', 'رضا کریمی'));
    }

    public function test_same_national_id_cannot_verify_two_accounts(): void
    {
        $kyc = new IranianKyc($this->provider());
        $a = $this->customer();
        $b = $this->customer();

        $this->assertTrue($kyc->verifyIdentity($a, '0084575948', '1370-05-12', '09121234567')->ok);
        $out = $kyc->verifyIdentity($b, '0084575948', '1370-05-12', '09121234567');

        $this->assertFalse($out->ok);
        $this->assertStringContainsString('قبلاً', $out->error);
    }

    public function test_national_id_checksum_is_validated(): void
    {
        $z = new ZohalProvider(['token' => 't', 'base_url' => 'https://x']);

        $this->assertTrue($z->validNationalId('0084575948'));   // معتبر
        $this->assertFalse($z->validNationalId('0084575949'));  // رقم کنترلی غلط
        $this->assertFalse($z->validNationalId('1111111111'));  // تکراری
        $this->assertFalse($z->validNationalId('123'));         // کوتاه
    }

    public function test_card_luhn_is_validated(): void
    {
        $z = new ZohalProvider(['token' => 't', 'base_url' => 'https://x']);

        $this->assertTrue($z->validCard('6037991234567893'));
        $this->assertFalse($z->validCard('6037991234567890'));  // Luhn غلط
        $this->assertFalse($z->validCard('60379912345678'));    // کوتاه
    }

    public function test_service_outage_is_reported_as_such_not_as_rejection(): void
    {
        $c = $this->customer();
        // ارائه‌دهندهٔ پیکربندی‌نشده
        $kyc = new IranianKyc(new ZohalProvider([]));

        $out = $kyc->verifyIdentity($c, '0084575948', '1370-05-12', '09121234567');

        $this->assertFalse($out->ok);
        // فرق مهم: «سرویس در دسترس نیست» با «هویت شما رد شد» یکی نیست
        $this->assertTrue($out->serviceDown);
    }
}

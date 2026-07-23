<?php

namespace Tests\Feature;

use App\Services\Identity\ZohalProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * تست‌ها با پاسخ‌های واقعی زحل نوشته شده‌اند — همان نمونه‌هایی که از
 * GET /api/v0/services/{name}/ استخراج شد، نه پاسخ‌های اختراعی.
 */
class ZohalProviderTest extends TestCase
{
    private function zohal(): ZohalProvider
    {
        return new ZohalProvider(['token' => 'test-token', 'base_url' => 'https://service.zohal.io']);
    }

    /** پوشش پاسخ واقعی زحل */
    private function envelope(array $data, int $result = 1): array
    {
        return [
            'result' => $result,
            'response_body' => ['data' => $data, 'message' => 'موفق', 'error_code' => null],
        ];
    }

    public function test_shahkar_match_uses_the_documented_request_and_response(): void
    {
        Http::fake(['*/inquiry/shahkar' => Http::response($this->envelope(['matched' => true]), 200)]);

        $r = $this->zohal()->shahkar('0084575948', '09121234567');

        $this->assertTrue($r->matched);

        // نام فیلدها باید دقیقاً همان چیزی باشد که زحل مستند کرده
        Http::assertSent(function ($req) {
            $b = $req->data();
            return isset($b['mobile'], $b['national_code'])
                && $b['national_code'] === '0084575948'
                && $b['mobile'] === '09121234567'
                && $req->hasHeader('Authorization', 'Bearer test-token');
        });
    }

    public function test_shahkar_mismatch_is_a_clean_no_not_an_error(): void
    {
        Http::fake(['*/inquiry/shahkar' => Http::response($this->envelope(['matched' => false]), 200)]);

        $r = $this->zohal()->shahkar('0084575948', '09121234567');

        $this->assertFalse($r->matched);
        $this->assertFalse($r->serviceDown);   // کاربر رد شد، نه سرویس خراب
        $this->assertNotNull($r->error);
    }

    public function test_identity_returns_the_official_name(): void
    {
        Http::fake(['*/national_identity_inquiry' => Http::response($this->envelope([
            'alive' => true, 'gender' => 1, 'is_dead' => false, 'matched' => true,
            'last_name' => 'صادقی', 'first_name' => 'امیر', 'father_name' => 'حمید',
            'national_code' => '0084575948',
        ]), 200)]);

        $r = $this->zohal()->identity('0084575948', '1370-05-12');

        $this->assertTrue($r->ok);
        $this->assertSame('امیر', $r->firstName);
        $this->assertSame('صادقی', $r->lastName);
        $this->assertSame('حمید', $r->fatherName);
    }

    public function test_birth_date_is_sent_in_the_format_zohal_expects(): void
    {
        Http::fake(['*/national_identity_inquiry' => Http::response($this->envelope([
            'matched' => true, 'first_name' => 'امیر', 'last_name' => 'صادقی',
        ]), 200)]);

        $this->zohal()->identity('0084575948', '1370-05-12');

        Http::assertSent(fn ($req) => $req->data()['birth_date'] === '1370/05/12');
    }

    public function test_mismatched_birth_date_is_rejected(): void
    {
        Http::fake(['*/national_identity_inquiry' => Http::response($this->envelope([
            'matched' => false,
        ]), 200)]);

        $r = $this->zohal()->identity('0084575948', '1360-01-01');

        $this->assertFalse($r->ok);
        $this->assertStringContainsString('مطابقت ندارند', $r->error);
    }

    public function test_deceased_person_cannot_register(): void
    {
        Http::fake(['*/national_identity_inquiry' => Http::response($this->envelope([
            'matched' => true, 'is_dead' => true, 'alive' => false,
            'first_name' => 'امیر', 'last_name' => 'صادقی',
        ]), 200)]);

        $r = $this->zohal()->identity('0084575948', '1370-05-12');

        $this->assertFalse($r->ok);
    }

    public function test_card_to_iban_gives_owner_bank_and_iban_in_one_call(): void
    {
        Http::fake(['*/inquiry/card_to_iban' => Http::response($this->envelope([
            'IBAN' => 'IR060540105180021273113007',
            'name' => 'امیر صادقی',
            'bank_name' => 'بانک ملت',
        ]), 200)]);

        $r = $this->zohal()->cardOwner('6037991234567893');

        $this->assertTrue($r->ok);
        $this->assertSame('امیر صادقی', $r->ownerName);
        $this->assertSame('IR060540105180021273113007', $r->iban);
        $this->assertSame('بانک ملت', $r->bankName);

        // فقط یک تماس — استعلام جداگانهٔ نام صاحب کارت لازم نیست (۴٬۰۰۰ تومان صرفه‌جویی)
        Http::assertSentCount(1);
    }

    public function test_iban_without_prefix_is_normalised(): void
    {
        Http::fake(['*/inquiry/card_to_iban' => Http::response($this->envelope([
            'IBAN' => '060540105180021273113007',   // بدون IR
            'name' => 'امیر صادقی',
        ]), 200)]);

        $r = $this->zohal()->cardOwner('6037991234567893');

        $this->assertSame('IR060540105180021273113007', $r->iban);
    }

    /**
     * حیاتی‌ترین تست: زحل روی خطا هم HTTP 200 می‌دهد و خطا در فیلد result است.
     * اگر به کد HTTP تکیه کنیم، خطا را «موفق» می‌فهمیم.
     */
    public function test_error_inside_a_200_response_is_not_mistaken_for_success(): void
    {
        Http::fake(['*/inquiry/shahkar' => Http::response([
            'result' => 6,
            'response_body' => ['data' => null, 'message' => 'پارامتر نادرست', 'error_code' => '6'],
        ], 200)]);

        $r = $this->zohal()->shahkar('0084575948', '09121234567');

        $this->assertFalse($r->matched);
    }

    public function test_disabled_token_and_outage_are_flagged_as_service_problems(): void
    {
        foreach ([4 => 'توکن', 5 => 'سرویس'] as $code => $_) {
            Http::fake(['*/inquiry/shahkar' => Http::response([
                'result' => $code, 'response_body' => ['data' => null, 'message' => 'x'],
            ], 200)]);

            $r = $this->zohal()->shahkar('0084575948', '09121234567');

            // این‌ها مشکل ماست نه کاربر — نباید به او بگوییم هویتش رد شد
            $this->assertTrue($r->serviceDown, "کد $code باید serviceDown باشد");
        }
    }

    public function test_bad_input_never_reaches_the_paid_service(): void
    {
        Http::fake();

        $this->zohal()->shahkar('1111111111', '09121234567');   // کد ملی نامعتبر
        $this->zohal()->shahkar('0084575948', '123');            // موبایل نامعتبر
        $this->zohal()->cardOwner('1234');                       // کارت نامعتبر

        // هیچ‌کدام نباید تماس گرفته باشند — هر تماس پول است
        Http::assertNothingSent();
    }

    public function test_mobile_formats_are_normalised(): void
    {
        Http::fake(['*/inquiry/shahkar' => Http::response($this->envelope(['matched' => true]), 200)]);

        foreach (['09121234567', '+989121234567', '00989121234567', '9121234567', '۰۹۱۲۱۲۳۴۵۶۷'] as $m) {
            $this->zohal()->shahkar('0084575948', $m);
        }

        Http::assertSent(fn ($req) => $req->data()['mobile'] === '09121234567');
        Http::assertSentCount(5);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerDocument;
use App\Models\CustomerProfile;
use App\Models\IdentityVerification;
use App\Models\User;
use App\Services\Customer\IranSalesGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * KYCِ سندیِ مشتریِ خارجی — پاسپورت + مدرکِ آدرس، تأییدِ دستیِ مدیر.
 *
 * ═══ چرا (۶ شهریور ۱۴۰۵) ═══
 *
 * کارفرما: «زیرساخت KYC برای خارجی‌ها — اطلاعاتشان را بفرستند، خودمان تأیید
 * کنیم؛ قبض و پاسپورت و…». خارجی = فردی بدونِ استعلامِ هویتِ ایرانی. تأییدِ
 * مدیر همان پرچمِ verified را می‌زند که IranSalesGate می‌خوانَد — پس دروازهٔ
 * محصولاتِ ایران خودکار برای تأییدشده باز می‌شود.
 */
class ForeignKycTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Storage::fake('local');
    }

    private function foreigner(): Customer
    {
        return Customer::create([
            'email' => 'f'.random_int(1, 999999).'@example.com',
            'phone' => '+90532'.random_int(1000000, 9999999),
            'password' => 'secret1234', 'status' => 'active', 'locale' => 'en',
        ]);
    }

    private function admin(): User
    {
        return User::create(['name' => 'م', 'email' => 'a'.random_int(1, 99999).'@x.com',
            'password' => bcrypt('secret1234'), 'role' => 'admin']);
    }

    /** 🔴 فردِ خارجی بدونِ پاسپورت و مدرکِ آدرس رد می‌شود — با نام و کشورِ اجباری */
    public function test_a_foreigner_must_supply_passport_address_and_name(): void
    {
        $res = $this->actingAs($this->foreigner(), 'customer')
            ->post('/en/account/verify', ['type' => 'individual']);

        $res->assertSessionHasErrors(['first_name', 'last_name', 'country', 'doc_passport', 'doc_address']);
    }

    /** ثبتِ کامل: پروفایل pending، مدارک ذخیره، نام و کشور روی پروفایل */
    public function test_a_complete_submission_goes_to_the_review_queue(): void
    {
        $c = $this->foreigner();

        $res = $this->actingAs($c, 'customer')->post('/en/account/verify', [
            'type' => 'individual',
            'first_name' => 'Mehmet', 'last_name' => 'Yilmaz', 'country' => 'Türkiye',
            'doc_passport' => UploadedFile::fake()->create('passport.pdf', 300, 'application/pdf'),
            'doc_address'  => UploadedFile::fake()->image('bill.png'),
        ]);

        $res->assertRedirect()->assertSessionHasNoErrors();

        $p = CustomerProfile::where('customer_id', $c->id)->firstOrFail();
        $this->assertSame('pending', $p->status);
        $this->assertSame('Mehmet', $p->first_name);
        $this->assertSame('Türkiye', $p->country);

        $kinds = CustomerDocument::where('customer_profile_id', $p->id)->pluck('kind')->sort()->values()->all();
        $this->assertSame(['address_proof', 'passport'], $kinds);

        // فایل واقعاً بیرونِ webroot نشسته
        foreach (CustomerDocument::where('customer_profile_id', $p->id)->get() as $d) {
            Storage::disk('local')->assertExists($d->disk_path);
        }
    }

    /** 🔴 تأییدِ مدیر ⇒ پروفایل verified ⇒ دروازهٔ ایران برای همین مشتری باز */
    public function test_admin_approval_verifies_and_opens_the_iran_gate(): void
    {
        $c = $this->foreigner();

        $this->actingAs($c, 'customer')->post('/en/account/verify', [
            'type' => 'individual',
            'first_name' => 'Jane', 'last_name' => 'Doe', 'country' => 'Germany',
            'doc_passport' => UploadedFile::fake()->create('passport.pdf', 300, 'application/pdf'),
            'doc_address'  => UploadedFile::fake()->image('bill.png'),
        ])->assertSessionHasNoErrors();

        $p = CustomerProfile::where('customer_id', $c->id)->firstOrFail();
        $this->assertTrue(IranSalesGate::blocks($c->fresh(), 'IR'), 'پیش از تأیید باید بسته باشد.');

        $this->actingAs($this->admin(), 'web')
            ->post('/admin/verifications/'.$p->id.'/approve')->assertRedirect();

        $this->assertSame('verified', $p->fresh()->status);
        $this->assertSame('approved', CustomerDocument::where('customer_profile_id', $p->id)->first()->status);
        $this->assertFalse(IranSalesGate::blocks($c->fresh(), 'IR'), 'تأییدِ مدیر باید دروازه را باز کند.');
    }

    /** فردِ ایرانی (با استعلامِ ثبتِ احوال) پاسپورت لازم ندارد */
    public function test_an_iranian_individual_is_not_asked_for_a_passport(): void
    {
        $c = Customer::create([
            'email' => 'ir'.random_int(1, 99999).'@example.com', 'phone' => '09121234567',
            'password' => 'secret1234', 'status' => 'active', 'locale' => 'fa',
        ]);
        IdentityVerification::create([
            'customer_id' => $c->id, 'status' => 'verified',
            'first_name' => 'علی', 'last_name' => 'محمدی', 'mobile' => '09121234567',
            'national_id_enc' => 'enc', 'national_id_hash' => str_repeat('a', 64), 'birth_date' => '1370-01-01',
        ]);

        $res = $this->actingAs($c, 'customer')->post('/account/verify', ['type' => 'individual']);

        $res->assertSessionDoesntHaveErrors(['doc_passport', 'doc_address', 'first_name']);
    }

    /** 🔴 صفِ بررسیِ مدیر، نام و کشورِ صاحبِ مدارک را کنارِ خودِ مدارک می‌گوید */
    public function test_the_review_queue_shows_who_and_which_country(): void
    {
        $c = $this->foreigner();

        $this->actingAs($c, 'customer')->post('/en/account/verify', [
            'type' => 'individual',
            'first_name' => 'Mehmet', 'last_name' => 'Yilmaz', 'country' => 'Türkiye',
            'doc_passport' => UploadedFile::fake()->create('passport.pdf', 300, 'application/pdf'),
            'doc_address'  => UploadedFile::fake()->image('bill.png'),
        ])->assertSessionHasNoErrors();

        $html = (string) $this->actingAs($this->admin(), 'web')
            ->get('/admin/verifications')->assertOk()->getContent();

        $this->assertStringContainsString('Mehmet Yilmaz', $html, 'نامِ روی پاسپورت باید کنارِ مدارک باشد.');
        $this->assertStringContainsString('Türkiye', $html, 'مدیر باید بداند مدارک مالِ کدام کشور است.');
        $this->assertStringContainsString($c->code, $html, 'کدِ مشتری برای رهگیری لازم است.');
        $this->assertStringContainsString('پاسپورت', $html);
    }

    /** صفحهٔ پروفایلِ خارجی، فرمِ پاسپورت را نشان می‌دهد */
    public function test_the_profile_page_shows_the_passport_form_to_foreigners(): void
    {
        $html = (string) $this->actingAs($this->foreigner(), 'customer')
            ->get('/en/account/profile')->assertOk()->getContent();

        $this->assertStringContainsString(__('ui.prof_doc_passport'), $html);
        $this->assertStringContainsString('name="doc_passport"', $html);
    }
}

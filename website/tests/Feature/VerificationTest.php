<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerDocument;
use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * احراز هویتِ حقوقی: مشتری اطلاعاتِ شرکت + معرفی‌نامه + اساسنامه می‌فرستد،
 * پروفایل «در انتظار» و به پشتیبانی اعلان می‌رود؛ مدیر تأیید/رد می‌کند و
 * نتیجه به مشتری می‌رسد. مدارک روی دیسکِ خصوصی و دانلودشان فقط برای مدیر.
 */
class VerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Mail::fake();
        Storage::fake('local');
        config()->set('servernet.contact.notify_phone', '09120000000');
    }

    private function staff(): User
    {
        return User::create(['name' => 'مدیر', 'email' => 's'.random_int(1, 99999).'@x.com', 'password' => bcrypt('secret1234'), 'role' => 'admin']);
    }

    private function customer(): Customer
    {
        return Customer::create(['email' => 'c'.random_int(1, 99999).'@x.com', 'phone' => '0912'.random_int(1000000, 9999999), 'password' => 'x', 'status' => 'active', 'locale' => 'fa']);
    }

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->create($name, 120, 'application/pdf');
    }

    /** صفحهٔ جدای احراز هویت با پروفایل ادغام شد — لینکِ قدیمی باید هدایت شود */
    public function test_old_verify_url_redirects_to_the_merged_profile_page(): void
    {
        $c = $this->customer();

        $this->actingAs($c, 'customer')->get('/account/verify')
            ->assertRedirect('/account/profile');
    }

    /** فرمِ مدارکِ شرکت روی همان صفحهٔ پروفایل دیده می‌شود */
    public function test_profile_page_contains_the_company_document_form(): void
    {
        $c = $this->customer();

        $this->actingAs($c, 'customer')->get('/account/profile')
            ->assertOk()
            ->assertSee('اساسنامه', false)
            ->assertSee('معرفی‌نامهٔ نماینده', false)
            ->assertSee('doc_articles', false);
    }

    public function test_company_customer_submits_company_info_and_documents(): void
    {
        $c = $this->customer();

        $this->actingAs($c, 'customer')->post('/account/verify', [
            'type'           => 'company',
            'company_name'   => 'شرکت نمونه',
            'rep_first_name' => 'علی',
            'rep_last_name'  => 'رضایی',
            'rep_position'   => 'مدیرعامل',
            'doc_letter'     => $this->pdf('letter.pdf'),
            'doc_articles'   => $this->pdf('articles.pdf'),
        ])->assertRedirect();

        $profile = CustomerProfile::where('customer_id', $c->id)->first();
        $this->assertNotNull($profile);
        $this->assertSame('company', $profile->type);
        $this->assertSame('pending', $profile->status);
        $this->assertSame('شرکت نمونه', $profile->company_name);

        $docs = CustomerDocument::where('customer_profile_id', $profile->id)->pluck('kind')->all();
        $this->assertContains('rep_letter', $docs);
        $this->assertContains('articles', $docs);

        // فایل واقعاً روی دیسکِ خصوصی ذخیره شده
        $letter = CustomerDocument::where('customer_profile_id', $profile->id)->where('kind', 'rep_letter')->first();
        Storage::disk('local')->assertExists($letter->disk_path);
    }

    public function test_company_submit_requires_documents_first_time(): void
    {
        $c = $this->customer();

        $this->actingAs($c, 'customer')->post('/account/verify', [
            'type'         => 'company',
            'company_name' => 'شرکت نمونه',
            'rep_first_name' => 'علی', 'rep_last_name' => 'رضایی',
        ])->assertSessionHasErrors(['doc_letter', 'doc_articles']);

        $this->assertNull(CustomerProfile::where('customer_id', $c->id)->where('status', 'pending')->first());
    }

    public function test_admin_approves_and_customer_profile_becomes_verified(): void
    {
        $c = $this->customer();
        $profile = CustomerProfile::create(['customer_id' => $c->id, 'type' => 'company', 'is_default' => true, 'status' => 'pending', 'email' => $c->email, 'company_name' => 'شرکت نمونه']);

        $this->actingAs($this->staff(), 'web')
            ->post("/admin/verifications/{$profile->id}/approve")
            ->assertRedirect();

        $profile->refresh();
        $this->assertSame('verified', $profile->status);
        $this->assertNotNull($profile->verified_at);
    }

    public function test_admin_rejects_with_reason(): void
    {
        $c = $this->customer();
        $profile = CustomerProfile::create(['customer_id' => $c->id, 'type' => 'company', 'is_default' => true, 'status' => 'pending', 'email' => $c->email, 'company_name' => 'شرکت نمونه']);

        $this->actingAs($this->staff(), 'web')
            ->post("/admin/verifications/{$profile->id}/reject", ['reason' => 'اساسنامه ناخوانا است'])
            ->assertRedirect();

        $profile->refresh();
        $this->assertSame('rejected', $profile->status);
        $this->assertSame('اساسنامه ناخوانا است', $profile->reject_reason);
    }

    public function test_admin_can_download_document_but_guest_cannot(): void
    {
        $c = $this->customer();
        $profile = CustomerProfile::create(['customer_id' => $c->id, 'type' => 'company', 'is_default' => true, 'status' => 'pending', 'email' => $c->email]);
        Storage::disk('local')->put('kyc/'.$c->id.'/letter.pdf', 'PDFDATA');
        $doc = CustomerDocument::create([
            'customer_profile_id' => $profile->id, 'kind' => 'rep_letter', 'status' => 'pending',
            'disk_path' => 'kyc/'.$c->id.'/letter.pdf', 'original_name' => 'letter.pdf', 'mime' => 'application/pdf', 'size_bytes' => 7,
            'scan_status' => 'skipped', 'uploaded_at' => now(), 'sha256' => str_repeat('a', 64),
        ]);

        // مهمان (بدون ورودِ ادمین) → به لاگین هدایت می‌شود، دانلود نمی‌کند
        $this->get("/admin/verifications/{$profile->id}/doc/{$doc->id}")->assertRedirect();

        // مدیر → دانلود موفق
        $this->actingAs($this->staff(), 'web')
            ->get("/admin/verifications/{$profile->id}/doc/{$doc->id}")
            ->assertOk();
    }
}

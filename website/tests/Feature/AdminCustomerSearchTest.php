<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * جستجو و فیلترهای پیشرفتهٔ فهرستِ مشتریان.
 *
 * ═══ دو باگی که «جستجو درست نیست» را ساخته بودند ═══
 *
 * 🔴 نامِ کامل: «علی رضایی» — نام در `first_name` است و نام‌خانوادگی در
 * `last_name`؛ رشتهٔ کامل با **هیچ‌کدام** LIKE نمی‌خورد ⇒ نتیجهٔ خالی. و این
 * دقیقاً همان جستجویی است که هر مدیری طبیعتاً می‌زند.
 *
 * 🔴 ارقامِ فارسی: مدیر «۰۹۱۲…» تایپ می‌کند، ستون لاتین است ⇒ باز هم خالی.
 * هر دو بی‌خطا و بی‌لاگ — فقط «پیدا نشد».
 */
class AdminCustomerSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function customer(array $attrs = [], ?string $first = null, ?string $last = null): Customer
    {
        $c = Customer::create(array_merge([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'c'.random_int(1, 999999).'@example.com',
            'password' => bcrypt('secret-pass-123'), 'status' => 'active',
        ], $attrs));

        if ($first !== null) {
            \App\Models\IdentityVerification::create([
                'customer_id' => $c->id, 'first_name' => $first, 'last_name' => $last ?? '',
                'national_id_enc' => 'enc-'.$c->id, 'national_id_hash' => hash('sha256', (string) $c->id),
                'birth_date' => '1370-01-01', 'mobile' => $c->phone ?? '0912'.random_int(1000000, 9999999),
                'shahkar_matched' => true, 'status' => 'verified', 'provider' => 'zohal',
            ]);
        }

        return $c;
    }

    /** 🔴 هستهٔ رفع: جستجوی نام و نام‌خانوادگی با هم. */
    public function test_a_full_name_finds_the_customer(): void
    {
        $c = $this->customer([], 'علی', 'رضایی');
        $this->customer([], 'مریم', 'کریمی');

        $html = $this->actingAs($this->admin)
            ->get('/admin/customers?q='.urlencode('علی رضایی'))->assertOk()->getContent();

        $this->assertStringContainsString($c->code, $html, 'نامِ کامل مشتری را پیدا نکرد');
        $this->assertSame(1, substr_count($html, 'SN-'), 'فقط همان یک مشتری باید بیاید');
    }

    /** 🔴 ارقامِ فارسی در جستجوی موبایل. */
    public function test_persian_digits_find_a_phone_number(): void
    {
        $c = $this->customer(['phone' => '09121234567']);

        $html = $this->actingAs($this->admin)
            ->get('/admin/customers?q='.urlencode('۰۹۱۲۱۲۳'))->assertOk()->getContent();

        $this->assertStringContainsString($c->code, $html, 'شمارهٔ فارسی‌تایپ‌شده پیدا نشد');
    }

    /** فیلترِ «دارای سرویس فعال» واقعاً محدود می‌کند. */
    public function test_the_service_filter_narrows(): void
    {
        $with = $this->customer();
        Service::create([
            'customer_id' => $with->id, 'name' => 'هاست', 'currency_code' => 'IRT',
            'price' => 100000, 'cycle' => 'monthly', 'status' => 'active',
        ]);
        $without = $this->customer();

        $html = $this->actingAs($this->admin)
            ->get('/admin/customers?service=with')->assertOk()->getContent();
        $this->assertStringContainsString($with->code, $html);
        $this->assertStringNotContainsString($without->code, $html);

        $html = $this->actingAs($this->admin)
            ->get('/admin/customers?service=without')->assertOk()->getContent();
        $this->assertStringContainsString($without->code, $html);
        $this->assertStringNotContainsString($with->code, $html);
    }

    /** فیلترِ احراز هویت. */
    public function test_the_verification_filter_narrows(): void
    {
        $verified = $this->customer([], 'سارا', 'محمدی');
        $bare = $this->customer();

        $html = $this->actingAs($this->admin)
            ->get('/admin/customers?verified=yes')->assertOk()->getContent();
        $this->assertStringContainsString($verified->code, $html);
        $this->assertStringNotContainsString($bare->code, $html);
    }

    /** بازهٔ تاریخِ ثبت‌نام. */
    public function test_the_date_range_narrows(): void
    {
        $old = $this->customer();
        $old->forceFill(['created_at' => now()->subDays(30)])->save();
        $new = $this->customer();

        $html = $this->actingAs($this->admin)
            ->get('/admin/customers?from='.now()->subDays(2)->toDateString())
            ->assertOk()->getContent();

        $this->assertStringContainsString($new->code, $html);
        $this->assertStringNotContainsString($old->code, $html);
    }

    /** ⚠️ مقدارِ نامعتبرِ هر فیلتر بی‌اثر است — نه ۵۰۰، نه ۴۲۲. */
    public function test_invalid_filter_values_are_ignored(): void
    {
        $c = $this->customer();

        $this->actingAs($this->admin)
            ->get('/admin/customers?service=banana&verified=x&sort=nope&from=not-a-date')
            ->assertOk()->assertSee($c->code);
    }

    /** فرمِ جستجو، فیلترهای فعال را نگه می‌دارد — وگرنه هر جستجو پاکشان می‌کرد. */
    public function test_the_search_form_carries_active_filters(): void
    {
        $this->customer();

        $html = $this->actingAs($this->admin)
            ->get('/admin/customers?service=with&verified=yes')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('~name="service" value="with"~', $html);
        $this->assertMatchesRegularExpression('~name="verified" value="yes"~', $html);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\IdentityVerification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * جستجوی زندهٔ مشتری — نتیجه از **کلِ** جدول، نه از صفحهٔ جاری.
 *
 * ═══ نقصی که این را لازم کرد ═══
 *
 * 🔴 فهرستِ مشتریان صفحه‌بندی‌شده است و جستجو فقط با زدنِ Enter و بارگذاریِ
 * دوبارهٔ صفحه کار می‌کرد. مدیری که نامِ یک مشتری را می‌دانست باید تایپ
 * می‌کرد، Enter می‌زد، صبر می‌کرد — و اگر مشتری در صفحهٔ دیگری بود، همان
 * چرخه دوباره. حالا حین تایپ، از کلِ جدول پاسخ می‌آید.
 *
 * ⚠️ نقطهٔ JSON روی `/admin/*` است، پس تلهٔ ثبت‌شدهٔ پروژه این‌جا زنده است:
 * `$request->validate()` روی این مسیرها ۳۰۲ HTML می‌دهد نه ۴۲۲ JSON.
 */
class AdminCustomerLiveSearchTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function customer(?string $first = null, ?string $last = null, array $attrs = []): Customer
    {
        $c = Customer::create(array_merge([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'l'.random_int(1, 999999).'@example.com',
            'password' => bcrypt('secret-pass-123'), 'status' => 'active',
        ], $attrs));

        if ($first !== null) {
            IdentityVerification::create([
                'customer_id' => $c->id, 'first_name' => $first, 'last_name' => $last ?? '',
                'national_id_enc' => 'enc-'.$c->id, 'national_id_hash' => hash('sha256', (string) $c->id),
                'birth_date' => '1370-01-01', 'mobile' => $c->phone ?? '0912'.random_int(1000000, 9999999),
                'shahkar_matched' => true, 'status' => 'verified', 'provider' => 'zohal',
            ]);
        }

        return $c;
    }

    /** 🔴 نامِ خانوادگی ⇒ همان مشتری، به‌صورت JSON. */
    public function test_it_finds_a_customer_by_family_name(): void
    {
        $c = $this->customer('علی', 'رضایی');
        $this->customer('مریم', 'کریمی');

        $r = $this->actingAs($this->admin())->getJson('/admin/customers/search?q='.urlencode('رضایی'));

        $r->assertOk()->assertJsonPath('ok', true);
        $this->assertCount(1, $r->json('results'));
        $this->assertSame($c->code, $r->json('results.0.code'));
        $this->assertSame('علی رضایی', $r->json('results.0.name'));
    }

    /** نامِ کامل (نام + فامیل) — همان جستجویی که هر مدیری طبیعتاً می‌زند. */
    public function test_a_full_name_matches_across_two_columns(): void
    {
        $c = $this->customer('سارا', 'محمدی');
        $this->customer('سارا', 'احمدی');

        $r = $this->actingAs($this->admin())->getJson('/admin/customers/search?q='.urlencode('سارا محمدی'));

        $this->assertCount(1, $r->json('results'));
        $this->assertSame($c->code, $r->json('results.0.code'));
    }

    /** 🔴 ارقامِ فارسی — مدیر «۰۹۱۲…» تایپ می‌کند، ستون لاتین است. */
    public function test_persian_digits_match_a_latin_phone(): void
    {
        $c = $this->customer(null, null, ['phone' => '09121234567']);

        $r = $this->actingAs($this->admin())->getJson('/admin/customers/search?q='.urlencode('۰۹۱۲۱۲۳'));

        $this->assertSame($c->code, $r->json('results.0.code'));
    }

    /**
     * 🔴 نتیجه از کلِ جدول می‌آید، نه از صفحهٔ اول.
     *
     * این هستهٔ خواسته است: مشتری‌ای که در صفحهٔ پنجمِ فهرست است هم باید
     * حین تایپ بیاید. ۴۰ مشتری می‌سازیم (بیش از یک صفحهٔ ۳۰تایی) و دنبالِ
     * **قدیمی‌ترین** می‌گردیم — آنکه در نمای پیش‌فرض آخر از همه است.
     */
    public function test_results_span_beyond_the_first_page(): void
    {
        $target = $this->customer('بهرام', 'قدیمی');

        for ($i = 0; $i < 40; $i++) {
            $this->customer('نفر', 'تازه'.$i);
        }

        $r = $this->actingAs($this->admin())->getJson('/admin/customers/search?q='.urlencode('قدیمی'));

        $this->assertSame($target->code, $r->json('results.0.code'),
            'مشتریِ خارج از صفحهٔ اول پیدا نشد — جستجو هنوز صفحه‌ای است');
    }

    /**
     * ⚠️ سقفِ نتایج ثابت است و `total` واقعیت را می‌گوید.
     *
     * بدونِ `total`، کاربر ۱۲ نتیجه می‌بیند و «نبودنِ» مشتریِ سیزدهم را
     * «وجود ندارد» می‌خوانَد.
     */
    public function test_the_result_list_is_capped_but_reports_the_true_total(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->customer('همنام', 'یکسان');
        }

        $r = $this->actingAs($this->admin())->getJson('/admin/customers/search?q='.urlencode('یکسان'));

        $this->assertCount(12, $r->json('results'), 'سقفِ نتایج رعایت نشد');
        $this->assertSame(20, $r->json('total'), 'تعدادِ واقعی گزارش نشد');
    }

    /** ⚠️ کمتر از دو نویسه: کلِ جدول کشیده نشود. */
    public function test_a_single_character_returns_nothing(): void
    {
        $this->customer('الف', 'ب');

        $r = $this->actingAs($this->admin())->getJson('/admin/customers/search?q=ا');

        $r->assertOk()->assertJsonPath('short', true);
        $this->assertSame([], $r->json('results'));
    }

    /**
     * 🔴 پاسخ همیشه JSON است — نه ریدایرکتِ HTML.
     *
     * تلهٔ `shouldRenderJsonWhen(api/*)`: اگر روزی این متد به
     * `$request->validate()` برگردد، این ادعا قرمز می‌شود.
     */
    public function test_a_weird_query_still_answers_in_json(): void
    {
        $r = $this->actingAs($this->admin())->getJson('/admin/customers/search?q='.urlencode('!!@@##'));

        $r->assertOk()->assertJsonPath('ok', true);
        $this->assertSame([], $r->json('results'));
    }

    /** پشتیبان اجازه دارد؛ نویسنده نه. */
    public function test_the_endpoint_follows_the_same_role_boundary(): void
    {
        $this->customer('علی', 'رضایی');

        $this->actingAs(User::factory()->create(['role' => 'support']))
            ->getJson('/admin/customers/search?q='.urlencode('رضایی'))->assertOk();

        $this->actingAs(User::factory()->create(['role' => 'author']))
            ->getJson('/admin/customers/search?q='.urlencode('رضایی'))->assertForbidden();
    }

    /** رابط: کادر و فهرستِ زنده و اسکریپتش روی صفحه‌اند. */
    public function test_the_page_ships_the_live_search_widget(): void
    {
        $html = $this->actingAs($this->admin())->get('/admin/customers')->assertOk()->getContent();

        $this->assertStringContainsString('id="cs-q"', $html);
        $this->assertStringContainsString('id="cs-drop"', $html);
        $this->assertStringContainsString('/admin/customers/search', $html, 'اسکریپتِ جستجوی زنده روی صفحه نیست');
    }
}

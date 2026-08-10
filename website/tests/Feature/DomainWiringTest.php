<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * وصل‌بودنِ دامنه به سایت، پنلِ مشتری و پنلِ مدیریت.
 *
 * 🔴 بازبینیِ خصمانه نشان داد کلِ مسیرِ خرید **از سایت غیرقابلِ دسترس** بود:
 * دکمهٔ ثبت در صفحهٔ جستجو هنوز به سبدِ خریدِ WHMCSِ بیرونی می‌رفت، پس هیچ‌چیز
 * به `account.domains.order` پست نمی‌کرد. صفحه‌ای که ساخته شود و ورودی نداشته
 * باشد، مثلِ نساختنش است.
 */
class DomainWiringTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        $c = Customer::create([
            'email' => 'w'.random_int(1000, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);

        CustomerProfile::create([
            'customer_id' => $c->id, 'type' => 'individual', 'is_default' => true,
            'status' => 'verified', 'email' => $c->email, 'mobile' => '09123456789',
            'country' => 'IR', 'city' => 'تهران', 'address' => 'خیابان نمونه',
            'postal_code' => '1234567890', 'first_name' => 'احسان', 'last_name' => 'ابراهیمی',
        ]);

        return $c;
    }

    private function staff(): User
    {
        return User::create([
            'name' => 'مدیر', 'email' => 's'.random_int(100, 99999).'@x.com',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);
    }

    /**
     * ⚠️ فاکتورِ **پرداخت‌شده** بخشی از فیکسچر است، نه تزئین.
     *
     * دامنه فقط بعد از پرداخت به صفِ ثبت (و از آن‌جا به `manual`) می‌رسد، پس
     * فیکسچرِ بی‌فاکتور حالتی را می‌ساخت که در واقعیت وجود ندارد — و همان باعث
     * شد محافظِ تازهٔ «پول نگرفته، نخر» این تست را بشکند. فیکسچرِ غیرواقعی
     * دقیقاً همان چیزی است که یک‌بار باگِ خاتمهٔ هاست را از چشمِ تست‌ها پنهان
     * کرد (`ServiceTerminateOtpTest` هرگز `server_id` نمی‌گذاشت).
     */
    private function domain(Customer $c, array $over = []): Domain
    {
        $d = Domain::create(array_merge([
            'customer_id' => $c->id, 'domain' => 'wired'.random_int(100, 9999).'.com',
            'sld' => 'wired', 'tld' => 'com', 'registrar' => 'openprovider',
            'status' => 'pending', 'provision_status' => 'manual',
            'provision_error' => 'رجیسترار جواب نداد',
        ], $over));

        \App\Models\Invoice::create([
            'customer_id' => $c->id, 'domain_id' => $d->id, 'kind' => 'domain',
            'currency_code' => 'IRT', 'subtotal' => 2_000_000, 'tax' => 0, 'total' => 2_000_000,
            'paid' => 2_000_000, 'status' => 'paid', 'issued_at' => now(),
        ]);

        return $d;
    }

    // ═══════════════ سایتِ عمومی ═══════════════

    /** 🔴 دکمهٔ ثبت باید به پنلِ خودمان برود، نه WHMCSِ بیرونی */
    public function test_the_public_search_page_sends_buyers_to_our_own_panel(): void
    {
        $html = $this->get('/domains')->assertOk()->getContent();

        // ⚠️ آدرس داخلِ یک بلوکِ `@json` است و آن‌جا اسلش‌ها escape می‌شوند
        // (`account\/domains`) — پس جستجوی رشتهٔ خام نتیجه نمی‌دهد.
        $this->assertMatchesRegularExpression('~account\\\\?/domains~', $html);
        $this->assertStringNotContainsString('cart.php', $html,
            'خرید نباید به سبدِ WHMCSِ بیرونی برود — فروش و تحویل در کنسولِ خودمان است');
    }

    // ═══════════════ پنلِ مشتری ═══════════════

    public function test_the_panel_domain_page_has_a_search_box(): void
    {
        $html = $this->actingAs($this->customer(), 'customer')
            ->get(route('account.domains'))->assertOk()->getContent();

        $this->assertStringContainsString('name="register"', $html);
    }

    /**
     * ورودِ `?register=` باید استعلام بگیرد و دکمهٔ سفارش بدهد.
     *
     * ⚠️ استعلام **در پنل** گرفته می‌شود نه در صفحهٔ عمومی: بینِ دیدنِ قیمت و
     * ورود به حساب ممکن است بیش از پنجرهٔ ۱۵ دقیقه‌ای طول بکشد.
     */
    public function test_a_register_query_shows_a_priced_order_button(): void
    {
        config()->set('services.openprovider.username', 'u');
        config()->set('services.openprovider.password', 'p');
        \App\Models\Setting::put('pricing_rate_override', '100000');

        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([
            '*/auth/login*'    => Http::response(['code' => 0, 'data' => ['token' => 't']]),
            '*/domains/check*' => Http::response(['code' => 0, 'data' => ['results' => [[
                'domain' => 'wiretest.com', 'status' => 'free',
                'price' => ['reseller' => ['price' => 10.0, 'currency' => 'EUR']],
            ]]]]),
        ]);

        $html = $this->actingAs($this->customer(), 'customer')
            ->get(route('account.domains', ['register' => 'wiretest.com']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('wiretest.com', $html);

        /*
        | ⚠️ دکمهٔ خرید حالا **لینکِ صفحهٔ تسویه** است، نه POSTِ مستقیمِ سفارش.
        |
        | مسیرِ قبلی از همین‌جا فاکتور صادر می‌کرد و کاربر هرگز نه نام‌سرور
        | انتخاب می‌کرد نه مشخصاتِ مالک می‌داد؛ ثبتِ خودکار ساعت‌ها بعد به‌خاطرِ
        | نبودِ همان مشخصات شکست می‌خورد و دامنهٔ پرداخت‌شده در صفِ دستی پارک
        | می‌شد. ادعا همان است — «ردیفِ قیمت‌دار راهِ خرید دارد».
        */
        $this->assertMatchesRegularExpression('~/domains/checkout/\d+~', $html);
    }

    /** جستجوی خراب نباید فهرستِ دامنه‌های مشتری را هم بخوابانَد */
    public function test_a_failing_search_still_renders_the_page(): void
    {
        config()->set('services.openprovider.username', 'u');
        config()->set('services.openprovider.password', 'p');

        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*' => Http::response('boom', 500)]);

        $c = $this->customer();
        $this->domain($c, ['domain' => 'existing.com', 'status' => 'active', 'provision_status' => 'done']);

        $html = $this->actingAs($c, 'customer')
            ->get(route('account.domains', ['register' => 'anything.com']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('existing.com', $html);
    }

    // ═══════════════ پنلِ مدیریت ═══════════════

    /**
     * 🔴 صفِ دستی باید **خواننده** داشته باشد.
     *
     * `DomainRegistrar` دامنهٔ مشکل‌دار را به `manual` می‌بَرد تا کرون رهایش کند
     * و آدم تصمیم بگیرد — ولی تا امروز هیچ صفحه‌ای آن را نشان نمی‌داد.
     */
    public function test_the_admin_page_shows_the_manual_queue(): void
    {
        $d = $this->domain($this->customer());

        $html = $this->actingAs($this->staff(), 'web')
            ->get('/admin/domains')->assertOk()->getContent();

        $this->assertStringContainsString($d->domain, $html);
        $this->assertStringContainsString('رجیسترار جواب نداد', $html);
    }

    public function test_the_admin_can_requeue_a_stuck_domain(): void
    {
        $d = $this->domain($this->customer(), ['provision_tries' => 3]);

        $this->actingAs($this->staff(), 'web')
            ->post(route('admin.domains.retry', $d))->assertRedirect();

        $d->refresh();
        $this->assertSame('pending', $d->provision_status);
        $this->assertSame(0, $d->provision_tries);
        $this->assertNull($d->provision_error);
    }

    /** دامنهٔ ازقبل‌ثبت‌شده نباید دوباره به صف برود */
    public function test_an_already_registered_domain_cannot_be_requeued(): void
    {
        $d = $this->domain($this->customer(), ['provision_status' => 'done', 'status' => 'active']);

        $this->actingAs($this->staff(), 'web')
            ->post(route('admin.domains.retry', $d))->assertSessionHasErrors();

        $this->assertSame('done', $d->fresh()->provision_status);
    }

    public function test_the_admin_sidebar_links_to_domains(): void
    {
        $html = $this->actingAs($this->staff(), 'web')
            ->get('/admin/domains')->assertOk()->getContent();

        $this->assertStringContainsString('/admin/domains', $html);
    }

    /** مشتری نباید به پنلِ مدیریتِ دامنه‌ها برسد */
    public function test_a_customer_cannot_reach_the_admin_domain_page(): void
    {
        $this->actingAs($this->customer(), 'customer')
            ->get('/admin/domains')
            ->assertRedirect();
    }

    // ═══════════════ پروندهٔ مشتری در پنلِ مدیریت ═══════════════

    public function test_the_customer_profile_shows_their_domains(): void
    {
        $c = $this->customer();
        $d = $this->domain($c);

        $html = $this->actingAs($this->staff(), 'web')
            ->get('/admin/customers/'.$c->id)->assertOk()->getContent();

        $this->assertStringContainsString($d->domain, $html);
    }

    /** مشتریِ بی‌دامنه نباید جدولِ خالی ببیند */
    public function test_a_customer_without_domains_shows_no_domain_table(): void
    {
        $c = $this->customer();

        $html = $this->actingAs($this->staff(), 'web')
            ->get('/admin/customers/'.$c->id)->assertOk()->getContent();

        $this->assertStringNotContainsString('<h3>دامنه‌ها</h3>', $html);
    }
}

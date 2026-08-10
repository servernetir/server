<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\Domain;
use App\Models\DomainQuote;
use App\Models\Invoice;
use App\Services\Domain\DomainRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * مشخصاتِ ناقصِ مالک نباید **بعد از** گرفتنِ پول کشف شود.
 *
 * ═══ رخدادِ واقعی که این تست از آن آمد ═══
 *
 * مشتری `zhina.shop` را خرید، پول رفت، و دامنه با `provision_status='manual'`
 * پارک شد. علت در ستونِ `provision_error`:
 *
 *     «مشخصاتِ مالک ناقص است (نام، نشانی، شهر، کدپستی، تلفن و ایمیل لازم است)»
 *
 * علتِ ساختاری: `DomainController::order()` فقط `$profile === null` را
 * می‌سنجید. ولی پروفایل در ثبت‌نام ساخته می‌شود و فقط نام و ایمیل دارد — نشانی
 * و شهر و تلفن ندارد. پس گیت رد می‌شد، فاکتور صادر می‌شد، پول گرفته می‌شد، و
 * شرطِ **واقعی** ساعت‌ها بعد در `DomainRegistrar` می‌شکست.
 *
 * یعنی تنها لحظه‌ای که کاربر می‌توانست کاری بکند — لحظهٔ خرید — رد شده بود، و
 * کارِ باقی‌مانده دستیِ یک آدم بود روی چیزی که هیچ تصمیمی در آن نیست.
 */
class DomainOwnerGateTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        return Customer::create([
            'code'     => 'SN-'.random_int(100000, 999999),
            'email'    => 'own'.random_int(1000, 9999).'@example.test',
            'phone'    => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('secret-for-test'),
            'status'   => 'active',
        ]);
    }

    /** پروفایلِ «تازه ثبت‌نام‌کرده»: فقط نام و ایمیل — دقیقاً حالتِ واقعی */
    private function thinProfile(Customer $c): CustomerProfile
    {
        return CustomerProfile::create([
            'customer_id' => $c->id,
            'type'        => 'person',
            'is_default'  => true,
            'first_name'  => 'جعفر',
            'last_name'   => 'ابراهیمی',
            'email'       => $c->email,
            'country'     => 'IR',
        ]);
    }

    private function fatten(CustomerProfile $p): CustomerProfile
    {
        $p->fill([
            'address'     => 'خیابان آزادی، پلاک ۱',
            'city'        => 'تهران',
            'postal_code' => '1234567890',
            'mobile'      => '09121234567',
        ])->save();

        return $p->refresh();
    }

    private function quote(string $domain = 'zhina.shop'): DomainQuote
    {
        return DomainQuote::create([
            'domain'        => $domain,
            'tld'           => 'shop',
            'registrar'     => 'openprovider',
            'is_premium'    => false,
            'cost_amount'   => 1000,
            'cost_currency' => 'EUR',
            'sell_toman'    => 190000,
            'renew_toman'   => 190000,
            'honour_until'  => now()->addMinutes(15),
            'raw'           => [],
        ]);
    }

    // ═══════════════ گیت: پیش از پول، نه بعدش ═══════════════

    public function test_an_incomplete_owner_never_gets_an_invoice(): void
    {
        $c = $this->customer();
        $this->thinProfile($c);
        $q = $this->quote();

        $res = $this->actingAs($c, 'customer')
            ->post('/account/domains/order', ['quote_id' => $q->id, 'years' => 1]);

        $res->assertRedirect();

        $this->assertSame(0, Invoice::count(), 'برای مشخصاتِ ناقص فاکتور صادر شد — پولِ گرفته‌شده و ثبتِ ناممکن');
        $this->assertSame(0, Domain::count());
    }

    /**
     * ⚠️ نیمهٔ دوم — بی‌این، «گیت را ببند» هم سبز می‌شد و هیچ‌کس نمی‌توانست
     * دامنه بخرد.
     */
    public function test_a_complete_owner_does_get_an_invoice(): void
    {
        $c = $this->customer();
        $this->fatten($this->thinProfile($c));
        $q = $this->quote();

        $this->actingAs($c, 'customer')
            ->post('/account/domains/order', ['quote_id' => $q->id, 'years' => 1])
            ->assertRedirect();

        $this->assertSame(1, Invoice::count(), 'مشخصات کامل بود ولی فاکتور صادر نشد');
        $this->assertSame(1, Domain::where('domain', 'zhina.shop')->count());
    }

    /** فرمِ تسویه خودش مشخصات را می‌گیرد — همان یک درخواست، بی‌رفت‌وبرگشت */
    public function test_the_checkout_form_can_supply_the_missing_fields_inline(): void
    {
        $c = $this->customer();
        $p = $this->thinProfile($c);
        $q = $this->quote();

        $this->actingAs($c, 'customer')->post('/account/domains/order', [
            'quote_id'    => $q->id,
            'years'       => 1,
            'address'     => 'خیابان ولیعصر، پلاک ۲۰',
            'city'        => 'تهران',
            'postal_code' => '1111111111',
            'mobile'      => '09129998877',
        ])->assertRedirect();

        $this->assertSame(1, Invoice::count(), 'مشخصات در همان فرم داده شد ولی سفارش نگرفت');
        $this->assertSame('تهران', $p->refresh()->city);
    }

    /** ارسالِ خالی نباید نشانیِ درستِ قبلی را پاک کند */
    public function test_blank_fields_never_wipe_existing_owner_data(): void
    {
        $c = $this->customer();
        $p = $this->fatten($this->thinProfile($c));
        $q = $this->quote();

        $this->actingAs($c, 'customer')->post('/account/domains/order', [
            'quote_id' => $q->id, 'years' => 1,
            'address'  => '', 'city' => '', 'mobile' => '',
        ])->assertRedirect();

        $p->refresh();
        $this->assertSame('تهران', $p->city);
        $this->assertSame('09121234567', $p->mobile);
    }

    // ═══════════════ صفحهٔ تسویه ═══════════════

    public function test_the_checkout_page_asks_only_for_what_is_missing(): void
    {
        $c = $this->customer();
        $this->thinProfile($c);
        $q = $this->quote();

        $html = $this->actingAs($c, 'customer')
            ->get('/account/domains/checkout/'.$q->id)
            ->assertOk()
            ->getContent();

        // کم است ⇒ پرسیده می‌شود
        $this->assertStringContainsString('name="address"', $html);
        $this->assertStringContainsString('name="city"', $html);
        // پر است ⇒ دوباره پرسیده نمی‌شود
        $this->assertStringNotContainsString('name="first_name"', $html,
            'فیلدِ پرشده دوباره پرسیده شد — فرمی که همه‌چیز را دوباره بخواهد خریدار را می‌پرانَد');
        // نام‌سرور همان‌جاست، نه در یک صفحهٔ دیگر
        $this->assertStringContainsString('name="ns[]"', $html);
    }

    public function test_a_complete_owner_sees_no_owner_form_at_all(): void
    {
        $c = $this->customer();
        $this->fatten($this->thinProfile($c));
        $q = $this->quote();

        $html = $this->actingAs($c, 'customer')
            ->get('/account/domains/checkout/'.$q->id)
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('name="address"', $html);
        $this->assertStringContainsString(__('ui.dch_complete_ok'), $html);
    }

    /**
     * 🔴 ترتیبِ روت: `checkout` باید **پیش از** `/domains/{domain}` بیاید.
     * وگرنه لاراول `checkout` را نامِ دامنه می‌خوانَد و صفحه ۴۰۴ می‌شود.
     */
    public function test_the_checkout_route_is_not_swallowed_by_the_domain_route(): void
    {
        $c = $this->customer();
        $this->thinProfile($c);
        $q = $this->quote();

        $this->actingAs($c, 'customer')
            ->get('/account/domains/checkout/'.$q->id)
            ->assertOk();
    }

    // ═══════════════ خودترمیمی ═══════════════

    /**
     * 🔴 کارفرما: «تمام کارها باید اتوماتیک انجام شود.»
     *
     * `manual` یعنی کرون دیگر برنمی‌داردش. ولی علتِ این پارک‌شدن با کامل‌شدنِ
     * مشخصات **برطرف** می‌شود، پس ماندنش در صفِ آدم یعنی یک کارِ دستی که هیچ
     * تصمیمی در آن نیست.
     */
    public function test_completing_the_profile_frees_a_parked_domain_by_itself(): void
    {
        $c = $this->customer();
        $p = $this->thinProfile($c);

        $d = Domain::create([
            'customer_id' => $c->id, 'domain' => 'zhina.shop',
            'sld' => 'zhina', 'tld' => 'shop', 'registrar' => 'openprovider',
            'status' => 'pending', 'provision_status' => 'manual',
            'provision_tries' => 3, 'period_years' => 1,
            'provision_error' => 'مشخصاتِ مالک ناقص است (نام، نشانی، شهر، کدپستی، تلفن و ایمیل لازم است).',
        ]);

        $this->fatten($p);

        $d->refresh();
        $this->assertSame('pending', $d->provision_status, 'مشخصات کامل شد ولی دامنه هنوز منتظرِ آدم است');
        $this->assertSame(0, (int) $d->provision_tries, 'سقفِ تلاش صفر نشد — دامنه فوراً دوباره manual می‌شود');
        $this->assertNull($d->provision_error);
    }

    /**
     * ⚠️ نیمهٔ دومِ خودترمیمی: دامنه‌ای که به علتِ **دیگری** پارک شده باید
     * همان‌جا بماند. بی‌این، این قلاب هر خطای ساختاری را به حلقهٔ تلاشِ
     * بی‌پایان تبدیل می‌کند و اعتبارِ حسابِ رجیسترار را می‌سوزاند.
     */
    public function test_a_domain_parked_for_another_reason_stays_parked(): void
    {
        $c = $this->customer();
        $p = $this->thinProfile($c);

        $d = Domain::create([
            'customer_id' => $c->id, 'domain' => 'other.shop',
            'sld' => 'other', 'tld' => 'shop', 'registrar' => 'openprovider',
            'status' => 'pending', 'provision_status' => 'manual',
            'provision_tries' => 3, 'period_years' => 1,
            'provision_error' => 'رجیسترار ثبت را رد کرد: دامنه رزرو شده است.',
        ]);

        $this->fatten($p);

        $this->assertSame('manual', $d->refresh()->provision_status);
    }

    // ═══════════════ پارک‌شدن باید داد بزند ═══════════════

    /**
     * تا امروز `fail()` فقط یک ستون می‌نوشت. یعنی دامنهٔ **پرداخت‌شده** پارک
     * می‌شد و `/admin/errors` خالی می‌مانْد — تنها سطحی که مدیر نگاهش می‌کند.
     */
    public function test_parking_a_paid_domain_leaves_a_trace_in_the_error_tracker(): void
    {
        Http::fake(['*' => Http::response([], 500)]);
        \App\Support\ErrorTracker::clear();

        $c = $this->customer();
        $this->thinProfile($c);

        $d = Domain::create([
            'customer_id' => $c->id, 'domain' => 'zhina.shop',
            'sld' => 'zhina', 'tld' => 'shop', 'registrar' => 'openprovider',
            'status' => 'pending', 'provision_status' => 'pending', 'period_years' => 1,
        ]);

        app(DomainRegistrar::class)->register($d);

        $this->assertSame('manual', $d->refresh()->provision_status);

        $hit = collect(\App\Support\ErrorTracker::recent(50))
            ->contains(fn ($r) => str_contains(
                json_encode($r, JSON_UNESCAPED_UNICODE) ?: '', 'zhina.shop'
            ));

        $this->assertTrue($hit, 'دامنهٔ پرداخت‌شده بی‌صدا پارک شد — هیچ ردی در /admin/errors نگذاشت');
    }
}

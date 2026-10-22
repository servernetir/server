<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Services\Cloud\HourlyCycleConverter;
use App\Services\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 🔴 مشتری سرورِ ساعتی را خودش دوره‌ای می‌کند (TK-261018-5379).
 *
 * ادعاها روی **پولی است که واقعاً جابه‌جا می‌شود** و حالتِ سرویس پس از آن —
 * نه روی اینکه دکمه‌ای رندر شد. قیمتِ پلن ۱٬۰۰۰٬۰۰۰ تومان/ماه:
 *
 *   ماهانه ۱٬۰۰۰٬۰۰۰ · سه‌ماهه ۲٬۸۵۰٬۰۰۰ (۵٪) · شش‌ماهه ۵٬۴۰۰٬۰۰۰ (۱۰٪) · سالانه ۹٬۶۰۰٬۰۰۰ (۲۰٪)
 */
class HourlyCycleConvertTest extends TestCase
{
    use RefreshDatabase;

    private const MONTHLY = 1_000_000;

    private const HOURLY = 1_500;

    private function customer(int $credit): Customer
    {
        $c = Customer::create([
            'email' => 'cc'.random_int(1, 999999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);

        if ($credit > 0) {
            CreditEntry::create([
                'customer_id' => $c->id, 'currency_code' => 'IRT', 'amount' => $credit,
                'balance_after' => $credit, 'reason' => 'topup', 'source_type' => Customer::class,
                'source_id' => $c->id, 'note' => 'test',
            ]);
        }

        return $c;
    }

    private function hourlyServer(Customer $c, string $provider = 'hetzner', array $over = []): Service
    {
        CloudLocation::firstOrCreate(['code' => 'fi-helsinki'], ['country' => 'FI', 'city' => 'Helsinki', 'is_active' => true]);

        $plan = CloudPlan::create([
            'provider' => $provider, 'provider_ref' => 'cx32-'.random_int(1, 99999), 'provider_location' => 'hel1',
            'location_code' => 'fi-helsinki', 'public_name' => 'CV-4-8', 'slug' => 'cv-4c-8g-80d-fi-helsinki',
            'vcpu' => 4, 'ram_mb' => 8192, 'disk_gb' => 80, 'disk_type' => 'nvme', 'traffic_gb' => 20480,
            'cpu_kind' => 'shared', 'arch' => 'x86', 'cost_eur_cents' => 700, 'price_eur_cents' => 1000,
            'price_irt' => self::MONTHLY, 'is_active' => true, 'in_stock' => true,
        ]);

        $s = Service::create(array_merge([
            'customer_id' => $c->id, 'name' => 'سرور مجازی vps-test (ساعتی)', 'currency_code' => 'IRT',
            'price' => 600_000,                      // قیمتِ قفل‌شدهٔ قدیمی — نباید استفاده شود
            'tax_percent' => 0, 'cycle' => 'monthly', 'billing_mode' => 'hourly',
            'hourly_rate_irt' => self::HOURLY, 'on_credit_out' => 'suspend',
            'last_metered_at' => now(), 'status' => 'active', 'provision_status' => 'done',
            'activated_at' => now()->subDays(10), 'cloud_plan_id' => $plan->id, 'cloud_addons' => [],
        ], $over));

        CloudInstance::create([
            'service_id' => $s->id, 'provider' => $provider, 'provider_ref' => '123'.$s->id,
            'location_code' => 'fi-helsinki', 'image_key' => 'ubuntu-24.04',
            'hostname' => 'sn-svc-'.$s->id, 'ipv4' => '65.109.176.71',
            'status' => 'running', 'ready_notified_at' => now()->subDays(10),
        ]);

        return $s;
    }

    private function convert(Customer $c, Service $s, string $cycle)
    {
        return $this->actingAs($c, 'customer')
            ->post(route('account.cloud.convert-cycle', $s, false), ['cycle' => $cycle]);
    }

    private function pay(Invoice $invoice): void
    {
        $payment = Payment::create([
            'invoice_id' => $invoice->id, 'customer_id' => $invoice->customer_id, 'gateway' => 'bale',
            'currency_code' => 'IRT', 'amount' => (int) $invoice->total, 'status' => 'redirected',
            'external_ref' => 'X'.$invoice->id,
        ]);

        app(PaymentService::class)->settleConfirmed($payment, 'REF-'.$invoice->id);
    }

    // ═══════════ مسیرِ کیف پول ═══════════

    public function test_enough_wallet_switches_now_at_todays_discounted_price(): void
    {
        $c = $this->customer(3_000_000);
        $s = $this->hourlyServer($c);

        $this->convert($c, $s, 'quarterly')->assertRedirect()->assertSessionHas('ok');

        $s->refresh();
        $this->assertSame('cycle', $s->billing_mode);
        $this->assertSame('quarterly', $s->cycle);
        $this->assertSame(2_850_000, (int) $s->price, 'قیمتِ امروز با ۵٪ تخفیف — نه قیمتِ قفل‌شدهٔ ۶۰۰٬۰۰۰');
        $this->assertSame(now()->addMonthsNoOverflow(3)->toDateString(), $s->next_due_at->toDateString(),
            'سررسید از لحظهٔ تبدیل؛ سرورِ ساعتی دوره‌ای نداشته که از پایانش جلو برود.');
        $this->assertSame(150_000, $c->creditBalance('IRT'));
        $this->assertDatabaseHas('credit_ledger', [
            'customer_id' => $c->id, 'amount' => -2_850_000, 'reason' => 'cloud_hourly_convert',
            'source_type' => Service::class, 'source_id' => $s->id,
        ]);
        $this->assertSame(0, Invoice::count(), 'کیف کافی بود — هیچ فاکتوری نباید ساخته شود.');
    }

    /** عددی که روی صفحه است دقیقاً همان است که کسر می‌شود */
    public function test_the_page_shows_exactly_what_is_charged(): void
    {
        $c = $this->customer(20_000_000);
        $s = $this->hourlyServer($c);

        $html = (string) $this->actingAs($c, 'customer')
            ->get(route('account.cloud.show', $s, false))->assertOk()->getContent();

        $this->assertStringContainsString(route('account.cloud.convert-cycle', $s, false), $html);
        foreach (['monthly' => 1_000_000, 'quarterly' => 2_850_000, 'semiannual' => 5_400_000, 'yearly' => 9_600_000] as $cy => $p) {
            $this->assertStringContainsString('value="'.$cy.'" data-price="'.$p.'"', $html, "قیمتِ $cy روی صفحه نیست یا فرق دارد.");
        }
        $this->assertStringNotContainsString('panel.cycle_convert', $html, 'کلیدِ خامِ ترجمه چاپ شده.');

        $this->convert($c, $s, 'yearly');
        $this->assertSame(20_000_000 - 9_600_000, $c->creditBalance('IRT'));
    }

    // ═══════════ گیت‌ها ═══════════

    public function test_a_provider_not_yet_enabled_gets_no_button_and_cannot_convert(): void
    {
        $c = $this->customer(3_000_000);
        $s = $this->hourlyServer($c, 'aeza');

        $html = (string) $this->actingAs($c, 'customer')
            ->get(route('account.cloud.show', $s, false))->assertOk()->getContent();
        $this->assertStringNotContainsString(route('account.cloud.convert-cycle', $s, false), $html,
            'دکمه‌ای که بعد خطا بدهد همان تیکت را می‌سازد.');

        $this->convert($c, $s, 'monthly')->assertSessionHasErrors();
        $this->assertSame('hourly', $s->fresh()->billing_mode);
        $this->assertSame(3_000_000, $c->creditBalance('IRT'));
    }

    public function test_gpu_is_never_convertible_even_if_listed(): void
    {
        config(['billing.cycle_convert_providers' => ['hetzner', 'salad']]);

        $this->assertFalse(app(HourlyCycleConverter::class)->providerAllowed('salad'));
        $this->assertTrue(app(HourlyCycleConverter::class)->providerAllowed('hetzner'));
    }

    public function test_a_suspended_server_cannot_convert(): void
    {
        $c = $this->customer(3_000_000);
        $s = $this->hourlyServer($c, 'hetzner', ['status' => 'suspended']);

        $this->convert($c, $s, 'monthly')->assertSessionHasErrors();
        $this->assertSame('hourly', $s->fresh()->billing_mode);
    }

    public function test_another_customers_server_is_not_found(): void
    {
        $owner = $this->customer(3_000_000);
        $s = $this->hourlyServer($owner);
        $other = $this->customer(3_000_000);

        $this->convert($other, $s, 'monthly')->assertNotFound();
        $this->assertSame('hourly', $s->fresh()->billing_mode);
    }

    public function test_an_unknown_cycle_is_rejected(): void
    {
        $c = $this->customer(3_000_000);
        $s = $this->hourlyServer($c);

        $this->convert($c, $s, 'weekly')->assertSessionHasErrors();
        $this->assertSame('hourly', $s->fresh()->billing_mode);
    }

    // ═══════════ مسیرِ فاکتور ═══════════

    public function test_short_wallet_issues_a_topup_and_paying_it_converts(): void
    {
        $c = $this->customer(500_000);
        $s = $this->hourlyServer($c);

        $resp = $this->convert($c, $s, 'yearly');

        $invoice = Invoice::sole();
        $resp->assertRedirect(route('account.invoice', $invoice, false));
        $this->assertSame('topup', $invoice->kind, 'فاکتورِ سرویس در applyPaid یعنی «تمدید» — نباید باشد.');
        $this->assertNull($invoice->service_id);
        // کسری ۹٬۱۰۰٬۰۰۰ + حاشیهٔ ۲۴ ساعت (۳۶٬۰۰۰) → گرد رو به بالا
        $this->assertSame(9_140_000, (int) $invoice->total);
        $this->assertSame('hourly', $s->fresh()->billing_mode, 'تا پرداخت، سرور ساعتی می‌ماند.');

        $this->pay($invoice);

        $s->refresh();
        $this->assertSame('cycle', $s->billing_mode);
        $this->assertSame('yearly', $s->cycle);
        $this->assertSame(9_600_000, (int) $s->price);
        $this->assertSame(500_000 + 9_140_000 - 9_600_000, $c->creditBalance('IRT'), 'مازاد در کیفِ مشتری می‌مانَد.');
        $this->assertArrayNotHasKey(HourlyCycleConverter::META, (array) $s->provision_meta);
    }

    public function test_a_second_click_reuses_the_same_invoice(): void
    {
        $c = $this->customer(100_000);
        $s = $this->hourlyServer($c);

        $this->convert($c, $s, 'monthly');
        $this->convert($c, $s, 'monthly');

        $this->assertSame(1, Invoice::count());
    }

    public function test_choosing_another_cycle_cancels_the_unpaid_invoice(): void
    {
        $c = $this->customer(100_000);
        $s = $this->hourlyServer($c);

        $this->convert($c, $s, 'monthly');
        $first = Invoice::sole();
        $this->convert($c, $s, 'yearly');

        $this->assertSame('canceled', $first->fresh()->status);
        $this->assertSame(1, Invoice::where('status', 'unpaid')->count());
        $this->assertSame('yearly', $s->fresh()->provision_meta[HourlyCycleConverter::META]['cycle']);
    }

    public function test_an_expired_quote_never_converts_and_the_money_stays(): void
    {
        $c = $this->customer(100_000);
        $s = $this->hourlyServer($c);

        $this->convert($c, $s, 'monthly');
        $invoice = Invoice::sole();

        $this->travel(HourlyCycleConverter::QUOTE_TTL_HOURS + 1)->hours();
        $this->pay($invoice);

        $s->refresh();
        $this->assertSame('hourly', $s->billing_mode, 'قیمتِ منقضی نباید محترم بماند.');
        $this->assertSame(100_000 + (int) $invoice->total, $c->creditBalance('IRT'), 'پول در کیف ماند.');
    }

    public function test_a_terminated_server_never_converts_and_the_money_stays(): void
    {
        $c = $this->customer(100_000);
        $s = $this->hourlyServer($c);

        $this->convert($c, $s, 'monthly');
        $invoice = Invoice::sole();
        $s->update(['status' => 'terminated', 'cancelled_at' => now()]);

        $this->pay($invoice);

        $this->assertSame('hourly', $s->fresh()->billing_mode);
        $this->assertSame(100_000 + (int) $invoice->total, $c->creditBalance('IRT'));
    }

    // ═══════════ یک تعریف: مترِ ساعتی هم از همین ═══════════

    public function test_meter_autoconvert_uses_todays_price_through_the_same_converter(): void
    {
        $c = $this->customer(1_200_000);
        // نرخِ ساعتیِ بیش از موجودی ⇒ متر به «اتمامِ اعتبار» می‌رسد
        $s = $this->hourlyServer($c, 'aeza', [
            'hourly_rate_irt' => 5_000_000, 'on_credit_out' => 'convert', 'last_metered_at' => now()->subHours(2),
        ]);

        $this->artisan('cloud:meter');

        $s->refresh();
        $this->assertSame('cycle', $s->billing_mode, 'انتخابِ لحظهٔ خرید روی همهٔ زیرساخت‌ها کار می‌کرد و باید بماند.');
        $this->assertSame('monthly', $s->cycle);
        $this->assertSame(self::MONTHLY, (int) $s->price, 'قیمتِ امروز، نه قیمتِ قفل‌شدهٔ ۶۰۰٬۰۰۰');
        $this->assertSame(200_000, $c->creditBalance('IRT'));
    }

    // ═══════════ رقابت‌ها — یافته‌های بازبینیِ مستقل ═══════════

    /**
     * 🔴 متر فهرستش را اولِ اجرا می‌خوانَد. اگر مشتری در همین فاصله تبدیل کند، شیءِ
     * کهنه نباید ساعت کسر کند، ذخیره بگذارد یا «اعتبار تمام شد» بزند.
     * لنگر عمداً به مقدارِ کهنه برگردانده می‌شود تا محافظِ متر جدا از
     * `last_metered_at`ِ تبدیل سنجیده شود.
     */
    public function test_a_meter_holding_a_stale_hourly_object_never_bills_a_converted_server(): void
    {
        $c = $this->customer(1_000_000 + 2 * self::HOURLY);
        $s = $this->hourlyServer($c, 'hetzner', ['last_metered_at' => now()->subHours(2)->subMinutes(5)]);
        $stale = Service::with(['customer', 'cloudInstance', 'cloudPlan'])->find($s->id);
        $anchor = $stale->last_metered_at;

        $this->assertTrue(app(HourlyCycleConverter::class)->convert(Service::find($s->id), 'monthly', self::MONTHLY, 'customer'));
        Service::whereKey($s->id)->update(['last_metered_at' => $anchor]);
        $afterConvert = $c->creditBalance('IRT');

        $cmd = app(\App\Console\Commands\CloudMeterHourly::class);
        $m = new \ReflectionMethod($cmd, 'meterOne');
        $result = $m->invoke($cmd, $stale, app(\App\Services\Cloud\CloudProvisioner::class),
            app(\App\Services\Cloud\CloudManager::class), app(\App\Services\Cloud\InterruptibleBillingClock::class));

        $this->assertSame('skipped', $result);
        $this->assertSame($afterConvert, $c->creditBalance('IRT'), 'متر روی سرورِ دوره‌ای کسرِ ساعتی زد — دو بار پول.');
        $fresh = $s->fresh();
        $this->assertSame('cycle', $fresh->billing_mode);
        $this->assertSame('active', $fresh->status, 'سرورِ تازه‌پرداخت‌شده خاموش شد.');
    }

    /** دو تب: اولی تبدیل کرد؛ دومی (که سرور را هنوز ساعتی دیده بود) نباید فاکتورِ کلِ دوره بسازد */
    public function test_a_second_tab_after_a_successful_switch_issues_no_invoice(): void
    {
        $c = $this->customer(1_000_000);
        $s = $this->hourlyServer($c);
        $tabB = Service::with(['customer', 'cloudInstance', 'cloudPlan'])->find($s->id);

        $converter = app(HourlyCycleConverter::class);
        $this->assertSame('converted', $converter->request(Service::with(['customer', 'cloudInstance', 'cloudPlan'])->find($s->id), 'monthly')['status']);
        $this->assertSame('converted', $converter->request($tabB, 'monthly')['status']);

        $this->assertSame(0, Invoice::count(), 'تبِ دوم یک فاکتورِ افزایشِ اعتبارِ بی‌جا ساخت.');
        $this->assertSame(0, $c->creditBalance('IRT'));
    }

    /** اگر متر عقب افتاده باشد، ساعت‌های کاملِ سپری‌شده هم‌زمان با تبدیل کسر می‌شوند — نه بخشیده */
    public function test_hours_the_meter_has_not_billed_yet_are_settled_on_switch(): void
    {
        $c = $this->customer(3_000_000);
        $s = $this->hourlyServer($c, 'hetzner', ['last_metered_at' => now()->subHours(3)->subMinutes(10)]);

        $this->convert($c, $s, 'quarterly')->assertSessionHas('ok');

        $this->assertSame(3_000_000 - 2_850_000 - 3 * self::HOURLY, $c->creditBalance('IRT'));
        $this->assertDatabaseHas('credit_ledger', [
            'source_id' => $s->id, 'reason' => 'cloud_hourly', 'amount' => -3 * self::HOURLY,
        ]);
    }

    /** سرورِ معلق‌شده بینِ سنجشِ بیرونی و قفل، با تبدیل «فعال» نوشته نمی‌شود */
    public function test_a_server_suspended_in_the_meantime_is_not_revived_by_convert(): void
    {
        $c = $this->customer(3_000_000);
        $s = $this->hourlyServer($c);
        $loaded = Service::find($s->id);
        Service::whereKey($s->id)->update(['status' => 'suspended']);

        $this->assertFalse(app(HourlyCycleConverter::class)->convert($loaded, 'monthly', self::MONTHLY, 'customer'));
        $this->assertSame('suspended', $s->fresh()->status);
        $this->assertSame('hourly', $s->fresh()->billing_mode);
        $this->assertSame(3_000_000, $c->creditBalance('IRT'));
    }

    // ═══════════ «وقتی اعتبار تمام شد» پس از خرید ═══════════

    public function test_the_customer_can_change_the_credit_out_choice_after_purchase(): void
    {
        $c = $this->customer(100_000);
        $s = $this->hourlyServer($c);

        $this->actingAs($c, 'customer')
            ->post(route('account.cloud.credit-out', $s, false), ['on_credit_out' => 'convert'])
            ->assertSessionHas('ok');
        $this->assertSame('convert', $s->fresh()->on_credit_out);

        $this->actingAs($c, 'customer')
            ->post(route('account.cloud.credit-out', $s, false), ['on_credit_out' => 'delete-everything'])
            ->assertSessionHasErrors();
        $this->assertSame('convert', $s->fresh()->on_credit_out);
    }
}

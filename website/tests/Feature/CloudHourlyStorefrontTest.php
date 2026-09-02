<?php

namespace Tests\Feature;

use App\Http\Controllers\Account\CloudStoreController;
use App\Models\CloudImage;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 🔴 فروشگاه نباید ساعتی‌ای را پیشنهاد کند که سرِ تحویل رد می‌شود.
 *
 * نیمهٔ دومِ پروندهٔ #۹۶/#۹۸: گاردِ `CloudProvisioner` جلوی سفارشِ بی‌فایده به
 * زیرساخت را می‌گیرد، ولی تا وقتی صفحه گزینه را نشان می‌دهد، پول **قبلِ** آن
 * گارد از کیفِ پول کم شده و مشتری یک سفارشِ لغوشدنی گرفته است. تجربهٔ بد
 * همان‌جا ساخته می‌شود، نه در لاگ.
 *
 * @see CloudHourlyTermSupportTest — قاعده، انتخابِ عرضه و گاردِ تحویل
 */
class CloudHourlyStorefrontTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private function u(): string
    {
        return route('account.cloud.store', [], false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        // همان بازسازیِ ترتیبِ روت‌ها که بقیهٔ تست‌های فروشگاه دارند: این دو روت
        // در routes/web.php **پیش از** روتِ همه‌گیرِ /{loc}/{rest?} ثبت می‌شوند.
        if (! Route::has('account.cloud.store')) {
            Route::middleware(['web', 'auth:customer'])->prefix('account')->name('account.')->group(function () {
                Route::get('/cloud-store', [CloudStoreController::class, 'index'])->name('cloud.store');
                Route::post('/cloud-store', [CloudStoreController::class, 'order'])->name('cloud.store.place');
            });

            $mine = ['account.cloud.store', 'account.cloud.store.place'];
            $ordered = new RouteCollection;

            foreach (collect(Route::getRoutes()->getRoutes())
                ->sortBy(fn ($r) => in_array($r->getName(), $mine, true) ? 0 : 1)->all() as $route) {
                $ordered->add($route);
            }

            Route::setRoutes($ordered);
        }
    }

    // ───────────────────────── فیکسچرها ─────────────────────────

    private function plan(string $provider, array $over = []): CloudPlan
    {
        CloudLocation::firstOrCreate(['code' => 'se-stockholm'],
            ['country' => 'SE', 'city' => 'Stockholm', 'is_active' => true]);

        return CloudPlan::create(array_merge([
            'provider' => $provider, 'provider_ref' => $provider.'-'.self::$seq++,
            'provider_location' => 'sto', 'location_code' => 'se-stockholm',
            'public_name' => 'CV-1-4', 'slug' => 'cv-1c-4g-10d-se-stockholm',
            'vcpu' => 1, 'ram_mb' => 4096, 'disk_gb' => 10, 'disk_type' => 'nvme',
            'traffic_gb' => 20480, 'cpu_kind' => 'shared', 'arch' => 'x86',
            'cost_eur_cents' => 201, 'price_eur_cents' => 260, 'price_irt' => 530000,
            'cost_hour_eur_micro' => null,
            'is_active' => true, 'in_stock' => true,
        ], $over));
    }

    private function image(string $provider): void
    {
        CloudImage::firstOrCreate(['provider' => $provider, 'key' => 'ubuntu-24.04'], [
            'provider_ref' => 'ubuntu-24-04', 'kind' => 'os', 'family' => 'ubuntu',
            'version' => '24.04', 'label' => 'Ubuntu 24.04', 'arch' => 'x86', 'is_active' => true,
        ]);
    }

    private function customer(int $credit = 0): Customer
    {
        $c = Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'hs'.random_int(1, 999999).'@example.com',
            'phone' => '0913'.random_int(1000000, 9999999),
            'password' => 'secret1234', 'status' => 'active', 'locale' => 'fa',
        ]);

        if ($credit > 0) {
            CreditEntry::create([
                'customer_id' => $c->id, 'currency_code' => 'IRT', 'amount' => $credit,
                'balance_after' => $credit, 'reason' => 'topup',
                'source_type' => Customer::class, 'source_id' => $c->id, 'note' => 'test',
            ]);
        }

        return $c;
    }

    private function order(Customer $c, array $over = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($c, 'customer')->post(route('account.cloud.store.place', [], false), array_merge([
            'location' => 'se-stockholm',
            'plan' => 'cv-1c-4g-10d-se-stockholm',
            'image' => 'ubuntu-24.04',
            'cycle' => 'monthly',
            'billing_mode' => 'hourly',
        ], $over));
    }

    // ═══════════ سفارش ═══════════

    /**
     * 🔴 سفارشِ ساعتیِ پلنی که زیرساخت ساعتی نمی‌فروشدش رد می‌شود — و
     * **کیفِ پول دست‌نخورده می‌مانَد**.
     *
     * ادعای دوم مهم‌تر از اولی است: در رخدادِ واقعی پول کم شد و سرویسِ
     * لغوشدنی ساخته شد، و برگرداندنش کارِ دستیِ مدیر بود.
     */
    public function test_an_hourly_order_on_a_monthly_only_plan_is_refused_before_any_charge(): void
    {
        $this->plan('aeza');
        $this->image('aeza');
        $customer = $this->customer(5_000_000);

        $this->order($customer)->assertSessionHasErrors('billing_mode');

        $this->assertSame(0, Service::where('customer_id', $customer->id)->count(),
            'سرویسِ ساعتیِ محکوم‌به‌شکست ساخته شد');
        $this->assertSame(0, CreditEntry::where('customer_id', $customer->id)
            ->where('reason', 'cloud_hourly')->count(),
            '🔴 پول از کیفِ پول کم شد برای سفارشی که تحویل نمی‌شود');
    }

    /** پیامِ خطا باید «ساعتی ندارد» باشد، نه «چنین پلنی نیست». */
    public function test_the_refusal_says_the_plan_is_monthly_only_not_that_it_is_missing(): void
    {
        $this->plan('aeza');
        $this->image('aeza');

        $this->order($this->customer(5_000_000))
            ->assertSessionHasErrors(['billing_mode' => __('ui.cvb_e_hourly_unsupported')]);
    }

    /** همان پلن، ماهانه، باید بی‌دردسر سفارش داده شود. */
    public function test_the_same_plan_still_sells_monthly(): void
    {
        $this->plan('aeza');
        $this->image('aeza');
        $customer = $this->customer();

        $this->order($customer, ['billing_mode' => 'cycle'])->assertSessionHasNoErrors();

        $this->assertSame(1, Service::where('customer_id', $customer->id)->count(),
            'گارد فروشِ ماهانه را هم بست — بیش از اندازه سفت است');
    }

    /** و اگر همان اسلاگ ردیفِ ساعتی‌فروش داشته باشد، سفارشِ ساعتی می‌گیرد. */
    public function test_hourly_goes_through_when_the_slug_has_an_hourly_capable_row(): void
    {
        $this->plan('aeza', ['cost_eur_cents' => 201]);
        $this->plan('hetzner', ['cost_eur_cents' => 380]);
        $this->image('aeza');
        $this->image('hetzner');
        $customer = $this->customer(5_000_000);

        $this->order($customer)->assertSessionHasNoErrors();

        $service = Service::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame('hourly', (string) $service->billing_mode);
    }

    // ═══════════ صفحه ═══════════

    /**
     * آیا خودِ تگِ کلیدِ ساعتی صفتِ `hidden` دارد؟
     *
     * ⚠️ روی **تگ** مچ می‌کند نه روی کلِ صفحه: رشتهٔ «hidden» ده‌ها بار در
     * این صفحه می‌آید (فیلدهای مخفی، بدنهٔ ساعتی، جاوااسکریپت) و جستجوی ساده
     * همیشه سبز می‌شد — یعنی تستی که هیچ‌وقت نمی‌افتد.
     */
    private function hourlyToggleIsHidden(string $html): bool
    {
        $this->assertMatchesRegularExpression('~<label[^>]*cvb-seg-h[^>]*>~', $html,
            'کلیدِ ساعتی اصلاً رندر نشده — با @if حذف شده به‌جای اینکه hidden شود');

        preg_match('~<label[^>]*cvb-seg-h[^>]*>~', $html, $m);

        return (bool) preg_match('~\shidden(\s|>|=)~', $m[0]);
    }

    /**
     * 🔴 کلیدِ «ساعتی» روی مکانی که هیچ ردیفِ ساعتی‌فروشی ندارد، خاموش است.
     *
     * ⚠️ صفتِ `hidden` تنها وقتی معنا دارد که CSS خنثی‌اش نکند — تستِ بعدی
     * دقیقاً همان را می‌سنجد.
     */
    public function test_the_hourly_toggle_is_switched_off_when_nothing_here_sells_hourly(): void
    {
        $this->plan('aeza');
        $this->image('aeza');

        $html = $this->actingAs($this->customer(), 'customer')
            ->get($this->u().'?loc=se-stockholm')->assertOk()->getContent();

        $this->assertTrue($this->hourlyToggleIsHidden($html),
            'کلیدِ ساعتی روشن مانده روی مکانی که هیچ پلنش ساعتی فروخته نمی‌شود');
    }

    /** و روی مکانی که ساعتی دارد، روشن است. */
    public function test_the_hourly_toggle_is_on_when_a_row_here_sells_hourly(): void
    {
        $this->plan('hetzner');
        $this->image('hetzner');

        $html = $this->actingAs($this->customer(), 'customer')
            ->get($this->u().'?loc=se-stockholm')->assertOk()->getContent();

        $this->assertStringContainsString('cvb-seg-h', $html);
        $this->assertFalse($this->hourlyToggleIsHidden($html),
            'کلیدِ ساعتی خاموش است در حالی که یک ردیفِ همین مکان ساعتی می‌فروشد');
    }

    /**
     * 🔴 `hidden` واقعاً پنهان کند.
     *
     * `.cvb-seg{display:inline-flex}` یک قاعدهٔ **نویسنده** است و بر
     * `[hidden]`ِ مرورگر می‌چربد. یعنی `hSeg.hidden = …` در جاوااسکریپت —
     * که از قبل برای سرورِ برمتال هم نوشته شده بود — هیچ کاری نمی‌کرد و کلید
     * سرِ جایش و کلیک‌شدنی می‌مانْد. همان تله که panel.css دو بار خورده
     * (`.cvb-plan[hidden]` و `.ad-bulk[hidden]`).
     */
    public function test_css_actually_hides_a_hidden_segment(): void
    {
        $css = (string) file_get_contents(public_path('assets/css/panel.css'));

        $this->assertStringContainsString('.cvb-seg[hidden]{display:none}', $css,
            '🔴 بی‌این قاعده، صفتِ hidden روی کلیدِ ساعتی/برمتال بی‌اثر است');

        // و ترتیب مهم است: خنثی‌کننده باید **پس از** قاعدهٔ display بیاید.
        $this->assertGreaterThan(
            strpos($css, '.cvb-seg{position:relative;display:inline-flex'),
            strpos($css, '.cvb-seg[hidden]{display:none}'),
            'خنثی‌کننده پیش از قاعدهٔ display آمده و با تساویِ ویژگی، بازنده است'
        );
    }
}

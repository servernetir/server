<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\ServiceController;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Provisioning\HetznerStorageCosts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 🔴 محافظِ تمدید — نیمهٔ دومِ «اجازهٔ ضرر به من نده».
 *
 * کفِ `Product::priceForCycle()` فقط لحظهٔ **فروش** را می‌پوشاند. بعد از آن،
 * عدد در `services.price` قفل می‌شود و `services:renew-due` تا ابد همان را
 * صورت‌حساب می‌کند — در حالی که اجارهٔ باکس یورویی است و نرخ حرکت می‌کند.
 * در فاصلهٔ چند ساعتِ همین کار، یورو از ۲۵۷٬۴۰۰ به ۲۶۲٬۸۰۰ رفت.
 *
 * پس سرویسی که دیروز سودده فروخته شد، امسال می‌تواند بی‌صدا زیانده تمدید شود —
 * بی‌هیچ خطایی، چون تمدید «موفق» است.
 */
class RenewalFloorTest extends TestCase
{
    use RefreshDatabase;

    /** bx11 = ۳٫۲۰ € · سربار ۱۰٪ → ۳٫۵۲ € · نرخ ۲۵۰٬۰۰۰ → ۸۸۰٬۰۰۰ ت/ماه */
    private function seedCosts(): void
    {
        config([
            'provisioning.hetzner_storage.plans' => ['sn_backup_3' => 'bx11'],
            'provisioning.hetzner_storage.min_margin_pct' => 5,
        ]);

        Setting::put('pricing_fx_fee_pct_hetzner', '10');
        Setting::put('pricing_rate_override', '250000');

        HetznerStorageCosts::remember(['bx11' => 320], 'fsn1');
    }

    private function service(array $over = []): Service
    {
        $c = Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'r'.random_int(1000, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);

        return Service::create(array_merge([
            'customer_id' => $c->id,
            'name' => 'هاست بکاپ — BK-1T', 'plan' => 'sn_backup_3',
            'currency_code' => 'IRT', 'price' => 700_000, 'tax_percent' => 10,
            'cycle' => 'monthly', 'status' => 'active',
            'next_due_at' => now()->addDays(3),
        ], $over));
    }

    public function test_a_renewal_below_cost_is_raised_to_the_floor(): void
    {
        $this->seedCosts();
        $s = $this->service(['price' => 700_000]);   // زیرِ ۸۸۰٬۰۰۰ بهای تمام‌شده

        $invoice = app(ServiceController::class)->issueInvoice($s);

        $floor = (int) ceil(880_000 * 1.05);

        $this->assertSame($floor, (int) $s->fresh()->price,
            'قیمتِ سرویس باید به کفِ امروز بالا رفته باشد.');
        $this->assertSame($floor, (int) $invoice->subtotal,
            'فاکتورِ تمدید هنوز مبلغِ قدیمیِ زیانده را دارد.');
    }

    /**
     * ⚠️ فقط بالا می‌رود. اگر نرخِ ارز پایین بیاید، قیمتِ مشتری خودبه‌خود کم
     * نمی‌شود — تخفیف تصمیمِ کارفراست نه کارِ خودکارِ کد.
     */
    public function test_a_healthy_renewal_price_is_never_lowered(): void
    {
        $this->seedCosts();
        $s = $this->service(['price' => 5_000_000]);

        $invoice = app(ServiceController::class)->issueInvoice($s);

        $this->assertSame(5_000_000, (int) $s->fresh()->price);
        $this->assertSame(5_000_000, (int) $invoice->subtotal);
    }

    /**
     * فاکتورِ ناگهان‌گران‌شدهٔ بی‌توضیح یک تیکتِ حتمی است — و بدتر، مشتری فکر
     * می‌کند اشتباهی رخ داده.
     */
    public function test_the_raise_is_explained_on_the_invoice_itself(): void
    {
        $this->seedCosts();
        $s = $this->service(['price' => 700_000]);

        $invoice = app(ServiceController::class)->issueInvoice($s);
        $item = $invoice->items()->first();

        $this->assertStringContainsString('بازنگریِ قیمت', (string) $item->description);
        $this->assertStringContainsString('700,000', (string) $item->description);
    }

    /** سرویسی که پلنش از هتزنر نیست، اصلاً از این مسیر رد نمی‌شود */
    public function test_a_non_hetzner_service_is_untouched(): void
    {
        $this->seedCosts();
        $s = $this->service(['plan' => 'sn_linux_1', 'price' => 170_000]);

        $invoice = app(ServiceController::class)->issueInvoice($s);

        $this->assertSame(170_000, (int) $s->fresh()->price);
        $this->assertSame(170_000, (int) $invoice->subtotal);
    }

    /**
     * 🔴 کف تومانی است. اعمالش روی سرویسِ یورویی یعنی مقایسهٔ عددِ یورو با
     * عددِ تومان — خرابیِ صامتی که مبلغ را هزاران برابر می‌کند.
     */
    public function test_a_euro_priced_service_is_never_compared_against_a_toman_floor(): void
    {
        $this->seedCosts();
        $s = $this->service(['currency_code' => 'EUR', 'price' => 400]);   // ۴٫۰۰ €

        $invoice = app(ServiceController::class)->issueInvoice($s);

        $this->assertSame(400, (int) $s->fresh()->price);
        $this->assertSame(400, (int) $invoice->subtotal);
    }

    /** دورهٔ «یک‌بار» تمدید ندارد، پس کفی هم ندارد */
    public function test_a_one_off_service_has_no_renewal_floor(): void
    {
        $this->seedCosts();
        $s = $this->service(['cycle' => 'onetime', 'price' => 100_000]);

        app(ServiceController::class)->issueInvoice($s);

        $this->assertSame(100_000, (int) $s->fresh()->price);
    }
}

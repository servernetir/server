<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Setting;
use App\Services\Provisioning\HetznerStorageCosts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 🔴 «اجازهٔ ضرر به من نده» — کفِ حاشیهٔ فضای بکاپ.
 *
 * ═══ رخداد (شهریور ۱۴۰۵) ═══
 * سه پلنِ بکاپ روی دورهٔ **سالانه** ۱۱ تا ۱۳ درصد زیرِ قیمتِ خرید فروخته
 * می‌شدند. ماهانه سود می‌داد و سالانه ضرر: تخفیفِ ۲۰٪ دوره از کلِ حاشیهٔ یک
 * محصولِ بازفروشی بزرگ‌تر بود.
 *
 * و چون تحویل **موفق** می‌شد، هیچ خطایی هیچ‌جا ثبت نمی‌شد. ضرر فقط در
 * صورت‌حسابِ ماهانهٔ هتزنر پیدا می‌شد — ماه‌ها بعد.
 *
 * ⚠️ ادعاهای این تست روی **قیمتِ خروجی** است، نه روی اینکه «کف صدا زده شد».
 * اصلاحِ سه عدد در پنل هم تست را سبز می‌کرد ولی فردا با جهشِ ارز می‌شکست؛
 * این‌جا کف باید از بهای واقعی حساب شود.
 */
class StorageMarginFloorTest extends TestCase
{
    use RefreshDatabase;

    /** bx11 = ۳٫۲۰ € خام · سربار ۱۰٪ → ۳٫۵۲ € · نرخ ۲۵۰٬۰۰۰ → ۸۸۰٬۰۰۰ ت/ماه */
    private function seedCosts(): void
    {
        config([
            'provisioning.hetzner_storage.plans' => ['sn_backup_3' => 'bx11'],
            'provisioning.hetzner_storage.min_margin_pct' => 5,
            'billing.cycles.yearly.discount_pct' => 20,
        ]);

        Setting::put('pricing_fx_fee_pct_hetzner', '10');
        Setting::put('pricing_rate_override', '250000');

        HetznerStorageCosts::remember(['bx11' => 320], 'fsn1');
    }

    private function product(int $monthlyPrice): Product
    {
        return Product::create([
            'name' => 'هاست بکاپ — BK-1T', 'slug' => 'backup-3', 'category' => 'shared',
            'group' => 'backup', 'plan' => 'sn_backup_3', 'currency_code' => 'IRT',
            'price' => $monthlyPrice, 'cycle' => 'monthly', 'tax_percent' => 10,
            'is_active' => true,
        ]);
    }

    /**
     * دقیقاً همان حالتی که ضرر می‌داد: قیمتِ ماهانهٔ سودده، ولی تخفیفِ سالانه
     * که آن را زیرِ بهای تمام‌شده می‌بُرد.
     */
    public function test_the_yearly_cycle_can_never_fall_below_cost(): void
    {
        $this->seedCosts();
        $p = $this->product(960_000);   // ماهانه سودده بود

        $costYear = 880_000 * 12;           // ۱۰٬۵۶۰٬۰۰۰
        $yearly = $p->priceForCycle('yearly');

        $this->assertGreaterThan($costYear, $yearly,
            'دورهٔ سالانه زیرِ بهای تمام‌شده فروخته می‌شود — همان ضرری که خاموش بود.');

        $this->assertGreaterThanOrEqual((int) ceil($costYear * 1.05), $yearly,
            'حداقلِ حاشیهٔ ۵٪ روی دورهٔ سالانه رعایت نشده.');
    }

    public function test_every_cycle_clears_cost_plus_the_minimum_margin(): void
    {
        $this->seedCosts();
        $p = $this->product(300_000);   // عمداً خیلی کم — کف باید نجاتش دهد

        foreach (['monthly' => 1, 'quarterly' => 3, 'semiannual' => 6, 'yearly' => 12] as $cycle => $months) {
            $price = $p->priceForCycle($cycle);
            $floor = (int) ceil(880_000 * $months * 1.05);

            $this->assertGreaterThanOrEqual($floor, $price,
                "دورهٔ «{$cycle}» زیرِ کفِ حاشیه است: {$price} < {$floor}");
        }
    }

    /**
     * ⚠️ کف نباید قیمتِ سالمِ بالاتر را پایین بیاورد — `max` است نه جایگزینی.
     * وگرنه سودِ بیشتر بی‌صدا به ۵٪ برش می‌خورد.
     */
    public function test_the_floor_never_lowers_a_healthy_price(): void
    {
        $this->seedCosts();
        $p = $this->product(5_000_000);

        $this->assertGreaterThan((int) ceil(880_000 * 1.05), $p->priceForCycle('monthly'));
        $this->assertGreaterThan(880_000 * 12 * 2, $p->priceForCycle('yearly'),
            'قیمتِ بالا باید دست‌نخورده بماند.');
    }

    /**
     * 🔴 «نمی‌دانیم» هرگز «رایگان» نیست — ولی بستنِ فروش هم جواب نیست.
     * پلنی که به هتزنر نگاشت ندارد اصلاً به این کف ربطی ندارد.
     */
    public function test_a_plan_without_a_hetzner_mapping_is_untouched(): void
    {
        $this->seedCosts();

        $p = Product::create([
            'name' => 'هاست لینوکس — LX-2', 'slug' => 'linux-1', 'category' => 'shared',
            'group' => 'linux', 'plan' => 'sn_linux_1', 'currency_code' => 'IRT',
            'price' => 170_000, 'cycle' => 'monthly', 'tax_percent' => 10, 'is_active' => true,
        ]);

        $this->assertSame(0, app(HetznerStorageCosts::class)->floorToman('sn_linux_1', 12));
        $this->assertLessThan(880_000, $p->priceForCycle('monthly'),
            'کفِ فضای بکاپ نباید روی محصولی که از هتزنر نیست اثر بگذارد.');
    }

    /** کشِ خالی ⇒ کفی نیست، ولی فروش هم بسته نمی‌شود */
    public function test_an_empty_cost_cache_does_not_block_sales(): void
    {
        config(['provisioning.hetzner_storage.plans' => ['sn_backup_3' => 'bx11']]);
        Setting::put(HetznerStorageCosts::SETTING, null);

        $this->assertSame(0, app(HetznerStorageCosts::class)->floorToman('sn_backup_3', 12));
    }
}

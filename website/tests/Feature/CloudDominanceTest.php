<?php

namespace Tests\Feature;

use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Services\Cloud\CloudDominance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * پلنی که هیچ‌کس نباید بخرد، نباید نمایش داده شود.
 *
 * 🔴 نمونهٔ واقعی از صفحهٔ آلمان که این قاعده را لازم کرد:
 *     ۲ هسته · ۲ گیگ  →  ۱٬۳۷۰٬۰۰۰
 *     ۱ هسته · ۲ گیگ  →  ۲٬۷۴۰٬۰۰۰   ← نصفِ پردازنده، دو برابرِ قیمت
 *
 * ردیفِ دوم فروش نمی‌رفت ولی به کلِ کاتالوگ آسیب می‌زد: هر بازدیدکننده‌ای که
 * می‌دیدش نتیجه می‌گرفت قیمت‌های ما حساب‌وکتاب ندارد.
 */
class CloudDominanceTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function plan(array $over = []): CloudPlan
    {
        $this->n++;

        return CloudPlan::create(array_merge([
            'provider' => 'p', 'provider_ref' => 'r'.$this->n,
            'location_code' => 'de-falkenstein', 'slug' => 'cv-'.$this->n,
            'public_name' => 'CV-'.$this->n,
            'vcpu' => 2, 'ram_mb' => 2048, 'disk_gb' => 40, 'disk_type' => 'nvme',
            'traffic_gb' => 20000, 'cpu_kind' => 'shared', 'arch' => 'x86',
            'cost_eur_cents' => 400, 'price_eur_cents' => 600, 'price_irt' => 1000000,
            'is_active' => true, 'in_stock' => true, 'admin_disabled' => false,
        ], $over));
    }

    /** @param array<int,CloudPlan> $plans */
    private function names(array $plans): array
    {
        return CloudDominance::prune(collect($plans))->pluck('public_name')->all();
    }

    // ═══════════ قاعدهٔ اصلی ═══════════

    /** 🔴 دقیقاً همان موردی که کارفرما دید */
    public function test_a_weaker_and_pricier_plan_is_removed(): void
    {
        $good = $this->plan(['public_name' => 'GOOD', 'vcpu' => 2, 'ram_mb' => 2048, 'price_irt' => 1370000]);
        $bad  = $this->plan(['public_name' => 'BAD',  'vcpu' => 1, 'ram_mb' => 2048, 'price_irt' => 2740000]);

        $this->assertSame(['GOOD'], $this->names([$good, $bad]));
    }

    /** مشخصاتِ یکسان با قیمتِ بالاتر: فقط ارزان‌تر می‌مانَد */
    public function test_the_same_spec_at_a_higher_price_is_removed(): void
    {
        $cheap = $this->plan(['public_name' => 'CHEAP', 'price_irt' => 1000000]);
        $dear  = $this->plan(['public_name' => 'DEAR',  'price_irt' => 1500000]);

        $this->assertSame(['CHEAP'], $this->names([$cheap, $dear]));
    }

    /**
     * ⚠️ مشخصات و قیمتِ **کاملاً** یکسان: هیچ‌کدام حذف نمی‌شود.
     *
     * وگرنه دو پلنِ یکسان در دو شهر همدیگر را حذف می‌کنند و **هر دو** غیب
     * می‌شوند — بدترین حالتِ ممکن.
     */
    public function test_two_truly_identical_plans_both_survive(): void
    {
        $a = $this->plan(['public_name' => 'A', 'location_code' => 'de-falkenstein']);
        $b = $this->plan(['public_name' => 'B', 'location_code' => 'de-nuremberg']);

        $this->assertCount(2, $this->names([$a, $b]));
    }

    /** پلنِ گران‌ترِ قوی‌تر باید بماند — مشتری ممکن است منابعِ بیشتر بخواهد */
    public function test_a_stronger_but_pricier_plan_survives(): void
    {
        $small = $this->plan(['public_name' => 'SMALL', 'vcpu' => 2, 'price_irt' => 1000000]);
        $big   = $this->plan(['public_name' => 'BIG',   'vcpu' => 8, 'price_irt' => 3000000]);

        $this->assertSame(['SMALL', 'BIG'], $this->names([$small, $big]));
    }

    // ═══════════ آنچه عمداً مقایسه نمی‌شود ═══════════

    /**
     * 🔴 معماریِ پردازنده: نرم‌افزارِ مشتری ممکن است روی ARM اجرا نشود، پس
     * پلنِ ARMِ ارزان‌ترِ قوی‌تر **جایگزینِ** x86 نیست.
     */
    public function test_arm_never_removes_x86(): void
    {
        $arm = $this->plan(['public_name' => 'ARM', 'arch' => 'arm', 'vcpu' => 4, 'price_irt' => 800000]);
        $x86 = $this->plan(['public_name' => 'X86', 'arch' => 'x86', 'vcpu' => 2, 'price_irt' => 1200000]);

        $this->assertCount(2, $this->names([$arm, $x86]));
    }

    /** جدولِ اشتراکی نباید با پلنِ اختصاصی خالی شود */
    public function test_a_dedicated_plan_never_removes_a_shared_one(): void
    {
        $ded = $this->plan(['public_name' => 'DED', 'cpu_kind' => 'dedicated', 'vcpu' => 4, 'price_irt' => 900000]);
        $sh  = $this->plan(['public_name' => 'SH',  'cpu_kind' => 'shared',    'vcpu' => 2, 'price_irt' => 1200000]);

        $this->assertCount(2, $this->names([$ded, $sh]));
    }

    /** دیسکِ بهتر یک بُعدِ کیفی است: NVMe را SSDِ ارزان‌تر حذف نمی‌کند */
    public function test_a_cheaper_ssd_does_not_remove_an_nvme_of_the_same_size(): void
    {
        $ssd  = $this->plan(['public_name' => 'SSD',  'disk_type' => 'ssd',  'price_irt' => 800000]);
        $nvme = $this->plan(['public_name' => 'NVME', 'disk_type' => 'nvme', 'price_irt' => 900000]);

        $this->assertCount(2, $this->names([$ssd, $nvme]));
    }

    /** ترافیکِ بیشتر هم یک بُعد است */
    public function test_more_traffic_counts_as_better(): void
    {
        $small = $this->plan(['public_name' => 'T1', 'traffic_gb' => 1024,  'price_irt' => 1000000]);
        $big   = $this->plan(['public_name' => 'T20', 'traffic_gb' => 20000, 'price_irt' => 900000]);

        $this->assertSame(['T20'], $this->names([$small, $big]));
    }

    /** قیمتِ نامعلوم (صفر) نه غالب می‌شود نه مغلوب */
    public function test_a_priceless_plan_is_left_alone(): void
    {
        $ok   = $this->plan(['public_name' => 'OK', 'price_irt' => 1000000]);
        $zero = $this->plan(['public_name' => 'ZERO', 'vcpu' => 1, 'price_irt' => 0]);

        $this->assertCount(2, $this->names([$ok, $zero]));
    }

    public function test_a_single_plan_is_returned_untouched(): void
    {
        $this->assertSame(['ONE'], $this->names([$this->plan(['public_name' => 'ONE'])]));
    }

    // ═══════════ روی صفحهٔ واقعی ═══════════

    public function test_the_country_page_hides_the_dominated_plan(): void
    {
        CloudLocation::create(['code' => 'de-falkenstein', 'country' => 'DE', 'city' => 'Falkenstein', 'is_active' => true, 'sort' => 0]);

        $this->plan(['public_name' => 'GOOD', 'slug' => 'g', 'vcpu' => 2, 'ram_mb' => 2048, 'price_irt' => 1370000]);
        $this->plan(['public_name' => 'BAD',  'slug' => 'b', 'vcpu' => 1, 'ram_mb' => 2048, 'price_irt' => 2740000]);

        $html = $this->get('/vps/germany')->assertOk()->getContent();

        $this->assertStringContainsString('GOOD', $html);
        $this->assertStringNotContainsString('BAD', $html,
            'پلنی که نصفِ پردازنده و دو برابرِ قیمت دارد نباید روی صفحه باشد');
    }
}

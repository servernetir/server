<?php

namespace Tests\Feature;

use App\Models\CloudLocation;
use App\Models\CloudPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * صفحهٔ کشور باید **همهٔ** پلن‌های همهٔ شهرهای آن کشور را نشان دهد.
 *
 * 🔴 دو خرابی که این تست‌ها می‌بندند، هر دو بی‌خطا و با کدِ ۲۰۰ کار می‌کردند:
 *
 *  ۱) ادغامِ بین‌شهری: پلن‌های پاریس و لیون با مشخصاتِ یکسان روی هم `unique()`
 *     می‌شدند و یکی حذف. ولی مشتری دقیقاً بین شهرها انتخاب می‌کند — تأخیرِ
 *     شبکه فرق دارد. نتیجه‌اش پنهان‌ماندنِ موجودیِ واقعی بود.
 *
 *  ۲) فیلترِ نوعِ پردازنده: `/vps/*` فقط اشتراکی می‌گرفت، پس صفحهٔ کشور هرگز
 *     پلنِ پردازندهٔ اختصاصی را نشان نمی‌داد.
 */
class CountryPlanTableTest extends TestCase
{
    use RefreshDatabase;

    private function loc(string $code, string $city, string $country = 'FR'): CloudLocation
    {
        return CloudLocation::create([
            'code' => $code, 'country' => $country, 'city' => $city,
            'is_active' => true, 'sort' => 0,
        ]);
    }

    private function plan(string $loc, int $vcpu, int $ram, int $price, string $cpuKind = 'shared', array $over = []): CloudPlan
    {
        return CloudPlan::create(array_merge([
            'provider' => 'p1', 'provider_ref' => $loc.'-'.$vcpu.'-'.$ram.'-'.$cpuKind,
            'location_code' => $loc,
            'slug' => 'cv-'.$vcpu.'c-'.$ram.'g-40d-'.$loc,
            'public_name' => 'CV-'.$vcpu.'-'.$ram,
            'vcpu' => $vcpu, 'ram_mb' => $ram * 1024, 'disk_gb' => 40, 'disk_type' => 'nvme',
            'traffic_gb' => 20000, 'cpu_kind' => $cpuKind, 'arch' => 'x86',
            'cost_eur_cents' => $price, 'price_eur_cents' => $price * 2,
            'price_irt' => $price * 10000,
            'is_active' => true, 'in_stock' => true, 'admin_disabled' => false,
        ], $over));
    }

    private function html(): string
    {
        return $this->get('/vps/france')->assertOk()->getContent();
    }

    /** 🔴 ادعای اصلی: مشخصاتِ یکسان در دو شهر = دو ردیف، نه یکی */
    public function test_the_same_spec_in_two_cities_stays_two_rows(): void
    {
        $this->loc('fr-paris', 'پاریس');
        $this->loc('fr-lyon', 'لیون');
        $this->plan('fr-paris', 2, 4, 500);
        $this->plan('fr-lyon', 2, 4, 600);

        $html = $this->html();

        $this->assertStringContainsString('پاریس', $html);
        $this->assertStringContainsString('لیون', $html,
            'شهر دوم حذف شده — یعنی همان ادغامِ بین‌شهری برگشته است');
        $this->assertSame(2, substr_count($html, 'data-city='));
    }

    /** 🔴 هر دو نوعِ پردازنده در یک صفحه، در دو جدولِ جدا */
    public function test_both_cpu_kinds_appear_in_separate_tables(): void
    {
        $this->loc('fr-paris', 'پاریس');
        $this->plan('fr-paris', 2, 4, 500, 'shared');
        $this->plan('fr-paris', 4, 8, 900, 'dedicated');

        $html = $this->html();

        $this->assertStringContainsString('data-group="std"', $html);
        $this->assertStringContainsString('data-group="ded"', $html);
        $this->assertStringContainsString(__('ui.pt_g_std'), $html);
        $this->assertStringContainsString(__('ui.pt_g_ded'), $html);
    }

    /**
     * ⚠️ ادغامِ **درون‌شهری** باید بماند: دو زیرساخت با مشخصاتِ یکسان در یک
     * شهر = یک ردیف، ارزان‌ترین. این همان قاعده‌ای است که کارفرما خواست و
     * سفیدبرچسبی را هم حفظ می‌کند.
     */
    public function test_two_providers_with_the_same_spec_in_one_city_collapse_to_the_cheaper(): void
    {
        $this->loc('fr-paris', 'پاریس');
        $this->plan('fr-paris', 4, 4, 900, 'shared', ['provider' => 'expensive', 'provider_ref' => 'x1']);
        $this->plan('fr-paris', 4, 4, 400, 'shared', ['provider' => 'cheap', 'provider_ref' => 'x2']);

        $html = $this->html();

        $this->assertSame(1, substr_count($html, 'data-city='), 'گران‌تر نباید نشان داده شود');
        $this->assertStringContainsString('data-price="4000000"', $html);
        $this->assertStringNotContainsString('data-price="9000000"', $html);
    }

    /** پیش‌فرضِ چیدمان: ارزان به گران */
    public function test_rows_are_ordered_cheapest_first(): void
    {
        $this->loc('fr-paris', 'پاریس');
        $this->plan('fr-paris', 8, 16, 900);
        $this->plan('fr-paris', 1, 2, 200);
        $this->plan('fr-paris', 4, 8, 500);

        $html = $this->html();

        preg_match_all('~data-price="(\d+)"~', $html, $m);
        $prices = array_map('intval', $m[1]);

        $sorted = $prices;
        sort($sorted);
        $this->assertSame($sorted, $prices, 'جدول باید از ارزان به گران باشد');
    }

    /** ابزارِ فیلتر باید فقط شهرهایی را بدهد که واقعاً ردیف دارند */
    public function test_the_city_filter_only_lists_cities_that_have_rows(): void
    {
        $this->loc('fr-paris', 'پاریس');
        $this->loc('fr-lyon', 'لیون');
        $this->plan('fr-paris', 2, 4, 500);
        // لیون عمداً پلنی ندارد

        $html = $this->html();

        $this->assertStringContainsString('<option value="پاریس">', $html);
        $this->assertStringNotContainsString('<option value="لیون">', $html,
            'فیلترِ بی‌نتیجه بدترین تجربه است');
    }

    /** هر ردیف باید مستقیم به تسویهٔ همان پلن در همان مکان برود */
    public function test_every_row_links_to_checkout_for_its_own_plan_and_city(): void
    {
        $this->loc('fr-paris', 'پاریس');
        $this->loc('fr-lyon', 'لیون');
        $this->plan('fr-paris', 2, 4, 500);
        $this->plan('fr-lyon', 2, 4, 600);

        $html = $this->html();

        $this->assertStringContainsString('location=fr-paris', $html);
        $this->assertStringContainsString('location=fr-lyon', $html);
    }

    /** نامِ زیرساخت هرگز نباید به HTML برسد */
    public function test_the_provider_name_never_reaches_the_page(): void
    {
        $this->loc('fr-paris', 'پاریس');
        $this->plan('fr-paris', 2, 4, 500, 'shared', ['provider' => 'hetzner']);

        $this->assertStringNotContainsString('hetzner', strtolower($this->html()));
    }

    /** کشورِ بی‌پلن نباید ۵۰۰ بدهد — به متنِ config برمی‌گردد */
    public function test_a_country_with_no_live_plan_still_renders(): void
    {
        $this->get('/vps/france')->assertOk();
    }
}

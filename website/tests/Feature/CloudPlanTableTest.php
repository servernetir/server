<?php

namespace Tests\Feature;

use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * صفحاتِ کشورِ سرورِ مجازی باید **همهٔ** پلن‌های فروختنی را نشان دهند.
 *
 * 🔴 چرا این تست‌ها لازم شدند: صفحهٔ `/vps/germany` سقفِ شش‌تایی داشت و هر
 * Nاُمین پلن را برمی‌داشت. یعنی ده‌ها پلنِ آماده‌به‌فروش هیچ‌جای سایت دیده
 * نمی‌شدند — نه خطایی، نه لاگی، فقط درآمدی که نمی‌آمد. صفحه ۲۰۰ می‌داد و
 * سالم به‌نظر می‌رسید، پس تنها راهِ گرفتنش شمردنِ ردیف‌هاست.
 */
class CloudPlanTableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    private function location(string $code = 'de-falkenstein', string $country = 'DE'): CloudLocation
    {
        return CloudLocation::firstOrCreate(['code' => $code], [
            'country' => $country, 'city' => ucfirst(explode('-', $code)[1] ?? 'x'),
            'is_active' => true,
        ]);
    }

    private function plan(array $over = []): CloudPlan
    {
        static $n = 0;
        $n++;

        return CloudPlan::create(array_merge([
            'provider' => 'hetzner', 'provider_ref' => 'ref'.$n,
            'provider_location' => 'fsn', 'location_code' => 'de-falkenstein',
            'public_name' => 'CV-'.$n, 'slug' => 'cv-'.$n.'c-4g-40d-de-falkenstein',
            'vcpu' => $n, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme',
            'traffic_gb' => 20480, 'cpu_kind' => 'shared', 'arch' => 'x86',
            'cost_eur_cents' => 400 + $n, 'price_eur_cents' => 600 + $n,
            'price_irt' => 600000 + $n, 'is_active' => true, 'in_stock' => true,
        ], $over));
    }

    private function germany(): string
    {
        return $this->get('/vps/germany')->assertOk()->getContent();
    }

    // ═══════════════ صفحهٔ عمومی ═══════════════

    /** 🔴 قلبِ ماجرا: بیست پلن یعنی بیست ردیف، نه شش */
    public function test_every_sellable_plan_appears(): void
    {
        $this->location();

        for ($i = 0; $i < 20; $i++) {
            $this->plan();
        }

        $html = $this->germany();

        for ($i = 1; $i <= 20; $i++) {
            $this->assertStringContainsString('CV-'.$i.'<', $html,
                "پلنِ CV-$i از صفحه غایب است — سقفِ نمایش دوباره برگشته؟");
        }
    }

    public function test_many_plans_render_as_a_table(): void
    {
        $this->location();
        foreach (range(1, 8) as $i) {
            $this->plan();
        }

        $html = $this->germany();

        $this->assertStringContainsString('plan-table', $html);
        // کلیدِ زبان باید ترجمه شود، نه خام چاپ شود
        $this->assertStringNotContainsString('ui.pt_row', $html);
        $this->assertStringNotContainsString('ui.pt_price', $html);
    }

    /** هر ردیف باید مستقیم به تسویهٔ همان پلن برود */
    public function test_each_row_links_straight_to_checkout_with_its_plan(): void
    {
        $this->location();
        $p = $this->plan();

        $html = $this->germany();

        $this->assertStringContainsString('plan='.urlencode($p->slug), $html);
        $this->assertStringContainsString('location='.urlencode('de-falkenstein'), $html);
    }

    /**
     * 🔴 پلن‌های واقعاً متفاوت نباید در هم ادغام شوند.
     *
     * `CloudNaming::planSlug` فقط هسته/رم/دیسک/مکان را می‌گیرد، پس ARM و x86 با
     * مشخصاتِ یکسان یک اسلاگ دارند (تلهٔ مستندشده در CLAUDE.md). اگر یکتاسازیِ
     * صفحه هم به همان اندازه درشت باشد، یکی از این دو محصول از سایت غیب می‌شود.
     */
    public function test_plans_differing_only_in_architecture_are_both_shown(): void
    {
        $this->location();
        $this->plan(['public_name' => 'INTEL-X', 'vcpu' => 2, 'arch' => 'x86', 'price_irt' => 900000]);
        $this->plan(['public_name' => 'ARM-Y', 'vcpu' => 2, 'arch' => 'arm', 'price_irt' => 700000]);

        $html = $this->germany();

        $this->assertStringContainsString('INTEL-X', $html);
        $this->assertStringContainsString('ARM-Y', $html);
    }

    public function test_plans_differing_only_in_traffic_are_both_shown(): void
    {
        $this->location();
        $this->plan(['public_name' => 'BIG-TRAFFIC', 'vcpu' => 3, 'traffic_gb' => 20480]);
        $this->plan(['public_name' => 'SMALL-TRAFFIC', 'vcpu' => 3, 'traffic_gb' => 1024]);

        $html = $this->germany();

        $this->assertStringContainsString('BIG-TRAFFIC', $html);
        $this->assertStringContainsString('SMALL-TRAFFIC', $html);
    }

    /**
     * ⚠️ سفیدبرچسبی: دو زیرساخت با پلنِ **دقیقاً یکسان** = یک ردیف، ارزان‌ترین.
     *
     * 🔴 اسلاگ باید صریح و **یکسان** داده شود. فیکسچر به‌طور پیش‌فرض برای هر
     * پلن اسلاگِ شماره‌دارِ متفاوتی می‌سازد، پس دو پلنی که این تست «یکسان»
     * می‌نامد در واقع دو اسلاگ داشتند و `offers()` — که کلیدش همین اسلاگ است —
     * هرگز ادغامشان نمی‌کرد. تست سبز بود ولی به‌خاطر یک ادغامِ دیگر در لایهٔ
     * بالاتر، نه به‌خاطر چیزی که ادعا می‌کرد. با برداشته‌شدنِ آن لایه، دروغش
     * آشکار شد. (همان تلهٔ فیکسچرِ معماریِ هتزنر در CLAUDE.md: فیکسچری که
     * تصادمِ واقعی نمی‌سازد، باگ را نمی‌بیند.)
     */
    public function test_identical_plans_from_two_providers_collapse_to_one_row(): void
    {
        $this->location();
        $same = 'cv-4c-4g-40d-de-falkenstein';
        $this->plan(['public_name' => 'CHEAP', 'provider' => 'hetzner', 'vcpu' => 4, 'slug' => $same, 'price_irt' => 500000, 'cost_eur_cents' => 400]);
        $this->plan(['public_name' => 'PRICEY', 'provider' => 'aeza', 'vcpu' => 4, 'slug' => $same, 'price_irt' => 900000, 'cost_eur_cents' => 800]);

        $html = $this->germany();

        $this->assertStringContainsString('CHEAP', $html, 'ارزان‌ترین باید نماینده شود');
        $this->assertStringNotContainsString('PRICEY', $html, 'ردیفِ تکراریِ زیرساختِ دوم نباید دیده شود');
        // و هیچ‌جا نامِ زیرساخت لو نرود
        $this->assertStringNotContainsStringIgnoringCase('hetzner', $html);
        $this->assertStringNotContainsStringIgnoringCase('aeza', $html);
    }

    /** پلنِ فروخته‌نشدنی نباید در جدول بیاید */
    public function test_unsellable_plans_stay_out(): void
    {
        $this->location();
        $this->plan(['public_name' => 'LIVE-ONE', 'vcpu' => 2]);
        $this->plan(['public_name' => 'NOSTOCK', 'vcpu' => 5, 'in_stock' => false]);
        $this->plan(['public_name' => 'NOPRICE', 'vcpu' => 6, 'price_irt' => 0]);
        $this->plan(['public_name' => 'BLOCKED', 'vcpu' => 7, 'admin_disabled' => true]);

        $html = $this->germany();

        $this->assertStringContainsString('LIVE-ONE', $html);
        foreach (['NOSTOCK', 'NOPRICE', 'BLOCKED'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $html, "$hidden قابلِ فروش نیست و نباید دیده شود");
        }
    }

    // ═══════════════ فیلترِ وضعیتِ پنلِ مدیریت ═══════════════

    private function admin(): User
    {
        return User::create(['name' => 'مدیر', 'email' => 'a'.random_int(1, 99999).'@x.com',
            'password' => bcrypt('x'), 'role' => 'admin']);
    }

    /**
     * اسلاگ‌هایی که در **جدولِ فیلترشده** آمده‌اند.
     *
     * ⚠️ نامِ پلن را نمی‌شود سنجید: همان صفحه یک پانلِ «عرضه‌ها» هم دارد که
     * مستقلِ از فیلتر، نامِ هر پلنِ فروختنی را چاپ می‌کند. پس یک
     * `assertStringNotContainsString` روی نام، همیشه شکست می‌خورد و چیزی را
     * که فکر می‌کنیم نمی‌سنجد. `data-plan` فقط روی ردیف‌های همان جدول است.
     *
     * @return array<int,string>
     */
    private function filteredSlugs(string $query = ''): array
    {
        $html = $this->actingAs($this->admin())->get('/admin/cloud'.$query)->assertOk()->getContent();

        preg_match_all('~data-plan="([^"]+)"~', $html, $m);

        return $m[1];
    }

    /**
     * 🔴 «در حالِ فروش» باید یعنی واقعاً قابلِ خرید.
     *
     * قبلاً فقط `admin_disabled = false` را می‌سنجید، پس پلنِ ناموجود و بی‌قیمت
     * و غیرفعال هم «در حالِ فروش» شمرده می‌شد — فیلتری که جواب می‌داد ولی
     * جوابش با آنچه مشتری می‌بیند نمی‌خواند.
     */
    public function test_admin_on_sale_filter_means_actually_sellable(): void
    {
        $this->location();
        $on = $this->plan(['public_name' => 'REALLY-ON', 'vcpu' => 2]);
        $a = $this->plan(['public_name' => 'NOSTOCK', 'vcpu' => 3, 'in_stock' => false]);
        $b = $this->plan(['public_name' => 'NOPRICE', 'vcpu' => 4, 'price_irt' => 0]);
        $c = $this->plan(['public_name' => 'INACTIVE', 'vcpu' => 5, 'is_active' => false]);

        $slugs = $this->filteredSlugs('?state=on');

        $this->assertContains($on->slug, $slugs);
        foreach ([$a, $b, $c] as $bad) {
            $this->assertNotContains($bad->slug, $slugs,
                $bad->public_name.' قابلِ فروش نیست و نباید «در حالِ فروش» باشد');
        }
    }

    /** «فروخته نمی‌شود» باید همه‌ی علت‌ها را بگیرد */
    public function test_admin_unsellable_filter_catches_every_reason(): void
    {
        $this->location();
        $on = $this->plan(['public_name' => 'REALLY-ON', 'vcpu' => 2]);
        $bad = [
            $this->plan(['public_name' => 'NOSTOCK', 'vcpu' => 3, 'in_stock' => false]),
            $this->plan(['public_name' => 'NOPRICE', 'vcpu' => 4, 'price_irt' => 0]),
            $this->plan(['public_name' => 'INACTIVE', 'vcpu' => 5, 'is_active' => false]),
            $this->plan(['public_name' => 'BLOCKED', 'vcpu' => 6, 'admin_disabled' => true]),
        ];

        $slugs = $this->filteredSlugs('?state=unsellable');

        foreach ($bad as $p) {
            $this->assertContains($p->slug, $slugs, $p->public_name.' باید در «فروخته نمی‌شود» بیاید');
        }
        $this->assertNotContains($on->slug, $slugs);
    }

    public function test_admin_inactive_filter_works(): void
    {
        $this->location();
        $live = $this->plan(['public_name' => 'LIVE-ONE', 'vcpu' => 2]);
        $dead = $this->plan(['public_name' => 'INACTIVE', 'vcpu' => 3, 'is_active' => false]);

        $slugs = $this->filteredSlugs('?state=inactive');

        $this->assertContains($dead->slug, $slugs);
        $this->assertNotContains($live->slug, $slugs);
    }

    /** فیلترِ کشور نباید با فیلترِ وضعیت تداخل کند */
    public function test_admin_country_and_state_filters_combine(): void
    {
        $this->location();
        $this->location('gb-london', 'GB');
        $de = $this->plan(['public_name' => 'DE-ON', 'vcpu' => 2]);
        $gb = $this->plan(['public_name' => 'GB-ON', 'vcpu' => 3, 'location_code' => 'gb-london']);

        $slugs = $this->filteredSlugs('?country=DE&state=on');

        $this->assertContains($de->slug, $slugs);
        $this->assertNotContains($gb->slug, $slugs);
    }

    /** شمارشِ واقعی باید نشان داده شود، وگرنه فهرستِ بریده «همه» خوانده می‌شود */
    public function test_admin_shows_the_real_match_count(): void
    {
        $this->location();
        foreach (range(1, 7) as $i) {
            $this->plan();
        }

        $html = $this->actingAs($this->admin())->get('/admin/cloud')->assertOk()->getContent();

        $this->assertStringContainsString(fa_num(7).' پلن با این فیلتر', $html);
    }
}

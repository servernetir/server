<?php

namespace Tests\Feature;

use App\Http\Controllers\Account\CloudStoreController;
use App\Models\CloudImage;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * جداییِ دو خطِ محصول در ویترینِ ابری — GPU و VPS.
 *
 * ═══ گزارشِ کارفرما (شهریور ۱۴۰۵) ═══
 *
 * «سفارش می‌خواستم بزارم دیدم رفت تو صفحهٔ سرور مجازی و قاطی شد با اونا.»
 *
 * زنجیرهٔ واقعی: CTAِ صفحهٔ /gpu فقط ?plan= می‌داد؛ فروشگاه بی‌مکان، یک شهرِ
 * VPS را پیش‌فرض می‌کرد؛ اسلاگِ GPU در آن مکان نبود؛ و «planMoved» بی‌صدا
 * اولین پلنِ VPS را جایش می‌گذاشت. مشتریِ کارتِ گرافیک، فرمِ سرورِ مجازی
 * می‌دید — با کدِ ۲۰۰ و بی‌هیچ خطایی.
 *
 * قاعده: کشورِ ساختگیِ XX (شبکهٔ توزیع‌شده) نشانهٔ خطِ محصولِ جداست.
 * حالتِ GPU فقط گروهِ XX را نشان می‌دهد و حالتِ VPS هرگز آن را.
 */
class GpuStoreSeparationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();

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

    protected function customer(): Customer
    {
        return Customer::create([
            'email' => 'gpu'.random_int(1, 999999).'@example.com',
            'phone' => '0914'.random_int(1000000, 9999999),
            'password' => 'secret1234', 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    /** یک شهرِ VPS و شبکهٔ GPU، هر دو با پلنِ فروختنی */
    protected function bothLines(): void
    {
        CloudLocation::create(['code' => 'de-frankfurt', 'country' => 'DE', 'city' => 'Frankfurt', 'is_active' => true]);
        CloudLocation::create(['code' => 'global-gpu', 'country' => 'XX', 'city' => null, 'is_active' => true]);

        CloudImage::create([
            'provider' => 'hetzner', 'provider_ref' => 'ubuntu-24.04', 'key' => 'ubuntu-24.04',
            'kind' => 'os', 'family' => 'ubuntu', 'version' => '24.04', 'label' => 'Ubuntu 24.04',
            'arch' => 'x86', 'min_disk_gb' => 5, 'is_active' => true,
        ]);
        CloudImage::create([
            'provider' => 'salad', 'provider_ref' => 'saladtechnologies/ollama-llama3.1-recipe:1.0.0',
            'key' => 'gpu-ollama', 'kind' => 'app', 'family' => 'gpu-ollama',
            'label' => 'Ollama - Llama 3.1', 'arch' => 'x86', 'min_disk_gb' => 0, 'is_active' => true,
        ]);

        CloudPlan::create([
            'provider' => 'hetzner', 'provider_ref' => 'cx22', 'provider_location' => 'fsn1',
            'location_code' => 'de-frankfurt', 'public_name' => 'CV-2-4',
            'slug' => 'cv-2c-4g-40d-de-frankfurt', 'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40,
            'disk_type' => 'nvme', 'traffic_gb' => 20480, 'cpu_kind' => 'shared', 'arch' => 'x86',
            'cost_eur_cents' => 379, 'price_eur_cents' => 570, 'price_irt' => 570_000,
            'is_active' => true, 'in_stock' => true,
        ]);
        CloudPlan::create([
            'provider' => 'salad', 'provider_ref' => 'gc-4090', 'provider_location' => 'global',
            'location_code' => 'global-gpu', 'public_name' => 'RTX 4090',
            'slug' => 'cv-8c-30g-100d-global-gpu-rtx-4090', 'vcpu' => 8, 'ram_mb' => 30720,
            'disk_gb' => 100, 'disk_type' => 'ssd', 'traffic_gb' => 0, 'cpu_kind' => 'shared',
            'arch' => 'x86', 'cost_eur_cents' => 400, 'price_eur_cents' => 600, 'price_irt' => 720_000,
            'is_active' => true, 'in_stock' => true,
            'gpu_model' => 'RTX 4090', 'gpu_count' => 1, 'is_interruptible' => true,
        ]);
    }

    /**
     * 🔴 بازتولیدِ عینِ گزارش: لینکِ GPU با مکان، باید پلنِ GPU را نگه دارد
     * و فرمِ VPS (شهرها) را نیاورد.
     */
    public function test_the_gpu_link_lands_on_the_gpu_line_not_the_vps_form(): void
    {
        $this->bothLines();

        $html = (string) $this->actingAs($this->customer(), 'customer')
            ->get('/account/cloud-store?billing_mode=hourly&location=global-gpu&plan=cv-8c-30g-100d-global-gpu-rtx-4090')
            ->assertOk()->getContent();

        $this->assertStringContainsString('RTX 4090', $html);
        // پلن بی‌صدا عوض نشده
        $this->assertStringNotContainsString(__('ui.cvb_plan_moved'), $html);
        // خطِ VPS در پیکربندِ GPU نیست
        $this->assertStringNotContainsString('de-frankfurt', $html);
        $this->assertStringNotContainsString('CV-2-4', $html);
    }

    /** ویترینِ عادی (VPS) هرگز گروهِ GPU را پیشنهاد نمی‌دهد */
    public function test_the_vps_store_never_offers_the_gpu_pseudo_location(): void
    {
        $this->bothLines();

        $html = (string) $this->actingAs($this->customer(), 'customer')
            ->get('/account/cloud-store')
            ->assertOk()->getContent();

        $this->assertStringContainsString('de-frankfurt', $html);
        $this->assertStringNotContainsString('global-gpu', $html,
            'مکانِ GPU در پیکربندِ VPS پیشنهاد شد - دو خطِ محصول دوباره قاطی‌اند.');
    }

    /**
     * پلنِ بی‌سیستم‌عامل باید روی زبانهٔ «برنامه» باز شود؛ وگرنه مشتری زبانهٔ
     * خالیِ «سیستم‌عامل» می‌بیند و گزینهٔ واقعی پشتِ کلیکِ دوم پنهان است.
     */
    public function test_an_os_less_plan_opens_on_the_app_tab(): void
    {
        $this->bothLines();

        $html = (string) $this->actingAs($this->customer(), 'customer')
            ->get('/account/cloud-store?location=global-gpu&plan=cv-8c-30g-100d-global-gpu-rtx-4090')
            ->assertOk()->getContent();

        preg_match('~<button[^>]*id="cvb-tab-app"[^>]*>~', $html, $m);
        $this->assertNotEmpty($m, 'زبانهٔ برنامه اصلاً رندر نشد.');
        $this->assertStringContainsString('aria-selected="true"', $m[0],
            'زبانهٔ برنامه فعال نیست؛ مشتری زبانهٔ خالیِ سیستم‌عامل می‌بیند.');

        // و گزینهٔ برنامه واقعاً انتخاب‌شده است
        $this->assertMatchesRegularExpression(
            '~name="image" value="gpu-ollama"[^>]*checked~', $html);
    }
}

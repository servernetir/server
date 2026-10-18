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

    public function test_gpu_cycle_prices_do_not_advertise_vps_discounts(): void
    {
        $this->bothLines();
        $html = $this->actingAs($this->customer(), 'customer')
            ->get('/account/cloud-store?location=global-gpu')->assertOk()->getContent();
        $badges = static function (string $html): \DOMNodeList {
            $document = new \DOMDocument;
            $previous = libxml_use_internal_errors(true);
            try {
                $document->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NONET);
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }

            return (new \DOMXPath($document))->query('//*[@id="cvb-bill"]//em[@data-cycle-saving]');
        };
        $gpuBadges = $badges($html);
        $this->assertCount(count(CloudStoreController::cycles()), $gpuBadges);
        foreach ($gpuBadges as $badge) {
            $this->assertTrue($badge->hasAttribute('hidden'), 'GPU نباید تخفیفِ دوره‌ایِ VPS را تبلیغ کند');
            $this->assertSame('', trim($badge->textContent));
        }

        $vps = $this->get('/account/cloud-store?location=de-frankfurt')->assertOk()->getContent();
        $vpsBadges = $badges($vps);
        $this->assertCount(count(CloudStoreController::cycles()), $vpsBadges);
        $visible = 0;
        foreach ($vpsBadges as $badge) {
            if (! $badge->hasAttribute('hidden')) {
                $visible++;
                $this->assertStringEndsWith(__('ui.cvb_save_suf'), trim($badge->textContent));
            }
        }
        $this->assertGreaterThan(0, $visible, 'پلنِ VPS دارای تخفیف باید نشانِ دیداریِ واقعی داشته باشد');
        Http::assertNothingSent();
    }

    public function test_jupyter_sales_pause_blocks_new_checkout_without_changing_runtime_compatibility(): void
    {
        $this->bothLines();
        $plan = CloudPlan::where('provider', 'salad')->firstOrFail();
        CloudImage::create([
            'provider' => 'salad', 'provider_ref' => \App\Services\Cloud\SaladClient::APPS['gpu-jupyter']['ref'],
            'key' => 'gpu-jupyter', 'kind' => 'app', 'family' => 'gpu-jupyter',
            'label' => 'Jupyter', 'arch' => 'x86', 'is_active' => true,
        ]);
        \App\Models\Setting::put('salad_jupyter_sales_paused', '1');
        $customer = $this->customer();
        \App\Models\CreditEntry::create([
            'customer_id' => $customer->id, 'currency_code' => 'IRT', 'amount' => 500000,
            'balance_after' => 500000, 'reason' => 'topup', 'source_type' => \App\Models\Customer::class,
            'source_id' => $customer->id, 'note' => 'isolated Jupyter sales pause fixture',
        ]);
        $this->assertNotContains('gpu-jupyter', CloudStoreController::imageKeysFor($plan, 'app'));
        $this->assertContains('gpu-ollama', CloudStoreController::imageKeysFor($plan, 'app'));
        $this->assertNotNull(CloudImage::compatibleRefFor($plan, 'gpu-jupyter'));
        $this->actingAs($customer, 'customer')->post('/account/cloud-store', [
            'location' => 'global-gpu', 'plan' => $plan->slug,
            'image' => 'gpu-jupyter', 'cycle' => 'monthly', 'billing_mode' => 'hourly',
        ])->assertRedirect()->assertSessionHasErrors('image');
        $this->assertDatabaseCount('services', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertSame(500000, $customer->fresh()->creditBalance('IRT'));
        Http::assertNothingSent();
        $html = $this->get('/account/cloud-store?location=global-gpu')->assertOk()->getContent();
        $this->assertStringContainsString(__('ui.gpu_jupyter_sales_paused'), $html);
        \App\Models\Setting::put('salad_jupyter_sales_paused', '0');
        $this->assertContains('gpu-jupyter', CloudStoreController::imageKeysFor($plan, 'app'));
    }

    public function test_each_application_pause_rejects_checkout_before_wallet_and_keeps_other_apps_available(): void
    {
        $this->bothLines();
        $plan = CloudPlan::where('provider', 'salad')->firstOrFail();
        $plan->update(['gpu_model' => 'RTX 3090 (24 GB)']);
        foreach (['gpu-comfyui' => 'ComfyUI', 'gpu-jupyter' => 'Jupyter'] as $key => $label) {
            CloudImage::create([
                'provider' => 'salad', 'provider_ref' => \App\Services\Cloud\SaladClient::APPS[$key]['ref'],
                'key' => $key, 'kind' => 'app', 'family' => $key,
                'label' => $label, 'arch' => 'x86', 'is_active' => true,
            ]);
        }
        $customer = $this->customer();
        \App\Models\CreditEntry::create([
            'customer_id' => $customer->id, 'currency_code' => 'IRT', 'amount' => 500000,
            'balance_after' => 500000, 'reason' => 'topup', 'source_type' => Customer::class,
            'source_id' => $customer->id, 'note' => 'isolated GPU application pause fixture',
        ]);
        $this->actingAs($customer, 'customer');
        foreach (['gpu-ollama' => ['salad_ollama_sales_paused', 'Ollama'], 'gpu-comfyui' => ['salad_comfyui_sales_paused', 'ComfyUI']] as $key => [$setting, $label]) {
            \App\Models\Setting::put($setting, '1');
            $this->assertNotContains($key, CloudStoreController::imageKeysFor($plan, 'app'));
            $this->assertContains('gpu-jupyter', CloudStoreController::imageKeysFor($plan, 'app'));
            $other = $key === 'gpu-ollama' ? 'gpu-comfyui' : 'gpu-ollama';
            $this->assertContains($other, CloudStoreController::imageKeysFor($plan, 'app'));
            $this->assertNotNull(CloudImage::compatibleRefFor($plan, $key));
            // Catalog activity cannot lift the separate operational hold.
            CloudImage::where('provider', 'salad')->where('key', $key)->update(['is_active' => true]);
            $this->post('/account/cloud-store', [
                'location' => 'global-gpu', 'plan' => $plan->slug,
                'image' => $key, 'cycle' => 'monthly', 'billing_mode' => 'hourly',
            ])->assertRedirect()->assertSessionHasErrors('image');
            $this->assertDatabaseCount('services', 0);
            $this->assertDatabaseCount('invoices', 0);
            $this->assertSame(500000, $customer->fresh()->creditBalance('IRT'));
            Http::assertNothingSent();
            $this->get('/account/cloud-store?location=global-gpu')->assertOk()
                ->assertSee(__('ui.gpu_app_sales_paused', ['app' => $label]));
            $this->get('/gpu')->assertOk()
                ->assertSee(__('ui.gpu_app_sales_paused', ['app' => $label]));
            \App\Models\Setting::put($setting, '0');
            $this->assertContains($key, CloudStoreController::imageKeysFor($plan, 'app'));
        }
    }

    public function test_incompatible_comfyui_is_unavailable_before_payment_and_provisioning(): void
    {
        $this->bothLines();
        $plan = CloudPlan::where('provider', 'salad')->firstOrFail();
        $plan->update(['gpu_model' => 'GTX 1060 (6 GB)']);
        CloudImage::create([
            'provider' => 'salad', 'provider_ref' => \App\Services\Cloud\SaladClient::APPS['gpu-comfyui']['ref'],
            'key' => 'gpu-comfyui', 'kind' => 'app', 'family' => 'gpu-comfyui',
            'label' => 'ComfyUI', 'arch' => 'x86', 'is_active' => true,
        ]);
        CloudImage::create([
            'provider' => 'salad', 'provider_ref' => \App\Services\Cloud\SaladClient::APPS['gpu-jupyter']['ref'],
            'key' => 'gpu-jupyter', 'kind' => 'app', 'family' => 'gpu-jupyter',
            'label' => 'Jupyter', 'arch' => 'x86', 'is_active' => true,
        ]);

        $this->assertNotContains('gpu-comfyui', CloudStoreController::imageKeysFor($plan, 'app'));
        $this->assertNull(CloudImage::compatibleRefFor($plan, 'gpu-comfyui'));
        $this->assertNotContains('gpu-jupyter', CloudStoreController::imageKeysFor($plan, 'app'));
        $this->assertNull(CloudImage::compatibleRefFor($plan, 'gpu-jupyter'));
        $this->assertContains('gpu-ollama', CloudStoreController::imageKeysFor($plan, 'app'));
        $customer = $this->customer();
        foreach (['gpu-comfyui', 'gpu-jupyter'] as $image) {
            $this->actingAs($customer, 'customer')->post('/account/cloud-store', [
                'location' => 'global-gpu', 'plan' => $plan->slug,
                'image' => $image, 'cycle' => 'monthly',
            ])->assertRedirect()->assertSessionHasErrors('image');
        }
        $this->assertDatabaseCount('services', 0);
        $this->assertDatabaseCount('invoices', 0);
        Http::assertNothingSent();
        $plan->update(['gpu_model' => 'Stable Diffusion Compatible']);
        $this->assertNotContains('gpu-comfyui', CloudStoreController::imageKeysFor($plan, 'app'));
        $this->assertNotContains('gpu-jupyter', CloudStoreController::imageKeysFor($plan, 'app'));
        $plan->update(['gpu_model' => 'RTX 5060 Ti (16 GB)']);
        $this->assertContains('gpu-comfyui', CloudStoreController::imageKeysFor($plan, 'app'));
        $this->assertNotNull(CloudImage::compatibleRefFor($plan, 'gpu-comfyui'));
        $this->assertContains('gpu-jupyter', CloudStoreController::imageKeysFor($plan, 'app'));
    }

    protected function setUp(): void
    {
        parent::setUp();
        \Tests\Support\NavasanRates::cloudSnapshot();
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
     * 🔴 ویزاردِ GPU دو تصمیم دارد؛ نام کنار درخواست نهایی قرار می‌گیرد.
     *
     * خطِ GPU یک مکان بیشتر ندارد؛ «کدام کشور؟ کدام شهر؟» دو مرحلهٔ بی‌جواب
     * بودند که هر دو یک مقدارِ ثابت را نشان می‌دادند. مکان از فیلدِ پنهانِ
     * فرم می‌رود و هیچ انتخابی گم نمی‌شود.
     */
    public function test_the_gpu_wizard_has_two_steps_with_the_name_at_checkout(): void
    {
        $this->bothLines();

        $html = (string) $this->actingAs($this->customer(), 'customer')
            ->get('/account/cloud-store?location=global-gpu')->assertOk()->getContent();

        $this->assertStringNotContainsString('id="cvb-step-1"', $html, 'مرحلهٔ «کشور» برای GPU بی‌معنی است.');
        $this->assertStringNotContainsString('id="cvb-step-2"', $html, 'مرحلهٔ «شهر» برای GPU بی‌معنی است.');
        $this->assertStringNotContainsString('id="cvb-step-5"', $html);
        $this->assertSame(2, substr_count($html, 'class="cvb-step-b"'));
        $this->assertStringContainsString(__('ui.cvb_step_gpu'), $html);
        // شمارهٔ نمایشی از ۱ شروع می‌شود، نه از ۳
        $this->assertStringContainsString(__('ui.cvb_step_idx', ['n' => fa_num(1), 't' => fa_num(2)]), $html);
        // و فرم هنوز مکان را حمل می‌کند
        $this->assertStringContainsString('name="location" value="global-gpu"', $html);
    }

    /** 🔴 بجِ «کمتر از دو دقیقه» در فروشگاهِ GPU دروغ است — بازهٔ واقعی می‌آید */
    public function test_the_gpu_store_does_not_promise_two_minute_delivery(): void
    {
        $this->bothLines();

        $html = (string) $this->actingAs($this->customer(), 'customer')
            ->get('/account/cloud-store?location=global-gpu')->assertOk()->getContent();

        $this->assertStringContainsString(__('ui.cvb_pill_gpu'), $html);
        $this->assertStringContainsString(__('ui.cvb_eta_gpu'), $html);
        $this->assertStringNotContainsString(__('ui.cvb_pill'), $html);
        $this->assertStringNotContainsString(__('ui.cvb_eta'), $html);
    }

    /** کشور و شهر یک مرحله‌اند؛ نام بالای پرداخت است. */
    public function test_the_vps_wizard_has_three_visible_steps(): void
    {
        $this->bothLines();

        $html = (string) $this->actingAs($this->customer(), 'customer')
            ->get('/account/cloud-store?location=de-frankfurt')->assertOk()->getContent();

        $this->assertStringContainsString('id="cvb-step-1"', $html);
        $this->assertStringNotContainsString('id="cvb-step-2"', $html);
        $this->assertStringNotContainsString('id="cvb-step-5"', $html);
        $this->assertSame(3, substr_count($html, 'class="cvb-step-b"'));
        $this->assertStringContainsString(__('ui.cvb_step_idx', ['n' => fa_num(3), 't' => fa_num(3)]), $html);
    }

    /**
     * 🔴 دو پلنِ هم‌مشخصات با GPUِ متفاوت باید دو کارتِ **متمایز** بسازند.
     *
     * گزارشِ کارفرما: «مرحله ۳ تکراری نشون میده» — GTX 1050 Ti و GTX 1650 هر
     * دو «CV-8-30» با یک قیمت رندر می‌شدند، چون کارت مدلِ GPU را چاپ نمی‌کرد؛
     * مهم‌ترین صفتِ محصول از دیدِ خریدارِ این خط.
     */
    public function test_same_spec_plans_with_different_gpus_render_distinct_cards(): void
    {
        $this->bothLines();

        CloudPlan::create([
            'provider' => 'salad', 'provider_ref' => 'gc-1650', 'provider_location' => 'global',
            'location_code' => 'global-gpu', 'public_name' => 'CV-8-30',
            'slug' => 'cv-8c-30g-100d-global-gpu-gtx-1650', 'vcpu' => 8, 'ram_mb' => 30720,
            'disk_gb' => 100, 'disk_type' => 'ssd', 'traffic_gb' => 0, 'cpu_kind' => 'shared',
            'arch' => 'x86', 'cost_eur_cents' => 100, 'price_eur_cents' => 150, 'price_irt' => 180_000,
            'is_active' => true, 'in_stock' => true,
            'gpu_model' => 'GTX 1650', 'gpu_count' => 1, 'is_interruptible' => true,
        ]);

        $html = (string) $this->actingAs($this->customer(), 'customer')
            ->get('/account/cloud-store?location=global-gpu')->assertOk()->getContent();

        $this->assertStringContainsString('RTX 4090', $html);
        $this->assertStringContainsString('GTX 1650', $html, 'مدلِ GPU روی کارت نیست — دو پلن «تکراری» دیده می‌شوند.');
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

    /** خط GPU فقط انتخاب برنامه دارد؛ گزینه‌های سازگار باید مستقیم دیده شوند. */
    public function test_gpu_apps_are_visible_without_an_empty_os_tab(): void
    {
        $this->bothLines();
        foreach (['gpu-jupyter' => 'Jupyter', 'gpu-comfyui' => 'ComfyUI'] as $key => $label) {
            CloudImage::create([
                'provider' => 'salad', 'provider_ref' => \App\Services\Cloud\SaladClient::APPS[$key]['ref'],
                'key' => $key, 'kind' => 'app', 'family' => $key,
                'label' => $label, 'arch' => 'x86', 'is_active' => true,
            ]);
        }

        $html = (string) $this->actingAs($this->customer(), 'customer')
            ->get('/account/cloud-store?location=global-gpu&plan=cv-8c-30g-100d-global-gpu-rtx-4090')
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('id="cvb-tab-os"', $html);
        $this->assertStringContainsString('class="cvb-opts cvb-gpu-app-grid"', $html);
        $this->assertMatchesRegularExpression('~<h1[^>]*>'.preg_quote(__('ui.cvb_gpu_h1'), '~').'</h1>~', $html);
        $this->assertStringContainsString(__('ui.cvb_gpu_step_app'), $html);
        $this->assertStringContainsString(__('ui.cvb_gpu_adv_sub'), $html);
        $this->assertStringNotContainsString(__('ui.cvb_adv_sub'), $html);
        foreach (['gpu-ollama', 'gpu-jupyter', 'gpu-comfyui'] as $key) {
            $this->assertMatchesRegularExpression('~class="cvb-img cvb-gpu-app[^\"]*"\s+data-key="'.$key.'"~', $html);
        }
        $this->assertMatchesRegularExpression(
            '~name="image" value="gpu-(?:ollama|jupyter|comfyui)"[^>]*checked~', $html);
    }

    /**
     * 🔴 صفحهٔ خرید نباید چیزی را وعده دهد که این خط ندارد.
     *
     * ═══ رخداد (۲ مهر ۱۴۰۵، تیکت TK-260924-8595) ═══
     * مشتری RTX 3090 خرید تا ComfyUI را با نودهای سفارشی خودش بالا بیاورد و
     * عیناً همین جمله را نقل کرد: «پس از تحویل می‌توانید از پنل، سیستم‌عامل را
     * عوض کنید». روی این خط نه SSH هست نه نصبِ دوباره
     * (`SaladClient::capabilities()` ← `rebuild:false`). حق با او بود و وجهش برگشت.
     *
     * ⚠️ سومین نمونهٔ یک الگو: چیپِ S3 روی هاست بکاپ، وعدهٔ رمزِ root در
     * ایمیلِ تحویل، و این. مشخصاتِ صفحهٔ محصول یک تعهدِ فروش است.
     */
    public function test_the_gpu_store_never_promises_an_os_reinstall(): void
    {
        $this->bothLines();

        $html = (string) $this->actingAs($this->customer(), 'customer')
            ->get('/account/cloud-store?location=global-gpu')->assertOk()->getContent();

        $this->assertStringNotContainsString(__('ui.cvb_os_note'), $html,
            'فروشگاهِ GPU دوباره وعدهٔ تغییرِ سیستم‌عامل می‌دهد — همان جمله‌ای که وجهِ یک مشتری را برگرداند.');
        $this->assertStringContainsString(__('ui.cvb_gpu_access_note'), $html,
            'به‌جای آن باید صریح بگوید دسترسی از راهِ نشانی و توکن است.');
    }

    /**
     * ⚠️ نیمهٔ دیگر: سرورِ مجازی واقعاً نصبِ دوباره دارد؛ فیکسی که آن را
     * هم خاموش کند، یک قابلیتِ واقعی را از مشتری پنهان می‌کند.
     */
    public function test_the_vps_store_keeps_the_os_reinstall_note(): void
    {
        $this->bothLines();

        $html = (string) $this->actingAs($this->customer(), 'customer')
            ->get('/account/cloud-store?location=de-frankfurt')->assertOk()->getContent();

        $this->assertStringContainsString(__('ui.cvb_os_note'), $html);
        $this->assertStringNotContainsString(__('ui.cvb_gpu_access_note'), $html);
    }
}

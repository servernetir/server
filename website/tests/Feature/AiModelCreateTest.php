<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\AiModelUnitPrice;
use App\Models\AiProvider;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ساختِ مدل از پنلِ مدیر + منوی «درگاه هوش مصنوعی».
 *
 * تا پیش از این هیچ راهی جز تست برای ساختِ ردیفِ `ai_models` نبود و روی سرور
 * پیش‌نمایشِ قیمت همیشه خالی می‌ماند. بها به دلار (مثلاً 0.23) تایپ و بی float به
 * میکرو تبدیل می‌شود؛ هر عددی که گرد شود رد می‌شود، نه ذخیره.
 */
class AiModelCreateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private AiProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->provider = AiProvider::create([
            'slug' => 'acme-create', 'name' => 'Acme', 'driver' => 'Acme',
            'enabled' => false, 'commercial_enabled' => false, 'resale_allowed' => false,
            'agreement_status' => 'none', 'priority' => 100, 'live_calls_enabled' => false,
            'billing_currency_code' => 'USD',
        ]);
    }

    private function store(array $over = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->post('/admin/ai/models', $over + [
            'ai_provider_id' => $this->provider->id,
            'slug' => 'llama-3.3-70b',
            'upstream_model' => 'meta-llama/Llama-3.3-70B-Instruct',
            'name' => 'Llama 3.3 70B',
            'category' => 'chat',
            'status' => 'active',
        ]);
    }

    public function test_form_renders_for_admin(): void
    {
        $this->actingAs($this->admin)->get('/admin/ai/models/create')
            ->assertOk()->assertSee('افزودنِ مدل')->assertSee('Acme (USD)');
    }

    public function test_non_admin_staff_cannot_create(): void
    {
        $author = User::factory()->create(['role' => 'author']);

        $this->actingAs($author)->get('/admin/ai/models/create')->assertForbidden();
        $this->actingAs($author)->post('/admin/ai/models', ['slug' => 'x'])->assertForbidden();
        $this->assertSame(0, AiModel::count());
    }

    public function test_model_and_dollar_prices_are_stored_exactly_and_preview_shows_toman(): void
    {
        Setting::put('pricing_usd_rate_override', '100000');
        Setting::put('ai_margin_pct', '25');
        $this->provider->update(['fx_fee_bp' => 800]);

        $res = $this->store(['price_input' => '0.23', 'price_cached' => '0.115', 'price_output' => '0.40', 'margin_pct' => '']);

        $m = AiModel::where('slug', 'llama-3.3-70b')->firstOrFail();
        $res->assertRedirect('/admin/ai/pricing?model='.$m->id);

        $rows = AiModelUnitPrice::where('ai_model_id', $m->id)->where('active', true)->pluck('price_micro_units', 'unit')->all();
        $this->assertSame(['cached_input' => 115_000, 'input' => 230_000, 'output' => 400_000], collect($rows)->sortKeys()->all());
        $this->assertSame(['USD'], AiModelUnitPrice::where('ai_model_id', $m->id)->distinct()->pluck('currency_code')->all());
        $this->assertNull($m->margin_bp);

        // همان اعدادِ مثالِ کارشدهٔ مشخصات
        $html = $this->actingAs($this->admin)->get('/admin/ai/pricing?model='.$m->id)->assertOk()->getContent();
        foreach (['31,050', '15,525', '54,000'] as $p) {
            $this->assertStringContainsString(fa_num($p), $html);
        }
    }

    public function test_model_without_prices_is_created_and_explains_what_is_missing(): void
    {
        $this->store(['margin_pct' => '30'])->assertRedirect();

        $m = AiModel::where('slug', 'llama-3.3-70b')->firstOrFail();
        $this->assertSame(3000, $m->margin_bp);
        $this->assertSame(0, AiModelUnitPrice::where('ai_model_id', $m->id)->count());

        $this->actingAs($this->admin)->get('/admin/ai/pricing?model='.$m->id)
            ->assertOk()->assertSee('قیمتِ فعالِ «توکن ورودی» در سطحِ همین مدل ثبت نشده');
    }

    public function test_prices_that_would_be_rounded_or_are_out_of_range_are_rejected(): void
    {
        foreach ([
            ['price_input' => '0.1234567'],                         // ۷ رقمِ اعشار — گرد نمی‌شود، رد می‌شود
            ['price_input' => '0'],
            ['price_input' => '1000.000001'],
            ['price_input' => '-1'],
            ['price_input' => '1e-3'],
            ['price_input' => '0.10', 'price_cached' => '0.20'],   // کش‌شده گران‌تر
            ['price_cached' => '0.10'],                            // کش‌شده بی ورودی
        ] as $bad) {
            $this->store($bad)->assertSessionHasErrors();
            $this->assertSame(0, AiModel::count(), 'مدل نباید با بهای نامعتبر ساخته شود: '.json_encode($bad));
        }
    }

    public function test_slug_rules_and_uniqueness(): void
    {
        $this->store(['slug' => 'Llama 3'])->assertSessionHasErrors('slug');

        $this->store()->assertRedirect();
        $this->store(['upstream_model' => 'other'])->assertSessionHasErrors('slug');
        $this->store(['slug' => 'llama-b'])->assertSessionHasErrors('upstream_model');

        // همان مدلِ بومی نزدِ ارائه‌دهندهٔ دیگر مجاز است
        $other = AiProvider::create([
            'slug' => 'other-p', 'name' => 'Other', 'driver' => 'X', 'enabled' => false,
            'commercial_enabled' => false, 'resale_allowed' => false, 'agreement_status' => 'none',
            'priority' => 100, 'live_calls_enabled' => false, 'billing_currency_code' => 'EUR',
        ]);
        $this->store(['slug' => 'llama-b', 'ai_provider_id' => $other->id, 'price_input' => '0.2'])->assertRedirect();
        $this->assertSame('EUR', AiModelUnitPrice::whereHas('model', fn ($q) => $q->where('slug', 'llama-b'))->value('currency_code'));
    }

    /**
     * مسیرِ قدیمیِ /v1 تا M5.1b سدهای فروش را نمی‌خوانَد (بازبینیِ پیش از انتشار با یک
     * تستِ واقعی نشانش داد)؛ پس فرم مدل را خاموش پیشنهاد می‌کند.
     */
    public function test_form_defaults_new_models_to_disabled(): void
    {
        $html = $this->actingAs($this->admin)->get('/admin/ai/models/create')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<option value="disabled"\s+selected/', $html);
        $this->assertDoesNotMatchRegularExpression('/<option value="active"\s+selected/', $html);
    }

    /**
     * دوبار-کلیک: هر دو درخواست از اعتبارسنجی رد می‌شوند و بازنده به قیدِ یکتا می‌خورد.
     * باید پیامِ روشن بگیرد، نه ۵۰۰ و نه ردیفِ نیمه‌کاره.
     */
    public function test_unique_race_after_validation_is_a_clear_error_not_a_500(): void
    {
        AiModel::creating(function (AiModel $m) {
            \Illuminate\Support\Facades\DB::table('ai_models')->insert([
                'ai_provider_id' => $m->ai_provider_id, 'slug' => $m->slug, 'upstream_model' => 'racer',
                'name' => 'racer', 'category' => 'chat', 'status' => 'disabled',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $this->store(['price_input' => '0.23', 'price_output' => '0.40'])
            ->assertRedirect()
            ->assertSessionHasErrors('slug');

        $this->assertSame(0, AiModelUnitPrice::count(), 'تراکنش باید سطرهای بها را هم برگردانده باشد');
    }

    public function test_models_list_links_to_create(): void
    {
        $this->actingAs($this->admin)->get('/admin/ai/models')
            ->assertOk()->assertSee('/admin/ai/models/create', false);
    }

    public function test_admin_menu_has_the_ai_gateway_section_with_every_page(): void
    {
        $html = $this->actingAs($this->admin)->get('/admin/ai')->assertOk()->getContent();

        $this->assertStringContainsString('درگاه هوش مصنوعی', $html);
        foreach (['href="/admin/ai"', 'href="/admin/ai/models"', 'href="/admin/ai/pricing"', 'href="/admin/settings?tab=pricing#ai-sales"'] as $link) {
            $this->assertStringContainsString($link, $html);
        }
    }
}

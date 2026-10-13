<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\PriceBook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * پنلِ مدیر برای قیمتِ AI (M5.1a): حاشیه، سربارِ ارز، مرزِ نرخِ دستی و پیش‌نمایش.
 *
 * هیچ‌کدام مسیرِ /v1 را لمس نمی‌کنند؛ این تست‌ها فقط تضمین می‌کنند عددی که مالک
 * تایپ می‌کند همان عددی است که ذخیره و نشان داده می‌شود.
 */
class AiPricingAdminTest extends TestCase
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
            'slug' => 'acme-admin', 'name' => 'Acme', 'driver' => 'Acme',
            'enabled' => false, 'commercial_enabled' => false, 'resale_allowed' => false,
            'agreement_status' => 'none', 'priority' => 100, 'live_calls_enabled' => false,
            'billing_currency_code' => 'USD',
        ]);
    }

    private function pricingTab(array $fields): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->post('/admin/settings', ['tab' => 'pricing'] + $fields);
    }

    /* ═══ تنظیمات ═══ */

    public function test_margin_is_saved_as_typed(): void
    {
        $this->pricingTab(['ai_margin_pct' => '12.5'])->assertSessionHasNoErrors();

        $this->assertSame('12.5', Setting::get('ai_margin_pct'));
    }

    public function test_zero_negative_and_three_decimal_margins_are_rejected(): void
    {
        foreach (['0', '-5', '12.345', '501'] as $bad) {
            $this->pricingTab(['ai_margin_pct' => $bad])->assertSessionHasErrors('ai_margin_pct');
        }
        $this->assertNull(Setting::get('ai_margin_pct'));
    }

    public function test_rate_override_must_be_zero_or_within_scraper_bounds(): void
    {
        foreach (['15000', '19999', '5000001', '100000000'] as $bad) {
            $this->pricingTab(['pricing_usd_rate_override' => $bad])->assertSessionHasErrors('pricing_usd_rate_override');
            $this->pricingTab(['pricing_rate_override' => $bad])->assertSessionHasErrors('pricing_rate_override');
        }

        foreach (['0', '20000', '99999', '100000', '4999999', '5000000'] as $ok) {
            $this->pricingTab(['pricing_usd_rate_override' => $ok])->assertSessionHasNoErrors();
            $this->assertSame($ok, Setting::get('pricing_usd_rate_override'));
        }
    }

    public function test_sales_gate_is_stored_only_as_1_and_canary_ids_are_normalised(): void
    {
        $this->pricingTab(['ai_sales_open' => '1', 'ai_canary_customer_ids' => ' 7, 3 ,7,,0 '])->assertSessionHasNoErrors();
        $this->assertSame('1', Setting::get('ai_sales_open'));
        $this->assertSame('7,3', Setting::get('ai_canary_customer_ids'));

        $this->pricingTab([])->assertSessionHasNoErrors();
        $this->assertNull(Setting::get('ai_sales_open'));
        $this->assertNull(Setting::get('ai_canary_customer_ids'));

        $this->pricingTab(['ai_canary_customer_ids' => '1; DROP'])->assertSessionHasErrors('ai_canary_customer_ids');
    }

    /* ═══ سربارِ ارزِ ارائه‌دهنده ═══ */

    public function test_provider_fee_is_saved_in_bp_and_empty_means_unsellable(): void
    {
        $url = '/admin/ai/providers/'.$this->provider->id;

        $this->actingAs($this->admin)->post($url, ['agreement_status' => 'none', 'fx_fee_pct' => '8.25'])->assertRedirect();
        $this->assertSame(825, $this->provider->fresh()->fx_fee_bp);

        $this->actingAs($this->admin)->post($url, ['agreement_status' => 'none', 'fx_fee_pct' => '0'])->assertRedirect();
        $this->assertSame(0, $this->provider->fresh()->fx_fee_bp);

        $this->actingAs($this->admin)->post($url, ['agreement_status' => 'none', 'fx_fee_pct' => ''])->assertRedirect();
        $this->assertNull($this->provider->fresh()->fx_fee_bp);
    }

    public function test_provider_form_without_the_fee_field_leaves_the_fee_alone(): void
    {
        $this->provider->update(['fx_fee_bp' => 800]);

        $this->actingAs($this->admin)->post('/admin/ai/providers/'.$this->provider->id, ['agreement_status' => 'none'])->assertRedirect();
        $this->assertSame(800, $this->provider->fresh()->fx_fee_bp);
    }

    public function test_provider_fee_above_25_percent_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/ai/providers/'.$this->provider->id, ['agreement_status' => 'none', 'fx_fee_pct' => '26'])
            ->assertSessionHasErrors('fx_fee_pct');
        $this->assertNull($this->provider->fresh()->fx_fee_bp);
    }

    /* ═══ حاشیهٔ مدل ═══ */

    public function test_model_margin_is_saved_and_empty_falls_back_to_global(): void
    {
        $m = $this->model();
        $base = ['name' => 'Model A', 'status' => 'active', 'category' => 'chat'];

        $this->actingAs($this->admin)->post('/admin/ai/models/'.$m->id, $base + ['margin_pct' => '30'])->assertRedirect('/admin/ai/models');
        $this->assertSame(3000, $m->fresh()->margin_bp);

        $this->actingAs($this->admin)->post('/admin/ai/models/'.$m->id, $base + ['margin_pct' => ''])->assertRedirect('/admin/ai/models');
        $this->assertNull($m->fresh()->margin_bp);

        $this->actingAs($this->admin)->post('/admin/ai/models/'.$m->id, $base + ['margin_pct' => '0'])->assertSessionHasErrors('margin_pct');
    }

    public function test_price_rows_accept_only_usd_or_eur(): void
    {
        $m = $this->model();

        $this->actingAs($this->admin)
            ->post('/admin/ai/pricing/supersede', ['model' => $m->id, 'unit' => 'input', 'micros' => 1000, 'currency' => 'IRR'])
            ->assertSessionHasErrors('currency');
    }

    /**
     * `models.blade.php` شمارشِ `active_models` را می‌خواند که کنترلر هرگز نمی‌سازد
     * (نامش `active_prices` است)؛ null به `fa_num` ⇒ ۵۰۰ به‌محضِ نخستین ردیفِ مدل.
     * تستِ قدیمی صفحه را با صفر مدل باز می‌کرد و این را نمی‌دید.
     */
    public function test_models_page_renders_with_a_priced_model(): void
    {
        $m = $this->model();
        (new PriceBook)->supersede($m, 'input', 230_000, currencyCode: 'USD');

        $this->actingAs($this->admin)->get('/admin/ai/models')
            ->assertOk()
            ->assertSee('acme/model-a');
    }

    public function test_every_admin_ai_page_renders_with_real_rows(): void
    {
        $m = $this->model();
        (new PriceBook)->supersede($m, 'input', 230_000, currencyCode: 'USD');

        foreach (['/admin/ai', '/admin/ai/models', '/admin/ai/pricing?model='.$m->id,
                  '/admin/ai/providers/edit?provider='.$this->provider->id, '/admin/ai/models/'.$m->id.'/edit'] as $url) {
            $this->actingAs($this->admin)->get($url)->assertOk();
        }
        Http::assertNothingSent();
    }

    /* ═══ پیش‌نمایش ═══ */

    public function test_preview_explains_why_a_model_is_not_sellable(): void
    {
        $m = $this->model();

        $this->actingAs($this->admin)->get('/admin/ai/pricing?model='.$m->id)
            ->assertOk()
            ->assertSee('این مدل الان فروختنی نیست')
            ->assertSee('سربارِ ارزِ ارائه‌دهنده ثبت نشده')
            ->assertSee('حاشیهٔ سودِ AI در تنظیماتِ قیمت‌گذاری خالی است')
            ->assertSee('قیمتِ فعالِ «توکن ورودی» در سطحِ همین مدل ثبت نشده');

        Http::assertNothingSent();
    }

    public function test_preview_shows_the_same_numbers_the_charge_uses(): void
    {
        Setting::put('pricing_usd_rate_override', '100000');
        Setting::put('ai_margin_pct', '25');
        $this->provider->update(['fx_fee_bp' => 800]);
        $m = $this->model();
        foreach (['input' => 230_000, 'cached_input' => 115_000, 'output' => 400_000] as $unit => $micros) {
            (new PriceBook)->supersede($m, $unit, $micros, currencyCode: 'USD');
        }

        $html = $this->actingAs($this->admin)->get('/admin/ai/pricing?model='.$m->id)->assertOk()->getContent();

        foreach (['31,050', '15,525', '54,000'] as $p) {
            $this->assertStringContainsString(fa_num($p), $html, "قیمتِ {$p} در پیش‌نمایش نیست");
        }
        $this->assertStringNotContainsString('این مدل الان فروختنی نیست', $html);
        // پرچم‌های فروش هنوز بسته‌اند و باید گفته شوند
        $this->assertStringContainsString('فروشِ ارائه‌دهنده خاموش است', $html);
        Http::assertNothingSent();
    }

    public function test_price_preview_command_runs_without_network(): void
    {
        $this->model();

        $this->artisan('ai:price-preview')->assertSuccessful();
        $this->artisan('ai:price-preview --strict')->assertFailed();
        Http::assertNothingSent();
    }

    private function model(): AiModel
    {
        return AiModel::create([
            'ai_provider_id' => $this->provider->id,
            'slug' => 'acme/model-a', 'upstream_model' => 'acme/model-a',
            'name' => 'Model A', 'category' => AiModel::CATEGORY_CHAT,
            'status' => AiModel::STATUS_ACTIVE,
        ]);
    }
}

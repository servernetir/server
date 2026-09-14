<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Services\Ai\AiModelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * رجیستریِ ارائه‌دهنده و مدل — M1.
 *
 * همهٔ ادعای «چه چیزی در دسترس است» مالِ `AiModelRegistry` است؛ کنترلر و *
 * روزی دروازهٔ `/v1` باید فقط از همین کلاس بپرسد. این تست‌ها همان قرارداد
 * را لنگر می‌کنند.
 */
class AiRegistryTest extends TestCase
{
    use RefreshDatabase;

    private AiModelRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new AiModelRegistry;
    }

    private function provider(array $overrides = []): AiProvider
    {
        return AiProvider::create(array_merge([
            'slug'       => 'acme',
            'name'       => 'Acme Compute',
            'driver'     => 'Acme',
            'enabled'    => true,
            'commercial_enabled' => false,
            'resale_allowed'     => false,
            'agreement_status'   => 'none',
            'priority'   => 100,
            'live_calls_enabled' => false,
        ], $overrides));
    }

    private function model(AiProvider $p, array $overrides = []): AiModel
    {
        return AiModel::create(array_merge([
            'ai_provider_id' => $p->id,
            'slug'       => 'acme/model-a',
            'upstream_model' => 'acme/model-a',
            'name'       => 'Model A',
            'category'   => AiModel::CATEGORY_CHAT,
            'status'     => AiModel::STATUS_ACTIVE,
        ], $overrides));
    }

    /* ── مهاجرت و رجیستری ── */

    public function test_deepinfra_registry_row_is_created_by_migration_safely_and_idempotently(): void
    {
        $p = AiProvider::query()->where('slug', 'deepinfra')->first();

        $this->assertNotNull($p, 'مهاجرت باید ردیفِ DeepInfra را بسازد');
        $this->assertFalse($p->enabled, 'ارائه‌دهندهٔ تازه باید خاموش متولد شود');
        $this->assertFalse($p->commercial_enabled);
        $this->assertFalse($p->live_calls_enabled, 'تماسِ زنده هیچ‌گز پیش‌فرض رویه نمی‌ماند');
        $this->assertSame('DeepInfra', $p->name);
    }

    public function test_public_model_slug_is_globally_unique(): void
    {
        $p = $this->provider();
        $this->model($p, ['slug' => 'shared/model']);

        $second = $this->provider(['slug' => 'other']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->model($second, ['slug' => 'shared/model']);
    }

    public function test_upstream_model_is_unique_per_provider(): void
    {
        $p = $this->provider();
        $this->model($p, ['slug' => 'x/a', 'upstream_model' => 'up-1']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->model($p, ['slug' => 'x/b', 'upstream_model' => 'up-1']);
    }

    /* ── رزولوشن ── */

    public function test_provider_resolution_by_slug(): void
    {
        $this->provider(['slug' => 'zzz-findme']);
        $this->assertNotNull($this->registry->provider('zzz-findme'));
        $this->assertNull($this->registry->provider('nope'));
    }

    public function test_model_resolution_loads_provider(): void
    {
        $p = $this->provider();
        $this->model($p, ['slug' => 'find/me']);

        $m = $this->registry->model('find/me');
        $this->assertNotNull($m);
        $this->assertSame('acme', $m->provider->slug);
    }

    public function test_model_resolution_for_unknown_slug_is_null(): void
    {
        $this->assertNull($this->registry->model('ghost/model'));
    }

    /* ── گیت‌های فنی ── */

    public function test_disabled_provider_model_is_not_routeable_nor_sellable(): void
    {
        $p = $this->provider(['enabled' => false, 'commercial_enabled' => true]);
        $this->model($p);

        $this->assertTrue($this->registry->routeableModels('chat')->isEmpty());
        $this->assertTrue($this->registry->sellableModels('chat')->isEmpty());
    }

    public function test_disabled_model_is_not_routeable(): void
    {
        $p = $this->provider();
        $this->model($p, ['status' => AiModel::STATUS_DISABLED]);

        $this->assertTrue($this->registry->routeableModels('chat')->isEmpty());
    }

    public function test_enabled_provider_and_model_are_routeable(): void
    {
        $p = $this->provider();
        $this->model($p);

        $this->assertSame('acme/model-a', $this->registry->routeableModels('chat')->first()->slug);
    }

    /* ── گیت‌های تجاری ── */

    public function test_commercial_off_blocks_selling_but_not_routing(): void
    {
        $p = $this->provider(['commercial_enabled' => false]);
        $this->model($p);

        $this->assertTrue($this->registry->sellableModels('chat')->isEmpty());
        $this->assertFalse($this->registry->routeableModels('chat')->isEmpty(),
            'مسیردهیِ فنی فروش نیست؛ خاموشیِ تجاری آن را نمی‌بندد');
    }

    public function test_disabled_status_blocks_selling_even_with_commercial_on(): void
    {
        $p = $this->provider(['commercial_enabled' => true]);
        $this->model($p, ['status' => AiModel::STATUS_DISABLED]);

        $this->assertTrue($this->registry->sellableModels('chat')->isEmpty());
    }

    public function test_resale_gate_is_explicit(): void
    {
        $p = $this->provider(['resale_allowed' => false]);
        $this->assertFalse($p->resale_allowed);

        $p->update(['resale_allowed' => true]);
        $this->assertTrue($p->fresh()->resale_allowed);
    }

    public function test_live_capability_requires_enabled_and_flag(): void
    {
        $this->provider(['live_calls_enabled' => false]);
        $this->assertTrue($this->registry->liveCapable()->isEmpty());

        AiProvider::query()->where('slug', 'acme')->update(['live_calls_enabled' => true]);
        $this->assertSame('acme', $this->registry->liveCapable()->first()->slug);
    }

    /* ── ترتیب ── */

    public function test_priority_orders_providers(): void
    {
        $this->provider(['slug' => 'late', 'priority' => 200]);
        $this->provider(['slug' => 'early', 'priority' => 10]);

        $slugs = $this->registry->enabledProviders()->pluck('slug')->values()->all();
        $this->assertSame(['early', 'late'], $slugs);
    }

    public function test_capabilities_default_off_and_readable(): void
    {
        $p = $this->provider();
        $m = $this->model($p, ['capabilities' => ['reasoning' => true, 'vision' => false]]);

        $this->assertTrue($m->capability('reasoning'));
        $this->assertFalse($m->capability('vision'));
        $this->assertFalse($m->capability('tool_calling'), 'قابلیتِ ثبت‌نشده = خاموش');
    }
}

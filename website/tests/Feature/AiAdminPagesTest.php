<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\AiModelUnitPrice;
use App\Models\AiProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * صفحاتِ ادمینِ زیرساختِ AI — M1.
 *
 * روت‌های نوشتنی پشتِ `admin` صریحِ صفرِ&– صفرِ; این‌جا هرگز بالاترِ
 * `admin` (پشتیبان/نویسنده) نمی‌گیرند. دیدن برای همه*؛ نوشتن برای
 * مدیر — و این تست، پیش از آنکه خواب بماند، لنگرش می‌کند.
 */
class AiAdminPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private AiProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);

        $this->provider = AiProvider::create([
            'slug' => 'acme', 'name' => 'Acme', 'driver' => 'Acme',
            'enabled' => false, 'commercial_enabled' => false,
            'resale_allowed' => false, 'agreement_status' => 'none',
            'priority' => 100, 'live_calls_enabled' => false,
        ]);
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

    /* ═══ مجوزِ دسترسی ═══ */

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/ai')->assertRedirect();
    }

    public function test_non_admin_staff_cannot_read_or_write(): void
    {
        $author = User::factory()->create(['role' => 'author']);

        $this->actingAs($author)->get('/admin/ai')->assertForbidden();
        $this->actingAs($author)
            ->post('/admin/ai/pricing/supersede', ['model' => 1, 'unit' => 'input', 'micros' => 1])
            ->assertForbidden();
    }

    public function test_admin_can_open_all_three_pages(): void
    {
        $this->actingAs($this->admin);
        $this->get('/admin/ai')->assertOk()->assertSee('ارائه‌دهنده');
        $this->get('/admin/ai/models')->assertOk();
        $this->get('/admin/ai/pricing')->assertOk();
    }

    /* ═══ ارائه‌دهنده ═══ */

    public function test_admin_can_update_provider_flags(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/ai/providers/'.$this->provider->id, [
                'name' => 'Acme',
                'enabled' => '1',
                'commercial_enabled' => '1',
                'resale_allowed' => null,
                'agreement_status' => 'signed',
                'priority' => 50,
                'live_calls_enabled' => '1',
            ])
            ->assertRedirect();

        $p = $this->provider->fresh();
        $this->assertTrue($p->enabled);
        $this->assertTrue($p->commercial_enabled);
        $this->assertTrue($p->live_calls_enabled);
        $this->assertTrue($p->isAgreedTo());
    }

    public function test_provider_flags_survive_unchecked_boxes(): void
    {
        $this->provider->update(['enabled' => true, 'commercial_enabled' => true]);

        // فرم بدونِ هیچ تیک — همهٔ پرچم‌ها باید خاموش شوند، نه «بی‌تغییر»
        $this->actingAs($this->admin)
            ->post('/admin/ai/providers/'.$this->provider->id, ['agreement_status' => 'none', 'priority' => 100])
            ->assertRedirect();

        $p = $this->provider->fresh();
        $this->assertFalse($p->enabled);
        $this->assertFalse($p->commercial_enabled);
    }

    /* ═══ مدل ═══ */

    public function test_admin_can_edit_safe_model_metadata(): void
    {
        $m = $this->model();

        $this->actingAs($this->admin)
            ->post('/admin/ai/models/'.$m->id, [
                'name' => 'Model A Prime',
                'status' => AiModel::STATUS_ACTIVE,
                'category' => AiModel::CATEGORY_CHAT,
                'context_tokens' => 131072,
                'max_output_tokens' => 8192,
                'claude_code_compatible' => '1',
            ])
            ->assertRedirect('/admin/ai/models');

        $m->refresh();
        $this->assertSame('Model A Prime', $m->name);
        $this->assertSame(131072, $m->context_tokens);
        $this->assertTrue($m->claude_code_compatible);
    }

    public function test_admin_can_toggle_model_status(): void
    {
        $m = $this->model();

        $this->actingAs($this->admin)
            ->post('/admin/ai/models/'.$m->id.'/status', ['status' => AiModel::STATUS_DISABLED])
            ->assertRedirect();

        $this->assertSame(AiModel::STATUS_DISABLED, $m->fresh()->status);
    }

    /* ═══ قیمت — فقط نسخهٔ تازه ═══ */

    public function test_admin_can_supersede_a_price_and_history_stays(): void
    {
        $m = $this->model();

        $this->actingAs($this->admin)
            ->post('/admin/ai/pricing/supersede', [
                'model' => $m->id, 'unit' => AiModelUnitPrice::UNIT_OUTPUT,
                'micros' => 245_000, 'note' => 'قیمتِ شروع',
            ])
            ->assertRedirect()
            ->assertSessionHas('ok');

        $this->assertSame(1,
            AiModelUnitPrice::query()->where('ai_model_id', $m->id)->where('active', true)->count());

        $v1 = AiModelUnitPrice::query()->where('ai_model_id', $m->id)->first();
        $this->assertSame(245_000, $v1->price_micro_units);
        $this->assertSame($this->admin->id, $v1->created_by);

        // نسخهٔ دوم
        $this->actingAs($this->admin)
            ->post('/admin/ai/pricing/supersede', [
                'model' => $m->id, 'unit' => AiModelUnitPrice::UNIT_OUTPUT, 'micros' => 300_000,
            ])
            ->assertRedirect();

        $v1->refresh();
        $this->assertFalse($v1->active, 'قدیمی فقط بسته می‌شود، نه عوض');
        $this->assertSame(245_000, $v1->price_micro_units);
        $this->assertSame(2, AiModelUnitPrice::query()->where('ai_model_id', $m->id)->count());
    }

    public function test_supersede_rejects_invalid_unit_and_zero_price(): void
    {
        $m = $this->model();

        $this->actingAs($this->admin)
            ->post('/admin/ai/pricing/supersede', [
                'model' => $m->id, 'unit' => 'space-oil', 'micros' => 100,
            ])
            ->assertSessionHasErrors('unit');

        $this->actingAs($this->admin)
            ->post('/admin/ai/pricing/supersede', [
                'model' => $m->id, 'unit' => AiModelUnitPrice::UNIT_INPUT, 'micros' => 0,
            ])
            ->assertSessionHasErrors('micros');

        $this->assertSame(0, AiModelUnitPrice::count());
    }

    /* ═══ parity چند-زبانه — قراردادِ سِui ═══ */

    public function test_admin_ai_views_add_no_new_ui_locale_keys(): void
    {
        // قراردادِ پروژه: کلیدِ ui.* تازه باید به هر سه فایل زبان برود.
        // صفحاتِ ادمینِ M1 رشتهٔ فارسیِ مستقیم دارند (همان رسومِ صفحاتِ ادمین)
        // و عمداً هیچ کلیدِ زبانی‌ای نمی‌خوانند — این تست می‌بندد که
        // همین‌طور بماند: یا صفر کلیدِ تازه، یا ترجمهٔ کاملِ سه‌زبانه.
        foreach (glob(resource_path('views/admin/ai/*.blade.php')) as $file) {
            $content = file_get_contents($file);
            $this->assertStringNotContainsString('__(', $content, basename($file).' نباید کلیدِ زبانِ تازه داشته باشد یا باید هر سه فایل را کامل کند');
        }
    }
}

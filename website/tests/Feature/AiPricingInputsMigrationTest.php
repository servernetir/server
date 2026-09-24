<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * مهاجرتِ 000050: دو ستونِ قیمت‌گذاری، هر گام با نگهبانِ خودش.
 *
 * 🔴 `--pretend` باید DDL ِ واقعی را نشان دهد. در حالتِ pretend هر SELECT آرایهٔ
 * خالی می‌دهد؛ نسخهٔ اول نگهبان‌ها را همان‌جا می‌سنجید، `hasTable` false می‌شد و
 * خروجی فقط دو پرس‌وجوی «جدول هست؟» بود — بازبینیِ مالک روی یک هیچِ ظاهری.
 */
class AiPricingInputsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_11_03_000050_ai_pricing_inputs.php');
    }

    public function test_pretend_shows_both_alters_then_real_run_adds_columns_and_rerun_is_a_noop(): void
    {
        Schema::table('ai_providers', fn ($t) => $t->dropColumn('fx_fee_bp'));
        Schema::table('ai_models', fn ($t) => $t->dropColumn('margin_bp'));

        $m = $this->migration();
        $sql = collect(DB::connection()->pretend(fn () => $m->up()))->pluck('query')->implode("\n");

        $this->assertMatchesRegularExpression('/alter table .*ai_providers.* add column .*fx_fee_bp/i', $sql);
        $this->assertMatchesRegularExpression('/alter table .*ai_models.* add column .*margin_bp/i', $sql);
        $this->assertFalse(Schema::hasColumn('ai_providers', 'fx_fee_bp'), 'pretend نباید چیزی بسازد');

        $m->up();
        $this->assertTrue(Schema::hasColumn('ai_providers', 'fx_fee_bp'));
        $this->assertTrue(Schema::hasColumn('ai_models', 'margin_bp'));

        $again = collect(DB::connection()->pretend(fn () => $m->up()))->pluck('query')->implode("\n");
        $this->assertStringNotContainsStringIgnoringCase('alter table', $again);
        $m->up();                                   // اجرای دوباره بی‌خطا
    }
}

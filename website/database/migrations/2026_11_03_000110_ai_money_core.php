<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M5.1b — هستهٔ پولیِ دروازهٔ AI (m5-spec §2.1، §2.2).
 *
 * ═══ `ai_usage` — یک ردیف به ازای هر تلاشِ /v1 که از سدها گذشت ═══
 *
 * همهٔ ورودی‌های قیمت (نرخِ ارز، سربار، حاشیه، مالیات، نرخ‌های خامِ ارائه‌دهنده،
 * قیمت‌های تومانیِ به‌ازای ۱M) **روی خودِ ردیف منجمد** می‌شوند تا هر شارژ بعداً
 * بیت‌به‌بیت از روی خودش بازسازی شود (`ai:explain`). عمداً **بی کلیدِ خارجی**:
 * تاریخچهٔ پول باید از حذفِ مشتری جان سالم به در ببرد، و این سرور با خطای
 * errno 150 سابقه دارد.
 *
 * ═══ چرا نامِ فایل 000110 و نه 000100 ═══
 *
 * شاخهٔ `feature/hourly-credit-transparency` از قبل
 * `2026_11_03_000100_add_hourly_hold_to_services` را روی سرور دارد؛ نامِ یکسانِ
 * پیشوند ترتیب را گیج‌کننده می‌کرد (README، انحرافِ ۲).
 *
 * هر گام نگهبانِ خودش را دارد، بی بازگشتِ زودهنگامِ سراسری (DDL در MariaDB
 * تراکنشی نیست؛ اجرای نیمه‌کاره باید با اجرای دوباره کامل شود). نگهبان‌ها بیرون
 * از pretend سنجیده می‌شوند تا `--pretend` DDL ِ واقعی را نشان دهد.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->real(fn () => Schema::hasTable('ai_usage'))) {
            Schema::create('ai_usage', function (Blueprint $t) {
                $t->id();
                $t->char('public_id', 26)->unique();                 // ULID — به مشتری و API نشان داده می‌شود
                $t->unsignedBigInteger('customer_id');
                $t->unsignedBigInteger('ai_reservation_id')->nullable()->unique();
                $t->unsignedBigInteger('ai_project_id')->nullable();
                $t->unsignedBigInteger('customer_api_token_id')->nullable();
                $t->unsignedBigInteger('ai_provider_id');
                $t->unsignedBigInteger('ai_model_id');
                $t->string('model_slug', 80);
                $t->string('upstream_model', 120);
                $t->string('idempotency_key', 80)->nullable();
                $t->char('request_sha256', 64);
                $t->boolean('stream')->default(false);
                // reserved|sending|streaming|settle_pending|settled|unknown_pending|unknown_charged|released
                $t->string('status', 16);
                $t->string('error_code', 40)->nullable();
                $t->unsignedSmallInteger('upstream_status')->nullable();
                $t->string('upstream_request_id', 120)->nullable();

                $t->unsignedInteger('max_input_tokens');              // I — سقفِ بایت‌محور
                $t->unsignedInteger('max_output_tokens');             // O — max_tokens ِ اجباری
                $t->unsignedInteger('prompt_tokens')->nullable();
                $t->unsignedInteger('cached_tokens')->nullable();
                $t->unsignedInteger('completion_tokens')->nullable();
                $t->unsignedInteger('reasoning_tokens')->nullable();
                $t->unsignedInteger('delta_count')->nullable();       // شاهدِ استریم؛ هرگز مبنای شارژ نیست
                $t->string('usage_source', 12)->nullable();           // provider|recovered|cap|none

                // ── منجمدِ قیمت‌گذاری (D1) ──
                $t->char('price_currency', 3);
                $t->unsignedBigInteger('input_price_id');
                $t->unsignedBigInteger('cached_price_id')->nullable();
                $t->unsignedBigInteger('output_price_id');
                $t->unsignedBigInteger('input_rate_micro');
                $t->unsignedBigInteger('cached_rate_micro')->nullable();
                $t->unsignedBigInteger('output_rate_micro');
                $t->unsignedInteger('fx_rate_toman');
                $t->string('fx_source', 24);                          // scraped|override|ratchet|scraped+stale
                $t->timestamp('fx_at')->nullable();
                $t->unsignedSmallInteger('fee_bp');
                $t->unsignedInteger('margin_bp');
                $t->unsignedSmallInteger('vat_bp');
                $t->string('vat_basis', 20);                          // ir_country|fa_locale|no_country|foreign_country
                $t->unsignedBigInteger('p_input_irt_m');
                $t->unsignedBigInteger('p_cached_irt_m');
                $t->unsignedBigInteger('p_output_irt_m');

                // ── پول ──
                $t->unsignedBigInteger('hold_sell_irt');
                $t->unsignedBigInteger('hold_tax_irt');
                $t->unsignedBigInteger('hold_irt');
                $t->unsignedBigInteger('cost_micro')->nullable();
                $t->unsignedBigInteger('provider_cost_micro')->nullable();
                $t->unsignedBigInteger('cost_irt')->nullable();
                $t->unsignedBigInteger('sell_irt')->nullable();       // درآمد، بی‌مالیات
                $t->unsignedBigInteger('tax_irt')->nullable();
                $t->unsignedBigInteger('charged_irt')->nullable();
                $t->unsignedBigInteger('refunded_irt')->default(0);
                $t->unsignedBigInteger('uncollected_irt')->default(0);
                $t->boolean('needs_review')->default(false);
                $t->string('review_reason', 32)->nullable();          // over_bound|cost_drift|below_cost|unknown|recovered_refund
                $t->unsignedTinyInteger('recover_attempts')->default(0);
                $t->unsignedBigInteger('credit_ledger_id')->nullable()->unique();
                $t->unsignedBigInteger('overage_ledger_id')->nullable()->unique();
                $t->date('day')->nullable();                          // تاریخِ تهرانِ settled_at — سطلِ جمع‌بندی
                $t->unsignedInteger('latency_ms')->nullable();
                $t->unsignedInteger('ttft_ms')->nullable();
                $t->timestamp('decide_by');
                $t->timestamp('sent_at')->nullable();
                $t->timestamp('settled_at')->nullable();
                $t->timestamps();

                $t->index(['customer_id', 'created_at']);
                $t->index(['customer_id', 'day']);
                $t->index(['ai_project_id', 'settled_at']);
                $t->index(['customer_api_token_id', 'settled_at']);
                $t->index(['day', 'ai_provider_id', 'status']);
                $t->index(['status', 'decide_by']);
                $t->index(['customer_id', 'idempotency_key']);
                $t->index(['ai_provider_id', 'sent_at']);
                $t->index(['needs_review', 'created_at']);
            });
        }

        $this->column('ai_reservations', 'pricing_version', fn (Blueprint $t) => $t->unsignedTinyInteger('pricing_version')->default(0));
        $this->column('ai_reservations', 'ai_project_id', function (Blueprint $t) {
            $t->unsignedBigInteger('ai_project_id')->nullable();
            $t->index(['ai_project_id', 'status']);
        });
        $this->column('ai_reservations', 'customer_api_token_id', function (Blueprint $t) {
            $t->unsignedBigInteger('customer_api_token_id')->nullable();
            $t->index(['customer_api_token_id', 'status']);
        });
        $this->column('ai_reservations', 'charged_irt', fn (Blueprint $t) => $t->unsignedBigInteger('charged_irt')->nullable());

        // 000050 این را روی سرور ساخته؛ نگهبان برای نصبِ تازه و CI
        $this->column('ai_providers', 'fx_fee_bp', fn (Blueprint $t) => $t->unsignedSmallInteger('fx_fee_bp')->nullable());
        $this->column('ai_providers', 'daily_cost_cap_micro', fn (Blueprint $t) => $t->unsignedBigInteger('daily_cost_cap_micro')->nullable());
        $this->column('ai_providers', 'paused_at', fn (Blueprint $t) => $t->timestamp('paused_at')->nullable());
        $this->column('ai_providers', 'paused_reason', fn (Blueprint $t) => $t->string('paused_reason', 120)->nullable());
        $this->column('ai_providers', 'usage_lookup_url', fn (Blueprint $t) => $t->string('usage_lookup_url', 255)->nullable());

        $this->column('ai_models', 'margin_bp', fn (Blueprint $t) => $t->unsignedInteger('margin_bp')->nullable());
        $this->column('ai_models', 'suspended_at', fn (Blueprint $t) => $t->timestamp('suspended_at')->nullable());
        $this->column('ai_models', 'suspended_reason', fn (Blueprint $t) => $t->string('suspended_reason', 120)->nullable());
    }

    private function column(string $table, string $column, callable $add): void
    {
        if ($this->real(fn () => Schema::hasTable($table) && ! Schema::hasColumn($table, $column))) {
            Schema::table($table, fn (Blueprint $t) => $add($t));
        }
    }

    /** نگهبان‌ها بیرون از pretend: در pretend هر SELECT خالی برمی‌گردد */
    private function real(callable $probe): bool
    {
        return (bool) DB::connection()->withoutPretending($probe);
    }

    /** بازگشت عمداً کاری نمی‌کند: ستون و جدولِ پولی را با rollback پاک نمی‌کنیم. */
    public function down(): void {}
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M5.1a — دو ورودیِ قیمت‌گذاری که مالک تایپ می‌کند.
 *
 *   ai_providers.fx_fee_bp  سربارِ واقعیِ رساندنِ یک دلار به ارائه‌دهنده
 *                           (کارمزدِ کارت/رمزارز + اسپردِ صرافی). NULL = فروختنی نیست.
 *   ai_models.margin_bp     حاشیهٔ اختصاصیِ مدل. NULL = حاشیهٔ سراسریِ `ai_margin_pct`.
 *
 * ⚠️ چرا ستون و نه کلیدِ تنظیمات: `SettingsController::FIELDS` فهرستِ سفیدِ هر
 * زبانه است و کلیدِ ناشناخته را **بی‌صدا دور می‌ریزد** — سربارِ ارزی که ذخیره
 * نشده و مدیر فکر می‌کند ذخیره شده، یعنی فروش با سربارِ صفر.
 *
 * چرا جدا از `000100` (هستهٔ پولیِ M5.1b): M5.1a پیش‌نمایشِ قیمت را بدونِ دست
 * زدن به مسیرِ /v1 می‌آورد و همین دو ستون را لازم دارد. `000100` هر ستون را پشتِ
 * `hasColumn` می‌گذارد، پس اجرای هر دو به هر ترتیبی بی‌خطر است.
 *
 * هر گام جداگانه نگهبان دارد و بدونِ بازگشتِ زودهنگامِ سراسری (DDL در MariaDB
 * تراکنشی نیست؛ اجرای نیمه‌کاره باید با اجرای دوباره کامل شود). هیچ داده‌ای
 * نوشته نمی‌شود — هر دو ستون NULL شروع می‌شوند، پس فروش بسته می‌ماند.
 *
 * 🔴 نگهبان‌ها بیرون از حالتِ pretend ارزیابی می‌شوند. در `migrate --pretend`
 * هر SELECT آرایهٔ خالی برمی‌گرداند، پس `hasTable` false می‌شد و خروجی فقط دو
 * پرس‌وجوی «جدول هست؟» بود — مالک یک مهاجرتِ ظاهراً بی‌اثر را بازبینی می‌کرد.
 * پرس‌وجوی information_schema فقط‌خواندنی است؛ اجرای واقعی‌اش در pretend بی‌خطر است.
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->needs('ai_providers', 'fx_fee_bp')) {
            Schema::table('ai_providers', function (Blueprint $t) {
                $t->unsignedSmallInteger('fx_fee_bp')->nullable()->after('billing_currency_code');
            });
        }

        if ($this->needs('ai_models', 'margin_bp')) {
            Schema::table('ai_models', function (Blueprint $t) {
                $t->unsignedInteger('margin_bp')->nullable()->after('max_output_tokens');
            });
        }
    }

    private function needs(string $table, string $column): bool
    {
        return (bool) DB::connection()->withoutPretending(
            fn () => Schema::hasTable($table) && ! Schema::hasColumn($table, $column)
        );
    }

    /** بازگشت عمداً کاری نمی‌کند: ستونِ پولی را با rollback پاک نمی‌کنیم. */
    public function down(): void {}
};

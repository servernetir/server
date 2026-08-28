<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * نامِ محصول به انگلیسی و ترکی — یک ستون، نه بازنویسیِ قالب.
 *
 * ═══ 🔴 خرابی‌ای که این می‌بندد (ممیزی نهم، یافتهٔ ۱) ═══
 *
 * هر ۱۳۴ صفحهٔ سفارشِ `/en/order/*` و `/tr/order/*` نامِ محصول را **فارسی**
 * نشان می‌دادند — در `<title>`، در `<h1>`، و در `schema.name`:
 *
 *     /en/order/backup-1 → Buy هاست بکاپ — BK-100 — from €0.63/mo
 *
 * نمونه‌گیری: ۳۰ از ۳۰ صفحه، ۱۰۰٪.
 *
 * ⚠️ علتش نبودِ ترجمه **نبود**: `config/hosting.php` هر سه زبان را دارد
 * (`fa.t`, `en.t`, `tr.t`) و بقیهٔ سایتِ انگلیسی واقعاً ترجمه شده (۰ از ۴۰
 * صفحهٔ غیرسفارش نشتِ فارسی داشت). فقط `SeedHostingProducts` در یک خط
 * `$prod['fa']['t']` را برمی‌داشت و دو زبانِ دیگر را دور می‌ریخت — و از آن
 * لحظه، جدولِ products تنها منبعِ نام بود و راهی به عقب نداشت.
 *
 * ⚠️ چرا دو ستون و نه جدولِ ترجمه: نامِ محصول یک رشتهٔ کوتاه است و به همان
 * ردیف تعلق دارد؛ جدولِ جدا یعنی یک join روی صفحه‌ای که کش می‌شود، برای
 * چیزی که هرگز بیش از سه زبان نمی‌شود.
 *
 * ⚠️ nullable و بی‌پیش‌فرض: ردیفی که ترجمه ندارد باید صریح «ندارم» بگوید تا
 * `displayName()` به فارسی برگردد — نه اینکه رشتهٔ خالی نشان دهد.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'name_en')) {
                $table->string('name_en', 150)->nullable()->after('name');
            }
            if (! Schema::hasColumn('products', 'name_tr')) {
                $table->string('name_tr', 150)->nullable()->after('name_en');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['name_en', 'name_tr']);
        });
    }
};

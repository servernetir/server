<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * منوی هدر و فوتر: **رویه**، نه جایگزینِ config.
 *
 * ═══ چرا رویه و نه انتقالِ کاملِ داده ═══
 *
 * وسوسه این بود که کلِ `config/servernet.php` (مگامنو، خدمات، ابزارها، دانش) و
 * لینک‌های فوتر به جدول منتقل شوند و config حذف شود. سه دلیل جلویش را گرفت:
 *
 * ۱) 🔴 `SiteMenu` گروهِ «موقعیت مکانی»‌ی سرورِ مجازی را **زنده** از کاتالوگ
 *    می‌سازد: هر کشوری که پلن بگیرد خودش در منو می‌آید. با انتقالِ کامل، آن
 *    گروه یک عکسِ منجمد می‌شد و کشورِ تازه دیگر هرگز در منو نمی‌آمد — خرابیِ
 *    ساکتی که ماه‌ها بعد و به‌شکلِ «چرا سنگاپور در منو نیست» پیدا می‌شد.
 *
 * ۲) رویه یعنی حذفِ یک ردیف = برگشت به پیش‌فرضِ سالم. با انتقالِ کامل، یک
 *    حذفِ اشتباهی در پنل، لینکی را برای همیشه می‌بُرد.
 *
 * ۳) تغییرِ منو در کد (مثلاً افزودنِ محصولِ تازه) همچنان با دیپلوی می‌آید و
 *    لازم نیست کسی دستی هم در پنل واردش کند.
 *
 * ═══ 🔴 چرا `path` و نه شمارهٔ ردیف ═══
 *
 * جای هر آیتم در config عوض می‌شود. اگر رویه به «آیتمِ سومِ گروهِ دوم» گره
 * می‌خورد، افزودنِ یک لینک در کد، ویرایش‌های مدیر را **جابه‌جا** می‌کرد: عنوانی
 * که برای «هاست وردپرس» نوشته بود، ناگهان روی «هاست پایتون» می‌نشست. `path` از
 * خودِ هویتِ آیتم (slug/route/anchor) ساخته می‌شود و با جابه‌جایی نمی‌شکند.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('menu_overrides')) {
            return;
        }

        Schema::create('menu_overrides', function (Blueprint $table) {
            $table->id();

            // `mega` | `services` | `tools` | `knowledge` | `footer`
            $table->string('menu', 24);

            // شناسهٔ پایدارِ گره — ساختِ `MenuManager::pathOf()`
            $table->string('path', 191)->unique();

            $table->boolean('visible')->default(true);

            /*
            | ⚠️ `sort` نال‌پذیر است و پیش‌فرضش نال می‌مانَد.
            |
            | نال یعنی «همان‌جا که config می‌گوید». اگر پیش‌فرض صفر بود، اولین
            | ذخیرهٔ یک ردیف (حتی برای تغییرِ عنوان) همهٔ آیتم‌ها را به ابتدای
            | فهرست می‌برد و ترتیبِ منو بی‌آنکه کسی خواسته باشد به‌هم می‌ریخت.
            */
            $table->integer('sort')->nullable();

            // متنِ سه‌زبانه؛ نال = متنِ خودِ config/ترجمه
            $table->string('label_fa')->nullable();
            $table->string('label_en')->nullable();
            $table->string('label_tr')->nullable();
            $table->string('desc_fa')->nullable();
            $table->string('desc_en')->nullable();
            $table->string('desc_tr')->nullable();

            /*
            | لینکِ **افزودهٔ مدیر** (که در config نیست): مقصد و آیکن و والد.
            |
            | ⚠️ مقصد این‌جا به‌شکلِ نامِ روت یا نشانیِ کامل ذخیره می‌شود و
            | `MenuManager` هنگامِ رندر امتحانش می‌کند؛ لینکی که ساخته نشود
            | **رد** می‌شود، نه اینکه استثنا بدهد. فوتر روی هر صفحه است و یک
            | مقصدِ بد یعنی کلِ سایت ۵۰۰ — همان چیزی که مرداد ۱۴۰۵ افتاد.
            */
            $table->json('custom')->nullable();

            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index(['menu', 'visible']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_overrides');
    }
};

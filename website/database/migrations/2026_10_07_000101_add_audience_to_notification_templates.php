<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الگوهای پیام دو مخاطب دارند، نه یکی.
 *
 * ═══ چرا این ستون ═══
 *
 * `notification_templates` از اول برای اعلانِ **مشتری** ساخته شد و همه‌چیزِ
 * لازم را دارد: `bale_body`، `variables` (تگ‌ها) و `is_active`. ولی اعلان‌هایی
 * که به **خودِ مدیر** می‌روند (`AdminNotifier::event`) هیچ‌کدام را نداشتند —
 * ۲۴ فراخوان با عنوان و متنِ سخت‌کد، بی‌هیچ کلیدِ خاموشی.
 *
 * نتیجه‌اش این بود که مدیر نمی‌توانست بگوید «این یکی را دیگر برایم نفرست» یا
 * «متنش را این‌طور بنویس» — و اعلانِ پرتکرارِ بی‌ارزش دقیقاً همان چیزی است که
 * باعث می‌شود اعلانِ مهم هم دیده نشود.
 *
 * ⚠️ ساختنِ جدولِ دومِ موازی وسوسه‌انگیز بود ولی غلط: هر دو مخاطب همان سه چیز
 * را می‌خواهند (روشن/خاموش، متن، تگ). جدولِ دوم یعنی دو صفحهٔ ویرایش، دو
 * موتورِ جایگزینی، و روزی یکی رفعِ اشکال بگیرد و دیگری نه.
 *
 * ⚠️ پیش‌فرض `customer` است تا هر ردیفِ موجود دقیقاً مثلِ امروز رفتار کند.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notification_templates')) {
            return;
        }

        if (Schema::hasColumn('notification_templates', 'audience')) {
            return;
        }

        Schema::table('notification_templates', function (Blueprint $table) {
            $table->string('audience', 16)->default('customer')->after('group');

            // صفحهٔ تنظیمات هر مخاطب را جدا فهرست می‌کند
            $table->index(['audience', 'group']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('notification_templates')
            || ! Schema::hasColumn('notification_templates', 'audience')) {
            return;
        }

        Schema::table('notification_templates', function (Blueprint $table) {
            $table->dropIndex(['audience', 'group']);
            $table->dropColumn('audience');
        });
    }
};

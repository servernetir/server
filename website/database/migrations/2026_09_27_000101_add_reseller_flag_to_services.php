<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «این سرویس یک حسابِ نمایندگی است» — صریح، نه استنتاجی.
 *
 * ═══ چرا یک ستونِ تازه و نه حدس از روی داده‌های موجود ═══
 *
 * درایورِ تحویل باید بداند `createacct` را با `reseller=1` بفرستد یا نه. سه
 * راهِ بی‌مهاجرت وجود داشت و هر سه **بی‌صدا** خراب می‌شوند:
 *
 *  ۱) از `plan` بخوان (`sn_reseller_linux_1`). یعنی تصمیمِ فنی به **نامِ اسلاگِ
 *     بازاریابی** گره بخورد؛ اسلاگِ فردا که `reseller` در آن نباشد، حسابِ
 *     نمایندگی را به حسابِ معمولی تبدیل می‌کند.
 *  ۲) از جدولِ `products` نگاه کن (`plan` → `category`). `services` هیچ
 *     `product_id` ندارد، پس تطبیق رشته‌ای است و اگر پکیج حذف/تغییرِنام داده
 *     باشد `null` می‌دهد — و `null` یعنی «حسابِ معمولی بساز». مشتری پولِ
 *     نمایندگی داده و یک cPanelِ ساده می‌گیرد.
 *  ۳) از `server.type` بخوان. نوعِ سرور می‌گوید **کجا** ساخته شود، نه **چه
 *     چیزی**؛ هاستِ اشتراکی و نمایندگی روی همان نودِ WHM می‌نشینند.
 *
 * پس نیت در لحظهٔ **سفارش** ثبت می‌شود (از `Product::category`) و بعد هرگز
 * دوباره حدس زده نمی‌شود. همان قاعده‌ای که `cloud_plan_id` از آن پیروی می‌کند.
 *
 * ⚠️ پیش‌فرض `false` عمدی است: سرویس‌های موجود همگی حسابِ معمولی‌اند و باید
 * دقیقاً همان بمانند. اگر پیش‌فرض `null` بود، هر شاخهٔ `if ($s->is_reseller)`
 * روی ردیفِ قدیمی همان `false` را می‌داد ولی گزارش‌ها «نمی‌دانیم» را با «نه»
 * قاطی می‌کردند.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('services') || Schema::hasColumn('services', 'is_reseller')) {
            return;
        }

        Schema::table('services', function (Blueprint $table) {
            $table->boolean('is_reseller')->default(false)->after('plan');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('services') || ! Schema::hasColumn('services', 'is_reseller')) {
            return;
        }

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('is_reseller');
        });
    }
};

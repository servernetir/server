<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * شمارهٔ کارت کامل — رمزنگاری‌شده.
 *
 * ⚠ این ستون عمداً دیر اضافه شد و باید بدانید چه چیزی را می‌پذیرید:
 *
 * نگهداری PAN کامل شما را مشمول الزامات PCI DSS می‌کند — رمزنگاری در حالت
 * سکون، مدیریت و چرخش کلید، محدودسازی دسترسی، و ممیزی. و در صورت نشت،
 * ارزشمندترین دادهٔ ممکن است.
 *
 * برای تسویه و بازگشت وجه، شبا و شماره حساب کافی‌اند؛ کارت فقط وسیلهٔ
 * رسیدن به آن دو بود. این ستون به درخواست صریح کارفرما اضافه شده است.
 *
 * محافظت‌هایی که همراهش آمد:
 *   • مقدار با کلید اپ رمزنگاری می‌شود (cast «encrypted» روی مدل)، پس
 *     دامپ دیتابیس به‌تنهایی چیزی لو نمی‌دهد
 *   • هیچ‌جای رابط کاربری نمایش داده نمی‌شود؛ نمایش همچنان BIN و چهار
 *     رقم آخر است
 *   • ستون‌های card_bin و card_last4 حذف نشدند تا جستجو و نمایش هرگز
 *     نیازی به رمزگشایی نداشته باشند
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->binary('card_number_enc')->nullable()->after('card_last4');
            // اسلاگ بانک از روی BIN — تا مرتب‌سازی و فیلتر لازم نباشد
            // هر بار جدول BIN خوانده شود
            $table->string('bank_slug', 24)->nullable()->after('bank_name');
        });
    }

    public function down(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropColumn(['card_number_enc', 'bank_slug']);
        });
    }
};

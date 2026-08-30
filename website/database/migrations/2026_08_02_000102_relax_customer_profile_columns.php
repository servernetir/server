<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * پروفایل صورت‌حساب در لحظهٔ ثبت‌نام ناقص است — و باید باشد.
 *
 * طراحی اولیه فرض کرده بود پروفایل یکجا و کامل پر می‌شود، پس نشانی و موبایل
 * را NOT NULL گرفته بود. ولی جریان واقعی این نیست: کاربر اول حساب می‌سازد،
 * بعد (پیش از اولین فاکتور) نشانی می‌دهد. برای مشتری خارجی هم اصلاً موبایل
 * ایرانی وجود ندارد.
 *
 * جدول‌ها روی سرور ساخته شده‌اند، پس به‌جای دستکاری مهاجرت قبلی — که روی
 * دیتابیس زنده هیچ اثری ندارد — اینجا تغییر داده می‌شود.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_profiles', function (Blueprint $table) {
            $table->string('mobile', 20)->nullable()->change();
            $table->string('address', 500)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('customer_profiles', function (Blueprint $table) {
            $table->string('mobile', 20)->nullable(false)->change();
            $table->string('address', 500)->nullable(false)->change();
        });
    }
};

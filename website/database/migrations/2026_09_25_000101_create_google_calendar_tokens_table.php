<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * اتصالِ حسابِ گوگلِ **هر کاربرِ پنل** — نه یک اتصالِ شرکتی.
 *
 * 🔴 چرا per-user: تقویمِ گوگلِ مدیر **شخصی** است (جلسهٔ دکتر، قرارِ خانوادگی).
 * این صفحه را هر کاربرِ نقشِ `admin` می‌بیند، پس اتصالِ مشترک یعنی رویدادهای
 * شخصیِ یک نفر روی میزِ همه. کارفرما هم همین را انتخاب کرد: «فقط خودم».
 *
 * ⚠️ هر دو توکن **رمزنگاری‌شده**اند (`encrypted` cast). refresh token عملاً
 * یک رمزِ دائمیِ دسترسی به تقویمِ شخصیِ کاربر است؛ نشستنش به‌صورت خام در
 * دیتابیس یعنی هر کسی که یک بار دامپ بگیرد، برای همیشه به تقویمِ او دسترسی
 * دارد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_calendar_tokens', function (Blueprint $table) {
            $table->id();

            // یک اتصال به ازای هر کاربرِ پنل. رفتنِ کاربر = رفتنِ اتصالش.
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();

            // ایمیلِ حسابِ وصل‌شده — فقط برای نمایش («وصل به: x@gmail.com»)
            $table->string('google_email')->nullable();

            // شناسهٔ تقویمِ مقصد؛ `primary` یعنی تقویمِ اصلیِ همان حساب
            $table->string('calendar_id')->default('primary');

            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();

            /*
             * ⚠️ نال‌پذیر و **بدونِ پیش‌فرض**: توکنِ منقضی‌نشده و «نمی‌دانیم کِی
             * منقضی می‌شود» دو چیزِ متفاوت‌اند. پیش‌فرضِ now() یعنی هر ردیفِ
             * ناقص «منقضی» خوانده شود و بی‌دلیل refresh بخورد.
             */
            $table->timestamp('expires_at')->nullable();

            // آخرین همگام‌سازیِ موفق + آخرین خطا — برای عیب‌یابی از خودِ پنل،
            // چون خطای گوگل در لاگِ سرور می‌ماند و کسی نمی‌بیندش.
            $table->timestamp('synced_at')->nullable();
            $table->string('last_error')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_calendar_tokens');
    }
};

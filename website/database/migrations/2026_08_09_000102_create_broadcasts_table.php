<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * اعلان‌های ارسالی به مشتریان — تاریخچهٔ آنچه فرستاده شد.
 *
 * وقتی مدیر به «همهٔ کاربران» یا «یک کاربر خاص» پیام می‌دهد، همان لحظه از
 * کانال پیامک و بله می‌رود؛ ولی یک ردیف این‌جا هم می‌ماند تا معلوم باشد چه
 * چیزی، به چه کسی، کِی و توسط کدام مدیر فرستاده شد. بدون این ثبت، «به همه
 * پیام دادم» یک ادعای بی‌رد است.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broadcasts', function (Blueprint $table) {
            $table->id();
            $table->string('audience', 20);                // all | one | verified | active
            $table->foreignId('customer_id')->nullable();  // فقط وقتی audience=one
            $table->string('title')->nullable();
            $table->text('body');
            $table->unsignedInteger('recipients')->default(0); // چند نفر واقعاً هدف شدند
            $table->foreignId('sent_by')->nullable();      // کدام کارمند (users.id)
            $table->timestamps();
        });

        // ── ریست opcache ──
        // سرور با opcache و validate_timestamps=0 اجرا می‌شود: فایل PHPِ
        // ویرایش‌شده روی دیسک عوض می‌شود ولی بایت‌کدِ قدیمی سرو می‌شود، پس
        // روت‌ها و ویوهای تازه تا ریست شدنِ opcache زنده نمی‌شوند. فایلِ
        // مهاجرت تازه است و از opcache رد نمی‌شود، پس این‌جا امن‌ترین جای
        // ریست است — دقیقاً همان لحظه‌ای که کد تازه دپلوی شده.
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcasts');
    }
};

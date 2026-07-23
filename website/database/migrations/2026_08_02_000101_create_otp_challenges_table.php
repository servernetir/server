<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * کد یک‌بارمصرف — دروازهٔ قبل از هر استعلام پولی.
 *
 * چرا این جدول قبل از فرم ثبت‌نام ساخته می‌شود:
 * هر ثبت‌نام ایرانی ۸۱٬۰۰۰ تومان استعلام دارد (شاهکار ۱۳٬۰۰۰ + هویت ۶۸٬۰۰۰).
 * بدون این دروازه، یک اسکریپت ساده می‌تواند در چند دقیقه میلیون‌ها تومان از
 * اعتبار ما بسوزاند. پس ترتیب تخطی‌ناپذیر است:
 *
 *     موبایل → پیامک → تأیید کد → و تازه بعدش استعلام پولی
 *
 * کد خودش ذخیره نمی‌شود، فقط hash کلیددار. دیتابیس لو برود، کدهای فعال
 * قابل استفاده نیستند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_challenges', function (Blueprint $table) {
            $table->id();

            $table->string('channel', 8);          // sms | email
            $table->string('destination', 190);    // موبایل نرمال‌شده یا ایمیل
            $table->string('purpose', 24);         // register | login | bank | phone_change

            $table->char('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('resends')->default(0);

            $table->string('ip', 45)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            // جستجوی «آخرین چالش فعال این شماره برای این کار»
            $table->index(['destination', 'purpose', 'expires_at']);
            $table->index('created_at');           // برای شمارش سقف روزانهٔ IP
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_challenges');
    }
};

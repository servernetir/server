<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * احراز هویت ایرانی و حساب بانکی.
 *
 * دو تصمیم امنیتی که عمدی‌اند:
 *
 * ۱) شمارهٔ کارت کامل ذخیره نمی‌شود. فقط شش رقم اول (BIN) و چهار رقم آخر که
 *    برای نمایش به کاربر کافی است. نگهداری PAN کامل ما را مشمول الزامات
 *    سنگین PCI می‌کند و هیچ سودی ندارد: بعد از تأیید، چیزی که واقعاً لازم
 *    داریم شبا و شماره حساب است، نه کارت.
 *
 * ۲) نام کاربر از استعلام ثبت احوال می‌آید نه از فرم. برای همین در
 *    identity_verifications ذخیره می‌شود و قابل ویرایش نیست.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // ورودی کاربر — کد ملی رمزنگاری‌شده، با hash برای یکتایی
            $table->binary('national_id_enc');
            $table->char('national_id_hash', 64);
            $table->date('birth_date');
            $table->string('mobile', 20);

            // شاهکار: تطابق کد ملی و موبایل
            $table->boolean('shahkar_matched')->default(false);
            $table->timestamp('shahkar_at')->nullable();

            // نتیجهٔ استعلام هویت — منبع رسمی نام
            $table->string('first_name', 80)->nullable();
            $table->string('last_name', 80)->nullable();
            $table->string('father_name', 80)->nullable();

            $table->string('status', 16)->default('pending'); // pending|verified|failed
            $table->string('fail_reason', 255)->nullable();
            $table->string('provider', 24)->default('zohal');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            // یک کد ملی فقط روی یک حساب تأییدشده می‌نشیند
            $table->unique('national_id_hash');
        });

        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // فقط بخش قابل‌نمایش کارت — PAN کامل ذخیره نمی‌شود
            $table->char('card_bin', 6);
            $table->char('card_last4', 4);

            $table->string('bank_name', 64)->nullable();
            $table->string('account_number', 40)->nullable();
            $table->char('iban', 26)->nullable();          // IR + 24 رقم

            // نامی که بانک برای صاحب کارت داد — برای بازرسی نگه داشته می‌شود
            $table->string('owner_name', 160)->nullable();
            $table->boolean('name_matched')->default(false);

            $table->string('status', 16)->default('pending'); // pending|verified|rejected
            $table->string('reject_reason', 255)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            // یک شبا فقط یک بار در کل سیستم
            $table->unique('iban');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
        Schema::dropIfExists('identity_verifications');
    }
};

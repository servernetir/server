<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حساب‌های دریافتِ **آفلاین** — حوالهٔ ارزی و کیفِ رمزارز.
 *
 * ═══ چرا جدول، و نه چند کلید در `settings` ═══
 *
 * واریزِ ریالی یک حساب دارد و در `settings` نشسته (`bank_sheba`, `bank_card`…).
 * ولی مشتریِ خارجی چند مقصدِ متفاوت لازم دارد — یورو، پوند، لیر — و هر رمزارز
 * روی هر شبکه یک آدرسِ **جدا** می‌خواهد. با کلیدهای تخت، افزودنِ ارزِ بعدی یعنی
 * مهاجرت و دیپلوی؛ با جدول، مدیر خودش از پنل اضافه می‌کند.
 *
 * 🔴 **آدرسِ اشتباهِ رمزارز = پولِ نابودشده.** انتقالِ USDT روی شبکهٔ اشتباه
 * برگشت‌ناپذیر است. برای همین `network` ستونِ جداست و اجباری، نه بخشی از
 * یادداشت: مشتری باید ببیند TRC20 است یا ERC20، و مدیر نتواند بی‌شبکه ثبتش کند.
 *
 * ⚠️ `is_active` هست تا حسابِ بسته‌شده **بایگانی** شود نه حذف: رسیدهای قدیمی
 * به آن ارجاع دارند و حذفِ ردیف، تاریخچهٔ پرداخت را بی‌معنا می‌کند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_accounts', function (Blueprint $table) {
            $table->id();

            // bank = حوالهٔ بانکی · crypto = کیفِ رمزارز
            $table->string('kind', 12)->default('bank');

            // ارزی که این مقصد می‌پذیرد: EUR/GBP/TRY/USDT…
            $table->string('currency_code', 8);

            $table->string('label', 80)->nullable();          // «حساب یورویی آلمان»
            $table->string('holder', 120)->nullable();        // نامِ صاحبِ حساب
            $table->string('bank_name', 120)->nullable();
            $table->string('iban', 64)->nullable();
            $table->string('swift', 24)->nullable();
            $table->string('account_no', 64)->nullable();
            $table->string('country', 60)->nullable();

            // فقط برای رمزارز — ⚠️ شبکه بدونِ آدرس و آدرس بدونِ شبکه بی‌معنی است
            $table->string('network', 32)->nullable();        // TRC20 / ERC20 / BEP20
            $table->string('address', 160)->nullable();

            $table->text('note')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'currency_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_accounts');
    }
};

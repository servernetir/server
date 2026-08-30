<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * استعلام قیمت دامنه — تنها دروازهٔ بین رسیلری و پول.
 *
 * هیچ قیمتی به مشتری نمایش داده نمی‌شود و هیچ مبلغی گرفته نمی‌شود مگر از یک
 * ردیف معتبر و منقضی‌نشدهٔ این جدول آمده باشد. موقع پرداخت دوباره استعلام
 * می‌گیریم و اگر قیمت عوض شده بود، به مشتری می‌گوییم — نه اینکه بی‌صدا
 * مبلغ دیگری بگیریم.
 *
 * cost_amount در واحد فرعی ارز مبدأ است (سنت/سنت یورو) تا اعشار نداشته باشیم.
 * sell_toman همیشه تومان صحیح است.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_quotes', function (Blueprint $table) {
            $table->id();
            $table->string('domain', 253);
            $table->string('tld', 63);
            $table->string('registrar', 24);              // openprovider | ...
            $table->boolean('is_premium')->default(false);

            $table->unsignedBigInteger('cost_amount');    // واحد فرعی ارز رسیلری
            $table->char('cost_currency', 3);
            $table->unsignedBigInteger('sell_toman');     // قیمت فروش نهایی
            $table->unsignedBigInteger('renew_toman')->nullable();

            $table->timestamp('honour_until');            // بعد از این، قیمت بی‌اعتبار است
            $table->json('raw')->nullable();              // پاسخ خام برای رفع اشکال
            $table->timestamps();

            $table->index(['domain', 'honour_until']);
            $table->index('registrar');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_quotes');
    }
};

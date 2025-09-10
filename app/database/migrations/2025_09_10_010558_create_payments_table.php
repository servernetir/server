<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id()->comment('شناسه یکتا پرداخت');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->comment('کاربری که پرداخت را انجام داده است');
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete()->comment('سرویسی که پرداخت برای آن انجام شده (ممکن است برای شارژ کیف پول باشد)');
            $table->decimal('amount', 15, 2)->comment('مبلغ پرداخت');
            $table->string('currency', 10)->default('USD')->comment('واحد پول (USD, EUR, IRR, USDT)');
            $table->enum('method', ['paypal', 'visa', 'mastercard', 'zarinpal', 'crypto', 'wallet'])->comment('روش پرداخت: پی‌پال، ویزا، مسترکارت، زرین‌پال، کریپتو، یا کیف پول');
            $table->string('transaction_id', 191)->nullable()->comment('شناسه تراکنش در درگاه پرداخت');
            $table->string('crypto_address', 191)->nullable()->comment('آدرس کیف پول کریپتو برای پرداخت‌های رمزارز');
            $table->enum('status', ['pending', 'success', 'failed', 'refunded'])->default('pending')->comment('وضعیت پرداخت: در انتظار، موفق، ناموفق، بازگشت وجه');
            $table->timestamp('paid_at')->nullable()->comment('زمان انجام پرداخت موفق');
            $table->timestamp('created_at')->useCurrent()->comment('زمان ایجاد رکورد');
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate()->comment('آخرین بروزرسانی رکورد');
        });
    }

    public function down()
    {
        Schema::dropIfExists('payments');
    }
};
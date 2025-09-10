<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id()->comment('شناسه یکتا تراکنش کیف پول');
            $table->foreignId('wallet_id')->constrained('users')->cascadeOnDelete()->comment('شناسه کیف پول مربوط به کاربر');
            $table->enum('type', ['deposit', 'withdraw', 'refund'])->comment('نوع تراکنش: افزایش اعتبار، برداشت، یا بازگشت وجه');
            $table->decimal('amount', 15, 2)->comment('مبلغ تراکنش');
            $table->string('currency', 10)->default('USD')->comment('واحد پول تراکنش (مثلاً USD, EUR, IRR, USDT)');
            $table->string('description', 255)->nullable()->comment('توضیحات تراکنش');
            $table->enum('status', ['pending', 'success', 'failed'])->default('pending')->comment('وضعیت تراکنش: در انتظار، موفق، ناموفق');
            $table->string('transaction_id', 100)->nullable()->comment('شناسه تراکنش در درگاه پرداخت (در صورت وجود)');
            $table->timestamp('created_at')->useCurrent()->comment('زمان ایجاد تراکنش');
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate()->comment('آخرین زمان بروزرسانی تراکنش');
        });
    }

    public function down()
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
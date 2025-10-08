<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id()->comment('شناسه یکتا فاکتور');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->comment('کاربری که فاکتور برای او صادر شده است');
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete()->comment('سرویسی که فاکتور مربوط به آن است (ممکن است برای شارژ کیف پول باشد)');
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete()->comment('شناسه پرداخت مربوط به این فاکتور (در صورت پرداخت شده)');
            $table->string('invoice_number', 100)->unique()->comment('شماره فاکتور یکتا');
            $table->decimal('amount', 15, 2)->comment('مبلغ فاکتور');
            $table->string('currency', 10)->default('USD')->comment('واحد پول (USD, EUR, IRR, USDT)');
            $table->timestamp('due_date')->comment('تاریخ سررسید فاکتور');
            $table->timestamp('valid_until')->comment('حداکثر زمان اعتبار فاکتور برای پرداخت');
            $table->enum('status', ['unpaid', 'paid', 'overdue', 'cancelled', 'expired'])->default('unpaid')->comment('وضعیت فاکتور: پرداخت نشده، پرداخت شده، معوق، لغو شده، منقضی شده');
            $table->text('notes')->nullable()->comment('توضیحات یا یادداشت‌های مربوط به فاکتور');
            $table->timestamp('created_at')->useCurrent()->comment('زمان ایجاد فاکتور');
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate()->comment('آخرین بروزرسانی فاکتور');
        });
    }

    public function down()
    {
        Schema::dropIfExists('invoices');
    }
};
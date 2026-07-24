<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سرویس‌های فروخته‌شده به مشتری — «خدماتی که بهش فروختیم».
 *
 * این همان چیزی است که کارفرما می‌خواست: در پنل یک سرویس برای مشتری خاص
 * می‌سازد (مثلاً «پشتیبانی ویژه»)، مبلغ و دورهٔ پرداخت (یک‌بار/ماهانه/سالانه)
 * و توضیحات می‌دهد؛ سیستم یک پیش‌فاکتور می‌سازد و پس از پرداخت، سرویس فعال
 * می‌شود و در پنل مشتری دیده می‌شود.
 *
 * سرویس «اشتراک» است؛ هر دورهٔ پرداخت یک فاکتور جدا دارد (invoices.service_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('currency_code', 3)->default('IRT');
            $table->bigInteger('price')->default(0);          // مبلغ هر دوره، پیش از مالیات، واحد فرعی
            $table->unsignedTinyInteger('tax_percent')->default(0);
            // دورهٔ پرداخت: once=یک‌بار، monthly، quarterly، yearly
            $table->string('cycle', 12)->default('once');
            // pending=منتظر پرداخت اولین فاکتور · active · suspended · cancelled · expired
            $table->string('status', 12)->default('pending');
            $table->date('next_due_at')->nullable();          // سررسید دورهٔ بعد (برای اشتراک‌ها)
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable();       // کدام کارمند فروخت
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index(['status', 'next_due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};

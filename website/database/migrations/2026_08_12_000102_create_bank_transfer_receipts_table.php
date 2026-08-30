<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * رسیدِ «واریز به حساب» — پرداختِ دستی که مدیر تأیید می‌کند.
 *
 * جریان: کاربر به حساب شرکت واریز می‌کند، شناسهٔ پیگیری/پرداخت را در فرم
 * ثبت می‌کند → یک رسید «در انتظار» ساخته می‌شود → مدیر در پنل تأیید می‌کند →
 * همان لحظه فاکتور تسویه و سرویس فعال/تمدید یا اعتبار شارژ می‌شود (از همان
 * مسیر settleConfirmed، پس هیچ منطق تسویهٔ موازی‌ای نیست).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_transfer_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id');
            $table->foreignId('invoice_id')->nullable();
            $table->bigInteger('amount');                    // مبلغی که کاربر می‌گوید واریز کرده
            $table->string('reference', 120);                // شناسهٔ پرداخت/پیگیری بانکی
            $table->string('paid_from', 120)->nullable();    // شمارهٔ کارت/حساب مبدأ (اختیاری)
            $table->text('note')->nullable();
            $table->string('status', 12)->default('pending'); // pending | approved | rejected
            $table->string('reject_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable();    // کدام کارمند
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transfer_receipts');
    }
};

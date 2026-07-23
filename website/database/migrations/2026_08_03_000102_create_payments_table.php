<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * پرداخت — یک «تلاش» برای پرداخت یک فاکتور، نه خود پول.
 *
 * یک فاکتور می‌تواند چند ردیف پرداخت داشته باشد: کاربر رفته درگاه، منصرف
 * شده، دوباره زده. فقط یکی از آنها paid می‌شود. اگر پرداخت را روی خود
 * فاکتور نگه می‌داشتیم، تلاش‌های ناموفق ناپدید می‌شدند و وقتی مشتری می‌گفت
 * «پول کم شد ولی ثبت نشد» هیچ ردی نداشتیم.
 *
 * ═══ سه ستون که بدون آنها پول گم می‌شود ═══
 *
 * external_ref  شناسهٔ درگاه (در زرین‌پال Authority). یکتاست، چون callback
 *               فقط همین را برمی‌گرداند و باید بتوانیم دقیقاً یک ردیف پیدا
 *               کنیم. بدون یکتایی، یک Authority می‌تواند دو فاکتور را
 *               تسویه کند.
 *
 * amount        مبلغ در لحظهٔ شروع، منجمد. هنگام verify همین را می‌فرستیم،
 *               نه مبلغ فعلی فاکتور. اگر بین شروع و بازگشت قیمت عوض شود،
 *               باید همانی را تأیید کنیم که کاربر دید.
 *
 * ref_id        شمارهٔ پیگیری بانک. تنها چیزی است که مشتری با آن به بانکش
 *               مراجعه می‌کند؛ نبودش یعنی پیگیری غیرممکن.
 *
 * ═══ آنچه عمداً ذخیره نمی‌شود ═══
 * شمارهٔ کارت کامل. زرین‌پال ماسک‌شده می‌دهد (۶۰۳۷۹۹******۱۲۳۴) و همان
 * کافی است. نگهداری PAN کامل ما را مشمول PCI می‌کند بدون هیچ سودی.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            $table->string('gateway', 24);                   // zarinpal | cryptomus | manual
            $table->char('currency_code', 3);
            $table->bigInteger('amount');                    // واحد فرعی، منجمد

            // pending: ساخته شده، هنوز به درگاه نرفته
            // redirected: کاربر به درگاه فرستاده شد
            $table->string('status', 16)->default('pending'); // pending|redirected|paid|failed|expired|canceled

            $table->string('external_ref', 128)->nullable()->unique();  // Authority
            $table->string('ref_id', 64)->nullable();                   // شمارهٔ پیگیری بانک
            $table->string('card_mask', 32)->nullable();                // فقط ماسک‌شده

            $table->bigInteger('fee')->default(0);
            $table->string('fee_type', 24)->nullable();

            $table->string('error_code', 24)->nullable();
            $table->string('error_message', 255)->nullable();

            // پنجرهٔ اعتبار — بعدش تلاش مرده حساب می‌شود و دوباره قابل شروع است
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();

            // برای بازرسی وقتی اختلاف پیش می‌آید
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->timestamps();

            $table->index(['invoice_id', 'status']);
            $table->index(['customer_id', 'created_at']);
            $table->index('status');
        });

        /*
         * دفتر اعتبار.
         *
         * موجودی به‌صورت یک ستون قابل‌تغییر نگه داشته نمی‌شود. هر تغییر یک
         * سطر است و موجودی جمعِ سطرهاست. دلیلش ساده است: با ستون موجودی،
         * وقتی عدد اشتباه شد هیچ راهی برای فهمیدن «کجا خراب شد» نیست.
         * balance_after فقط برای نمایش و بازرسی سریع است، نه منبع حقیقت.
         */
        Schema::create('credit_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            $table->char('currency_code', 3);
            $table->bigInteger('amount');                 // مثبت = افزایش، منفی = برداشت
            $table->bigInteger('balance_after');

            $table->string('reason', 32);                 // topup | invoice | refund | adjustment
            $table->nullableMorphs('source');             // فاکتور یا پرداختِ مربوطه
            $table->string('note', 255)->nullable();

            $table->timestamps();

            $table->index(['customer_id', 'currency_code', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_ledger');
        Schema::dropIfExists('payments');
    }
};

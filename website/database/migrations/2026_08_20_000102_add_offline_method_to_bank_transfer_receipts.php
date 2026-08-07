<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * رسیدِ واریز باید بگوید **به کجا** واریز شده و **چقدر**.
 *
 * 🔴 تا وقتی فقط یک حسابِ ریالی داشتیم، «به کدام حساب» پرسیدنی نبود. با چند
 * حسابِ ارزی و چند کیفِ رمزارز، رسیدی که مقصدش را نگوید عملاً غیرقابلِ تطبیق
 * است: مدیر باید صورت‌حسابِ چهار حساب را دستی بگردد تا یک شناسهٔ پیگیری را
 * پیدا کند، و اگر پیدا نکرد نمی‌داند مشتری دروغ گفته یا حسابِ اشتباه را نگاه
 * می‌کند.
 *
 * 🔴 `sent_amount`/`sent_currency` جدا از `amount` است چون **می‌توانند فرق
 * کنند**: فاکتور یورویی است ولی مشتری از حسابِ لیری حواله می‌کند. اگر همین یک
 * ستون را نداشته باشیم، مدیر مبلغِ رسیده را با مبلغِ فاکتور مقایسه می‌کند،
 * نمی‌خواند، و پرداختِ درست را رد می‌کند.
 *
 * ⚠️ همه nullable — رسیدهای موجودِ ریالی باید دست‌نخورده معتبر بمانند.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bank_transfer_receipts')) {
            return;
        }

        Schema::table('bank_transfer_receipts', function (Blueprint $table) {
            $table->foreignId('payment_account_id')->nullable()->after('invoice_id');
            $table->bigInteger('sent_amount')->nullable()->after('amount');
            $table->string('sent_currency', 8)->nullable()->after('sent_amount');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('bank_transfer_receipts')) {
            return;
        }

        Schema::table('bank_transfer_receipts', function (Blueprint $table) {
            $table->dropColumn(['payment_account_id', 'sent_amount', 'sent_currency']);
        });
    }
};

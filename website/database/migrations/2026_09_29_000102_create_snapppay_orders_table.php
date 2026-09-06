<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سفارشِ اسنپ‌پی — چرخهٔ عمری که در جدولِ `payments` جا نمی‌شود.
 *
 * ═══ چرا جدولِ جدا و نه چند ستون روی payments ═══
 *
 * یک پرداختِ زرین‌پال دو حالت دارد: شد یا نشد. یک سفارشِ اسنپ‌پی شش حالت
 * دارد و **بعد از** پرداخت هم زنده می‌مانَد: verify → settle → و ماه‌ها بعد
 * ممکن است update یا cancel بخورد. این‌ها روی `payments` یعنی ستون‌هایی که
 * برای بقیهٔ درگاه‌ها همیشه خالی‌اند.
 *
 * ═══ چه چیزی این‌جا **نیست** ═══
 *
 * ⚠️ `paymentToken` این‌جا ذخیره نمی‌شود. همان `payments.external_ref` است —
 * همان‌جایی که زرین‌پال Authority را می‌گذارد و کلِ ماشینِ تسویه از آن‌جا
 * می‌خوانَد. کپی‌کردنِ یک کلید در دو جا یعنی روزی این دو از هم جدا شوند و
 * هیچ‌کدام معلوم نباشد کدام درست است.
 *
 * ═══ transaction_id ═══
 *
 * 🔴 اسنپ‌پی صریحاً خواسته: این شناسه باید در پنلِ ادمینِ پذیرنده **نمایش
 * داده شود و قابلِ جست‌وجو باشد**، چون کلِ ارتباطِ ما و اسنپ‌پی دربارهٔ یک
 * سفارش با همین شماره است. پس ایندکسِ یکتا دارد، نه فقط ستون.
 *
 * قالبش هم مالِ خودشان است: ۵ تا ۱۰ کاراکتر. شمارهٔ فاکتورِ ما پانزده
 * کاراکتر است و قبول نمی‌شود، برای همین شناسهٔ جدا لازم شد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('snapppay_orders', function (Blueprint $table) {
            $table->id();

            // یک سفارش به‌ازای هر تلاشِ پرداخت — و هر تلاش شناسهٔ تازه می‌گیرد
            $table->foreignId('payment_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // شناسه‌ای که با اسنپ‌پی مشترک است — ادمین با همین جست‌وجو می‌کند
            $table->string('transaction_id', 16)->unique();

            /*
             * وضعیت از دیدِ **ما**. وضعیتِ سمتِ اسنپ‌پی در `remote_status`
             * می‌نشیند و عمداً جداست: اگر یکی بودند، اولین مغایرت بی‌صدا
             * پاک می‌شد و دقیقاً همان چیزی است که getPaymentStatus برای
             * پیدا کردنش اجباری شده.
             */
            $table->string('state', 16)->default('pending');
            // pending | verified | settled | canceled | failed

            $table->string('remote_status', 16)->nullable();   // SETTLE|VERIFY|PENDING|CANCEL|REVERT
            $table->timestamp('checked_at')->nullable();

            // مبلغِ لحظهٔ شروع، به **تومان** (واحدِ پایهٔ پروژه). تبدیل به ریال
            // فقط در SnappPayClient انجام می‌شود.
            $table->bigInteger('amount');

            $table->string('mobile', 20)->nullable();

            /*
             * سبدی که واقعاً فرستادیم.
             *
             * 🔴 بدونِ این، `update` غیرممکن است: اسنپ‌پی سبدِ **کامل**ِ تازه
             * می‌خواهد و ما باید بدانیم قبلاً چه فرستاده‌ایم. و در ممیزی هم
             * تنها سندی است که می‌گوید مشتری بابتِ چه چیزی اقساط گرفت.
             */
            $table->json('cart')->nullable();

            $table->timestamp('verified_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('canceled_at')->nullable();

            $table->string('error', 255)->nullable();

            $table->timestamps();

            $table->index(['state', 'created_at']);
            $table->index('customer_id');
        });

        /*
         * ردِ حسابرسیِ هر تماس.
         *
         * 🔴 `update` و `cancel` برگشت‌ناپذیرند و مستنداتِ اسنپ‌پی برایشان
         * تأییدیهٔ ادمین خواسته. تأییدیه بدونِ ثبتِ «چه کسی، کِی، چه چیزی»
         * فقط یک پاپ‌آپ است، نه کنترل. ممیزیِ مالی هم دقیقاً همین را نداشت.
         */
        Schema::create('snapppay_order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapppay_order_id')->constrained()->cascadeOnDelete();

            $table->string('kind', 16);        // token|verify|settle|status|update|cancel
            $table->boolean('ok')->default(false);
            $table->string('message', 255)->nullable();

            // برای update: سبدِ تازه‌ای که فرستادیم — تفاوتش با قبلی همان «چه شد»
            $table->json('payload')->nullable();

            // خالی = سیستم؛ پرشده = ادمینی که دکمه را زد
            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            $table->index(['snapppay_order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('snapppay_order_events');
        Schema::dropIfExists('snapppay_orders');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دفتر مالی کسب‌وکار — منبع واحد حقیقت برای «چقدر سرمایه گذاشتم، چقدر سود
 * کردم، چقدر مالیات گرفتم و دادم».
 *
 * ═══ چرا یک دفتر و نه چند ستون خلاصه ═══
 *
 * اگر «سود» یک عدد ذخیره‌شده بود، وقتی اشتباه می‌شد هیچ راهی برای فهمیدن
 * «کجا خراب شد» نبود. اینجا هر رویداد مالی یک ردیف است و هر عدد داشبورد
 * جمعِ ردیف‌هاست. همان اصلی که در دفتر اعتبار مشتری هم رعایت شد.
 *
 * ═══ مبنا دار بودن ═══
 *
 * درآمد و مالیاتِ گرفته‌شده **خودکار** از پرداخت‌های واقعی ثبت می‌شوند
 * (created_by خالی = سیستم). سرمایه، هزینه، برداشت و پرداخت مالیات را صاحب
 * کسب‌وکار وارد می‌کند (created_by = کاربر). پس هیچ عددی از هوا نیامده.
 *
 * ═══ جهت و نوع ═══
 *
 *   direction: in  → پول وارد کسب‌وکار شد
 *              out → پول خارج شد
 *
 *   kind:
 *     capital        سرمایه‌ای که صاحب گذاشت            (in)
 *     revenue        درآمد فروش، بدون مالیات            (in)
 *     tax_collected  مالیات ارزش‌افزوده که از مشتری گرفتیم (in) — بدهی به دولت
 *     expense        هزینه (سرور، API، دامنه، حقوق…)    (out)
 *     tax_paid       مالیاتی که به دولت دادیم           (out)
 *     withdrawal     برداشت صاحب از کسب‌وکار            (out)
 *     refund         بازگشت وجه به مشتری                (out)
 *     adjustment     اصلاح دستی                         (هر جهت)
 *
 * مالیات ارزش‌افزوده «درآمد» نیست؛ پول دولت است که موقتاً دست ماست. برای
 * همین جدا از revenue نگه داشته می‌شود تا سود واقعی را متورم نکند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_ledger', function (Blueprint $table) {
            $table->id();

            $table->char('currency_code', 3)->default('IRT');
            $table->string('direction', 3);    // in | out
            $table->string('kind', 16);
            $table->string('category', 32)->nullable(); // برای هزینه‌ها

            // همیشه مثبت، در واحد فرعی؛ جهت، علامت را می‌دهد
            $table->bigInteger('amount');

            // به پرداخت/فاکتور واقعی وصل می‌شود وقتی خودکار ثبت شده
            $table->nullableMorphs('source');

            // تاریخِ جابه‌جایی پول — ممکن است با created_at فرق کند
            $table->date('occurred_at');

            $table->string('note', 255)->nullable();

            // خالی = ثبت خودکار سیستم؛ پرشده = ثبت دستی صاحب کسب‌وکار
            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            $table->index(['kind', 'occurred_at']);
            $table->index(['direction', 'occurred_at']);
            $table->index('occurred_at');
            // جلوگیری از ثبت دوبارهٔ درآمدِ یک پرداخت (idempotency)
            $table->unique(['source_type', 'source_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_ledger');
    }
};

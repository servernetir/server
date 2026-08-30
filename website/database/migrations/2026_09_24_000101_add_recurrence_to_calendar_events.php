<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تکرارشوندگی + مبلغ برای رویدادهای دستی.
 *
 * انگیزه‌اش هزینه‌های ثابتِ آینده است: «اجارهٔ دفتر، پنجمِ هر ماه، فلان مبلغ».
 * تا امروز باید دوازده ردیفِ دستی می‌ساختید و سالِ بعد دوباره.
 *
 * 🔴 **ردیفِ تکرارشونده یکی است، نه صدتا.** تکرارها در لحظهٔ نمایش و فقط
 * داخلِ بازهٔ درخواستی باز می‌شوند (`CalendarEvent::occurrencesBetween`). اگر
 * موقعِ ساخت، ردیف‌ها را materialize می‌کردیم، «اجارهٔ ده سالِ آینده» ۱۲۰ ردیف
 * می‌شد و تغییرِ مبلغ یعنی ویرایشِ ۱۲۰ ردیف — همان دام «دو منبعِ حقیقت» که در
 * مهاجرتِ اصلیِ این جدول توضیح داده شد.
 *
 * ⚠️ وضعیتِ «انجام شد» **به‌ازای هر تکرار** است نه کلِ سری: اجارهٔ مرداد
 * پرداخت می‌شود، شهریور هنوز نه. تاریخ‌های انجام‌شده در `meta.done` (آرایهٔ
 * JSON از تاریخِ میلادی) می‌نشینند — ستونِ `meta` از قبل هست، پس این مهاجرت
 * فقط سه ستون اضافه می‌کند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            // none | weekly | monthly | yearly — رشته، نه enum (همان دلیلِ `type`)
            $table->string('repeat', 12)->default('none')->after('event_date');

            // نال یعنی «تا ابد». تاریخِ پایانِ سری (میلادی، مثل event_date)
            $table->date('repeat_until')->nullable()->after('repeat');

            /*
             * مبلغِ اختیاری — واحدِ فرعی و BIGINT، دقیقاً مثلِ بقیهٔ پول در این
             * پروژه. هیچ float و هیچ DECIMAL برای پول.
             */
            $table->bigInteger('amount')->nullable()->after('repeat_until');
            $table->string('currency_code', 3)->nullable()->after('amount');
        });

        // پرس‌وجوی «سری‌های تکرارشونده» باید بدونِ اسکنِ کلِ جدول انجام شود:
        // ردیفِ تکرارشونده ممکن است تاریخِ شروعش سال‌ها پیش باشد و فیلترِ بازه
        // آن را برنمی‌دارد، پس جدا خوانده می‌شود.
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->index(['repeat', 'event_date']);
        });
    }

    public function down(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->dropIndex(['repeat', 'event_date']);
            $table->dropColumn(['repeat', 'repeat_until', 'amount', 'currency_code']);
        });
    }
};

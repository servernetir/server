<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «چرا سرورت را حذف کردی؟» — دادهٔ بازاریابی، اختیاری و بی‌مانع.
 *
 * ═══ چرا دو ستون و نه یکی ═══
 *
 * `terminate_reason` یک **کدِ پایدار** است (`too_expensive`, `support`, …) و
 * `terminate_reason_note` متنِ آزادِ خودِ مشتری. اگر فقط متنِ آزاد ذخیره شود،
 * شش ماه بعد کارفرما انبوهی جملهٔ فارسیِ دست‌نویس دارد که **قابلِ شمارش نیست**
 * — و کلِ هدفِ این کار «چند نفر بابتِ قیمت رفتند» بود، نه خواندنِ تک‌تکِ
 * جمله‌ها. برچسبِ فارسیِ هر کد در `Service::TERMINATE_REASONS` است، پس تغییرِ
 * متنِ نمایشی هیچ‌وقت دادهٔ تاریخی را بی‌معنی نمی‌کند.
 *
 * ⚠️ هر دو nullable و بی‌پیش‌فرض: حذف باید **بدونِ** هیچ دلیلی هم انجام شود.
 * مشتری در آن لحظه از ما ناراضی است و فیلدِ اجباری یک دیوار است — نتیجه‌اش
 * دادهٔ بهتر نیست، تیکتِ عصبانی است. `null` یعنی «نپرسیدیم یا نگفت» و در
 * گزارشِ مدیر صریح شمرده می‌شود.
 *
 * ⚠️ سرویسِ حذف‌شدهٔ قبلی `null` می‌مانَد — درست است؛ آن‌وقت این سؤال پرسیده
 * نمی‌شد و ساختنِ دادهٔ نبوده بدتر از نداشتنش است.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('services')) {
            return;
        }

        Schema::table('services', function (Blueprint $table) {
            // کدِ گزینه — کوتاه و پایدار. طولِ ۳۲ جا برای کدهای بعدی دارد.
            $table->string('terminate_reason', 32)->nullable()->after('cancelled_at');
            // توضیحِ آزادِ مشتری. ۵۰۰ نویسه: به‌اندازهٔ یک پاراگرافِ واقعی،
            // نه آن‌قدر که ستون به انبارِ متن تبدیل شود.
            $table->string('terminate_reason_note', 500)->nullable()->after('terminate_reason');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('services')) {
            return;
        }

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['terminate_reason', 'terminate_reason_note']);
        });
    }
};

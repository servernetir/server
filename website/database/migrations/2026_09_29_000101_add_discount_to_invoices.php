<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تخفیفِ **فاکتور** — یک ستون واقعی، نه عددی که در قیمت حل شده باشد.
 *
 * ═══ چرا لازم شد ═══
 *
 * دو فشار هم‌زمان:
 *
 * ۱) ممیزیِ مالیِ شهریور ۱۴۰۵: تخفیف هیچ ردی نداشت. قیمتِ واحد کم نوشته
 *    می‌شد و «چرا این مشتری ارزان‌تر خرید؟» هیچ جوابی در سیستم نداشت.
 *
 * ۲) قراردادِ سبدِ خریدِ اسنپ‌پی `discountAmount` را **جدا** می‌خواهد:
 *      amount = Σ(count × unit) + tax − discount − externalSource
 *    اگر تخفیف داخلِ قیمتِ واحد پنهان باشد، عددی که به اسنپ‌پی می‌فرستیم با
 *    فاکتورِ خودمان می‌خواند ولی سبد را غلط توصیف می‌کند.
 *
 * ═══ آنچه این ستون **نیست** ═══
 *
 * ⚠️ تخفیفِ **سرویس** همچنان داخلِ `services.price` می‌مانَد و این مهاجرت
 * دستش نمی‌زند. آن یک تصمیمِ عمدی است: تخفیفِ سرویس روی هر تمدید هم اعمال
 * می‌شود، و جدا نگه‌داشتنش یعنی مشتری دورهٔ اول را ارزان می‌دهد و دورهٔ دوم
 * بی‌خبر قیمتِ کامل می‌گیرد. (توضیحش در Admin\ServiceController.)
 *
 * این ستون تخفیفِ **یک‌بارهٔ همین فاکتور** است — کوپن، جبرانِ خرابی، توافقِ
 * موردی.
 *
 * ═══ ریاضی ═══
 *
 *   subtotal = Σ line_total            (ناخالص، پیش از تخفیف)
 *   discount = Σ discountِ ردیف‌ها + تخفیفِ سطحِ فاکتور
 *   tax      = روی مبلغِ **خالص** (پس از تخفیف) — قاعدهٔ ارزش افزودهٔ ایران
 *   total    = subtotal − discount + tax
 *
 * پیش‌فرضِ صفر یعنی فاکتورهای موجود دقیقاً همان‌طور می‌مانند که بودند:
 * total = subtotal + tax. هیچ عددِ تاریخی عوض نمی‌شود.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // همیشه مثبت؛ از total کم می‌شود. واحد فرعی، مثل بقیهٔ پول‌ها.
            $table->bigInteger('discount')->default(0)->after('subtotal');

            // 🔴 «چرا؟» بخشی از خودِ تخفیف است. ممیز نمی‌پرسد چقدر، می‌پرسد بابتِ چه.
            $table->string('discount_note', 190)->nullable()->after('discount');
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->bigInteger('discount')->default(0)->after('line_total');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['discount', 'discount_note']);
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn('discount');
        });
    }
};

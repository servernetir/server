<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بهایِ اجارهٔ سرور — بزرگ‌ترین هزینهٔ جاریِ یک شرکتِ هاستینگ که تا امروز
 * **هیچ ستونی** در این دیتابیس نداشت.
 *
 * ═══ چرا این نبودن گران بود ═══
 *
 * درآمد در `business_ledger` **خودکار** ثبت می‌شود (از هر پرداخت)، ولی هزینه
 * فقط دستی. یعنی «سودِ خالص» در `/admin/finance` عملاً این بود:
 *
 *     درآمدِ واقعی − هزینه‌ای که یادمان مانده وارد کنیم
 *
 * و همیشه به نفعِ خوش‌بینی خطا می‌داد. با این چهار ستون، اجارهٔ سرورها از یک
 * عددِ حافظه‌ای به یک عددِ محاسبه‌شدنی تبدیل می‌شود: هم در پیش‌بینیِ «هزینهٔ
 * در راه» و هم به‌عنوان پایهٔ سودِ ناخالصِ هاستِ اشتراکی.
 *
 * ═══ تصمیم‌های این چهار ستون ═══
 *
 * 🔴 `monthly_cost` در **واحدِ فرعیِ ارزِ خودش** است، مثل هر مبلغِ دیگری در
 * این پروژه: تومان با exponent صفر (یعنی خودِ تومان) و یورو به سنت. هرگز
 * `float`. و هرگز دو ارز را با هم جمع نکن — تبدیل فقط در لحظهٔ نمایش و با
 * نرخِ همان لحظه.
 *
 * 🔴 `cost_currency` لازم است و پیش‌فرضش عمداً **EUR** است: سرورهای آلمان از
 * تأمین‌کنندهٔ اروپایی به یورو اجاره می‌شوند و همان اکثریتِ ماشین‌هاست. اگر
 * پیش‌فرض IRT بود، یک سرورِ ۴۰ یورویی که ارزش فراموش‌کردنِ فیلد را داشته باشد
 * «۴۰ تومان» خوانده می‌شد — عددی آن‌قدر کوچک که در هیچ جمعی به چشم نمی‌آید.
 *
 * ⚠️ `null` بودنِ `monthly_cost` معنای صریح دارد: «نمی‌دانم». صفر یعنی
 * «واقعاً رایگان است» (سرورِ خودمان در دیتاسنترِ خودمان). گزارش این دو را
 * **جدا** نشان می‌دهد، چون یکی‌گرفتنشان یعنی هر سرورِ پرنشده به‌عنوان رایگان
 * وارد جمع شود و هزینهٔ کل کم‌تر از واقع بیاید.
 *
 * ⚠️ `billing_day` روزِ ماهِ **میلادی** است (۱ تا ۲۸)، نه شمسی: صورت‌حسابِ
 * تأمین‌کنندهٔ خارجی با تقویمِ خودش می‌آید. سقف عمداً ۲۸ است تا هر ماهی آن
 * روز را داشته باشد؛ ۳۱ در اسفند/فوریه معنا ندارد و باعث می‌شد یک ماه از
 * پیش‌بینی بیفتد.
 *
 * ⚠️ `vendor` آزاد است و از فهرستِ ثابت نمی‌آید: تأمین‌کننده‌ها عوض می‌شوند و
 * یک enum یعنی مهاجرتِ تازه برای هر قرارداد تازه. ولی **در هیچ صفحهٔ عمومی
 * چاپ نمی‌شود** — سفیدبرچسبیِ این پروژه به آن بند است.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('servers')) {
            return;
        }

        Schema::table('servers', function (Blueprint $table) {
            if (! Schema::hasColumn('servers', 'monthly_cost')) {
                // null = نمی‌دانیم · 0 = واقعاً رایگان. این دو یکی نیستند.
                $table->unsignedBigInteger('monthly_cost')->nullable()->after('max_accounts');
            }

            if (! Schema::hasColumn('servers', 'cost_currency')) {
                $table->char('cost_currency', 3)->default('EUR')->after('monthly_cost');
            }

            if (! Schema::hasColumn('servers', 'billing_day')) {
                $table->unsignedTinyInteger('billing_day')->nullable()->after('cost_currency');
            }

            if (! Schema::hasColumn('servers', 'vendor')) {
                // ⚠️ داخلی. هرگز در ویوِ عمومی چاپ نشود.
                $table->string('vendor', 60)->nullable()->after('billing_day');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('servers')) {
            return;
        }

        Schema::table('servers', function (Blueprint $table) {
            foreach (['monthly_cost', 'cost_currency', 'billing_day', 'vendor'] as $col) {
                if (Schema::hasColumn('servers', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

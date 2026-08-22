<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فروشگاهِ قطعاتِ سرور.
 *
 * 🔴 چرا جدولِ جدا و نه `physical_servers`:
 *
 * آن یکی کاتالوگِ **سرورِ کامل** است — نام، گالری، توضیحِ بازاریابی. این‌جا
 * قطعه است: پردازنده، رم، دیسک، کارتِ شبکه، پاور. قطعه باید **فیلترپذیر** و
 * **مقایسه‌پذیر** باشد، و آن با ستون‌های متنیِ آزاد ممکن نیست.
 *
 * ⚠️ تفاوتِ کلیدی: `attrs` دادهٔ **ماشین‌خوان** است (هسته، گیگابایت، وات) و
 * `specs` دادهٔ **آدم‌خوان**. هر دو لازم‌اند: با اولی فیلتر و مقایسه می‌کنیم،
 * با دومی جدولِ مشخصات را نشان می‌دهیم. یکی‌کردنشان یعنی یا فیلتر از دست
 * می‌رود یا خوانایی.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_parts', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();

            // chassis | cpu | ram | disk | raid | nic | psu | gpu | other
            $table->string('category', 20)->index();
            $table->string('brand', 40)->default('HPE')->index();

            /*
            | نسلِ سازگار — آرایه چون یک قطعه می‌تواند روی چند نسل بنشیند
            | (مثلاً همان رمِ DDR4 روی Gen9 و Gen10).
            |
            | ⚠️ ستونِ `gen` تکی **عمداً نیست**: قطعه‌ای که فقط یک نسل بخورد
            | استثناست نه قاعده، و ستونِ تکی ما را مجبور می‌کرد ردیف تکرار کنیم.
            */
            $table->json('compat_gens')->nullable();

            // new | refurb | used
            $table->string('condition', 12)->default('refurb')->index();

            /*
            | قیمت — پیش‌فرض «استعلام».
            |
            | 🔴 قیمتِ حدسی روی قطعهٔ سرور خطرناک است: خریدارِ فنی قیمت را با
            | بازار می‌سنجد و عددِ غلط یعنی از دست دادنِ اعتماد در همان صفحه.
            | تا وقتی مدیر عددِ واقعی وارد نکرده، «تماس بگیرید» صادقانه‌تر است.
            */
            $table->boolean('price_contact')->default(true);
            $table->unsignedBigInteger('price_irt')->nullable();
            $table->unsignedInteger('price_eur')->nullable();   // سنت

            $table->boolean('in_stock')->default(true);
            $table->boolean('popular')->default(false);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort')->default(0);

            // متنِ سه‌زبانه — هرکدام {fa,en,tr}
            $table->json('name');
            $table->json('tagline')->nullable();
            $table->json('summary')->nullable();
            $table->json('body')->nullable();          // محتوای بلندِ سئو

            /*
            | مشخصاتِ آدم‌خوان: [{label:{fa,en,tr}, value:{fa,en,tr}}]
            | جدولِ صفحهٔ محصول از همین ساخته می‌شود.
            */
            $table->json('specs')->nullable();

            /*
            | مشخصاتِ ماشین‌خوان برای فیلتر و مقایسه: {cores:12, ghz:2.2, …}
            | ⚠️ کلیدها در `ServerPart::ATTR_LABELS` تعریف شده‌اند تا جدولِ
            | مقایسه بداند هر عدد چیست و واحدش چیست.
            */
            $table->json('attrs')->nullable();

            $table->json('gallery')->nullable();

            $table->timestamps();

            // فهرستِ دسته + مرتب‌سازی، پرتکرارترین پرس‌وجوی فروشگاه
            $table->index(['category', 'active', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_parts');
    }
};

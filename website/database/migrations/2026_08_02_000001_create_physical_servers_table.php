<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فروشگاهِ سرورِ فیزیکی — از config به دیتابیس.
 *
 * تا امروز کاتالوگِ سرورِ فیزیکی فقط در `config/servers.php` بود و راهی برای
 * مدیریتش در پنل نبود. این جدول همان داده را به DB می‌آورد تا مدیر بتواند
 * از `/admin/server-shop` مدل اضافه/ویرایش/حذف کند و عکس بارگذاری کند.
 *
 * ⚠️ داده اینجا seed **نمی‌شود** (ستون‌های محتوای غنی در مهاجرتِ بعدی اضافه
 *    می‌شوند). پرکردنِ اولیه با `PhysicalServerSeeder` است که هم روتِ دیپلوی
 *    و هم `db:seed` صدایش می‌زنند و idempotent است (فقط اسلاگِ نبوده را می‌افزاید).
 *    config به‌عنوان منبعِ seed و fallback می‌ماند؛ بعد از این، DB منبعِ اصلی است.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('physical_servers', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('brand', 40)->index();          // hp/dell/lenovo/supermicro (کلید config)
            $table->string('condition', 20)->default('new'); // new / refurb
            $table->boolean('popular')->default(false);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort')->default(0);

            // قیمت: پیش‌فرض «تماس برای استعلام». اگر عددی شد، تومان + یورو(سنت).
            $table->boolean('price_contact')->default(true);
            $table->unsignedBigInteger('price_irt')->nullable();
            $table->unsignedInteger('price_eur')->nullable();  // سنت

            // متن‌های سه‌زبانه — هرکدام {fa,en,tr}
            $table->json('name');
            $table->json('tag');
            $table->json('hero_d');
            $table->json('description');

            // مشخصات: [{label:{fa,en,tr}, fa, en, tr}]
            $table->json('specs')->nullable();
            // گالری: ["/assets/servers/{slug}/1.jpg", …]
            $table->json('gallery')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('physical_servers');
    }
};

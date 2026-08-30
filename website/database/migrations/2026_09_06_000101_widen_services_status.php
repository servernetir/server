<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * پهن‌کردنِ services.status — ریشهٔ «سرویس پس از پرداخت ساخته نمی‌شد».
 *
 * ستون varchar(12) بود ولی مقدارهای واقعیِ ما بلندترند:
 *   awaiting_provision = ۱۸ نویسه
 *   provision_failed   = ۱۶ نویسه
 *
 * MariaDB با خطای «Data too long for column 'status'» کلِ تراکنشِ پرداخت را
 * برمی‌گرداند → مشتری ۵۰۰ می‌دید، فاکتور پرداخت‌نشده می‌ماند و سرویس ساخته
 * نمی‌شد.
 *
 * ⚠️ چرا تست‌ها نگرفتند: تست‌ها روی SQLite اجرا می‌شوند و SQLite طولِ VARCHAR
 * را **اعمال نمی‌کند** (هر رشته‌ای را می‌پذیرد)، ولی MariaDBِ پروداکشن سخت‌گیر
 * است. این تفاوت را باید همیشه در نظر داشت.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('services')) {
            return;
        }

        Schema::table('services', function (Blueprint $table) {
            // ۲۴ برای سرجمعِ آینده هم جا دارد
            $table->string('status', 24)->default('pending')->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('services')) {
            return;
        }

        Schema::table('services', function (Blueprint $table) {
            $table->string('status', 12)->default('pending')->change();
        });
    }
};

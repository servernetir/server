<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مکانِ سرور (ایران/آلمان) تا مشتری در لحظهٔ خرید انتخاب کند.
 *
 * همه‌چیز nullable است تا روی ردیف‌های موجودِ پروداکشن بی‌خطر اجرا شود؛ سرورهای
 * قبلی بدونِ کشور می‌مانند و مدیر از /admin/servers کشورشان را ست می‌کند.
 * نوعِ ستون‌ها عمداً سادهٔ قابل‌حمل است (هم SQLite تست، هم MariaDB پروداکشن).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('servers')) {
            Schema::table('servers', function (Blueprint $table) {
                if (! Schema::hasColumn('servers', 'country')) {
                    $table->string('country', 2)->nullable()->after('name');   // IR | DE
                }
                if (! Schema::hasColumn('servers', 'city')) {
                    $table->string('city', 60)->nullable()->after('country');
                }
            });
        }

        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                // null یعنی «هرجا که سرورِ فعال داریم» — محدودسازی اختیاری است
                if (! Schema::hasColumn('products', 'locations')) {
                    $table->json('locations')->nullable()->after('server_id');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('servers')) {
            Schema::table('servers', function (Blueprint $table) {
                foreach (['country', 'city'] as $col) {
                    if (Schema::hasColumn('servers', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('products') && Schema::hasColumn('products', 'locations')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('locations');
            });
        }
    }
};

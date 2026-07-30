<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ردیابیِ مالکیتِ سرویس در طولِ زمان — خواستهٔ کارفرما:
 * «باید بدانم این سرور در فلان زمان دستِ کی بود؛ کی خرید، کی تمدید کرد، کی
 * غیرفعال شد». تا امروز لاگِ فعالیت فقط به مشتری وصل بود؛ با یک ستونِ
 * `service_id` می‌شود کلِ تاریخچهٔ **یک سرویسِ مشخص** را پرس‌وجو کرد.
 *
 * نال‌پذیر عمداً: رویدادهای غیرِ سرویس (ورود، تغییر رمز، …) service_id ندارند.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('activity_logs')) {
            return;
        }

        Schema::table('activity_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('activity_logs', 'service_id')) {
                $table->foreignId('service_id')->nullable()->after('customer_id');
                // تاریخچهٔ یک سرویس، به‌ترتیبِ زمان: پرس‌وجوی روزمرهٔ پنلِ مدیریت.
                $table->index(['service_id', 'id']);
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('activity_logs') && Schema::hasColumn('activity_logs', 'service_id')) {
            Schema::table('activity_logs', function (Blueprint $table) {
                $table->dropIndex(['service_id', 'id']);
                $table->dropColumn('service_id');
            });
        }
    }
};

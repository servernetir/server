<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * افزودن مکانِ IP (کشور + استان) به لاگِ فعالیت.
 *
 * کنارِ user-agent که از قبل ذخیره می‌شود، حالا کشور و استانِ IP هم ذخیره
 * می‌شود تا کاربر ببیند «از کجا و با چه دستگاهی» وارد شده — حسِ امنیت و
 * حرفه‌ای‌بودن. مکان از ip-api (منبعِ ابزارِ /tools/ip) با کش گرفته می‌شود.
 * ستون‌ها nullable و idempotent‌اند تا روی سرورِ ازقبل‌مهاجرت‌کرده هم امن باشند.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('activity_logs')) {
            return;
        }

        Schema::table('activity_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('activity_logs', 'geo_cc')) {
                $table->string('geo_cc', 2)->nullable()->after('user_agent');       // کد کشور ISO (IR, DE, …)
            }
            if (! Schema::hasColumn('activity_logs', 'geo_country')) {
                $table->string('geo_country', 64)->nullable()->after('geo_cc');     // نام کشور
            }
            if (! Schema::hasColumn('activity_logs', 'geo_region')) {
                $table->string('geo_region', 96)->nullable()->after('geo_country'); // استان/ایالت
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('activity_logs')) {
            return;
        }

        Schema::table('activity_logs', function (Blueprint $table) {
            foreach (['geo_cc', 'geo_country', 'geo_region'] as $col) {
                if (Schema::hasColumn('activity_logs', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

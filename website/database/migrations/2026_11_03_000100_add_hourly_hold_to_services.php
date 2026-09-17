<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * نگهداریِ ۲۴ساعتهٔ سرورِ ساعتی پس از اتمامِ اعتبار — **از پیش ذخیره‌شده**.
 *
 * تا امروز آن ۲۴ ساعت را روی زیرساخت‌هایی که ماشینِ خاموش را صورت‌حساب
 * می‌کنند ما می‌پرداختیم. کارفرما (شهریور ۱۴۰۵): «جایی که برای ما هزینه دارد
 * ما هم هزینه بگیریم… متضرر نشویم اصلاً.» منطق: `App\Services\Cloud\HourlyHold`.
 *
 *   hold_rate_irt     بهای نگهداریِ ماشینِ خاموش برای ما — تومان/ساعت (۰ = رایگان)
 *   hold_reserve_irt  پولی که همین حالا برای نگهداریِ این سرور کنار گذاشته شده
 *                     (`Wallet::reservedOf` آن را از «در دسترس» کم می‌کند)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('services') || Schema::hasColumn('services', 'hold_reserve_irt')) {
            return;
        }

        Schema::table('services', function (Blueprint $table) {
            $table->unsignedBigInteger('hold_rate_irt')->default(0)->after('on_credit_out');
            $table->unsignedBigInteger('hold_reserve_irt')->default(0)->after('hold_rate_irt');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('services') || ! Schema::hasColumn('services', 'hold_reserve_irt')) {
            return;
        }

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['hold_rate_irt', 'hold_reserve_irt']);
        });
    }
};

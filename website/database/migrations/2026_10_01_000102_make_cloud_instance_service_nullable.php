<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `cloud_instances.service_id` را nullable می‌کند.
 *
 * ═══ چرا ═══
 *
 * تا امروز هر نمونه‌ی ابری ۱:۱ با یک سرویسِ مشتری بود (`service_id` غیرِنال و
 * unique). ولی ماشین‌های **زیرساختیِ** اکسیت — Selmi، Personal، و هر VMی که از
 * پنل «وارد» می‌کنیم — خریدِ مشتری نیستند و سرویس ندارند. برای اینکه این‌ها هم
 * ردیف داشته باشند و در سیستمِ اکسیت (سوییچِ کشور، port-forward) مدیریت شوند،
 * `service_id` باید nullable شود.
 *
 * ⚠️ ایندکسِ **unique دست‌نمی‌خورد**: هم SQLite هم MariaDB چند NULL را در ایندکسِ
 * یکتا می‌پذیرند، پس چند «یتیم» ممکن است ولی هر service_idِ غیرِنال هنوز یکتاست
 * (همان محافظِ «دو نمونه برای یک سرویس» که کدِ تحویل رویش حساب می‌کند).
 *
 * ⚠️ کاملاً افزایشی و بی‌ازدست‌رفتنِ داده: ردیف‌های موجود که service_id دارند
 * دست‌نمی‌خورند؛ فقط ستون اجازه‌ی NULL می‌گیرد.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cloud_instances')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            // Laravel جدول را بازمی‌سازد و ایندکس‌ها (از جمله unique) را نگه می‌دارد.
            Schema::table('cloud_instances', function (Blueprint $table) {
                $table->unsignedBigInteger('service_id')->nullable()->change();
            });
        } else {
            // MariaDB/MySQL: MODIFY ستون را nullable می‌کند و به ایندکس دست نمی‌زند.
            DB::statement('ALTER TABLE cloud_instances MODIFY service_id BIGINT UNSIGNED NULL');
        }
    }

    public function down(): void
    {
        // عمداً no-op: اگر ردیفِ یتیمی (service_id=null) ساخته شده باشد، برگرداندن
        // به NOT NULL شکست می‌خورد. برگشت با حذفِ آن ردیف‌ها دستی است.
    }
};

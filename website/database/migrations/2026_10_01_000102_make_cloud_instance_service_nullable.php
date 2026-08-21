<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `cloud_instances.service_id` را nullable می‌کند.
 *
 * ═══ چرا ═══
 *
 * تا امروز هر نمونهٔ ابری ۱:۱ با یک سرویسِ مشتری بود (`service_id` غیرِنال و
 * unique). ولی ماشین‌های **زیرساختیِ** اکسیت — و هر VMی که از پنل «وارد»
 * می‌کنیم — خریدِ مشتری نیستند و سرویس ندارند. برای اینکه این‌ها هم ردیف
 * داشته باشند و در سیستمِ اکسیت (سوییچِ کشور، port-forward) مدیریت شوند،
 * `service_id` باید nullable شود.
 *
 * ⚠️ ایندکسِ **unique دست نمی‌خورد**: هم SQLite و هم MariaDB چند NULL را در
 * ایندکسِ یکتا می‌پذیرند، پس چند «یتیم» ممکن است ولی هر `service_id`ِ غیرِنال
 * هنوز یکتاست — همان محافظِ «دو نمونه برای یک سرویس» که کدِ تحویل رویش حساب
 * می‌کند و بی‌آن یک سرویس می‌توانست دو سرورِ واقعی بخرد.
 *
 * ⚠️ کاملاً افزایشی و بی‌ازدست‌رفتنِ داده: ردیف‌های موجود که `service_id`
 * دارند دست نمی‌خورند؛ فقط ستون اجازهٔ NULL می‌گیرد.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cloud_instances')) {
            return;
        }

        Schema::table('cloud_instances', function (Blueprint $table) {
            $table->unsignedBigInteger('service_id')->nullable()->change();
        });
    }

    /**
     * برگشت عمداً ستون را به NOT NULL برنمی‌گرداند.
     *
     * اگر ماشینِ زیرساختیِ بی‌سرویس ثبت شده باشد، سفت‌کردنِ دوبارهٔ ستون یا
     * شکست می‌خورد یا آن ردیف‌ها را می‌کُشد. برگشتِ بی‌خطر یعنی هیچ کاری نکردن.
     */
    public function down(): void
    {
        // no-op — بخشِ up افزایشی است و برگشتش داده را نابود می‌کند.
    }
};

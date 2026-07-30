<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * غیرفعال‌سازیِ **دستیِ مدیر** — جدا از `is_active`.
 *
 * ═══ چرا ستونِ جدا و نه همان `is_active` ═══
 *
 * `is_active` مالِ **همگام‌سازی** است: هر اجرا پلنی که ارائه‌دهنده دارد را
 * `true` و پلنی که برداشته را `false` می‌کند. اگر مدیر روی همان ستون بنویسد،
 * **اجرای بعدیِ کرون کارش را بی‌صدا برمی‌گردانَد** — پکیجی که عمداً بسته بود،
 * دو روز بعد خودش باز می‌شود و فروخته می‌شود.
 *
 * این همان کلاسِ خطاست که در این پروژه چند بار گران تمام شد: دو منبعِ حقیقت
 * روی یک ستون. پس دو ستون:
 *
 *   `is_active`      → واقعیتِ ارائه‌دهنده (مالِ سینک)
 *   `admin_disabled` → تصمیمِ مدیر (سینک **هرگز** لمسش نمی‌کند)
 *
 * و `scopeSellable` هر دو را می‌سنجد.
 *
 * برای مکان، ستونِ تازه لازم نیست: `syncLocations` عمداً `is_active` را فقط
 * برای ردیفِ **تازه** می‌نویسد، پس تصمیمِ مدیر روی مکان‌های موجود حفظ می‌شود.
 * (همان‌جا در کد مستند شده.)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cloud_plans') && ! Schema::hasColumn('cloud_plans', 'admin_disabled')) {
            Schema::table('cloud_plans', function (Blueprint $table) {
                $table->boolean('admin_disabled')->default(false)->after('is_active')->index();
                // یادداشتِ مدیر: «گران بود» / «شکایت داشتیم» — تا شش ماه بعد
                // یادش بماند چرا بسته است.
                $table->string('admin_note', 190)->nullable()->after('admin_disabled');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('cloud_plans')) {
            return;
        }

        Schema::table('cloud_plans', function (Blueprint $table) {
            foreach (['admin_disabled', 'admin_note'] as $col) {
                if (Schema::hasColumn('cloud_plans', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

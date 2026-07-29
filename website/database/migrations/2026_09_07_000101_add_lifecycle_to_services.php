<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ردگیریِ چرخهٔ تمدید روی هر سرویس.
 *
 * بدونِ این ستون‌ها، کرونِ یادآوری هر بار که اجرا می‌شد دوباره پیام می‌فرستاد
 * (روزی چند بار «۳ روز مانده»). این‌ها می‌گویند «تا کجا پیش رفته‌ایم».
 *
 * همه nullable و بی‌پیش‌فرضِ سنگین، تا روی ردیف‌های موجودِ پروداکشن بی‌خطر
 * اجرا شود.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('services')) {
            return;
        }

        Schema::table('services', function (Blueprint $table) {
            // آخرین «مرحلهٔ» یادآوری که فرستاده شده: ۷ یا ۳ یا ۱ روز مانده.
            // با تمدیدِ موفق صفر می‌شود تا دورهٔ بعد از نو شمرده شود.
            if (! Schema::hasColumn('services', 'reminder_stage')) {
                $table->unsignedTinyInteger('reminder_stage')->nullable()->after('next_due_at');
            }

            // لحظهٔ تعلیقِ خودکار به‌خاطرِ عدمِ پرداخت (با تعلیقِ دستیِ مدیر فرق
            // دارد؛ این یکی با پرداخت خودکار برداشته می‌شود).
            if (! Schema::hasColumn('services', 'suspended_at')) {
                $table->timestamp('suspended_at')->nullable()->after('reminder_stage');
            }

            // به مدیر گفتیم «۳۰ روز مهلت تمام شد، تصمیم بگیر» — یک‌بار، نه هر روز.
            if (! Schema::hasColumn('services', 'grace_alert_at')) {
                $table->timestamp('grace_alert_at')->nullable()->after('suspended_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('services')) {
            return;
        }

        Schema::table('services', function (Blueprint $table) {
            foreach (['reminder_stage', 'suspended_at', 'grace_alert_at'] as $col) {
                if (Schema::hasColumn('services', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

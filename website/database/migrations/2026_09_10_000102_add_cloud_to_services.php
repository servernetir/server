<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * پیوندِ سرویس به سرورِ ابری.
 *
 * چرا `server_id` کافی نبود: جدولِ `servers` سرورهای **خودمان** است (WHM،
 * DirectAdmin) که مدیر دستی ثبت می‌کند. سرورِ ابری اصلاً پیش از خرید وجود ندارد
 * — لحظهٔ پرداخت ساخته می‌شود. پس سرویس به **پلن** اشاره می‌کند نه به سرور، و
 * `ProvisioningService` از پُر بودنِ همین ستون می‌فهمد که مسیرِ ابری را برود.
 *
 * `cloud_image_key` انتخابِ مشتری در لحظهٔ خرید است (`ubuntu-24.04`) — کلیدِ
 * یکسان‌شدهٔ ما، نه شناسهٔ زیرساخت. ترجمه‌اش سرِ تحویل انجام می‌شود، پس اگر
 * تحویل به زیرساختِ دیگری بیفتد، همان سیستم‌عامل نصب می‌شود.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('services')) {
            return;
        }

        Schema::table('services', function (Blueprint $table) {
            if (! Schema::hasColumn('services', 'cloud_plan_id')) {
                $table->unsignedBigInteger('cloud_plan_id')->nullable()->after('server_id')->index();
            }
            if (! Schema::hasColumn('services', 'cloud_image_key')) {
                $table->string('cloud_image_key', 64)->nullable()->after('cloud_plan_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('services')) {
            return;
        }

        Schema::table('services', function (Blueprint $table) {
            foreach (['cloud_plan_id', 'cloud_image_key'] as $col) {
                if (Schema::hasColumn('services', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

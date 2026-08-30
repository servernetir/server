<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| هزینهٔ راه‌اندازی (setup fee) — بهایِ تمام‌شده به سنتِ یورو، با کارمزدِ
| انتقال داخلش (همان قراردادِ cost_eur_cents).
|
| چرا لازم شد: خطِ استانداردِ سرورِ اختصاصیِ زیرساختِ ۷ (EX/AX) هزینهٔ نصبِ
| یک‌بارهٔ واقعی دارد و فاز ۱ کلاً ردش می‌کرد — ۳۴ محصولِ فروختنی بیرون
| می‌ماند. با این ستون، هزینهٔ نصب در **فاکتورِ اول** از مشتری گرفته می‌شود
| و تمدیدها بدونِ آن صادر می‌شوند.
|
| 🔴 `null` یعنی «ندارد»، نه صفر — و این ستون هرگز به JSONِ مشتری نمی‌رسد
| (CloudPlan::$hidden)؛ قیمتِ فروشِ راه‌اندازی در لحظه با همان زنجیرهٔ
| «بها × (۱+حاشیه) × نرخِ روز» ساخته می‌شود (CloudPlan::setupIrt).
*/
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cloud_plans') || Schema::hasColumn('cloud_plans', 'setup_eur_cents')) {
            return;
        }

        Schema::table('cloud_plans', function (Blueprint $table) {
            $table->unsignedInteger('setup_eur_cents')->nullable()->after('cost_eur_cents');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('cloud_plans', 'setup_eur_cents')) {
            Schema::table('cloud_plans', function (Blueprint $table) {
                $table->dropColumn('setup_eur_cents');
            });
        }
    }
};

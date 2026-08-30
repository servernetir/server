<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ردگیریِ **تغییرِ بهایِ تمام‌شده** — محافظِ قیمتِ تمدید.
 *
 * ═══ مشکلی که این حل می‌کند ═══
 *
 * قیمتِ فروشِ مشتری سرِ سفارش **قفل** می‌شود و سرویس خودکار تمدید می‌شود. اگر
 * زیرساخت بهایش را بالا ببرد، ما همان قیمتِ قدیم را فاکتور می‌کنیم و از آن
 * لحظه **هر تمدید ضررِ خالص** است — ماه‌به‌ماه، بی‌صدا، چون سرور کار می‌کند و
 * مشتری راضی است. تا رسیدنِ صورت‌حسابِ زیرساخت هیچ‌چیز صدا در نمی‌آورد.
 *
 * پس هر همگام‌سازی، بهای تازه با بهای قبلی مقایسه می‌شود:
 *  • `previous_cost_eur_cents` بهای پیش از این تغییر
 *  • `cost_changed_at` کِی عوض شد
 *
 * با این دو، `cloud:sync` می‌تواند بگوید «۷ پلن گران‌تر شدند» و مدیر بداند کدام
 * سرویس‌های فعال باید بازقیمت‌گذاری شوند. بی‌این، افزایشِ بها فقط در ستونِ
 * `cost_eur_cents` می‌نشست و هیچ‌کس نمی‌فهمید که عوض شده.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cloud_plans')) {
            return;
        }

        Schema::table('cloud_plans', function (Blueprint $table) {
            if (! Schema::hasColumn('cloud_plans', 'previous_cost_eur_cents')) {
                $table->unsignedBigInteger('previous_cost_eur_cents')->nullable()->after('cost_eur_cents');
            }
            if (! Schema::hasColumn('cloud_plans', 'cost_changed_at')) {
                $table->timestamp('cost_changed_at')->nullable()->after('previous_cost_eur_cents');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cloud_plans')) {
            return;
        }

        Schema::table('cloud_plans', function (Blueprint $table) {
            foreach (['previous_cost_eur_cents', 'cost_changed_at'] as $col) {
                if (Schema::hasColumn('cloud_plans', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| بهایِ **ساعتیِ** واقعیِ زیرساخت — ستونِ جدا از بهایِ ماهانه.
|
| ═══ چرا (۶ شهریور ۱۴۰۵ — رخدادِ sn-svc-76) ═══
|
| قیمتِ ساعتیِ فروشِ ما «ماهانه ÷ ۷۲۰» بود، ولی زیرساختی مثلِ aeza برای
| صورت‌حسابِ ساعتی نرخِ جدا و ~۳ برابری دارد (LND-1: ماهانه €۱۲٫۱۸ ولی
| ساعتی €۰٫۰۵) — و تحویلِ سرویسِ ساعتی هم عمداً با `term=hour` می‌خرد.
| نتیجه: مشتری €۰٫۰۲/ساعت می‌داد و ما €۰٫۰۵/ساعت — ضررِ نقد روی هر ساعت،
| بی‌هیچ خطایی. کارفرما خودش در پنلِ زیرساخت دید.
|
| واحد **میکرو‌یورو** است نه سنت: ساعتیِ هتزنر زیرِ یک سنت است (€۰٫۰۰۶۳)
| و در سنت گرد می‌شد به صفر یا دو برابر.
*/
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cloud_plans') || Schema::hasColumn('cloud_plans', 'cost_hour_eur_micro')) {
            return;
        }

        Schema::table('cloud_plans', function (Blueprint $table) {
            // null یعنی «زیرساخت نرخِ ساعتی اعلام نکرده»، نه صفر
            $table->unsignedInteger('cost_hour_eur_micro')->nullable()->after('cost_eur_cents');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('cloud_plans', 'cost_hour_eur_micro')) {
            Schema::table('cloud_plans', fn (Blueprint $t) => $t->dropColumn('cost_hour_eur_micro'));
        }
    }
};

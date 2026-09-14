<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دفترِ ناوگانِ زیرساخت — یک ردیف به‌ازای هر ماشینی که **پولش را ما می‌دهیم**.
 *
 * چرا این جدول لازم شد و چرا گزارشِ زندهٔ `CloudInventory` کافی نبود:
 *
 * ۱. **گزارشِ زنده حافظه ندارد.** «این سرورِ بی‌مشتری از کِی بی‌مشتری است؟»
 *    گران‌ترین سؤالِ این حوزه است و جوابش فقط از مقایسهٔ دو اسکن درمی‌آید. بی‌آن،
 *    یک سرورِ فراموش‌شده و یک سرورِ ده‌دقیقه‌پیش‌ساخته‌شده در گزارش یک‌شکل‌اند و
 *    مدیر نمی‌داند کدام واقعاً پول سوزانده.
 * ۲. **گزارشِ زنده کُند است.** هر بار چند تماسِ صفحه‌بندی‌شده با همهٔ زیرساخت‌ها.
 *    صفحه‌ای که مدیر روزی چند بار باز می‌کند نباید هر بار ۱۰ ثانیه منتظر بماند،
 *    و جست‌وجو/مرتب‌سازی روی داده‌ای که هر بار از نو کشیده می‌شود ممکن نیست.
 * ۳. **گزارشِ زنده جای یادداشت ندارد.** بعضی «یتیم»ها عمدی‌اند (سرورِ مانیتورینگ،
 *    ماشینِ آزمایش). بی‌جایی برای علامت‌زدنشان، هر اسکن همان چند ردیف را دوباره
 *    هشدار می‌دهد و مدیر بعد از بارِ سوم کلِ گزارش را نادیده می‌گیرد — یعنی
 *    هشدارِ واقعیِ بعدی هم دیده نمی‌شود.
 *
 * ⚠️ این جدول **حقیقت نیست، عکسِ حقیقت است.** منبعِ حقیقت همیشه خودِ زیرساخت و
 * جدولِ `cloud_instances` است. هیچ‌جای برنامه نباید از این‌جا بخواند تا تصمیمِ
 * تحویل/صورت‌حساب بگیرد؛ فقط تحلیل و پیگیری. برای همین هیچ کلیدِ خارجیِ سختی
 * ندارد: پاک‌شدنِ یک سرویس نباید ردِ تاریخیِ ماشینش را از این‌جا ببرد — دقیقاً
 * همان حالتی که این ابزار برای کشفش ساخته شده.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('infra_assets')) {
            return;
        }

        Schema::create('infra_assets', function (Blueprint $table) {
            $table->id();

            // ── هویتِ ماشین نزدِ زیرساخت ──
            $table->string('provider', 24);
            $table->string('provider_ref', 120);
            $table->string('name', 190)->nullable();
            $table->string('ipv4', 45)->nullable();
            $table->string('ipv6', 64)->nullable();
            $table->string('plan_ref', 96)->nullable();
            $table->string('location_ref', 96)->nullable();
            $table->string('provider_status', 24)->default('unknown');
            $table->timestamp('provider_created_at')->nullable();

            // ── اتصالِ تجاری ──
            // بی‌کلیدِ خارجی: ردیفِ ماشین باید بعد از حذفِ سرویس هم بماند، چون
            // «سرویسش رفته ولی ماشینش هست» همان چیزی است که دنبالش می‌گردیم.
            $table->unsignedBigInteger('service_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('service_status', 24)->nullable();

            /*
            | وضعیتِ اتصال — چهار حالت، و مرزهایشان عمداً تیز است:
            |
            |   attached  ماشین هست، سرویسِ زنده‌ای دارد → سالم
            |   orphan    ماشین هست، هیچ سرویسی نمی‌شناسدش → ما پول می‌دهیم، کسی نمی‌دهد
            |   zombie    ماشین هست، سرویسش بسته شده → مشتری رفته، اجاره مانده
            |   ghost     سرویس ماشین را ادعا می‌کند، زیرساخت نمی‌شناسدش → فاکتورِ هوا
            |
            | ⚠️ `zombie` در گزارشِ زنده یک **پرچم** روی ردیفِ «متصل» است
            | (`service_dead`). این‌جا عمداً یک حالتِ مستقل شد، چون فیلتر،
            | مرتب‌سازی و شمارشِ سنِ رهاشدگی همه روی `link_state` کار می‌کنند و
            | حالتی که فقط یک پرچم باشد، در هیچ‌کدامشان دیده نمی‌شود.
            */
            $table->string('link_state', 12)->default('orphan');
            $table->boolean('ip_mismatch')->default(false);

            // ── پول ──
            // بهایِ ماهانهٔ تخمینی به سنتِ یورو. صفر یعنی «نمی‌دانم» و در جمع‌ها
            // به‌عنوانِ صفر می‌آید، ولی جدا هم شمرده می‌شود تا «۰ یورو» با
            // «۱۲ ماشینِ بی‌قیمت» اشتباه گرفته نشود.
            $table->unsignedInteger('cost_eur_cents')->default(0);
            $table->string('cost_source', 12)->default('unknown'); // plan | service | manual | unknown

            // ── طبقه‌بندیِ دستیِ مدیر ──
            // یتیمِ عمدی (سرورِ خودمان) از یتیمِ فراموش‌شده جدا می‌شود؛ بی‌این،
            // هشدارها بی‌اعتبار می‌شوند.
            $table->string('role', 16)->default('unknown'); // unknown|internal|staging|reserve|customer|decommission
            $table->text('note')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->unsignedBigInteger('acknowledged_by')->nullable();

            // ── حافظهٔ زمانی: بدونِ این‌ها ابزار فقط یک عکس است ──
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            // از کِی بی‌صاحب است؛ مبنای «چقدر پول سوخته»
            $table->timestamp('unlinked_since')->nullable();
            // از کِی زیرساخت دیگر نمی‌شناسدش (برای شبح‌ها)
            $table->timestamp('missing_since')->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_ref'], 'infra_assets_unique_ref');
            $table->index(['link_state', 'provider']);
            $table->index('ipv4');
            $table->index('service_id');
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('infra_assets');
    }
};

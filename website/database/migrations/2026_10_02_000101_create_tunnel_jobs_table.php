<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * صفِ کارهای روترِ مشتری، و ثبتِ ایجنتی که آن‌ها را برمی‌دارد.
 *
 * ═══ چرا جدول و نه کلیدی در همان `meta` ═══
 *
 * وسوسه‌اش هست: پروفایلِ تونل از قبل داخلِ `cloud_instances.meta` است و افزودنِ
 * یک آرایهٔ `jobs` هیچ مهاجرتی نمی‌خواست. ولی آن بلوب را دو نویسندهٔ **هم‌زمان**
 * دست می‌زنند — APIِ مشتری که اکانت می‌سازد، و ایجنتِ روتر که هر ۳۰ ثانیه
 * وضعیت را برمی‌گردانَد. هر دو الگوی «بخوان، تغییر بده، کلِ بلوب را بنویس»
 * دارند، پس یکی دیگری را بی‌صدا پاک می‌کند: اکانتی که مشتری همین الان ساخت
 * ناپدید می‌شود و **هیچ خطایی تولید نمی‌شود**.
 *
 * ═══ 🔴 چرا ضربانِ ایجنت در `settings` نرفت ═══
 *
 * الگوی موجودِ پروژه برای ضربانِ ایجنتِ ایران `Setting::put('agent_seen_…')`
 * است و در آن‌جا درست است، چون **یک** ایجنت است و چند دقیقه یک بار می‌نویسد.
 * این‌جا هر مشتری یک روتر دارد و هر روتر هر ۳۰ ثانیه می‌پرسد — و
 * `Setting::put()` در هر فراخوان `Cache::forget('settings.all')` می‌زند. یعنی
 * با ده روترِ فعال، کشِ تنظیماتِ کلِ سایت **هرگز گرم نمی‌مانَد** و هر درخواستِ
 * هر بازدیدکننده یک پرس‌وجوی اضافه به همان دیتابیسی می‌زند که در CLAUDE.md
 * سابقهٔ قطعیِ گذرا دارد. یک قابلیتِ حاشیه‌ای نباید هزینهٔ سراسری بسازد.
 *
 * ═══ 🔴 توکن فقط هش می‌شود ═══
 *
 * همان قاعدهٔ `CustomerApiToken`: مقدارِ خام یک بار برمی‌گردد و دیگر بازیابی
 * نمی‌شود. توکن روی روترِ مشتری می‌نشیند — جایی که ما نه وصله‌اش می‌کنیم نه
 * لاگش را می‌بینیم — پس فرضِ پایه این است که روزی لو می‌رود، و آن روز نباید
 * دیتابیسِ ما هم نسخهٔ خوانا داشته باشد.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
        | ⚠️ گاردِ hasTable: این قابلیت اول روی سرور زنده شد و جدول‌ها آن‌جا از
        | قبل ساخته شده‌اند ولی ردیفِ مهاجرت ثبت نشده — بدونِ گارد، اولین
        | migrate روی سرور با «table exists» کلِ دیپلوی را می‌شکست.
        */
        if (! Schema::hasTable('tunnel_jobs')) {
        Schema::create('tunnel_jobs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('service_id')->index();

            // `add` یا `remove`. رشته و نه enum: enum در MariaDB برای افزودنِ
            // مقدارِ تازه ALTER می‌خواهد و این ستون قطعاً روزی مقدارِ سوم می‌گیرد.
            $table->string('op', 16);

            $table->string('name', 64);
            $table->string('ip', 45)->nullable();
            $table->string('public_key', 128)->nullable();

            // pending → done | failed
            $table->string('status', 16)->default('pending')->index();

            /*
            | شمارشِ **تحویل**، نه شمارشِ تلاشِ ناموفق. ایجنتی که یک ساعت خاموش
            | باشد هیچ تحویلی نمی‌گیرد، پس این عدد بالا نمی‌رود — و همین باعث
            | می‌شود «کار گیر کرده» از «روتر خاموش است» قابلِ تفکیک بماند.
            */
            $table->unsignedInteger('attempts')->default(0);

            $table->text('last_error')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamps();

            // پرس‌وجوی داغِ ایجنت: «کارهای منتظرِ این سرویس، به ترتیبِ ورود».
            $table->index(['service_id', 'status', 'id']);
        });
        }

        if (! Schema::hasTable('tunnel_agents')) {
        Schema::create('tunnel_agents', function (Blueprint $table) {
            $table->id();

            // یک ایجنت به‌ازای هر سرویس. صدورِ دوباره همین ردیف را بازنویسی
            // می‌کند، پس توکنِ قبلی همان لحظه می‌میرد — ابطال از راهِ صدور.
            $table->unsignedBigInteger('service_id')->unique();

            $table->string('token_hash', 64)->index();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->timestamps();
        });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tunnel_agents');
        Schema::dropIfExists('tunnel_jobs');
    }
};

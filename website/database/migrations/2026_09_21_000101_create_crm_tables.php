<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * موتورِ جذبِ مشتری — سرنخ، پیام، فهرستِ سیاه.
 *
 * ⚠️ قاعدهٔ مطلقِ این حوزه: **این جدول‌ها هرگز نباید بتوانند فروش را بخوابانند.**
 * این اپ تحویلِ سرویس و صورت‌حسابِ مشتریانِ واقعی را می‌گرداند؛ بازاریابی
 * مهمانِ آن است، نه شریکش. به همین دلیل:
 *
 *  • پیشوندِ `crm_` و **هیچ کلیدِ خارجی به جدول‌های مشتری/سرویس**. اگر روزی
 *    خواستی این حوزه را حذف کنی، سه تا `drop` باید کافی باشد و بس.
 *  • کارهایش روی صفِ جدا (`crm`) می‌روند. یک `fetch` که روی سایتِ یک کلینیک
 *    ۳۰ ثانیه گیر کند، نباید پشتِ سرش تحویلِ سرورِ یک مشتریِ واقعی معطل بماند.
 *  • تراکنش‌ها کوتاه: هر سرنخ یک commit. روی SQLite تراکنشِ طولانی یعنی قفل
 *    شدنِ نوشتنِ صورت‌حساب.
 *
 * طولِ ستون‌ها صریح داده شده: SQLite طولِ VARCHAR را نادیده می‌گیرد ولی MariaDB
 * اجرا می‌کند — همان تفاوتی که یک‌بار روی پروداکشن «Data too long for column
 * 'status'» داد بدون آنکه هیچ تستی بگیردش.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─────────────── سرنخ‌ها ───────────────
        if (! Schema::hasTable('crm_leads')) {
            Schema::create('crm_leads', function (Blueprint $table) {
                $table->id();

                // کلیدِ ضدِ تکرار. sha256 دامنهٔ نرمال‌شده (بدون www، بدون پروتکل).
                // بدونِ این، هر اجرای کشف دوباره همان کلینیک‌ها را پیدا می‌کند و
                // دو بار به یک نفر ایمیل می‌رود — که سریع‌ترین راهِ اسپم‌خوردن است.
                $table->string('domain_hash', 64)->unique();

                $table->string('company', 160);
                $table->string('contact_name', 120)->nullable();
                $table->string('country', 2)->nullable();       // ISO-3166: AE
                $table->string('city', 64)->nullable();
                $table->string('vertical', 48)->nullable();     // dental، aesthetic، …

                $table->string('website', 255)->nullable();
                $table->string('email', 190)->nullable();
                $table->string('phone', 40)->nullable();

                // از کجا پیدا شد: places | search | manual
                $table->string('source', 24)->default('manual');

                // خروجیِ SiteAudit — امتیازِ کل و گزارشِ کامل.
                // موتورِ بررسیِ سایت از قبل در `App\Services\SiteAudit` هست؛
                // اینجا فقط نتیجه‌اش نگه داشته می‌شود تا دوباره fetch نشود.
                $table->unsignedTinyInteger('audit_score')->nullable();
                $table->json('audit')->nullable();

                // «مشاهدهٔ مشخص» — همان یک جملهٔ راست که پیام با آن شروع می‌شود.
                // 🔴 بدونِ این هیچ پیامی نباید برود. قانونِ ۶۰ ثانیه، در سطحِ داده.
                $table->text('observation')->nullable();

                $table->string('offer', 48)->nullable();        // Business €2,900 …
                $table->unsignedInteger('value_eur')->default(0);

                // new · contacted · fu1 · replied · review · proposal · won · lost
                $table->string('stage', 24)->default('new');

                $table->date('next_action_at')->nullable();
                $table->timestamp('last_contacted_at')->nullable();
                $table->timestamp('replied_at')->nullable();
                $table->timestamp('won_at')->nullable();
                $table->timestamp('lost_at')->nullable();
                $table->string('lost_reason', 120)->nullable();

                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['stage', 'next_action_at']);
                $table->index('country');
                $table->index('email');
            });
        }

        // ─────────────── پیام‌ها (رفت و برگشت) ───────────────
        if (! Schema::hasTable('crm_messages')) {
            Schema::create('crm_messages', function (Blueprint $table) {
                $table->id();

                // کلیدِ خارجی فقط **درونِ** حوزهٔ crm مجاز است.
                $table->foreignId('lead_id')->constrained('crm_leads')->cascadeOnDelete();

                $table->string('channel', 16)->default('email');   // email | linkedin | instagram
                $table->string('direction', 8)->default('out');    // out | in

                $table->string('subject', 190)->nullable();
                $table->text('body');

                // queued · sent · failed · bounced · received
                $table->string('status', 16)->default('queued');

                // شمارهٔ پیام در دنباله: ۰ = اولین، ۱ = فالوآپ اول، ۲ = فالوآپ دوم.
                // 🔴 هرگز ۳. در کد هم سقف گذاشته می‌شود، اینجا فقط ثبت می‌شود.
                $table->unsignedTinyInteger('sequence')->default(0);

                // Message-ID برای گره زدنِ جوابِ IMAP به همین رشته
                $table->string('provider_id', 190)->nullable();
                $table->text('error')->nullable();

                $table->timestamp('sent_at')->nullable();
                $table->timestamps();

                $table->index(['lead_id', 'direction']);
                $table->index(['status', 'created_at']);
                $table->index('provider_id');
            });
        }

        // ─────────────── فهرستِ سیاه ───────────────
        /*
         * این جدول فقط **رشد** می‌کند. هیچ کجای کد از آن حذف نمی‌کند.
         *
         * یک «no» یعنی هرگزِ دائمی. اگر کسی شش ماه بعد دوباره در نتایجِ کشف
         * ظاهر شد، همین جدول جلویش را می‌گیرد. این هم ادبِ کار است هم الزامِ
         * CAN-SPAM و CASL: درخواستِ لغو باید برای همیشه محترم شمرده شود.
         */
        if (! Schema::hasTable('crm_suppression')) {
            Schema::create('crm_suppression', function (Blueprint $table) {
                $table->id();
                $table->string('email', 190)->unique();
                // دامنه هم نگه می‌داریم: اگر info@x.com لغو کرد، sales@x.com هم نرود.
                $table->string('domain', 190)->nullable();
                // unsubscribe · bounce_hard · complaint · manual
                $table->string('reason', 32)->default('unsubscribe');
                $table->text('note')->nullable();
                $table->timestamps();

                $table->index('domain');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_messages');
        Schema::dropIfExists('crm_leads');
        Schema::dropIfExists('crm_suppression');
    }
};

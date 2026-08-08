<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «ایمیلِ تحویل هنوز بدهیِ ما است» — یک مهرِ زمانیِ صریح.
 *
 * ═══ 🔴 چرا لازم شد ═══
 *
 * `CloudProvisioner::notify()` ایمیلِ «سرورت آماده شد» را همان لحظه‌ای می‌فرستاد
 * که سفارش **پذیرفته** می‌شد. زیرساختِ ۱ در پاسخِ ساخت IP می‌دهد، ولی زیرساختِ ۲
 * نمی‌دهد: ماشین چند ده ثانیه در حالِ `activating` است و IP بعداً می‌آید. نتیجه
 * روی یک خریدِ واقعی: ایمیلی با `IP: —` و بی‌هیچ رمزی. کد **می‌دانست** که ممکن
 * است IP نباشد (`$instance->ipv4 ?: '—'`) و به‌جای درست کردنش خط تیره چاپ کرد.
 *
 * پیش از این، تنها نشانهٔ «فرستاده شد یا نه» یک استنتاج بود:
 * `filled($service->provision_meta['ip'])`. آن استنتاج دو جا می‌شکند —
 * سرویسی که با IP تحویل شده ولی ایمیلش ناموفق بوده، و سرویسی که IP را از راهِ
 * دیگری (پرسشِ خودِ صفحهٔ مشتری) گرفته. حالا یک ستون حقیقت را می‌گوید:
 *
 *   ready_notified_at = null  ⇒ ایمیل **بدهیِ** ما است، کرون باید بفرستد
 *   ready_notified_at = زمان  ⇒ فرستاده شده، هرگز دوباره نه
 *
 * ⚠️ **پرکردنِ ردیف‌های موجود عمدی و حیاتی است.** بی‌آن، اولین اجرای کرون بعد از
 * این مهاجرت هر سرورِ تحویل‌شدهٔ **قبلی** را «بی‌ایمیل» می‌دید و به همهٔ مشتریان
 * قدیمی یک ایمیلِ تکراریِ «سرورت آماده شد» می‌فرستاد. برای آنها ایمیل قبلاً رفته
 * (منطقِ قدیمی همیشه لحظهٔ تحویل می‌فرستاد)، پس همه «فرستاده‌شده» علامت می‌خورند.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cloud_instances')
            || Schema::hasColumn('cloud_instances', 'ready_notified_at')) {
            return;
        }

        Schema::table('cloud_instances', function (Blueprint $table) {
            $table->timestamp('ready_notified_at')->nullable();
            // کرون هر دقیقه دنبالِ «بدهیِ ایمیل» می‌گردد؛ بی‌ایندکس روی حسابِ پر
            // این یک اسکنِ کاملِ جدول در هر دقیقه است.
            $table->index(['ready_notified_at', 'status'], 'cloud_instances_owed_notice');
        });

        DB::table('cloud_instances')
            ->whereNull('ready_notified_at')
            ->update(['ready_notified_at' => now()]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('cloud_instances')
            || ! Schema::hasColumn('cloud_instances', 'ready_notified_at')) {
            return;
        }

        Schema::table('cloud_instances', function (Blueprint $table) {
            $table->dropIndex('cloud_instances_owed_notice');
            $table->dropColumn('ready_notified_at');
        });
    }
};

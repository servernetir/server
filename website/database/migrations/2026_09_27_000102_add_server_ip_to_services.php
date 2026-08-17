<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IP سروری که لایسنس روی آن ثبت می‌شود — از **خودِ مشتری، هنگام سفارش**.
 *
 * ═══ چرا هنگام سفارش و نه بعد از پرداخت ═══
 *
 * لایسنس بی‌IP اصلاً قابلِ فعال‌سازی نیست. اگر بعد از پرداخت بپرسیم، هر سفارش
 * یک رفت‌وبرگشتِ تیکتی می‌شود و صفحهٔ محصول هم دیگر نمی‌تواند «تحویل آنی پس از
 * پرداخت» بگوید — چون تحویل به پاسخِ مشتری گره می‌خورد، نه به کارِ ما.
 * گرفتنش در فرمِ سفارش، آن وعده را **قابلِ انجام** می‌کند.
 *
 * ⚠️ چرا ستونِ جدا و نه استفاده از `domain`:
 * ستونِ `domain` در تحویلِ WHM/DirectAdmin به `createacct` می‌رود. نشاندنِ یک
 * IP در آن یعنی روزی که همین ردیف از کنارِ یک درایور رد شود، دامنه‌ای به شکلِ
 * `1.2.3.4` به کنترل‌پنل فرستاده شود. ستونِ جدا این را ناممکن می‌کند.
 *
 * ⚠️ nullable است چون اکثرِ سرویس‌ها (هاست، نمایندگی، سرور) IP ورودی ندارند؛
 * اجباری‌بودن فقط در اعتبارسنجیِ همان محصولاتی است که `requires_server_ip`
 * دارند.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('services') && ! Schema::hasColumn('services', 'server_ip')) {
            Schema::table('services', function (Blueprint $table) {
                // ۴۵ نویسه: جا برای IPv6 کامل (۳۹) با حاشیه
                $table->string('server_ip', 45)->nullable()->after('domain');
            });
        }

        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'requires_server_ip')) {
            Schema::table('products', function (Blueprint $table) {
                $table->boolean('requires_server_ip')->default(false)->after('requires_domain');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('services') && Schema::hasColumn('services', 'server_ip')) {
            Schema::table('services', function (Blueprint $table) {
                $table->dropColumn('server_ip');
            });
        }

        if (Schema::hasTable('products') && Schema::hasColumn('products', 'requires_server_ip')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('requires_server_ip');
            });
        }
    }
};

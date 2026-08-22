<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «آپ‌استریم‌های اکسیت» — رله‌ها و نودهایی که موتورِ اکسیتِ ایران از راهِ آنها
 * از کشور خارج می‌شود. این جدول همان چیزی را قابلِ‌مدیریت از پنل می‌کند که تا
 * امروز فقط با اسکریپت‌های `servernet-relay-set`/`servernet-exit-set` روی هاست
 * دستی ست می‌شد: افزودنِ SSH-VPN یا VLESSِ تازه به زیرساختِ اکسیت.
 *
 * دو نقش (`role`):
 *   • `relay` — «آپ‌لینک»/رله‌ی فرار از DPI (SSH -D SOCKS روی 127.0.0.1). مستقلِ
 *     کشور است؛ mihomo همه‌ی نودها را از داخلِ آن dial می‌کند. برای چرخش ≥۲ لازم است.
 *   • `exit`  — اکسیتِ **اختصاصیِ** یک کشور (سرورِ خودِ کاربر): خروجِ آن کشور را
 *     تضمین/پایدار می‌کند. `country_code` اجباری است.
 *
 * `type` = پروتکل: ssh | socks | vless | trojan | wireguard.
 *
 * 🔴 امنیت: `secret` (کلیدِ خصوصیِ SSH، لینکِ vless://، یا رمز) در لایه‌ی اپ با
 * cast=encrypted رمزنگاری می‌شود و در مدل `$hidden` است — هرگز خام در JSON/صفحه
 * نمی‌آید. فقط endpointِ توکن‌دارِ `/agent/exitupstreams` (که هاستِ ایران می‌کشد)
 * مقدارِ خام را می‌گیرد، چون برای dial لازمش دارد.
 *
 * ⚠️ کاملاً افزایشی و مستقل: هیچ کلیدِ خارجی به جدول‌های مشتری/سرویس ندارد، پس
 * حذفِ کلِ حوزه یک `drop` است. طولِ ستون‌ها صریح داده شده چون SQLite طولِ VARCHAR
 * را نادیده می‌گیرد ولی MariaDB اجرا می‌کند (همان تفاوتی که یک‌بار روی پروداکشن
 * «Data too long» داد بی‌آنکه تستی بگیردش).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('exit_upstreams')) {
            return;
        }

        Schema::create('exit_upstreams', function (Blueprint $table) {
            $table->id();

            $table->string('name', 160);

            // relay | exit
            $table->string('role', 12)->default('exit');
            // ssh | socks | vless | trojan | wireguard
            $table->string('type', 12)->default('ssh');

            // کدِ کشور (ISO-2، lowercase) — برای role=exit اجباری، برای relay خالی
            $table->string('country_code', 2)->nullable();

            $table->string('host', 190)->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->string('username', 64)->nullable();

            // 🔴 رمزنگاری‌شده در لایه‌ی اپ (cast=encrypted) و $hidden — کلیدِ SSH،
            // لینکِ vless://، یا رمز. متنِ خام هرگز در دیتابیس نیست.
            $table->text('secret')->nullable();

            // برای vless/trojan+REALITY: نامِ سرورِ استتار
            $table->string('sni', 190)->nullable();

            $table->boolean('enabled')->default(true);
            // ترتیب/ترجیح در استخرِ یک کشور یا میانِ رله‌ها (کوچک‌تر = مقدم‌تر)
            $table->unsignedInteger('priority')->default(100);

            // وضعیتِ سلامت که ایجنتِ هاست بعداً گزارش می‌دهد: unknown | up | down
            $table->string('health', 16)->default('unknown');
            $table->timestamp('last_seen_at')->nullable();
            $table->unsignedInteger('last_latency_ms')->nullable();

            $table->json('meta')->nullable();
            $table->text('note')->nullable();

            $table->timestamps();

            // پرس‌وجوی داغ: «آپ‌استریم‌های فعالِ یک کشور به‌ترتیبِ اولویت»
            $table->index(['role', 'country_code', 'enabled']);
            $table->index(['enabled', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exit_upstreams');
    }
};

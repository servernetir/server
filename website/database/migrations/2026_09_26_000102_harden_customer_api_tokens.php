<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سخت‌سازیِ توکنِ API — پیش‌نیازِ توکنی که **پول خرج می‌کند**.
 *
 * تا امروز توکن فقط‌خواندنی بود و این ستون‌ها لازم نبودند. با APIِ نمایندگی،
 * یک رشتهٔ متنی روی سرورِ WHMCSِ نماینده می‌نشیند — سروری که ما نه وصله‌اش
 * می‌کنیم، نه لاگش را می‌بینیم، نه از نفوذش خبردار می‌شویم. پس فرضِ پایه
 * همان فرضِ وب‌هوکِ بله است: **توکن روزی لو می‌رود.**
 *
 * ═══ 🔴 چرا محدودیتِ IP این‌جاست و نه در `customer_ip_rules` ═══
 *
 * `EnforceCustomerIp` مشتری را از `Auth::guard('customer')` می‌گیرد، ولی
 * `CustomerApiToken` (میدل‌ور) هرگز واردِ guard نمی‌شود — فقط
 * `setUserResolver()` می‌زند. یعنی افزودنِ آن میدل‌ور به `api/v1` یک
 * **no-opِ کاملاً بی‌صدا** است: صفحهٔ امنیت می‌گوید «قواعدِ IP فعال است»،
 * اپراتور خیال می‌کند توکنش محدود شده، و هیچ چیزی محدود نشده.
 *
 * دومین دلیل مستقل است: تنها حالتی که آن قواعد را واقعاً اعمال می‌کند
 * `enforce` است و آن **کلِ حساب** را قفل می‌کند — یعنی مشتری برای محدودکردنِ
 * توکنِ سرورش، خودش را از مرورگرِ خودش بیرون می‌اندازد و بلافاصله خاموشش
 * می‌کند. محافظی که استفاده‌اش گران باشد، استفاده نمی‌شود.
 *
 * پس محدودیت روی **خودِ توکن** می‌نشیند: ربطی به نشستِ مرورگر ندارد و
 * روشن‌کردنش هیچ‌چیزِ دیگری را نمی‌شکند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_api_tokens', function (Blueprint $table) {
            /*
            | CIDRهای مجاز برای **این** توکن. خالی/نال = بدونِ محدودیت.
            |
            | ⚠️ نال و آرایهٔ خالی هر دو «بی‌محدودیت»اند و این عمدی است: اگر
            | آرایهٔ خالی را «هیچ IPای مجاز نیست» بخوانیم، یک ویرایشِ ناقص
            | توکنِ زندهٔ نماینده را بی‌صدا می‌کُشد و او علتش را نمی‌فهمد.
            */
            $table->json('allowed_cidrs')->nullable()->after('abilities');

            /*
            | انقضا. `null` = بی‌انقضا (رفتارِ توکن‌های موجود، دست‌نخورده).
            | برای توکنِ نوشتنی، رابطِ صدور یک انقضای پیش‌فرض پیشنهاد می‌دهد.
            */
            $table->timestamp('expires_at')->nullable()->after('allowed_cidrs');

            /*
            | 🔴 ابطالِ **نرم** به‌جای حذفِ فیزیکی.
            |
            | `tokenDestroy()` امروز `delete()` می‌زند. یعنی درست در لحظه‌ای که
            | مشتری می‌گوید «این توکن لو رفته»، تنها چیزی که می‌گفت آن توکن چه
            | کرده هم پاک می‌شود — و `reseller_api_logs.token_id` به نال
            | می‌افتد. حسابرسیِ حادثه بدونِ ردیفِ توکن ممکن نیست.
            */
            $table->timestamp('revoked_at')->nullable()->after('expires_at');

            // سقفِ خرجِ روزانهٔ همین توکن (تومان). صفر = سقفِ سطحِ مشتری.
            $table->unsignedBigInteger('daily_spend_cap_irt')->default(0)->after('revoked_at');

            // شمارندهٔ استفاده — دو ستونِ بازنویسی‌شوندهٔ `last_used_*` نمی‌گویند
            // «هزار تماس شد»؛ فقط می‌گویند «آخری کِی بود».
            $table->unsignedBigInteger('use_count')->default(0)->after('last_used_ip');

            $table->index(['customer_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::table('customer_api_tokens', function (Blueprint $table) {
            $table->dropIndex(['customer_id', 'revoked_at']);
            $table->dropColumn([
                'allowed_cidrs', 'expires_at', 'revoked_at',
                'daily_spend_cap_irt', 'use_count',
            ]);
        });
    }
};

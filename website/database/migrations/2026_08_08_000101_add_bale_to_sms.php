<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بله (پیام‌رسان) به‌عنوان کانال دوم، موازی پیامک.
 *
 * ═══ چرا این شکلی ═══
 *
 * بله زیرساخت ایرانی است و مثل آی‌پی‌پنل آی‌پی خارج را بلاک می‌کند، پس
 * ارسال بله هم باید از سرور ایران رد شود — همان پلی که برای پیامک ساختیم.
 *
 * برای فرستادن بله، «شناسهٔ گفتگو» (chat_id) کاربر لازم است، نه شماره‌اش.
 * ربات‌های تلگرام‌مانند فقط به کسی می‌توانند پیام دهند که اول ربات را
 * استارت کرده و شماره‌اش را به اشتراک گذاشته باشد. پس:
 *
 *   ۱) کاربر ربات بله را استارت و شماره‌اش را share می‌کند
 *   ۲) وب‌هوک، (شماره → chat_id) را در bale_contacts ذخیره می‌کند
 *   ۳) موقع صف‌کردن پیامک، اگر chat_id این شماره را داریم، روی همان ردیف
 *      outbox می‌گذاریم
 *   ۴) فرستندهٔ ایران پیامک را می‌فرستد و اگر chat_id بود، بله را هم
 */
return new class extends Migration
{
    public function up(): void
    {
        // نگاشت شمارهٔ موبایل به chat_id بله
        Schema::create('bale_contacts', function (Blueprint $table) {
            $table->id();
            $table->string('mobile', 20)->unique();   // 09xxxxxxxxx نرمال‌شده
            $table->string('chat_id', 40);
            $table->string('name', 120)->nullable();
            $table->timestamp('linked_at')->nullable();
            $table->timestamps();

            $table->index('chat_id');
        });

        // ردیف صف: chat_id بله (اگر داریم) تا فرستندهٔ ایران هر دو را بفرستد
        Schema::table('sms_outbox', function (Blueprint $table) {
            $table->string('bale_chat_id', 40)->nullable()->after('params');
            $table->boolean('bale_sent')->default(false)->after('bale_chat_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bale_contacts');
        Schema::table('sms_outbox', function (Blueprint $table) {
            $table->dropColumn(['bale_chat_id', 'bale_sent']);
        });
    }
};

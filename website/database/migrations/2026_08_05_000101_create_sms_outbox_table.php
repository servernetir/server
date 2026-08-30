<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * صف پیامک.
 *
 * ═══ چرا صف، و چرا جهت اتصال برعکس شد ═══
 *
 * آی‌پی‌پنل به آی‌پی‌های خارج از ایران سرویس نمی‌دهد، پس سرور آلمان
 * نمی‌تواند مستقیم پیامک بفرستد. طرح اول یک «رابط» روی سرور ایران بود که
 * آلمان صدایش بزند — ولی سنجش نشان داد سرور ایران **اتصال ورودی از آلمان
 * را هم نمی‌پذیرد** (timeout روی خود ریشهٔ دامنه، نه فقط مسیر رابط).
 *
 * پس جهت برعکس شد:
 *
 *   آلمان: پیام را در این جدول می‌گذارد و فوراً برمی‌گردد
 *   ایران: هر چند ثانیه می‌آید، پیام‌های نفرستاده را برمی‌دارد،
 *          به آی‌پی‌پنل می‌دهد، و نتیجه را گزارش می‌کند
 *
 * خروجی از ایران به بیرون باز است، پس این مسیر کار می‌کند.
 *
 * ═══ نکته‌ای که این طرح را درست نگه می‌دارد ═══
 *
 * کد یک‌بارمصرف سه دقیقه اعتبار دارد. پیام کهنه نباید فرستاده شود — کاربر
 * کدی می‌گیرد که همان لحظه منقضی است و فقط گیج می‌شود. برای همین
 * `expires_at` روی خود پیام است و فرستنده پیام منقضی را رد می‌کند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_outbox', function (Blueprint $table) {
            $table->id();

            $table->string('destination', 20);      // 09xxxxxxxxx
            $table->string('event', 24)->nullable(); // otp | invoice | … (برای الگو)
            $table->text('body')->nullable();        // متن آزاد، وقتی الگو نداریم
            $table->json('params')->nullable();      // متغیرهای الگو

            // queued → claimed → sent | failed | expired
            $table->string('status', 12)->default('queued');
            $table->unsignedTinyInteger('attempts')->default(0);

            // قفل نرم: دو فرستنده هم‌زمان نباید یک پیام را دو بار بفرستند
            $table->string('claim_token', 40)->nullable();
            $table->timestamp('claimed_at')->nullable();

            $table->timestamp('expires_at');
            $table->timestamp('sent_at')->nullable();
            $table->string('provider_code', 24)->nullable();
            $table->string('provider_message', 255)->nullable();

            $table->timestamps();

            // پرس‌وجوی اصلی فرستنده: «صف‌شده‌ها که منقضی نشده‌اند»
            $table->index(['status', 'expires_at']);
            $table->index('claimed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_outbox');
    }
};

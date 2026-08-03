<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الگوی پیام‌هایی که بین سرورنت و کاربر رد و بدل می‌شود.
 *
 * تا امروز متنِ هر پیام در نقطهٔ فراخوانی‌اش **سخت‌کد** بود؛ برای عوض کردنِ یک
 * جمله باید کد دپلوی می‌شد. این جدول همان متن‌ها را یک‌جا می‌آورد.
 *
 * یک ردیف = یک رویداد، با چند کانال کنارِ هم. عمداً یک ردیف به ازای هر کانال
 * نساختیم: مدیر «تحویل سرویس» را یک چیز می‌بیند، نه سه چیز؛ و ویرایشِ هم‌زمانِ
 * ایمیل و بله در یک صفحه، متن‌ها را هم‌آهنگ نگه می‌دارد.
 *
 * ⚠️ متنِ **پیامک** این‌جا نیست و نمی‌تواند باشد: اپراتورهای ایرانی متنِ الگو را
 * در پنلِ خودشان نگه می‌دارند و تأیید می‌کنند؛ ما فقط کدِ الگو و متغیرها را
 * می‌فرستیم. `sms_event` را نگه می‌داریم تا در همان صفحه معلوم باشد این رویداد
 * با کدام الگو می‌رود و چه متغیرهایی برایش فرستاده می‌شود.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notification_templates')) {
            return;
        }

        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();

            // کلیدِ رویداد — همان چیزی که کد با آن صدا می‌زند (service_ready, invoice, …)
            $table->string('key', 64)->unique();
            $table->string('title', 150);                 // نامِ خواندنی برای مدیر
            $table->string('group', 60)->default('other'); // دسته‌بندیِ صفحه

            // ── کانال‌ها ──
            $table->string('email_subject', 200)->nullable();
            $table->text('email_body')->nullable();       // HTML (ویرایشگر)
            $table->text('bale_body')->nullable();        // متنِ ساده — بله و اعلانِ پنل

            // فقط برای نمایش: کدام الگوی پیامک برای این رویداد می‌رود
            $table->string('sms_event', 64)->nullable();

            // فهرستِ متغیرهای در دسترس: [{name, desc}] — برای راهنما و پیش‌نمایش
            $table->json('variables')->nullable();

            // خاموش‌کردنِ یک اعلان بدونِ دست‌زدن به کد
            $table->boolean('is_active')->default(true);

            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};

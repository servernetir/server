<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * اعلانِ رسمیِ اختلال — صفحهٔ وضعیت.
 *
 * چرا لازم است: سایت «آپتایم تضمینی» تبلیغ می‌کند و `/status` صفحه‌ای نداشت.
 * در یک اختلالِ واقعی هیچ کانالِ ارتباطیِ ازپیش‌آماده‌ای نبود، و خلأِ ارتباطی را
 * شایعه پر می‌کند: مشتری از توییتر خبردار می‌شود، پشتیبانی زیر بارِ تیکتِ تکراری
 * می‌رود، و تعهدِ آپتایم بی‌پشتوانه می‌مانَد چون نه ثبتی هست نه تاریخچه‌ای.
 *
 * عمداً **دستی** است نه خودکار: پایشِ خودکار زیرساختِ مستقل می‌خواهد (اگر روی
 * همان کلاستر بنشیند، دقیقاً لحظهٔ اختلال خاموش است). تا آن روز، اعلانِ صادقانهٔ
 * دستی از سکوت بی‌نهایت بهتر است.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('status_incidents')) {
            return;
        }

        Schema::create('status_incidents', function (Blueprint $table) {
            $table->id();

            $table->string('title', 200);

            // investigating / identified / monitoring / resolved
            $table->string('state', 20)->default('investigating');

            // none / minor / major — «none» برای نگهداریِ برنامه‌ریزی‌شده
            $table->string('impact', 20)->default('minor');

            // کدام مکان‌ها درگیرند (کدِ cloud_locations) — خالی = همه‌جا
            $table->json('locations')->nullable();

            $table->text('body')->nullable();

            $table->timestamp('started_at');
            $table->timestamp('resolved_at')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            // صفحهٔ عمومی همیشه «بازها به‌ترتیبِ تازگی» می‌خواهد
            $table->index(['resolved_at', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_incidents');
    }
};

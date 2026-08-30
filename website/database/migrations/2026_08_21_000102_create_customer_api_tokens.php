<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * توکن‌های APIِ مشتری — دستی، بدونِ وابستگی به Sanctum.
 *
 * فقط هشِ توکن ذخیره می‌شود (مثل رمز)؛ متنِ خام یک‌بار به کاربر نشان داده
 * می‌شود و بعد دیگر بازیابی‌شدنی نیست. ستونِ abilities از الان JSON است تا
 * وقتی «ساختِ سرویس/دامنه» اضافه شد، دامنهٔ دسترسی (نوشتن) هم پشتیبانی شود؛
 * فعلاً همه فقط ["read"] هستند.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_api_tokens')) {
            return;
        }

        Schema::create('customer_api_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('token_hash', 64)->unique();   // sha256 hex
            $table->json('abilities')->nullable();         // ["read"] | later ["read","service:create",...]
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestamps();

            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_api_tokens');
    }
};

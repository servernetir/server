<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * لاگ فعالیت — «این کار با این IP توسط این کاربر انجام شد».
 *
 * هم به مشتری حس پویایی و امنیت می‌دهد (می‌بیند از کجا و کِی وارد شده و چه
 * کرده)، هم برای پشتیبانی و ممیزی رد می‌گذارد. لاگ‌ها تغییرناپذیرند، پس فقط
 * created_at دارند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable();
            $table->string('actor', 12)->default('customer'); // customer | staff | system
            $table->string('action', 40);                     // login | payment | service | password | ...
            $table->string('description', 400);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 200)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['customer_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};

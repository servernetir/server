<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سرورهای تحویل — جایی که سرویسِ مشتری واقعاً ساخته می‌شود.
 *
 * هر ردیف یک سرورِ کنترل‌پنل (WHM/cPanel، Plesk، DirectAdmin) یا زیرساختِ
 * مجازی/اختصاصی است. توکن/رمزِ API رمزنگاری‌شده ذخیره می‌شود (cast=encrypted).
 * پکیج‌ها به این سرورها وصل می‌شوند و موقعِ پرداختِ سفارش، تحویلِ خودکار روی
 * همین سرور انجام می‌گیرد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);                         // نامِ نمایشی: WHM-DE-01
            $table->string('type', 20)->default('whm');         // whm | plesk | directadmin | vps | dedicated | generic
            $table->string('hostname', 190)->nullable();        // server1.servernet.cloud
            $table->unsignedSmallInteger('port')->nullable();   // 2087 برای WHM و…
            $table->string('username', 60)->default('root');    // کاربرِ API
            $table->text('api_token')->nullable();              // رمزنگاری‌شده (cast)
            $table->boolean('verify_tls')->default(true);       // برای گواهیِ self-signed می‌شود خاموش کرد
            $table->string('server_ip', 45)->nullable();        // IPِ سرور (برای IP اختصاصی)
            $table->string('nameservers', 190)->nullable();     // ns1.x,ns2.x
            $table->string('status', 16)->default('active');    // active | maintenance | full
            $table->unsignedInteger('max_accounts')->nullable(); // ظرفیت (null=نامحدود)
            $table->unsignedInteger('active_accounts')->default(0);
            $table->text('note')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};

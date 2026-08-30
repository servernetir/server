<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جدول‌های جانبی هویت: شناسه‌های رجیستری، ورود اجتماعی، محدودیت IP، نشست‌ها.
 * همه کوچک‌اند و به customers وابسته، پس در یک مهاجرت جمع شده‌اند.
 */
return new class extends Migration
{
    public function up(): void
    {
        // شناسه‌های ثبت دامنه (IRNIC و بقیه). یک پروفایل ممکن است برای هر
        // رجیستری شناسهٔ جدا داشته باشد — این‌ها قابل ادغام نیستند.
        Schema::create('registry_handles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_profile_id')->constrained()->cascadeOnDelete();
            $table->string('registry', 24);                    // irnic|openprovider|centralnic
            $table->string('handle', 64);
            $table->string('role', 16)->default('registrant'); // registrant|admin|tech|billing
            $table->string('status', 16)->default('active');
            $table->timestamp('verified_at')->nullable();      // IRNIC فرایند تأیید جدا دارد
            // تنها JSON این حوزه: شکل داده را رجیستری تعیین می‌کند نه ما
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['registry', 'handle']);
            $table->index(['customer_profile_id', 'registry']);
        });

        // ورود با گوگل/اپل/شماره
        Schema::create('customer_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 16);        // google|apple|phone
            $table->string('provider_uid');        // sub
            // اپل فقط بار اول نام و ایمیل واقعی می‌دهد؛ اگر ذخیره نکنیم دیگر نمی‌گیریم
            $table->string('email_at_link')->nullable();
            $table->timestamp('linked_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_uid']);
            $table->index('customer_id');
        });

        // محدودسازی ورود به IP یا رنج
        Schema::create('customer_ip_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('cidr', 43);            // 1.2.3.4/32 یا 10.0.0.0/8 یا IPv6
            $table->string('label', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['customer_id', 'is_active']);
        });

        // نشست‌های فعال تا مشتری بتواند بقیه را ببندد
        Schema::create('customer_sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('customer_id')->nullable()->index()->constrained()->cascadeOnDelete();
            $table->binary('ip_address')->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->text('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_sessions');
        Schema::dropIfExists('customer_ip_rules');
        Schema::dropIfExists('customer_identities');
        Schema::dropIfExists('registry_handles');
    }
};

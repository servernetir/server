<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مشتریان — هویت ورود.
 *
 * عمداً جدا از جدول users (که فقط کارکنان و ادمین را نگه می‌دارد) با guard
 * جداگانه. یک باگ در منطق نقش نباید بتواند مشتری را به پنل مدیریت برساند؛
 * جدول و guard جدا این را ساختاری غیرممکن می‌کند.
 *
 * اینجا فقط چیزهای مربوط به «ورود و امنیت حساب» است. اطلاعات صورت‌حساب و
 * احراز هویت در customer_profiles می‌نشیند، چون یک انسان می‌تواند هم شخص
 * حقیقی باشد هم نمایندهٔ یک شرکت.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();

            // شناسهٔ عمومی مثل SN-104829. هرگز id عددی را به مشتری نشان نمی‌دهیم:
            // با دو ثبت‌نام پشت‌سرهم می‌شود فهمید چند مشتری داریم.
            $table->string('code', 16)->unique();

            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('phone', 20)->nullable()->unique();   // E.164
            $table->timestamp('phone_verified_at')->nullable();

            // nullable چون ممکن است فقط با گوگل/اپل ثبت‌نام کرده باشد
            $table->string('password')->nullable();

            $table->string('locale', 5)->default('fa');
            $table->string('timezone', 40)->default('Asia/Tehran');
            $table->string('status', 16)->default('active');     // active|suspended|closed

            // دومرحله‌ای — رمزنگاری‌شده
            $table->binary('two_factor_secret')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->binary('two_factor_recovery')->nullable();

            // محدودسازی IP. پیش‌فرض off؛ warn حالت میانی است تا کاربر خودش را
            // بیرون نیندازد (قواعد در customer_ip_rules).
            $table->string('ip_restriction_mode', 12)->default('off'); // off|warn|enforce

            $table->timestamp('last_login_at')->nullable();
            $table->binary('last_login_ip')->nullable();          // باینری تا IPv6 جا شود
            $table->unsignedSmallInteger('failed_login_count')->default(0);
            $table->timestamp('locked_until')->nullable();

            $table->rememberToken();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};

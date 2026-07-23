<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * پروفایل حقیقی/حقوقی — هویتِ صورت‌حساب و ثبت دامنه.
 *
 * یک مشتری می‌تواند چند پروفایل داشته باشد: خودش به‌عنوان حقیقی، و شرکتش به
 * عنوان حقوقی. با یک جدول تخت یا باید دو حساب ورود بسازد (بد) یا مدارک
 * شرکتی و شخصی قاطی می‌شوند (بدتر).
 *
 * فیلدهای هر نوع در ستون‌های واقعی‌اند نه JSON، چون روی کد ملی و شناسهٔ ملی
 * ایندکس یکتا و جستجو لازم داریم — که داخل JSON درست کار نمی‌کند.
 *
 * کد ملی دادهٔ شخصی حساس است: رمزنگاری‌شده ذخیره می‌شود و کنارش یک hash برای
 * ایندکس یکتا و جستجو بدون رمزگشایی.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            $table->string('type', 12);                    // individual | company
            $table->boolean('is_default')->default(false);
            $table->string('status', 16)->default('draft'); // draft|pending|verified|rejected|expired
            $table->text('reject_reason')->nullable();      // باید بگوید چه چیزی و چطور اصلاح شود
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();    // مدارکی مثل روزنامهٔ رسمی منقضی می‌شوند

            // --- مشترک ---
            $table->string('mobile', 20);
            $table->string('email');                        // ممکن است با ایمیل ورود فرق کند
            $table->char('country', 2)->default('IR');
            $table->string('province', 64)->nullable();
            $table->string('city', 64)->nullable();
            $table->string('address', 500);
            $table->string('postal_code', 20)->nullable();

            // --- حقیقی ---
            $table->string('first_name', 80)->nullable();
            $table->string('last_name', 80)->nullable();
            $table->binary('national_id_enc')->nullable();   // کد ملی، رمزنگاری‌شده
            $table->char('national_id_hash', 64)->nullable(); // SHA-256 با pepper
            $table->date('birth_date')->nullable();

            // --- حقوقی ---
            $table->string('company_name')->nullable();
            $table->binary('company_national_id_enc')->nullable();
            $table->char('company_national_id_hash', 64)->nullable();
            $table->string('registration_number', 40)->nullable();
            $table->string('economic_code', 40)->nullable();
            $table->string('rep_first_name', 80)->nullable();
            $table->string('rep_last_name', 80)->nullable();
            $table->binary('rep_national_id_enc')->nullable();
            $table->char('rep_national_id_hash', 64)->nullable();
            $table->string('rep_position', 80)->nullable();

            $table->timestamps();

            $table->index(['customer_id', 'type']);
            $table->index('status');
            // جلوگیری از نشستن یک کد ملی روی چند حساب
            $table->unique('national_id_hash');
            $table->unique('company_national_id_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_profiles');
    }
};

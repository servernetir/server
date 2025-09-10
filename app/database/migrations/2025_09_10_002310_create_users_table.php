<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id()->comment('شناسه یکتا کاربر');
            $table->string('name', 150)->comment('نام کامل (برای حقیقی) یا نام نماینده (برای حقوقی)');
            $table->string('email', 191)->unique()->comment('ایمیل کاربر');
            $table->string('password', 255)->nullable()->comment('رمز عبور (ممکن است خالی باشد در صورت ورود با گوگل/فیسبوک)');
            $table->string('phone', 50)->nullable()->comment('شماره تلفن کاربر');
            $table->enum('user_type', ['individual', 'company'])->default('individual')->comment('نوع کاربر: حقیقی یا حقوقی');
            $table->string('company_name', 191)->nullable()->comment('نام شرکت (برای کاربران حقوقی)');
            $table->string('company_register_no', 100)->nullable()->comment('شماره ثبت شرکت');
            $table->string('company_national_id', 100)->nullable()->comment('شناسه ملی شرکت');
            $table->tinyInteger('verification_level')->default(0)->comment('سطح احراز هویت: 0=بدون تایید، 1=بنیادی، 2=کامل');
            $table->string('referral_code', 50)->unique()->nullable()->comment('کد دعوت اختصاصی کاربر');
            $table->foreignId('referred_by')->nullable()->constrained('users')->nullOnDelete()->comment('شناسه کاربری که این کاربر را دعوت کرده است');
            $table->decimal('wallet_balance', 15, 2)->default(0)->comment('موجودی کیف پول کاربر');
            $table->enum('status', ['active', 'inactive', 'banned'])->default('active')->comment('وضعیت حساب: فعال، غیرفعال، مسدود');
            $table->timestamp('last_login_at')->nullable()->comment('آخرین زمان ورود کاربر');
            $table->timestamps(0); // created_at و updated_at با دقت ثانیه و مدیریت خودکار
        });
    }

    public function down()
    {
        Schema::dropIfExists('users');
    }
};
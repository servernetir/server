<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id()->comment('شناسه یکتا سرویس');
            $table->string('title', 191)->comment('عنوان سرویس (مثلاً هاست لینوکس، دامنه .com، سرور مجازی)');
            $table->text('description')->nullable()->comment('توضیحات سرویس');
            $table->enum('type', ['hosting', 'vps', 'domain', 'ssl', 'email', 'other'])->comment('نوع سرویس: هاست، سرور مجازی، دامنه، SSL، ایمیل، سایر');
            $table->decimal('price', 15, 2)->comment('قیمت پایه سرویس');
            $table->string('currency', 10)->default('USD')->comment('واحد پول قیمت (مثلاً USD, IRR, EUR, USDT)');
            $table->integer('duration_days')->default(30)->comment('مدت زمان اعتبار سرویس به روز (مثلاً 30 برای یک ماه)');
            $table->tinyInteger('min_verification_level')->default(0)->comment('حداقل سطح احراز هویت لازم برای خرید این سرویس (0=بدون تایید، 1=بنیادی، 2=کامل)');
            $table->enum('status', ['active', 'inactive'])->default('active')->comment('وضعیت سرویس: فعال یا غیرفعال');
            $table->json('api_config')->nullable()->comment('تنظیمات API برای پرویژنینگ خودکار سرویس (اطلاعات اتصال به WHM/cPanel یا رجیسترار دامنه)');
            $table->timestamp('created_at')->useCurrent()->comment('زمان ایجاد رکورد');
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate()->comment('آخرین بروزرسانی رکورد');
        });
    }

    public function down()
    {
        Schema::dropIfExists('services');
    }
};
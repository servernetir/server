<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('user_services', function (Blueprint $table) {
            $table->id()->comment('شناسه یکتا رکورد سرویس کاربر');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->comment('شناسه کاربری که سرویس را خریداری کرده است');
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete()->comment('شناسه سرویس خریداری شده');
            $table->enum('status', ['pending', 'active', 'suspended', 'expired', 'cancelled'])->default('pending')->comment('وضعیت سرویس: در انتظار، فعال، معلق، منقضی، لغو شده');
            $table->timestamp('start_date')->nullable()->comment('تاریخ شروع سرویس');
            $table->timestamp('end_date')->nullable()->comment('تاریخ پایان یا انقضای سرویس');
            $table->enum('provisioning_status', ['pending', 'in_progress', 'done', 'failed'])->default('pending')->comment('وضعیت پرویژنینگ (ایجاد خودکار سرویس)');
            $table->json('provisioning_data')->nullable()->comment('اطلاعات مربوط به سرویس ایجاد شده (مثلاً اطلاعات هاست یا IP سرور)');
            $table->timestamp('created_at')->useCurrent()->comment('زمان ایجاد رکورد');
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate()->comment('آخرین بروزرسانی رکورد');
        });
    }

    public function down()
    {
        Schema::dropIfExists('user_services');
    }
};  
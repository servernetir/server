<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('referrals', function (Blueprint $table) {
            $table->id()->comment('شناسه یکتا رکورد دعوت');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->comment('کاربری که کد دعوت متعلق به اوست');
            $table->string('referral_code', 50)->comment('کد دعوت اختصاصی کاربر');
            $table->foreignId('referred_user_id')->nullable()->constrained('users')->nullOnDelete()->comment('کاربری که با این کد ثبت‌نام کرده است');
            $table->decimal('reward_amount', 15, 2)->default(0)->comment('مقدار پاداش تعلق گرفته به دعوت‌کننده');
            $table->enum('reward_status', ['pending', 'approved', 'rejected'])->default('pending')->comment('وضعیت پاداش: در انتظار، تایید شده، رد شده');
            $table->timestamp('created_at')->useCurrent()->comment('تاریخ ایجاد رکورد');
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate()->comment('آخرین تغییرات رکورد');
        });
    }

    public function down()
    {
        Schema::dropIfExists('referrals');
    }
};
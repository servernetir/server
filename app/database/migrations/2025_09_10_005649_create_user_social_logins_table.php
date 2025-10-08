<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('user_social_logins', function (Blueprint $table) {
            $table->id()->comment('شناسه یکتا رکورد ورود اجتماعی');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->comment('شناسه کاربری که با سرویس خارجی وارد شده است');
            $table->enum('provider', ['google', 'linkedin', 'facebook', 'github', 'twitter'])->comment('نام سرویس خارجی: گوگل، لینکدین، فیسبوک، گیت‌هاب، توییتر');
            $table->string('provider_user_id', 191)->comment('شناسه یکتای کاربر در سرویس خارجی');
            $table->text('access_token')->nullable()->comment('توکن دسترسی دریافت شده از سرویس خارجی');
            $table->text('refresh_token')->nullable()->comment('توکن رفرش برای تمدید دسترسی');
            $table->timestamp('expires_at')->nullable()->comment('تاریخ انقضای توکن');
            $table->timestamp('created_at')->useCurrent()->comment('زمان ایجاد رکورد');
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate()->comment('آخرین بروزرسانی رکورد');
        });
    }

    public function down()
    {
        Schema::dropIfExists('user_social_logins');
    }
};
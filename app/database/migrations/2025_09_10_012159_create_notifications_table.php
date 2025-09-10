<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id()->comment('شناسه یکتا اعلان');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->comment('شناسه کاربری که اعلان برای او ارسال شده است');
            $table->enum('type', ['email', 'sms', 'in_app'])->comment('نوع اعلان: ایمیل، پیامک، نوتیفیکیشن داخل پنل');
            $table->string('title', 191)->comment('عنوان اعلان');
            $table->text('message')->comment('متن اعلان');
            $table->foreignId('related_service_id')->nullable()->constrained('user_services')->nullOnDelete()->comment('سرویس مرتبط با این اعلان (مثلاً تمدید هاست)');
            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending')->comment('وضعیت ارسال اعلان');
            $table->timestamp('sent_at')->nullable()->comment('زمان ارسال اعلان');
            $table->timestamp('created_at')->useCurrent()->comment('زمان ایجاد اعلان');
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate()->comment('آخرین بروزرسانی');
        });
    }

    public function down()
    {
        Schema::dropIfExists('notifications');
    }
};
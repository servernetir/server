<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('ticket_replies', function (Blueprint $table) {
            $table->id()->comment('شناسه یکتا پاسخ تیکت');
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete()->comment('شناسه تیکتی که این پاسخ مربوط به آن است');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete()->comment('شناسه کاربری که پاسخ را ارسال کرده (در صورتی که فرستنده کاربر یا ادمین باشد)');
            $table->enum('sender_type', ['user', 'admin', 'ai'])->default('user')->comment('نوع ارسال‌کننده پیام: کاربر، ادمین، AI Agent');
            $table->text('message')->comment('متن پاسخ');
            $table->string('attachment_url', 255)->nullable()->comment('آدرس فایل پیوست (در صورت وجود)');
            $table->timestamp('created_at')->useCurrent()->comment('زمان ارسال پاسخ');
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate()->comment('آخرین بروزرسانی پاسخ');
        });
    }

    public function down()
    {
        Schema::dropIfExists('ticket_replies');
    }
};
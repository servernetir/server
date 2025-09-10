<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id()->comment('شناسه یکتا تیکت پشتیبانی');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->comment('کاربری که تیکت را ثبت کرده است');
            $table->string('subject', 191)->comment('عنوان یا موضوع تیکت');
            $table->text('message')->comment('متن اولیه تیکت (پیام کاربر)');
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium')->comment('اولویت تیکت: کم، متوسط، زیاد، فوری');
            $table->enum('status', ['open', 'answered', 'customer_reply', 'closed'])->default('open')->comment('وضعیت تیکت: باز، پاسخ داده شده، پاسخ مشتری، بسته شده');
            $table->string('department', 100)->nullable()->comment('دپارتمان مربوطه (مثل فروش، پشتیبانی فنی، مالی)');
            $table->unsignedBigInteger('assigned_to')->nullable()->comment('شناسه ادمینی که تیکت به او واگذار شده است');
            $table->enum('handled_by', ['ai', 'human'])->default('ai')->comment('نوع پشتیبان: AI Agent یا انسان');
            $table->timestamp('created_at')->useCurrent()->comment('زمان ایجاد تیکت');
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate()->comment('آخرین بروزرسانی تیکت');
        });
    }

    public function down()
    {
        Schema::dropIfExists('support_tickets');
    }
};
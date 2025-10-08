<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('ai_sessions', function (Blueprint $table) {
            $table->id()->comment('شناسه یکتا جلسه AI');
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete()->comment('شناسه تیکت مربوطه');
            $table->json('conversation')->comment('تاریخچه مکالمه با AI در قالب JSON');
            $table->enum('resolution_status', ['resolved', 'unresolved', 'escalated'])->default('unresolved')->comment('نتیجه مکالمه: حل شد، حل نشد، انتقال به انسان');
            $table->timestamp('created_at')->useCurrent()->comment('زمان شروع مکالمه');
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate()->comment('آخرین بروزرسانی');
        });
    }

    public function down()
    {
        Schema::dropIfExists('ai_sessions');
    }
};
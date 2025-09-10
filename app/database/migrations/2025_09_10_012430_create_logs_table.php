<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('logs', function (Blueprint $table) {
            $table->id()->comment('شناسه یکتا لاگ');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete()->comment('کاربری که این عمل را انجام داده (ممکن است NULL باشد برای عملیات سیستمی)');
            $table->string('action', 100)->comment('نوع عملیات (login, logout, create_service, payment, update_profile, system_notification, ...)');
            $table->text('description')->nullable()->comment('توضیحات کامل درباره عملیات');
            $table->string('ip_address', 45)->nullable()->comment('آدرس IP انجام دهنده عملیات');
            $table->string('user_agent', 255)->nullable()->comment('مرورگر یا کلاینت کاربر');
            $table->enum('status', ['success', 'failed'])->default('success')->comment('وضعیت عملیات: موفق یا ناموفق');
            $table->timestamp('created_at')->useCurrent()->comment('زمان انجام عملیات');
        });
    }

    public function down()
    {
        Schema::dropIfExists('logs');
    }
};
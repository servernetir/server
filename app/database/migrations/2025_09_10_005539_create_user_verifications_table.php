<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('user_verifications', function (Blueprint $table) {
            $table->id()->comment('شناسه یکتا رکورد احراز هویت');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->comment('شناسه کاربر مربوطه');
            $table->enum('document_type', ['national_id', 'passport', 'company_certificate', 'other'])->comment('نوع مدرک: کارت ملی، پاسپورت، گواهی ثبت شرکت، سایر');
            $table->string('document_file_url', 255)->comment('مسیر فایل آپلود شده مدرک');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending')->comment('وضعیت بررسی: در انتظار، تایید شده، رد شده');
            $table->unsignedBigInteger('verified_by')->nullable()->comment('شناسه ادمین که مدرک را بررسی و تایید/رد کرده است');
            $table->timestamp('verified_at')->nullable()->comment('زمان تایید یا رد شدن مدرک');
            $table->timestamp('created_at')->useCurrent()->comment('زمان ایجاد رکورد');
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate()->comment('آخرین بروزرسانی رکورد');
        });
    }

    public function down()
    {
        Schema::dropIfExists('user_verifications');
    }
};
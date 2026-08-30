<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مدارک احراز هویت.
 *
 * تصویر کارت ملی و اساسنامهٔ شرکت، پرارزش‌ترین هدف برای مهاجم است. پس:
 * دیسک خصوصی بیرون webroot، مسیر تصادفی، اسکن ویروس قبل از اجازهٔ دانلود،
 * و لاگ هر دانلود. original_name فقط برای نمایش است و هرگز در مسیر فایل
 * استفاده نمی‌شود.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_profile_id')->constrained()->cascadeOnDelete();

            // national_card|birth_cert|passport|articles_of_association|
            // rep_authorization|official_gazette|other
            $table->string('kind', 32);
            $table->string('status', 16)->default('pending'); // requested|pending|accepted|rejected
            $table->string('requested_note', 255)->nullable(); // ادمین چه مدرکی خواسته
            $table->string('reject_reason', 255)->nullable();

            $table->string('disk_path', 255);                 // مسیر تصادفی، بیرون webroot
            $table->string('original_name');                  // فقط نمایش
            $table->string('mime', 100);                      // از محتوا تشخیص داده شود نه هدر
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);                       // تشخیص آپلود تکراری
            $table->string('scan_status', 16)->default('pending'); // pending|clean|infected

            $table->timestamp('uploaded_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['customer_profile_id', 'kind']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_documents');
    }
};

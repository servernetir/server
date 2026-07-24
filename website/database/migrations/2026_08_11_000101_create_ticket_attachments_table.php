<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * پیوست‌های تیکت — تصویر و PDF.
 *
 * خودِ فایل بیرون از webroot در storage ذخیره می‌شود (نه public)، و فقط از
 * مسیر دانلودِ احرازشده سرو می‌شود؛ پس یک نفر نمی‌تواند با حدسِ آدرس، پیوستِ
 * تیکت دیگری را ببیند. این‌جا فقط فراداده می‌ماند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id');
            $table->foreignId('ticket_message_id');
            $table->string('disk', 20)->default('local');
            $table->string('path');                 // مسیر ذخیره روی disk (نام تصادفی)
            $table->string('original_name');
            $table->string('mime', 100);
            $table->unsignedInteger('size');        // بایت
            $table->timestamps();

            $table->index('ticket_id');
            $table->index('ticket_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_attachments');
    }
};

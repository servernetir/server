<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تصویر شاخص و مبدأ پست‌های واردشده.
 * image  : مسیر تصویر شاخص (مثلاً /assets/img/blog/xxx.jpg)
 * source : آدرس اصلی پست در سایت مبدأ (برای جلوگیری از واردات تکراری)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->string('image')->nullable()->after('cover');
            $table->string('source')->nullable()->after('image');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn(['image', 'source']);
        });
    }
};

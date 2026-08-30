<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * پذیرش شرایط استفاده و حریم خصوصی — با نسخه‌بندی و متن کامل.
 *
 * چرا متن کامل ذخیره می‌شود: اگر فردا شرایط را عوض کنید و مشتری ادعا کند
 * «من این را نپذیرفتم»، باید بتوانید دقیقاً همان متنی که او دید را نشان دهید.
 * لینک به صفحهٔ فعلی کافی نیست — آن صفحه عوض شده.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_documents', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 24);       // terms|privacy|sla|aup
            $table->string('version', 16);    // 2026-08-01 یا v1
            $table->string('locale', 5);      // fa|en|tr — متن هر زبان جدا
            $table->mediumText('body');       // متن کامل در همان لحظه
            $table->char('sha256', 64);       // اثر انگشت متن
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['kind', 'version', 'locale']);
            $table->index(['kind', 'published_at']);
        });

        Schema::create('legal_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('legal_document_id')->constrained()->cascadeOnDelete();
            $table->timestamp('accepted_at');
            $table->binary('ip');
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'legal_document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_acceptances');
        Schema::dropIfExists('legal_documents');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5)->index();          // fa | en | tr
            $table->string('title');
            $table->text('excerpt')->nullable();
            $table->longText('content')->nullable();
            $table->json('tags')->nullable();
            $table->boolean('auto')->default(false);        // ترجمه‌ی خودکار AI بوده؟
            $table->timestamps();
            $table->unique(['post_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_translations');
    }
};

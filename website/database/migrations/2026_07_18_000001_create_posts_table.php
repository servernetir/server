<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('type', 10)->default('blog')->index();   // blog | kb
            $table->string('category', 40)->index();
            $table->string('status', 12)->default('draft')->index(); // draft | published
            $table->string('cover', 4)->default('a');
            $table->string('icon', 24)->default('book');
            $table->unsignedInteger('reading')->default(5);
            $table->foreignId('author_id')->nullable();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};

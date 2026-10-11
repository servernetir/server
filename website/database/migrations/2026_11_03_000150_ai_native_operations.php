<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ai_usage', function (Blueprint $table) {
            $table->unsignedBigInteger('input_price_id')->nullable()->change();
            $table->string('usage_source',32)->nullable()->change();
            $table->json('native_snapshot')->nullable();
            $table->json('native_usage')->nullable();
        });
    }

    public function down(): void
    {
        // Native snapshots are historical billing evidence; keep them on rollback.
    }
};

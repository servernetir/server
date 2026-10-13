<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usage', function (Blueprint $table) {
            $table->string('operation', 32)->default('chat');
            $table->unsignedBigInteger('output_price_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Keep operation and nullable output price snapshots for historical bills.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cloud_engine_api_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('key_hash', 64);
            $table->string('fingerprint', 64);
            $table->foreignId('operation_id')->constrained('cloud_engine_operations')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['customer_id', 'key_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloud_engine_api_requests');
    }
};

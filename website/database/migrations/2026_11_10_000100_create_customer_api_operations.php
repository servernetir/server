<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_api_operations', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('token_id')->nullable()->constrained('customer_api_tokens')->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('key_hash', 64);
            $table->string('fingerprint', 64);
            $table->string('action', 40);
            $table->string('state', 24)->default('processing');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->json('response')->nullable();
            $table->timestamps();
            $table->unique(['customer_id', 'key_hash'], 'customer_api_operation_key');
            $table->index(['service_id', 'state']);
        });
        Schema::table('customer_api_tokens', fn (Blueprint $table) => $table->json('cloud_service_ids')->nullable());
    }

    public function down(): void
    {
        Schema::table('customer_api_tokens', fn (Blueprint $table) => $table->dropColumn('cloud_service_ids'));
        Schema::dropIfExists('customer_api_operations');
    }
};

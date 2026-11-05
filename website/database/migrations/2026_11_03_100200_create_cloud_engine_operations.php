<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cloud_engine_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vm_id')->constrained('cloud_engine_vms')->restrictOnDelete();
            $table->string('action', 32);
            $table->string('idempotency_key', 64)->unique();
            $table->string('fingerprint', 64);
            $table->string('status', 32)->default('pending');
            $table->string('phase', 40)->default('queued');
            $table->text('payload');
            $table->text('receipt')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->uuid('lease_token')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('deadline_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'lease_until']);
            $table->index(['vm_id', 'action']);
        });
        Schema::create('cloud_engine_operation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operation_id')->constrained('cloud_engine_operations')->restrictOnDelete();
            $table->string('phase', 40);
            $table->string('status', 32);
            $table->string('error_code', 64)->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloud_engine_operation_events');
        Schema::dropIfExists('cloud_engine_operations');
    }
};

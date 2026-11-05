<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cloud_engine_agent_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('node_id')->unique()->constrained('cloud_engine_nodes')->restrictOnDelete();
            $table->text('secret');
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });
        Schema::create('cloud_engine_traffic_cursors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vm_id')->unique()->constrained('cloud_engine_vms')->restrictOnDelete();
            $table->string('interface', 32);
            $table->string('epoch', 64);
            $table->unsignedBigInteger('sequence');
            $table->unsignedBigInteger('in_bytes');
            $table->unsignedBigInteger('out_bytes');
            $table->timestamp('sampled_at');
            $table->timestamps();
        });
        Schema::create('cloud_engine_traffic_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vm_id')->constrained('cloud_engine_vms')->restrictOnDelete();
            $table->string('interface', 32);
            $table->string('epoch', 64);
            $table->unsignedBigInteger('sequence');
            $table->string('payload_hash', 64);
            $table->timestamp('sampled_at');
            $table->string('month', 7);
            $table->unsignedBigInteger('in_bytes');
            $table->unsignedBigInteger('out_bytes');
            $table->unsignedBigInteger('in_delta')->default(0);
            $table->unsignedBigInteger('out_delta')->default(0);
            $table->string('quality', 24);
            $table->string('reason', 80);
            $table->timestamps();
            $table->unique(['vm_id', 'epoch', 'sequence'], 'engine_traffic_sample_identity');
            $table->index(['vm_id', 'month']);
        });
        Schema::create('cloud_engine_traffic_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vm_id')->constrained('cloud_engine_vms')->restrictOnDelete();
            $table->string('month', 7);
            $table->unsignedSmallInteger('threshold');
            $table->unsignedBigInteger('observed_bytes');
            $table->unsignedBigInteger('quota_bytes');
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
            $table->unique(['vm_id', 'month', 'threshold'], 'engine_traffic_alert_identity');
        });
        Schema::create('cloud_engine_console_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vm_id')->constrained('cloud_engine_vms')->restrictOnDelete();
            $table->unsignedBigInteger('service_id');
            $table->unsignedBigInteger('customer_id');
            $table->foreignId('node_id')->constrained('cloud_engine_nodes')->restrictOnDelete();
            $table->unsignedBigInteger('vmid');
            $table->string('vm_uuid', 64);
            $table->string('fingerprint', 64);
            $table->string('token_hash', 64)->unique();
            $table->string('session_hash', 64)->nullable()->unique();
            $table->timestamp('expires_at');
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['vm_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloud_engine_console_grants');
        Schema::dropIfExists('cloud_engine_traffic_alerts');
        Schema::dropIfExists('cloud_engine_traffic_samples');
        Schema::dropIfExists('cloud_engine_traffic_cursors');
        Schema::dropIfExists('cloud_engine_agent_credentials');
    }
};

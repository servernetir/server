<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cloud_engine_nodes', function (Blueprint $t) {
            $t->id();
            $t->string('name', 80)->unique();
            $t->string('location_code', 32)->index();
            $t->string('api_url', 255);
            $t->string('pve_node', 80);
            $t->string('token_id', 190);
            $t->text('token_secret');
            $t->string('tls_ca_file', 255)->nullable();
            $t->string('storage', 80);
            $t->string('bridge', 32);
            $t->string('gateway', 45);
            $t->string('arch', 12)->default('x86');
            $t->boolean('sales_enabled')->default(false);
            $t->boolean('draining')->default(false);
            $t->string('health', 24)->default('unknown');
            $t->timestamp('health_checked_at')->nullable();
            $t->unsignedInteger('health_ttl_seconds')->default(300);
            foreach (['vcpu', 'ram_mb', 'disk_gb'] as $resource) {
                $t->unsignedBigInteger($resource.'_capacity')->default(0);
                $t->unsignedBigInteger($resource.'_reserve')->default(0);
            }
            $t->unsignedInteger('vmid_min')->default(100);
            $t->unsignedInteger('vmid_max')->default(999999999);
            $t->json('metadata')->nullable();
            $t->timestamps();
        });
        Schema::create('cloud_engine_subnets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('node_id')->constrained('cloud_engine_nodes')->restrictOnDelete();
            $t->string('cidr', 18)->unique();
            $t->string('gateway', 15);
            $t->boolean('enabled')->default(false);
            $t->json('metadata')->nullable();
            $t->timestamps();
        });
        Schema::create('cloud_engine_images', function (Blueprint $t) {
            $t->id();
            $t->foreignId('node_id')->constrained('cloud_engine_nodes')->restrictOnDelete();
            $t->string('key', 64);
            $t->string('family', 40);
            $t->string('version', 40);
            $t->string('arch', 12)->default('x86');
            $t->unsignedInteger('template_vmid');
            $t->string('checksum_sha256', 64);
            $t->string('source_url', 1024);
            $t->timestamp('accepted_at')->nullable();
            $t->timestamp('initialization_verified_at')->nullable();
            $t->timestamp('license_verified_at')->nullable();
            $t->unsignedInteger('min_ram_mb')->default(0);
            $t->unsignedInteger('min_disk_gb')->default(0);
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->unique(['node_id', 'key', 'arch']);
        });
        Schema::create('cloud_engine_plan_nodes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('cloud_plan_id');
            $t->foreignId('node_id')->constrained('cloud_engine_nodes')->restrictOnDelete();
            $t->boolean('enabled')->default(false);
            $t->timestamps();
            $t->unique(['cloud_plan_id', 'node_id']);
        });
        Schema::create('cloud_engine_addresses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subnet_id')->constrained('cloud_engine_subnets')->restrictOnDelete();
            $t->foreignId('node_id')->constrained('cloud_engine_nodes')->restrictOnDelete();
            $t->string('address', 15)->unique();
            $t->string('state', 24)->default('free')->index();
            $t->unsignedBigInteger('service_id')->nullable()->unique();
            $t->unsignedBigInteger('vm_id')->nullable()->unique();
            $t->timestamp('allocated_at')->nullable();
            $t->text('quarantine_reason')->nullable();
            $t->timestamps();
        });
        Schema::create('cloud_engine_vms', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('service_id')->unique();
            $t->foreignId('node_id')->constrained('cloud_engine_nodes')->restrictOnDelete();
            // Historical deleted reservations retain the former address. Active ownership
            // is unique on addresses.vm_id/service_id, allowing verified later reuse.
            $t->foreignId('address_id')->nullable()->constrained('cloud_engine_addresses')->restrictOnDelete();
            $t->foreignId('image_id')->constrained('cloud_engine_images')->restrictOnDelete();
            $t->unsignedInteger('vmid');
            $t->string('name', 80);
            $t->unsignedInteger('vcpu');
            $t->unsignedInteger('ram_mb');
            $t->unsignedInteger('disk_gb');
            $t->string('status', 24)->default('reserved')->index();
            $t->string('fingerprint', 64);
            $t->json('metadata')->nullable();
            $t->text('credentials')->nullable();
            $t->timestamps();
            $t->unique(['node_id', 'vmid']);
        });
        Schema::create('cloud_engine_ip_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('address_id')->constrained('cloud_engine_addresses')->restrictOnDelete();
            $t->unsignedBigInteger('service_id')->nullable();
            $t->unsignedBigInteger('vm_id')->nullable();
            $t->string('action', 30);
            $t->json('evidence')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['address_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['ip_events', 'vms', 'addresses', 'plan_nodes', 'images', 'subnets', 'nodes'] as $table) {
            Schema::dropIfExists('cloud_engine_'.$table);
        }
    }
};

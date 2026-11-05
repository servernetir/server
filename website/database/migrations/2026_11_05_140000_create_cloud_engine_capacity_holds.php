<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cloud_engine_capacity_holds', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('node_id')->constrained('cloud_engine_nodes')->restrictOnDelete();
            $t->foreignId('vm_id')->constrained('cloud_engine_vms')->restrictOnDelete();
            $t->string('purpose', 30);
            $t->string('status', 24)->default('reserved')->index();
            foreach (['vcpu', 'ram_mb', 'disk_gb'] as $resource) $t->unsignedBigInteger($resource)->default(0);
            $t->timestamps();
            $t->index(['node_id', 'status']);
        });
    }
    public function down(): void { Schema::dropIfExists('cloud_engine_capacity_holds'); }
};

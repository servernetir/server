<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Registering the same PVE node twice must not double its apparent capacity
        // or allocate the same physical VMID independently through two inventory rows.
        Schema::table('cloud_engine_nodes', fn (Blueprint $t) =>
            $t->unique(['api_url', 'pve_node'], 'cloud_engine_node_identity_unique'));
    }

    public function down(): void
    {
        Schema::table('cloud_engine_nodes', fn (Blueprint $t) => $t->dropUnique('cloud_engine_node_identity_unique'));
    }
};

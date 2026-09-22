<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cloud_images') && ! Schema::hasColumn('cloud_images', 'min_ram_mb')) {
            Schema::table('cloud_images', function (Blueprint $table) {
                $table->unsignedInteger('min_ram_mb')->default(0)->after('min_disk_gb');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('cloud_images') && Schema::hasColumn('cloud_images', 'min_ram_mb')) {
            Schema::table('cloud_images', function (Blueprint $table) {
                $table->dropColumn('min_ram_mb');
            });
        }
    }
};

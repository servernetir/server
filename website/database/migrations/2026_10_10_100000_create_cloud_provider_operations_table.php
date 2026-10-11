<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cloud_plans', function (Blueprint $table) {
            $table->json('network_terms')->nullable();
            $table->unsignedInteger('boot_disk_gb')->nullable();
            $table->unsignedInteger('data_disk_gb')->nullable();
        });
        Schema::create('cloud_provider_operations', function (Blueprint $table) {
            $table->id();
            $table->string('operation_key', 64)->unique();
            $table->string('provider', 30);
            $table->string('action', 30);
            $table->string('resource_ref')->nullable();
            $table->string('task_ref')->nullable();
            $table->string('state', 30)->default('submitting');
            $table->text('payload');
            $table->timestamps();
            $table->index(['provider', 'resource_ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloud_provider_operations');
        Schema::table('cloud_plans', function (Blueprint $table) {
            $table->dropColumn(['network_terms', 'boot_disk_gb', 'data_disk_gb']);
        });
    }
};

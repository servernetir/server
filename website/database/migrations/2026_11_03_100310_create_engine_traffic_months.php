<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cloud_engine_traffic_months', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vm_id')->constrained('cloud_engine_vms')->restrictOnDelete();
            $table->string('month', 7);
            $table->unsignedBigInteger('in_bytes')->default(0);
            $table->unsignedBigInteger('out_bytes')->default(0);
            $table->unsignedBigInteger('samples')->default(0);
            $table->unsignedBigInteger('gaps')->default(0);
            $table->json('gap_reasons');
            $table->timestamp('first_sample_at');
            $table->timestamp('last_sample_at');
            $table->timestamps();
            $table->unique(['vm_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloud_engine_traffic_months');
    }
};

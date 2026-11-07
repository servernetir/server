<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hosting_upgrades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->unique()->constrained()->restrictOnDelete();
            // Nullable unique slot serializes requests, including different target plans.
            $table->unsignedBigInteger('open_service_id')->nullable()->unique();
            $table->string('status', 24)->default('awaiting_payment')->index();
            $table->json('quote');
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hosting_upgrades');
    }
};

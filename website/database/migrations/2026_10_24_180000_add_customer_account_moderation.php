<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedInteger('session_version')->default(0);
        });
        Schema::create('customer_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name');
            $table->string('from_status', 24);
            $table->string('to_status', 24);
            $table->text('reason');
            $table->timestamp('created_at');
            $table->index(['customer_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_status_changes');
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('session_version'));
    }
};

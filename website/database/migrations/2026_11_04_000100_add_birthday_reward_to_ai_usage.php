<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Existing AI migrations use November timestamps. This must run after their schema
// on a fresh database as well as on an already-installed production database.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usage', function (Blueprint $table) {
            $table->foreignId('birthday_reward_id')->nullable()->constrained('birthday_rewards')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage', fn (Blueprint $table) => $table->dropConstrainedForeignId('birthday_reward_id'));
    }
};

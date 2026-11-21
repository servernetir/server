<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_admin_actions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->char('token_id', 80)->index();
            $t->string('target', 80);
            $t->char('target_hash', 64);
            $t->text('command');
            $t->string('status', 24)->default('pending');
            $t->dateTime('expires_at');
            $t->dateTime('approved_at')->nullable();
            $t->dateTime('executed_at')->nullable();
            $t->text('result')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'status']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('mcp_admin_actions');
    }
};

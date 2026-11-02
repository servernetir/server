<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('urmia_auth_requests', function (Blueprint $table) {
            $table->string('request_key', 80)->primary();
            $table->uuid('challenge_id')->nullable()->unique();
            $table->longText('payload');
            $table->timestamp('updated_at')->nullable();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('urmia_auth_requests');
    }
};

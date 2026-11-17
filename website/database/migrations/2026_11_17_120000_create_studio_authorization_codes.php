<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('studio_authorization_codes', function (Blueprint $table) {
            $table->char('code_hash', 64)->primary();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->char('challenge', 43);
            $table->string('redirect_uri', 512);
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('studio_authorization_codes');
    }
};

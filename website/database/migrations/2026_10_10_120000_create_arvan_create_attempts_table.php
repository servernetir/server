<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arvan_create_attempts', function (Blueprint $table) {
            $table->string('operation_key', 64)->primary();
            $table->string('region');
            $table->string('hostname');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        // Do not roll this table back while an order may still be reconciled:
        // the journal prevents a lost provider response buying another VM.
        Schema::dropIfExists('arvan_create_attempts');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_tax_profiles', function (Blueprint $table) {
            $table->bigInteger('vat_output_irt')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Retain reviewed tax profiles on application rollback.');
    }
};

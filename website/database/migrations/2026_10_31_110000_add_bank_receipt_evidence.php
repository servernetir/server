<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bank_transfer_receipts') || Schema::hasColumn('bank_transfer_receipts', 'evidence_path')) { return; }
        Schema::table('bank_transfer_receipts', function (Blueprint $table): void {
            $table->string('evidence_path')->nullable();
            $table->string('evidence_name')->nullable();
            $table->string('evidence_mime', 80)->nullable();
            $table->string('evidence_sha256', 64)->nullable();
            $table->unsignedInteger('evidence_size')->nullable();
        });
    }

    public function down(): void
    {
        // Evidence is an audit record. App rollback keeps these nullable fields and files.
    }
};

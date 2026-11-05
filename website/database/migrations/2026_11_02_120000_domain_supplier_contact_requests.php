<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('domain_supplier_contact_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('supplier_account_id')->index();
            $table->unsignedBigInteger('customer_profile_id')->index();
            $table->string('tld', 253);
            $table->string('request_hash', 64)->unique();
            $table->string('active_key', 64)->nullable()->unique();
            $table->string('supplier_identity', 64);
            $table->longText('payload'); // encrypted:array
            $table->text('handle')->nullable(); // encrypted
            $table->string('status', 24)->default('prepared')->index();
            $table->string('process_id', 128)->nullable();
            $table->string('tracking_id', 128)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Retained after live use; never delete unresolved contact evidence as a code rollback.
        if (\Illuminate\Support\Facades\DB::table('domain_supplier_contact_requests')->whereNotNull('sent_at')->exists()) {
            throw new \RuntimeException('supplier_contact_evidence_must_be_retained');
        }
        Schema::dropIfExists('domain_supplier_contact_requests');
    }
};

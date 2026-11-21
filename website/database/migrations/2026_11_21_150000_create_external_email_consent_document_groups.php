<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A shared document pointer is intake metadata only, not a verification or grant.
        Schema::create('external_email_consent_document_batches', function (Blueprint $table) {
            $table->id();
            $table->text('document_reference');
            $table->unsignedInteger('record_count');
            $table->unsignedBigInteger('created_by');
            $table->timestamp('created_at');
        });
        Schema::create('external_email_consent_document_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('batch_id');
            $table->unsignedBigInteger('review_id')->unique('ext_email_document_review_unique');
            $table->foreign('batch_id', 'ext_email_document_batch_fk')->references('id')
                ->on('external_email_consent_document_batches')->restrictOnDelete();
            $table->foreign('review_id', 'ext_email_document_review_fk')->references('id')
                ->on('external_email_consent_reviews')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Disposable development databases only. Retain source-link history on production rollback.
        Schema::dropIfExists('external_email_consent_document_items');
        Schema::dropIfExists('external_email_consent_document_batches');
    }
};

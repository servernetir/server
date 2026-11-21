<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only staff evidence decisions; this is not a consent or recipient table.
        Schema::create('external_email_consent_review_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('review_id');
            $table->string('decision', 16);
            $table->string('reason', 40);
            $table->string('source_kind', 40)->nullable();
            $table->text('review_reference')->nullable();
            $table->unsignedBigInteger('reviewed_by');
            $table->timestamp('created_at');
            $table->index(['review_id', 'id'], 'ext_email_review_event_order');
            $table->foreign('review_id', 'ext_email_review_event_parent')->references('id')
                ->on('external_email_consent_reviews')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Disposable development databases only. Retain audit history on production rollback.
        Schema::dropIfExists('external_email_consent_review_events');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Evidence references only. Never a sendable address book or grant.
        Schema::create('external_email_consent_reviews', function (Blueprint $table) {
            $table->id();
            $table->char('email_digest', 64)->unique();
            $table->char('digest_key_id', 64)->index();
            $table->date('consent_date');
            $table->text('evidence_reference');
            $table->unsignedBigInteger('imported_by');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Only for disposable development databases; retain evidence on production rollback.
        Schema::dropIfExists('external_email_consent_reviews');
    }
};

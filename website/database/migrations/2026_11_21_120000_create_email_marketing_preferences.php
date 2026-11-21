<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_marketing_preferences', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            // Retain address-specific withdrawal/suppression when an account is removed.
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->char('email_digest', 64)->index();
            // Detect accidental loss of a previous digest key; do not create new grants
            // when that would make an old withdrawal unrecognisable.
            $table->char('digest_key_id', 64)->index();
            $table->string('state', 16)->default('pending');
            $table->timestamp('consented_at')->nullable();
            $table->string('consent_version', 60)->nullable();
            $table->string('consent_locale', 5)->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamp('suppressed_at')->nullable();
            $table->string('suppression_reason', 60)->nullable();
            $table->timestamps();
            $table->unique(['customer_id', 'email_digest'], 'email_marketing_customer_digest_unique');
            $table->index('state');
        });

        // Append-only history. Never include an address, name, token or request payload.
        Schema::create('email_marketing_preference_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_marketing_preference_id')
                ->constrained('email_marketing_preferences', 'id', 'email_marketing_events_preference_fk')
                ->restrictOnDelete();
            $table->string('event', 24);
            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('consent_version', 60)->nullable();
            $table->timestamp('created_at');
            $table->index(['email_marketing_preference_id', 'id'], 'email_marketing_events_history_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_marketing_preference_events');
        Schema::dropIfExists('email_marketing_preferences');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_marketing_recipients', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->char('email_digest', 64)->unique();
            $table->char('digest_key_id', 64);
            $table->text('email'); // encrypted by the model; never copied into logs or URLs
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->unsignedBigInteger('review_id')->nullable()->unique();
            $table->string('basis', 24);
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamp('suppressed_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('email_marketing_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('subject', 180);
            $table->text('body');
            $table->string('audience', 16);
            $table->string('status', 16)->default('draft')->index();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('queued_by')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamps();
        });
        Schema::create('email_marketing_deliveries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('campaign_id');
            $table->unsignedBigInteger('recipient_id');
            $table->string('status', 16)->default('queued')->index();
            $table->string('reason', 40)->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['campaign_id', 'recipient_id'], 'email_campaign_recipient_unique');
            $table->foreign('campaign_id')->references('id')->on('email_marketing_campaigns')->restrictOnDelete();
            $table->foreign('recipient_id')->references('id')->on('email_marketing_recipients')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Only disposable test databases. Production rollback retains recipients and delivery history.
        Schema::dropIfExists('email_marketing_deliveries');
        Schema::dropIfExists('email_marketing_campaigns');
        Schema::dropIfExists('email_marketing_recipients');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_permissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('ability');
            $t->unique(['user_id', 'ability']);
        });
        Schema::create('marketing_campaigns', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('status')->default('draft')->index();
            $t->unsignedInteger('version')->default(1);
            $t->unsignedBigInteger('scheduler_cursor')->default(0);
            $t->char('currency_code', 3);
            $t->string('trigger')->default('manual');
            $t->string('timezone')->default('Asia/Tehran');
            $t->json('rule');
            $t->json('audience');
            $t->json('automation');
            $t->json('message');
            $t->bigInteger('budget');
            $t->bigInteger('held')->default(0);
            $t->bigInteger('spent')->default(0);
            $t->unsignedInteger('max_uses');
            $t->unsignedInteger('max_per_customer')->default(1);
            $t->boolean('messages_paused')->default(true);
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('ends_at')->nullable();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
        });
        Schema::create('marketing_preferences', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->unique()->constrained()->restrictOnDelete();
            $t->string('consent')->default('unknown');
            $t->timestamp('consent_at')->nullable();
            $t->boolean('suppressed')->default(false);
            $t->string('birth_calendar')->nullable();
            $t->unsignedSmallInteger('birth_year')->nullable();
            $t->unsignedTinyInteger('birth_month')->nullable();
            $t->unsignedTinyInteger('birth_day')->nullable();
            $t->json('tags')->nullable();
            $t->timestamps();
        });
        Schema::create('marketing_grants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('campaign_id')->constrained('marketing_campaigns')->restrictOnDelete();
            $t->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('code', 40)->unique();
            $t->string('issue_key', 64)->unique();
            $t->string('track_token', 64)->unique();
            $t->json('snapshot');
            $t->bigInteger('liability');
            $t->boolean('budget_held')->default(false);
            $t->timestamp('starts_at');
            $t->timestamp('expires_at')->index();
            $t->timestamp('revoked_at')->nullable();
            $t->string('revoke_reason')->nullable();
            $t->timestamps();
        });
        Schema::create('marketing_redemptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('campaign_id')->constrained('marketing_campaigns')->restrictOnDelete();
            $t->foreignId('grant_id')->constrained('marketing_grants')->restrictOnDelete();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('invoice_id')->unique()->constrained()->restrictOnDelete();
            $t->string('status')->index();
            $t->bigInteger('amount');
            $t->bigInteger('tax_reduction')->default(0);
            $t->json('snapshot');
            $t->timestamp('expires_at');
            $t->timestamp('committed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('marketing_payment_reconciliations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payment_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $t->bigInteger('credited_amount');
            $t->char('currency_code', 3);
            $t->timestamp('created_at');
        });
        Schema::create('marketing_runs', function (Blueprint $t) {
            $t->id();
            $t->string('run_key', 64)->unique();
            $t->foreignId('campaign_id')->constrained('marketing_campaigns')->restrictOnDelete();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->string('trigger');
            $t->timestamp('occurred_at');
            $t->timestamps();
            $t->index(['customer_id', 'trigger', 'occurred_at']);
        });
        Schema::create('marketing_dispatch_locks', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
        });
        DB::table('marketing_dispatch_locks')->insert(['id' => 1]);
        Schema::create('marketing_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('campaign_id')->constrained('marketing_campaigns')->restrictOnDelete();
            $t->foreignId('grant_id')->constrained('marketing_grants')->restrictOnDelete();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->string('delivery_key', 64)->unique();
            $t->string('status')->default('scheduled')->index();
            $t->string('kind')->default('offer');
            $t->text('body'); // encrypted model cast; never copied to logs
            $t->unsignedInteger('segments');
            $t->bigInteger('estimated_cost')->nullable();
            $t->string('reason')->nullable();
            $t->timestamp('scheduled_at')->index();
            $t->timestamp('attempted_at')->nullable();
            $t->timestamp('accepted_at')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->timestamps();
        });
        Schema::create('marketing_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('campaign_id')->constrained('marketing_campaigns')->restrictOnDelete();
            $t->foreignId('grant_id')->nullable()->constrained('marketing_grants')->restrictOnDelete();
            $t->foreignId('invoice_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('event_key', 64)->unique();
            $t->string('kind')->index();
            $t->timestamp('created_at');
        });
        Schema::create('marketing_audits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('campaign_id')->nullable()->constrained('marketing_campaigns')->restrictOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('action');
            $t->string('reason');
            $t->json('details')->nullable();
            $t->timestamp('created_at');
        });
        Schema::table('invoice_items', fn (Blueprint $t) => $t->json('marketing_context')->nullable());
    }

    public function down(): void
    {
        Schema::table('invoice_items', fn (Blueprint $t) => $t->dropColumn('marketing_context'));
        foreach (['marketing_audits', 'marketing_events', 'marketing_deliveries', 'marketing_runs',
            'marketing_dispatch_locks', 'marketing_payment_reconciliations', 'marketing_redemptions', 'marketing_grants', 'marketing_preferences', 'marketing_campaigns', 'marketing_permissions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

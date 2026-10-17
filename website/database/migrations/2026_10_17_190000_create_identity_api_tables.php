<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_applications', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('customer_id')->index();
            $t->string('name', 80);
            $t->string('status', 20)->default('active');
            $t->string('terms_version', 40);
            $t->timestamp('accepted_at');
            $t->unsignedBigInteger('daily_cap_irt');
            $t->unsignedInteger('concurrency')->default(2);
            $t->timestamps();
        });
        Schema::create('identity_prices', function (Blueprint $t) {
            $t->id();
            $t->string('service', 100)->index();
            $t->unsignedBigInteger('source_amount');
            $t->string('source_currency', 3); // IRR or IRT, whole currency units.
            $t->boolean('purchase_tax_included');
            $t->unsignedBigInteger('purchase_tax_amount')->default(0);
            $t->unsignedInteger('markup_bps')->default(500);
            $t->boolean('confirmed_zero_cost')->default(false);
            $t->unsignedInteger('daily_quota')->default(100);
            $t->string('billing_policy', 80); // Explicit contract: valid_data_v1.
            $t->string('evidence', 200);
            $t->string('access_evidence', 200);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamp('effective_at');
            $t->timestamp('valid_until');
            $t->timestamps();
        });
        Schema::create('identity_controls', function (Blueprint $t) {
            $t->string('key', 100)->primary();
            $t->json('value');
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
        });
        Schema::create('identity_audits', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('action', 80);
            $t->json('detail');
            $t->timestamps();
        });
        DB::table('identity_controls')->insert(['key' => 'provider_mutex', 'value' => 'null', 'created_at' => now(), 'updated_at' => now()]);
        Schema::create('identity_requests', function (Blueprint $t) {
            $t->id();
            $t->string('public_id', 36)->unique();
            $t->unsignedBigInteger('customer_id')->index();
            $t->unsignedBigInteger('application_id')->index();
            $t->unsignedBigInteger('token_id')->nullable()->index();
            $t->string('service', 100);
            $t->string('operation', 30)->default('execute');
            $t->char('key_hash', 64);
            $t->char('fingerprint', 64);
            $t->unique(['application_id', 'service', 'operation', 'key_hash'], 'identity_idempotency');
            $t->string('processing_status', 24)->default('accepted');
            $t->string('inquiry_outcome', 40)->nullable();
            $t->string('financial_status', 30)->default('reserved')->index();
            $t->string('currency_code', 3)->default('IRT');
            $t->unsignedBigInteger('hold_irt');
            $t->unsignedBigInteger('charged_irt')->default(0);
            $t->unsignedBigInteger('credit_entry_id')->nullable()->unique();
            $t->unsignedBigInteger('refund_entry_id')->nullable()->unique();
            $t->json('quote');
            $t->longText('payload')->nullable();
            $t->longText('result')->nullable();
            $t->longText('provider_evidence')->nullable();
            $t->string('error_code', 60)->nullable();
            $t->boolean('discrepancy')->default(false);
            $t->unsignedInteger('latency_ms')->nullable();
            $t->unsignedSmallInteger('upstream_http')->nullable();
            $t->timestamp('decide_by')->index();
            $t->timestamp('retain_until')->index();
            $t->timestamp('dispatch_at')->nullable();
            $t->timestamp('settled_at')->nullable();
            $t->timestamps();
            $t->index(['customer_id', 'currency_code', 'financial_status'], 'identity_wallet_holds');
        });
        Schema::table('customer_api_tokens', function (Blueprint $t) {
            $t->unsignedBigInteger('identity_application_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        // Financial records and idempotency tombstones must survive app rollback.
        throw new RuntimeException('Identity financial schema is retained; use the documented forward-only rollback.');
    }
};

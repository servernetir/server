<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ovh_orders', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('service_id')->unique();
            $t->uuid('idempotency_key')->unique();
            $t->string('account_hash', 64);
            $t->string('spec_hash', 64);
            $t->json('spec');
            $t->string('phase', 40)->default('new')->index();
            $t->string('resume_phase', 40)->nullable();
            $t->string('cart_id', 100)->nullable()->unique();
            $t->unsignedBigInteger('item_id')->nullable();
            $t->timestamp('assigned_at')->nullable();
            $t->string('ipv4', 45)->nullable();
            $t->string('ipv6', 45)->nullable();
            $t->unsignedBigInteger('order_id')->nullable()->unique();
            $t->string('server_ref', 190)->nullable()->unique();
            $t->string('provider_status', 50)->nullable();
            $t->unsignedBigInteger('payment_method_id')->nullable();
            $t->string('financial_state', 40)->default('unverified');
            $t->string('currency', 3)->nullable();
            $t->unsignedBigInteger('cost_minor')->nullable();
            $t->unsignedBigInteger('renewal_minor')->nullable();
            $t->unsignedBigInteger('revenue_minor')->nullable();
            $t->string('revenue_currency', 3)->nullable();
            $t->bigInteger('cost_irt')->nullable();
            $t->bigInteger('revenue_irt')->nullable();
            $t->bigInteger('profit_irt')->nullable();
            $t->json('quote')->nullable();
            $t->json('fx_snapshot')->nullable();
            $t->unsignedBigInteger('invoice_id')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->unsignedInteger('retry_epoch')->default(0);
            $t->timestamp('retry_started_at')->nullable();
            $t->timestamp('next_attempt_at')->nullable()->index();
            $t->uuid('lease_token')->nullable();
            $t->timestamp('locked_until')->nullable();
            $t->timestamp('checkout_sent_at')->nullable();
            $t->timestamp('payment_sent_at')->nullable();
            $t->timestamp('install_sent_at')->nullable();
            $t->unsignedBigInteger('install_task_id')->nullable();
            $t->text('private_key')->nullable();
            $t->text('public_key')->nullable();
            $t->timestamp('key_downloaded_at')->nullable();
            $t->timestamp('ready_at')->nullable();
            $t->timestamp('alerted_at')->nullable();
            $t->string('error_code', 60)->nullable();
            $t->string('error_message', 500)->nullable();
            $t->timestamps();
        });
        Schema::create('ovh_order_events', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('ovh_order_id')->index();
            $t->string('event', 60);
            $t->string('phase', 40);
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('message', 500)->nullable();
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        // Operational rollback must retain this journal if a checkout was sent.
        if (\Illuminate\Support\Facades\DB::table('ovh_orders')->whereNotNull('checkout_sent_at')->exists()) {
            throw new \RuntimeException('OVH financial journal cannot be dropped after checkout.');
        }
        Schema::dropIfExists('ovh_order_events');
        Schema::dropIfExists('ovh_orders');
    }
};

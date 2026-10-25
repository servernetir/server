<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_refund_permissions', function (Blueprint $t) {
            $t->foreignId('customer_id')->primary()->constrained('customers')->restrictOnDelete();
            $t->boolean('enabled')->default(false);
            $t->unsignedBigInteger('changed_by');
            $t->timestamps();
        });
        Schema::create('wallet_refund_requests', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->char('payload_hash', 64);
            $t->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $t->unsignedBigInteger('active_customer_id')->nullable()->unique();
            $t->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $t->text('bank_snapshot');
            $t->char('currency_code', 4)->default('IRT');
            $t->unsignedBigInteger('amount_irt');
            $t->string('status', 16)->default('requested');
            $t->text('customer_note');
            $t->text('review_note')->nullable();
            $t->text('source_note')->nullable();
            $t->unsignedBigInteger('source_payment_id')->nullable()->index();
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->date('payout_date')->nullable()->index();
            $t->string('bank_reference', 150)->nullable();
            $t->char('payout_fingerprint', 64)->nullable()->unique();
            $t->char('payout_hash', 64)->nullable();
            $t->unsignedBigInteger('paid_by')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->foreignId('voucher_id')->nullable()->unique()->constrained('finance_vouchers');
            $t->foreignId('document_id')->nullable()->unique()->constrained('finance_documents');
            $t->foreignId('credit_entry_id')->nullable()->unique()->constrained('credit_ledger');
            $t->timestamps();
            $t->index(['customer_id', 'status']);
        });
        Schema::create('wallet_refund_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('customer_id')->index();
            $t->foreignId('request_id')->nullable()->constrained('wallet_refund_requests')->restrictOnDelete();
            $t->string('actor_type', 16);
            $t->unsignedBigInteger('actor_id');
            $t->string('actor_name', 150);
            $t->string('action', 32);
            $t->json('details');
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        if (DB::table('wallet_refund_requests')->exists() || DB::table('wallet_refund_events')->exists()) {
            throw new RuntimeException('Refund history must be retained; roll back application files without dropping financial records.');
        }
        Schema::dropIfExists('wallet_refund_events');
        Schema::dropIfExists('wallet_refund_requests');
        Schema::dropIfExists('wallet_refund_permissions');
    }
};

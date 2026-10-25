<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_accounts', function (Blueprint $t) {
            $t->id();
            $t->string('name', 150);
            $t->string('type', 24);
            $t->char('currency_code', 4);
            $t->string('owner_name', 150)->nullable();
            $t->unsignedBigInteger('created_by');
            $t->timestamps();
        });
        Schema::create('finance_controls', function (Blueprint $t) {
            $t->id();
            $t->date('locked_through')->nullable();
            $t->timestamps();
        });
        DB::table('finance_controls')->insert(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        Schema::create('finance_vouchers', function (Blueprint $t) {
            $t->id();
            $t->uuid('idempotency_key')->unique();
            $t->char('vendor_invoice_key', 64)->nullable()->index();
            $t->char('payload_hash', 64);
            $t->string('type', 32);
            $t->date('occurred_at')->index();
            $t->string('service_line', 32);
            $t->string('category', 32);
            $t->string('vendor', 150)->nullable();
            $t->string('invoice_number', 150)->nullable();
            $t->string('bank_reference', 150)->nullable();
            $t->string('fx_basis', 255)->nullable();
            $t->text('note');
            $t->bigInteger('amount_irt');
            $t->char('original_currency', 4);
            $t->bigInteger('original_minor');
            $t->char('settlement_currency', 4)->nullable();
            $t->bigInteger('settlement_minor')->nullable();
            $t->foreignId('funding_account_id')->nullable()->constrained('finance_accounts');
            $t->foreignId('target_account_id')->nullable()->constrained('finance_accounts');
            $t->unsignedBigInteger('business_entry_id')->nullable()->index();
            $t->boolean('owns_business_entry')->default(false);
            $t->unsignedBigInteger('adjusts_entry_id')->nullable()->index();
            $t->bigInteger('book_delta_irt')->default(0);
            $t->bigInteger('fx_gain_irt')->default(0);
            $t->unsignedBigInteger('bank_transaction_id')->nullable()->index();
            $t->string('tax_status', 16)->default('pending');
            $t->string('tax_reference', 255)->nullable();
            $t->bigInteger('input_vat_irt')->default(0);
            $t->string('vat_status', 16)->default('none');
            $t->foreignId('reversal_of')->nullable()->unique()->constrained('finance_vouchers');
            $t->string('asset_period', 64)->nullable()->index();
            $t->unsignedBigInteger('created_by');
            $t->timestamps();
            $t->index(['type', 'occurred_at']);
            $t->index(['vendor', 'invoice_number']);
        });
        Schema::create('finance_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('voucher_id')->constrained('finance_vouchers');
            $t->foreignId('account_id')->nullable()->constrained('finance_accounts');
            $t->string('code', 32);
            $t->bigInteger('debit_irt')->default(0);
            $t->bigInteger('credit_irt')->default(0);
            $t->bigInteger('native_debit')->default(0);
            $t->bigInteger('native_credit')->default(0);
            $t->index(['account_id', 'voucher_id']);
        });
        Schema::create('finance_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('voucher_id')->constrained('finance_vouchers');
            $t->string('path');
            $t->string('name');
            $t->string('mime', 100);
            $t->char('sha256', 64);
            $t->unsignedBigInteger('size');
            $t->timestamps();
        });
        Schema::create('finance_assets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('voucher_id')->unique()->constrained('finance_vouchers');
            $t->string('name', 150);
            $t->string('serial', 150)->nullable();
            $t->date('in_service_at');
            $t->unsignedInteger('useful_life_months');
            $t->bigInteger('cost_irt');
            $t->bigInteger('residual_irt')->default(0);
            $t->timestamps();
        });
        Schema::create('finance_tax_profiles', function (Blueprint $t) {
            $t->id();
            $t->date('from_date')->index();
            $t->date('to_date');
            $t->string('legal_name', 150);
            $t->string('entity_type', 16);
            $t->unsignedInteger('income_rate_bp');
            $t->bigInteger('loss_relief_irt')->default(0);
            $t->bigInteger('other_addbacks_irt')->default(0);
            $t->bigInteger('vat_carry_irt')->default(0);
            $t->string('legal_basis', 255);
            $t->boolean('reviewed')->default(false);
            $t->unsignedBigInteger('created_by');
            $t->timestamps();
        });
        Schema::create('finance_tax_reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('voucher_id')->constrained('finance_vouchers');
            $t->string('status', 16);
            $t->string('reference', 255);
            $t->unsignedBigInteger('created_by');
            $t->timestamps();
            $t->index(['voucher_id', 'id']);
        });
        Schema::create('finance_bank_imports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('account_id')->constrained('finance_accounts');
            $t->string('path');
            $t->char('sha256', 64);
            $t->unsignedBigInteger('created_by');
            $t->timestamps();
            $t->unique(['account_id', 'sha256']);
        });
        Schema::create('finance_bank_transactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('import_id')->constrained('finance_bank_imports');
            $t->foreignId('account_id')->constrained('finance_accounts');
            $t->date('occurred_at')->index();
            $t->string('reference', 150);
            $t->char('currency_code', 4);
            $t->bigInteger('credit_minor');
            $t->bigInteger('debit_minor');
            $t->char('fingerprint', 64)->unique();
            $t->timestamps();
        });
        Schema::create('finance_bank_matches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('bank_transaction_id')->constrained('finance_bank_transactions');
            $t->unsignedBigInteger('payment_id')->index();
            $t->bigInteger('amount_minor');
            $t->bigInteger('fee_minor')->default(0);
            $t->string('reference', 255);
            $t->unsignedBigInteger('created_by');
            $t->timestamp('voided_at')->nullable();
            $t->unsignedBigInteger('voided_by')->nullable();
            $t->string('void_reason', 255)->nullable();
            $t->timestamps();
        });
        Schema::create('finance_audit_events', function (Blueprint $t) {
            $t->id();
            $t->string('action', 32);
            $t->unsignedBigInteger('actor_id');
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->json('details');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        // Production rollback must preserve financial records. No automatic drop of populated tables.
        if (DB::table('finance_vouchers')->exists() || DB::table('finance_accounts')->exists() || DB::table('finance_tax_profiles')->exists()) {
            throw new RuntimeException('Accounting contains records; restore application files and retain the accounting tables.');
        }
        foreach (['finance_tax_reviews', 'finance_bank_matches', 'finance_bank_transactions', 'finance_bank_imports', 'finance_audit_events', 'finance_tax_profiles', 'finance_assets', 'finance_documents', 'finance_lines', 'finance_vouchers', 'finance_controls', 'finance_accounts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

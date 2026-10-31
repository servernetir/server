<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_supplier_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->string('environment', 16)->default('production');
            $table->string('label', 100);
            $table->text('credentials')->nullable(); // Laravel encrypted:array
            $table->json('settings')->nullable();
            $table->boolean('legacy_config')->default(false);
            $table->boolean('active')->default(true);
            $table->boolean('sales_enabled')->default(false);
            $table->boolean('contract_verified')->default(false);
            $table->boolean('identity_verified')->default(false);
            $table->boolean('pricing_verified')->default(false);
            $table->string('connection_status', 20)->default('unknown');
            $table->unsignedBigInteger('balance_minor')->nullable();
            $table->string('balance_currency', 3)->nullable();
            $table->json('capabilities')->nullable();
            $table->timestamp('health_checked_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->index(['provider', 'environment', 'active']);
        });

        Schema::create('domain_supplier_contacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('supplier_account_id')->index();
            $table->unsignedBigInteger('customer_profile_id')->index();
            $table->string('role', 16)->default('registrant');
            $table->string('handle', 128);
            $table->longText('sent_data')->nullable(); // encrypted:array
            $table->string('status', 20)->default('active');
            $table->timestamp('verified_at')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->string('evidence', 500)->nullable();
            $table->timestamps();
            $table->unique(['supplier_account_id', 'customer_profile_id', 'role'], 'domain_supplier_contact_profile_unique');
            $table->unique(['supplier_account_id', 'handle'], 'domain_supplier_contact_handle_unique');
        });

        Schema::create('domain_supplier_operations', function (Blueprint $table) {
            $table->id();
            // Retain financial audit rows even when a customer/domain is archived.
            $table->unsignedBigInteger('domain_id')->nullable()->index();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->unsignedBigInteger('supplier_account_id')->index();
            $table->string('operation', 24);
            $table->string('idempotency_key', 128)->unique();
            $table->string('fqdn', 253);
            // NULL only after a definitive terminal result. One unresolved purchase per FQDN,
            // independent of provider/account/domain-row or an expired application cache lock.
            $table->string('active_fqdn', 253)->nullable()->unique('domain_supplier_active_fqdn_unique');
            $table->unsignedSmallInteger('period_months');
            $table->string('status', 24)->default('prepared')->index();
            $table->string('object_id', 253)->nullable();
            $table->string('process_id', 100)->nullable();
            $table->string('tracking_id', 100)->nullable();
            $table->string('request_hash', 64)->nullable();
            $table->timestamp('approved_at')->nullable()->index();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->string('approval_hash', 64)->nullable();
            $table->longText('request_context')->nullable(); // encrypted; may contain EPP/contact handles
            $table->json('payment_snapshot')->nullable();
            $table->json('quote_snapshot')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();
            $table->unique(['invoice_id', 'operation'], 'domain_supplier_invoice_operation_unique');
            $table->index(['supplier_account_id', 'fqdn']);
        });

        Schema::table('domains', function (Blueprint $table) {
            $table->unsignedBigInteger('supplier_account_id')->nullable()->index();
            $table->string('supplier_object_id', 253)->nullable();
            $table->json('supplier_quote')->nullable();
        });
        Schema::table('domain_quotes', function (Blueprint $table) {
            $table->unsignedBigInteger('supplier_account_id')->nullable()->index();
            $table->string('operation', 24)->nullable();
            $table->unsignedSmallInteger('period_months')->nullable();
            $table->unsignedBigInteger('purchase_minor')->nullable();
            $table->string('currency', 3)->nullable();
            $table->json('fx_snapshot')->nullable();
            $table->json('cost_components')->nullable();
            $table->string('selection_reason', 100)->nullable();
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('supplier_account_id')->nullable()->index();
            $table->json('supplier_quote')->nullable();
            $table->unsignedBigInteger('supplier_payment_ledger_id')->nullable()->index();
        });
        Schema::table('registry_handles', function (Blueprint $table) {
            $table->unsignedBigInteger('supplier_account_id')->nullable()->index();
        });

        // Existing positive OpenProvider object IDs are historical evidence. Keep op_id unchanged.
        // No lookup, registration, invoice, contact edit or DNS mutation happens in this migration.
        $legacyFingerprint = \App\Services\Domain\Providers\LegacyAccountIdentity::currentFingerprint();
        $legacy = DB::table('domain_supplier_accounts')->insertGetId([
            'provider' => 'openprovider', 'environment' => 'production',
            'label' => 'OpenProvider (existing configuration)', 'legacy_config' => true,
            'settings' => $legacyFingerprint === null ? null : json_encode([
                \App\Services\Domain\Providers\LegacyAccountIdentity::SETTING => $legacyFingerprint,
            ], JSON_THROW_ON_ERROR),
            'active' => true, 'sales_enabled' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        // A historical global token may have been issued before a credential
        // change. Refresh it against the account pinned by this migration.
        \Illuminate\Support\Facades\Cache::forget('openprovider.token');
        DB::table('domains')->where('op_id', '>', 0)
            ->where(fn ($q) => $q->whereNull('registrar')->orWhere('registrar', '')->orWhere('registrar', 'openprovider'))
            ->orderBy('id')->chunkById(500, function ($domains) use ($legacy) {
                foreach ($domains as $domain) {
                    DB::table('domains')->where('id', $domain->id)->update([
                        'supplier_account_id' => $legacy,
                        'supplier_object_id' => (string) $domain->op_id,
                    ]);
                }
            });
        DB::table('registry_handles')->where('registry', 'openprovider')
            ->update(['supplier_account_id' => $legacy]);
        DB::table('registry_handles')->where('registry', 'openprovider')->orderBy('id')
            ->chunkById(500, function ($handles) use ($legacy) {
                foreach ($handles as $handle) {
                    // No new verification is asserted; keep its historical verification time.
                    DB::table('domain_supplier_contacts')->insertOrIgnore([
                        'supplier_account_id' => $legacy, 'customer_profile_id' => $handle->customer_profile_id,
                        'role' => $handle->role, 'handle' => $handle->handle, 'status' => $handle->status,
                        'sent_data' => $handle->sent_data === null ? null
                            : \Illuminate\Support\Facades\Crypt::encryptString($handle->sent_data),
                        'verified_at' => $handle->verified_at, 'created_at' => $handle->created_at,
                        'updated_at' => $handle->updated_at, 'evidence' => 'legacy_registry_handle:'.$handle->id,
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Once used, keep the journal and account binding. Roll application back with sales off.
        if (DB::table('domain_supplier_operations')->exists()
            || DB::table('domain_supplier_accounts')->where('legacy_config', false)->exists()
            || DB::table('domain_quotes')->whereNotNull('supplier_account_id')->exists()
            || DB::table('invoices')->whereNotNull('supplier_account_id')->exists()
            || DB::table('domain_supplier_contacts')->whereNotNull('verified_by')->exists()
            || DB::table('domains')->whereNotNull('supplier_account_id')
                ->whereNotIn('supplier_account_id', DB::table('domain_supplier_accounts')->where('legacy_config', true)->select('id'))->exists()) {
            throw new RuntimeException('Used domain supplier data must be retained; disable sales and roll application code back instead.');
        }
        foreach (['invoices', 'registry_handles', 'domain_quotes', 'domains'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropIndex([$table === 'invoices' ? 'supplier_payment_ledger_id' : 'supplier_account_id']));
            if ($table === 'invoices') Schema::table($table, fn (Blueprint $t) => $t->dropIndex(['supplier_account_id']));
        }
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn(['supplier_account_id', 'supplier_quote', 'supplier_payment_ledger_id']));
        Schema::table('registry_handles', fn (Blueprint $t) => $t->dropColumn('supplier_account_id'));
        Schema::table('domain_quotes', fn (Blueprint $t) => $t->dropColumn([
            'supplier_account_id', 'operation', 'period_months', 'purchase_minor', 'currency',
            'fx_snapshot', 'cost_components', 'selection_reason',
        ]));
        Schema::table('domains', fn (Blueprint $t) => $t->dropColumn(['supplier_account_id', 'supplier_object_id', 'supplier_quote']));
        Schema::dropIfExists('domain_supplier_contacts');
        Schema::dropIfExists('domain_supplier_operations');
        Schema::dropIfExists('domain_supplier_accounts');
    }
};

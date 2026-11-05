<?php

namespace App\Console\Commands;

use App\Models\DomainSupplierAccount;
use App\Services\Domain\DomainSupplierProbe;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class ProbeDomainSuppliers extends Command
{
    protected $signature = 'domains:suppliers-probe {--account= : Exact account ID for a read-only manual test}';
    protected $description = 'Read-only supplier authentication and balance synchronization; no paid requests';

    public function handle(DomainSupplierProbe $probe): int
    {
        if (! Schema::hasTable('domain_supplier_accounts')) {
            $this->info('supplier_schema_unavailable');
            return self::SUCCESS;
        }
        $specific = $this->option('account');
        if ($specific !== null && (! ctype_digit((string) $specific) || (int) $specific <= 0)) {
            $this->error('invalid_account_id');
            return self::FAILURE;
        }
        if ($specific === null && ! config('domain_suppliers.enabled', false)) {
            $this->info('supplier_feature_disabled');
            return self::SUCCESS;
        }
        $accounts = DomainSupplierAccount::where('active', true);
        if ($specific !== null) $accounts->whereKey((int) $specific);
        $checked = 0;
        $failed = 0;
        foreach ($accounts->orderBy('id')->get() as $account) {
            // Do not repeatedly authenticate placeholders that have never been configured.
            if (! $account->legacy_config && empty($account->credentials)) continue;
            $checked++;
            if (! $probe->check($account)) $failed++;
        }
        $this->info('supplier_probe_checked='.$checked.' failed='.$failed);
        return $failed > 0 || ($specific !== null && $checked === 0) ? self::FAILURE : self::SUCCESS;
    }
}

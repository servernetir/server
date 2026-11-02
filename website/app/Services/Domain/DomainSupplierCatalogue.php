<?php

namespace App\Services\Domain;

use App\Models\DomainSupplierAccount;
use App\Services\Domain\Providers\DomainProviderFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/** Private complete account snapshots; public readers project them through the shared pricing policy. */
class DomainSupplierCatalogue
{
    public const PATH = 'pricing/domain-suppliers-catalogue-v1.json';

    public function __construct(private DomainProviderFactory $providers, private DomainProcurement $pricing) {}

    public static function identity(DomainSupplierAccount $account): string
    {
        return hash_hmac('sha256', json_encode([$account->id, $account->provider, $account->environment,
            $account->credentials, $account->settings, $account->capabilities, $account->legacy_config,
            $account->legacy_config ? \App\Services\Domain\Providers\LegacyAccountIdentity::currentFingerprint() : null], JSON_THROW_ON_ERROR),
            (string) config('app.key'));
    }

    public function refresh(): array
    {
        $lock = Cache::lock('domain-suppliers:catalogue-refresh', 900);
        if (! $lock->get()) throw new \RuntimeException('supplier_catalogue_busy');
        try {
            $accounts = []; $excluded = 0; $suffixes = [];
            foreach (DomainSupplierAccount::where('active', true)->where('environment', 'production')->orderBy('id')->get() as $account) {
                if (! $account->legacy_config && empty($account->credentials)) continue;
                try {
                    $catalogue = $this->providers->forAccount($account)->catalogue();
                    if (! is_array($catalogue['offers'] ?? null) || $catalogue['offers'] === []
                        || count($catalogue['offers']) > 200000 || ! is_array($catalogue['suffixes'] ?? null)) {
                        throw new \RuntimeException('supplier_catalogue_incomplete');
                    }
                    // Discovery is evidence, never automatic management/IDN/premium authorization.
                    $accounts[(string) $account->id] = ['identity' => self::identity($account),
                        'offers' => $catalogue['offers'], 'suffixes' => $catalogue['suffixes'],
                        'fetched_at' => now()->toIso8601String(), 'source' => $catalogue['source'] ?? 'account_api'];
                    foreach ($catalogue['suffixes'] as $tld) $suffixes[$tld] = true;
                } catch (\Throwable) {
                    $excluded++;
                    // A transient read failure must not rewrite the owner's sales intent.
                    $account->forceFill(['connection_status' => 'failed', 'health_checked_at' => now()])->save();
                    Log::notice('Domain supplier catalogue excluded', ['account_id' => $account->id]);
                }
            }
            if ($accounts === []) throw new \RuntimeException('supplier_catalogue_unavailable');
            $snapshot = ['version' => 1, 'fetched_at' => now()->toIso8601String(), 'accounts' => $accounts,
                'discovered_suffixes' => count($suffixes), 'excluded_accounts' => $excluded];
            $json = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $path = Storage::disk('local')->path(self::PATH);
            File::ensureDirectoryExists(dirname($path), 0700, true);
            $tmp = tempnam(dirname($path), '.supplier-catalogue-');
            if ($tmp === false) throw new \RuntimeException('supplier_catalogue_storage');
            try {
                chmod($tmp, 0600);
                if (file_put_contents($tmp, $json, LOCK_EX) !== strlen($json) || ! rename($tmp, $path)) {
                    throw new \RuntimeException('supplier_catalogue_storage');
                }
            } finally { if (is_file($tmp)) unlink($tmp); }
            return ['count' => count($this->prices()), 'total' => count($suffixes), 'excluded' => $excluded,
                'fetched_at' => $snapshot['fetched_at']];
        } finally { $lock->release(); }
    }

    public function prices(?array $tlds = null): array
    {
        try {
            $snapshot = json_decode(Storage::disk('local')->get(self::PATH), true, 512, JSON_THROW_ON_ERROR);
            if (($snapshot['version'] ?? null) !== 1 || ! is_array($snapshot['accounts'] ?? null)
                || ! is_string($snapshot['fetched_at'] ?? null)) return [];
            $at = Carbon::parse($snapshot['fetched_at']);
            if ($at->isFuture() || $at->lt(now()->subDay())) return [];
            $catalogues = [];
            foreach ($snapshot['accounts'] as $id => $stored) {
                $account = DomainSupplierAccount::find((int) $id);
                if (! $account || ! $account->saleEligible() || ! is_array($stored['offers'] ?? null)
                    || ! is_string($stored['identity'] ?? null) || ! hash_equals(self::identity($account), $stored['identity'])) continue;
                $offers = $stored['offers'];
                if ($tlds !== null) $offers = array_values(array_filter($offers, fn ($q) => is_array($q) && in_array($q['tld'] ?? null, $tlds, true)));
                $catalogues[] = ['account' => $account, 'offers' => $offers];
            }
            return $this->pricing->cataloguePrices($catalogues);
        } catch (\Throwable) { return []; }
    }
}

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
        return hash_hmac('sha256', json_encode(self::canonical([(int) $account->id, $account->provider, $account->environment,
            (array) $account->credentials, (array) $account->settings, (array) $account->capabilities, (bool) $account->legacy_config,
            $account->legacy_config ? \App\Services\Domain\Providers\LegacyAccountIdentity::currentFingerprint() : null]), JSON_THROW_ON_ERROR),
            (string) config('app.key'));
    }

    /** Contact ownership depends on the login, never on prices or enabled suffixes. */
    public static function contactIdentity(DomainSupplierAccount $account): string
    {
        return hash_hmac('sha256', json_encode(self::canonical([(int) $account->id, $account->provider,
            $account->environment, (array) $account->credentials, (bool) $account->legacy_config,
            $account->legacy_config ? \App\Services\Domain\Providers\LegacyAccountIdentity::currentFingerprint() : null]), JSON_THROW_ON_ERROR),
            (string) config('app.key'));
    }

    private static function canonical(array $value): array
    {
        if (! array_is_list($value)) ksort($value);
        foreach ($value as &$item) if (is_array($item)) $item = self::canonical($item);
        return $value;
    }

    /** No network. Optional index lives only inside the caller's bounded search scope. */
    public static function promotionEvidence(DomainSupplierAccount $account, string $tld, string $operation,
        int $months, int $amount, string $currency, ?array &$scope = null): ?array
    {
        try {
            $identity = self::identity($account);
            $index = $scope[$identity] ?? null;
            if ($index === null) {
                $snapshot = json_decode(Storage::disk('local')->get(self::PATH), true, 512, JSON_THROW_ON_ERROR);
                if (($snapshot['version'] ?? null) !== 1 || ! is_string($snapshot['fetched_at'] ?? null)) return null;
                $at = Carbon::parse($snapshot['fetched_at']);
                $stored = $snapshot['accounts'][(string) $account->id] ?? null;
                if ($at->isFuture() || $at->lt(now()->subDay()) || ! is_array($stored)
                    || ! is_string($stored['identity'] ?? null) || ! hash_equals($identity, $stored['identity'])) return null;
                $index = ['fetched_at' => $snapshot['fetched_at'], 'offers' => []];
                foreach ($stored['offers'] ?? [] as $q) {
                    if (! is_array($q) || ($q['premium'] ?? null) !== false) continue;
                    $key = json_encode([$q['tld'] ?? null, $q['operation'] ?? null, $q['period_months'] ?? null,
                        $q['amount_minor'] ?? null, $q['currency'] ?? null], JSON_THROW_ON_ERROR);
                    $index['offers'][$key][] = ['valid_until' => $q['valid_until'] ?? null,
                        'promotion' => ($q['promotion'] ?? false) === true, 'promotion_evidence' => $q['promotion_evidence'] ?? null];
                }
                if ($scope !== null) $scope[$identity] = $index;
            }
            $at = Carbon::parse($index['fetched_at']);
            if ($at->isFuture() || $at->lt(now()->subDay())) return null;
            $matches = $index['offers'][json_encode([$tld, $operation, $months, $amount, $currency], JSON_THROW_ON_ERROR)] ?? [];
            if (count($matches) !== 1) return null;
            $offer = $matches[0]; $evidence = $offer['promotion_evidence'] ?? null;
            if (($offer['promotion'] ?? false) !== true || ! is_array($evidence) || ! is_string($offer['valid_until'] ?? null)
                || ! Carbon::parse($offer['valid_until'])->isFuture()
                || ! is_int($evidence['regular_amount_minor'] ?? null) || $evidence['regular_amount_minor'] <= $amount
                || ($evidence['currency'] ?? null) !== $currency || ($evidence['period_months'] ?? null) !== $months
                || ! is_string($evidence['starts_at'] ?? null) || ! is_string($evidence['ends_at'] ?? null)
                || Carbon::parse($evidence['starts_at'])->isFuture() || ! Carbon::parse($evidence['ends_at'])->isFuture()) return null;
            return $evidence;
        } catch (\Throwable) { return null; }
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

<?php

namespace App\Services\Domain\Providers;

use App\Models\DomainSupplierAccount;
use App\Services\Domain\OpenProviderClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Pins the historical login without storing it. Principal changes require an account migration. */
final class LegacyAccountIdentity
{
    public const SETTING = 'legacy_credential_fingerprint';

    public static function currentFingerprint(): ?string
    {
        $client = new OpenProviderClient;
        return $client->enabled() ? $client->accountFingerprint() : null;
    }

    public static function account(): ?DomainSupplierAccount
    {
        if (! Schema::hasTable('domain_supplier_accounts')) { return null; }
        return DomainSupplierAccount::where('provider', 'openprovider')->where('environment', 'production')->where('legacy_config', true)->first();
    }

    public static function matches(DomainSupplierAccount $account, bool $allowUnpinned = false): bool
    {
        if (! $account->legacy_config || $account->provider !== 'openprovider' || $account->environment !== 'production') { return false; }
        $pinned = (string) data_get($account->settings, self::SETTING, '');
        if ($pinned === '') { return $allowUnpinned; }
        $current = self::currentFingerprint();
        return $current !== null && hash_equals($pinned, $current);
    }

    /** A pinned mismatch also blocks unbound historical orders. */
    public static function unboundCompatible(): bool
    {
        $account = self::account();
        return $account === null || self::matches($account, true);
    }

    public static function assertReadable(DomainSupplierAccount $account): void
    {
        if ($account->legacy_config && ! self::matches($account, true)) { throw new DomainProviderException('legacy_account_identity_changed'); }
    }

    public static function assertWritable(DomainSupplierAccount $account): void
    {
        if ($account->legacy_config && ! self::matches($account)) { throw new DomainProviderException('legacy_account_identity_unverified'); }
    }

    /** Establish a missing pin only from a fresh, scoped authenticated object read. */
    public static function confirmObject(DomainSupplierAccount $account, string $fqdn, string $objectId): bool
    {
        if (! self::matches($account, true) || ! ctype_digit($objectId) || (int) $objectId < 1) { return false; }
        if (self::matches($account)) { return true; }
        $client = new OpenProviderClient(null, null, null, true);
        if (! $client->enabled()) { return false; }
        $result = $client->getDomain((int) $objectId);
        $data = (array) ($result['data'] ?? []);
        $actual = strtolower((string) data_get($data, 'domain.name').'.'.(string) data_get($data, 'domain.extension'));
        if (! ($result['ok'] ?? false) || (string) ($data['id'] ?? '') !== $objectId || $actual !== strtolower($fqdn)) { return false; }
        return DB::transaction(function () use ($account, $client) {
            $fresh = DomainSupplierAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            if (! self::matches($fresh, true)) { return false; }
            $settings = (array) $fresh->settings;
            $settings[self::SETTING] = $client->accountFingerprint();
            $fresh->forceFill(['settings' => $settings])->save();
            Cache::forget('openprovider.token');
            return true;
        });
    }
}

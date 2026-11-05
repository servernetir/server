<?php

namespace App\Services\Domain\Providers;

use App\Models\DomainSupplierAccount;
use App\Services\Domain\OpenProviderClient;

class DomainProviderFactory
{
    public function forAccount(DomainSupplierAccount $account): DomainProvider
    {
        if (! in_array($account->environment, ['production', 'ote'], true)) {
            throw new DomainProviderException('invalid_environment');
        }
        if ($account->provider === 'joker') { return new JokerAdapter($account); }
        if ($account->provider !== 'openprovider') { throw new DomainProviderException('unsupported_provider'); }

        $credentials = (array) $account->credentials;
        if ($account->legacy_config) {
            LegacyAccountIdentity::assertReadable($account);
            // Preserve the configured legacy account, while segregating its
            // token from all other account/environment combinations.
            $credentials = [
                'username' => config('services.openprovider.username'),
                'password' => config('services.openprovider.password'),
            ];
        }
        $base = $account->environment === 'ote'
            ? 'https://api.sandbox.openprovider.nl/v1beta'
            : 'https://api.openprovider.eu/v1beta';
        return new OpenProviderAdapter($account, new OpenProviderClient(
            $base, (string) ($credentials['username'] ?? ''), (string) ($credentials['password'] ?? ''), true,
        ));
    }
}

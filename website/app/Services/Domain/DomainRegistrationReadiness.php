<?php

namespace App\Services\Domain;

use App\Models\Customer;
use App\Models\DomainSupplierContact;

/** A known contact blocker must be resolved before collecting customer money. */
class DomainRegistrationReadiness
{
    public function ready(Customer $customer, int $accountId): bool
    {
        $profile = $customer->defaultProfile();
        return $profile !== null && DomainSupplierContact::where('supplier_account_id', $accountId)
            ->where('customer_profile_id', $profile->id)->where('role', 'registrant')
            ->where('status', 'active')->whereNotNull('verified_at')->where('verified_at', '<=', now())
            ->whereNotNull('handle')->where('handle', '!=', '')->exists();
    }
}

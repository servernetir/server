<?php

namespace App\Services\Marketing;

use App\Models\Customer;
use App\Models\Setting;

/** Controls new customer code entry; existing reservations and campaign eligibility stay independent. */
final class DiscountEntryVisibility
{
    public const SETTING = 'invoice_discount_entry_enabled';

    public const MODES = ['inherit', 'enabled', 'disabled'];

    public function defaultEnabled(): bool
    {
        return Setting::get(self::SETTING, '0') === '1';
    }

    public function enabled(Customer $customer): bool
    {
        return match ($customer->getAttribute('discount_entry_visibility')) {
            'enabled' => true,
            'disabled' => false,
            default => $this->defaultEnabled(),
        };
    }
}

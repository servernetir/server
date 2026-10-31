<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinanceAccount extends Model
{
    protected $table = 'finance_accounts';

    protected $guarded = [];

    protected $hidden = ['bank_details', 'bank_iban_fingerprint'];

    protected function casts(): array
    {
        return ['bank_details' => 'encrypted:array'];
    }

    /** Safe identity for screens, audit events and immutable journal snapshots. */
    public function maskedBankIdentity(): array
    {
        $details = $this->bank_details ?? [];

        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'currency_code' => $this->currency_code,
            'owner_name' => $this->owner_name,
            'partner_key' => $this->partner_key,
            'bank_name' => is_string($details['bank_name'] ?? null) ? $details['bank_name'] : null,
            'card_last4' => ! empty($details['card_number']) ? substr((string) $details['card_number'], -4) : null,
            'iban_last4' => ! empty($details['iban']) ? substr((string) $details['iban'], -4) : null,
            'iban_country' => ! empty($details['iban']) ? substr((string) $details['iban'], 0, 2) : null,
        ];
    }
}

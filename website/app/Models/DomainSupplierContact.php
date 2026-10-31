<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Opaque registry handles are scoped to a real account and customer profile. */
class DomainSupplierContact extends Model
{
    protected $fillable = ['supplier_account_id', 'customer_profile_id', 'role', 'handle', 'sent_data',
        'status', 'verified_at', 'verified_by', 'evidence'];
    protected $hidden = ['handle', 'sent_data', 'evidence'];

    protected function casts(): array
    {
        return ['sent_data' => 'encrypted:array', 'verified_at' => 'datetime',
            'supplier_account_id' => 'integer', 'customer_profile_id' => 'integer', 'verified_by' => 'integer'];
    }
}

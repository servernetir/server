<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DomainSupplierContactRequest extends Model
{
    protected $guarded = [];
    protected $hidden = ['payload', 'handle', 'supplier_identity', 'process_id', 'tracking_id'];
    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'handle' => 'encrypted', 'attempts' => 'integer',
            'supplier_account_id' => 'integer', 'customer_profile_id' => 'integer', 'sent_at' => 'datetime', 'reconciled_at' => 'datetime'];
    }
}

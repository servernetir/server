<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IdentityPrice extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['source_amount' => 'integer', 'purchase_tax_amount' => 'integer', 'markup_bps' => 'integer', 'daily_quota' => 'integer', 'confirmed_zero_cost' => 'boolean', 'purchase_tax_included' => 'boolean', 'effective_at' => 'datetime', 'valid_until' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Create a new price version.'));
        static::deleting(fn () => throw new \LogicException('Price history is immutable.'));
    }
}

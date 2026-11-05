<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Allocation history is append-only; infrastructure removal never erases it. */
class EngineIpEvent extends Model
{
    protected $table = 'cloud_engine_ip_events';
    protected $guarded = [];
    public const UPDATED_AT = null;
    protected $casts = ['evidence' => 'array'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('IP allocation history cannot be changed.'));
        static::deleting(fn () => throw new \LogicException('IP allocation history cannot be erased.'));
    }
}

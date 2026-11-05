<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Infrastructure journal only. Payment and service lifecycle remain in existing services. */
class EngineOperation extends Model
{
    protected $table = 'cloud_engine_operations';

    protected $guarded = [];

    protected $hidden = ['idempotency_key', 'fingerprint', 'payload', 'receipt', 'lease_token'];

    protected $casts = [
        'payload' => 'encrypted:array',
        'receipt' => 'encrypted:array',
        'lease_until' => 'datetime',
        'deadline_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function vm()
    {
        return $this->belongsTo(EngineVm::class, 'vm_id');
    }
}

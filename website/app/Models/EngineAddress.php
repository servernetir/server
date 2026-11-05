<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EngineAddress extends Model
{
    protected $table = 'cloud_engine_addresses';
    protected $guarded = [];
    protected $casts = ['allocated_at' => 'datetime'];
    public function subnet() { return $this->belongsTo(EngineSubnet::class, 'subnet_id'); }
    public function node() { return $this->belongsTo(EngineNode::class, 'node_id'); }
    public function vm() { return $this->belongsTo(EngineVm::class, 'vm_id'); }
}

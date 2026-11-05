<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EngineSubnet extends Model
{
    protected $table = 'cloud_engine_subnets';
    protected $guarded = [];
    protected $attributes = ['enabled' => false];
    protected $casts = ['enabled' => 'bool', 'metadata' => 'array'];
    public function node() { return $this->belongsTo(EngineNode::class, 'node_id'); }
    public function addresses() { return $this->hasMany(EngineAddress::class, 'subnet_id'); }
}

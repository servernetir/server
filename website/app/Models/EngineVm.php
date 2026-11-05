<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EngineVm extends Model
{
    protected $table = 'cloud_engine_vms';
    protected $guarded = [];
    protected $hidden = ['credentials', 'metadata', 'fingerprint'];
    protected $casts = ['metadata' => 'array', 'credentials' => 'encrypted:array'];
    public function service() { return $this->belongsTo(Service::class, 'service_id'); }
    public function node() { return $this->belongsTo(EngineNode::class, 'node_id'); }
    public function address() { return $this->belongsTo(EngineAddress::class, 'address_id'); }
    public function image() { return $this->belongsTo(EngineImage::class, 'image_id'); }
}

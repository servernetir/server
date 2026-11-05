<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EnginePlanNode extends Model
{
    protected $table = 'cloud_engine_plan_nodes';
    protected $guarded = [];
    protected $attributes = ['enabled' => false];
    protected $casts = ['enabled' => 'bool'];
    public function node() { return $this->belongsTo(EngineNode::class, 'node_id'); }
    public function plan() { return $this->belongsTo(CloudPlan::class, 'cloud_plan_id'); }
}

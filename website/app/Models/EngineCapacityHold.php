<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EngineCapacityHold extends Model
{
    protected $table = 'cloud_engine_capacity_holds';
    protected $guarded = [];
    public $incrementing = false;
    protected $keyType = 'string';
}

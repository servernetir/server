<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EngineApiRequest extends Model
{
    protected $table = 'cloud_engine_api_requests';

    protected $guarded = [];

    protected $hidden = ['key_hash', 'fingerprint'];
}

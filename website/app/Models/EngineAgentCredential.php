<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EngineAgentCredential extends Model
{
    protected $table = 'cloud_engine_agent_credentials';
    protected $guarded = [];
    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['secret' => 'encrypted', 'enabled' => 'boolean'];
    }
}

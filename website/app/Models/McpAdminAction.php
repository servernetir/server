<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class McpAdminAction extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $hidden = ['command', 'result', 'token_id', 'target_hash'];
    protected function casts(): array
    {
        return ['command' => 'encrypted', 'result' => 'encrypted:array',
            'expires_at' => 'datetime', 'approved_at' => 'datetime', 'executed_at' => 'datetime'];
    }
}

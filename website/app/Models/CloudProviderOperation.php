<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Durable journal: a lost POST response must never buy a second server. */
class CloudProviderOperation extends Model
{
    protected $guarded = [];

    protected $hidden = ['payload', 'task_ref', 'resource_ref'];

    protected $casts = ['payload' => 'encrypted:array'];
}

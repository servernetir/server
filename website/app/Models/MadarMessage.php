<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MadarMessage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_submission' => 'boolean', 'rights_confirmed' => 'boolean',
            'disclosure_confirmed' => 'boolean', 'attachment_bytes' => 'integer'];
    }
}

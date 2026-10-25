<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinanceLine extends Model
{
    protected $table = 'finance_lines';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [];
    }
}

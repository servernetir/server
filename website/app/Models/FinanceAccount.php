<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinanceAccount extends Model
{
    protected $table = 'finance_accounts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [];
    }
}

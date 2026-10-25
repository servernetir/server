<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinanceDocument extends Model
{
    protected $table = 'finance_documents';

    protected $guarded = [];

    protected function casts(): array
    {
        return [];
    }
}

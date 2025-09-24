<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DomainOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'domain','tld','price_eur','period_years','autoprolong',
        'full_name','dob','passport_series','passport_issuer','issue_date',
        'postcode','region','country','city','address','phone',
        'status'
    ];

    protected $casts = [
        'autoprolong' => 'boolean',
        'dob' => 'date',
        'issue_date' => 'date',
    ];
}

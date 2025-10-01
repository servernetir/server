<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class EmailVerificationCode extends Model
{
    use HasFactory;

    protected $table = 'email_verification_codes';

    protected $fillable = [
        'email',
        'code_hash',
        'expires_at',
        'consumed_at',
        'resend_count',
        'ip',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];
}

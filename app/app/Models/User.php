<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $table = 'users';

    /**
     * فیلدهایی که قابل پر کردن هستند
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'user_type',
        'company_name',
        'company_register_no',
        'company_national_id',
        'verification_level',
        'referral_code',
        'referred_by',
        'wallet_balance',
        'status',
        'last_login_at',
    ];

    /**
     * فیلدهایی که باید مخفی شوند
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * فیلدهایی که باید Cast شوند
     */
    protected $casts = [
        'wallet_balance' => 'decimal:2',
        'last_login_at' => 'datetime',
    ];

    /**
     * کاربری که این کاربر توسط او دعوت شده
     */
    public function inviter()
    {
        return $this->belongsTo(User::class, 'referred_by');
    }

    /**
     * کاربرانی که این کاربر آن‌ها را دعوت کرده
     */
    public function referrals()
    {
        return $this->hasMany(User::class, 'referred_by');
    }
}

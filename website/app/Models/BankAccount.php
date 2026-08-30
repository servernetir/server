<?php

namespace App\Models;

use App\Support\IranianBank;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * حساب بانکی تأییدشده.
 *
 * ⚠ شمارهٔ کارت کامل در `card_number_enc` نگهداری می‌شود و با کلید اپ
 * رمزنگاری شده است. هیچ‌جای رابط کاربری نشان داده نمی‌شود — نمایش همیشه
 * از `card_bin` و `card_last4` می‌آید تا خواندنِ صفحه هرگز نیازی به
 * رمزگشایی نداشته باشد.
 *
 * آنچه برای تسویه و بازگشت وجه واقعاً لازم است شبا و شماره حساب است.
 */
class BankAccount extends Model
{
    protected $fillable = [
        'customer_id', 'card_bin', 'card_last4', 'card_number_enc',
        'bank_name', 'bank_slug', 'account_number',
        'iban', 'owner_name', 'name_matched', 'status', 'reject_reason',
        'is_default', 'verified_at',
    ];

    /** شمارهٔ کارت هرگز در خروجی آرایه یا JSON مدل نمی‌آید */
    protected $hidden = ['card_number_enc'];

    protected function casts(): array
    {
        return [
            'name_matched'    => 'boolean',
            'is_default'      => 'boolean',
            'verified_at'     => 'datetime',
            // رمزنگاری در سطح مدل: مقدار خام هرگز وارد دیتابیس نمی‌شود
            'card_number_enc' => 'encrypted',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isVerified(): bool
    {
        return $this->status === 'verified';
    }

    /** ۶۰۳۷۹۹ **** **** ۱۲۳۴ — تنها شکلی که به کاربر نشان داده می‌شود */
    public function maskedCard(): string
    {
        return $this->card_bin.' **** **** '.$this->card_last4;
    }

    /** شبا با فاصله برای خوانایی */
    public function formattedIban(): ?string
    {
        return $this->iban ? trim(chunk_split($this->iban, 4, ' ')) : null;
    }

    /**
     * نشان بانک — از اسلاگ ذخیره‌شده، وگرنه از روی BIN.
     *
     * @return array{slug:string,name:string,short:string,color:string}|null
     */
    public function bank(): ?array
    {
        return IranianBank::bySlug($this->bank_slug)
            ?? IranianBank::fromBin($this->card_bin);
    }

    /** نامی که نشان داده می‌شود: تشخیص محلی، وگرنه هرچه سرویس داد */
    public function bankLabel(): string
    {
        return $this->bank()['name'] ?? ($this->bank_name ?: '—');
    }
}

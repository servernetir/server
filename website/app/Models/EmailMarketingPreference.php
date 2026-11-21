<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailMarketingPreference extends Model
{
    public const STATE_PENDING = 'pending';

    public const STATE_GRANTED = 'granted';

    public const STATE_WITHDRAWN = 'withdrawn';

    protected $table = 'email_marketing_preferences';

    // Consent state may only be changed by the dedicated service.
    protected $guarded = ['*'];

    protected $hidden = ['email_digest', 'digest_key_id', 'public_id'];

    protected function casts(): array
    {
        return [
            'consented_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'suppressed_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    protected static function booted(): void
    {
        static::creating(function (self $preference): void {
            if ($preference->state !== self::STATE_PENDING || $preference->consented_at !== null
                || $preference->withdrawn_at !== null || $preference->suppressed_at !== null
                || $preference->consent_version !== null || $preference->consent_locale !== null) {
                throw new DomainException('New email preferences must be pending.');
            }
        });

        static::updating(function (self $preference): void {
            if ($preference->isDirty(['public_id', 'customer_id', 'email_digest', 'digest_key_id'])) {
                throw new DomainException('Email preference identity is immutable.');
            }

            $oldState = $preference->getRawOriginal('state');
            if ($preference->isDirty('state') && ! (match ($oldState) {
                self::STATE_PENDING => in_array($preference->state, [self::STATE_GRANTED, self::STATE_WITHDRAWN], true),
                self::STATE_GRANTED => $preference->state === self::STATE_WITHDRAWN,
                default => false,
            })) {
                throw new DomainException('Invalid email preference state transition.');
            }

            if ($preference->getRawOriginal('suppressed_at') !== null
                && ($preference->isDirty(['suppressed_at', 'suppression_reason']) || $preference->state === self::STATE_GRANTED && $oldState !== self::STATE_GRANTED)) {
                throw new DomainException('An email suppression cannot be removed.');
            }
            if ($preference->getRawOriginal('withdrawn_at') !== null && $preference->isDirty('withdrawn_at')) {
                throw new DomainException('Email withdrawal history is immutable.');
            }
            if ($preference->getRawOriginal('consented_at') !== null
                && $preference->isDirty(['consented_at', 'consent_version', 'consent_locale'])
                && ($oldState !== self::STATE_GRANTED || $preference->state !== self::STATE_GRANTED
                    || $preference->suppressed_at !== null || $preference->withdrawn_at !== null
                    || $preference->consented_at === null || $preference->consent_version === null
                    || ! $preference->consented_at->greaterThanOrEqualTo($preference->getOriginal('consented_at')))) {
                throw new DomainException('Email consent history cannot be erased.');
            }
        });

        static::deleting(static function (): void {
            throw new DomainException('Email preference history cannot be deleted.');
        });
    }
}

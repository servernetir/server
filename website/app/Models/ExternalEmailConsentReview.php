<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** An immutable evidence review reference, not a recipient or consent grant. */
class ExternalEmailConsentReview extends Model
{
    protected $table = 'external_email_consent_reviews';

    protected $fillable = ['email_digest', 'digest_key_id', 'consent_date', 'evidence_reference', 'imported_by'];

    protected $hidden = ['email_digest', 'digest_key_id', 'evidence_reference', 'imported_by'];

    protected function casts(): array
    {
        return ['consent_date' => 'immutable_date', 'evidence_reference' => 'encrypted'];
    }

    /** Database identity is unique and stable; no assumption of gapless sequencing. */
    public function getContactIdAttribute(): int
    {
        return (int) $this->getKey() + 999;
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(ExternalEmailConsentReviewEvent::class, 'review_id');
    }

    public function latestDecision(): HasOne
    {
        return $this->hasOne(ExternalEmailConsentReviewEvent::class, 'review_id')->latestOfMany();
    }

    public function sharedDocumentItem(): HasOne
    {
        return $this->hasOne(ExternalEmailConsentDocumentItem::class, 'review_id');
    }

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new DomainException('Evidence review references are immutable.');
        });
        static::deleting(static function (): void {
            throw new DomainException('Evidence review references cannot be deleted through enrollment.');
        });
    }
}

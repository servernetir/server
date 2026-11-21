<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An append-only decision on evidence, never a subscription or sending authorization. */
class ExternalEmailConsentReviewEvent extends Model
{
    protected $table = 'external_email_consent_review_events';

    public $timestamps = false;

    protected $fillable = ['review_id', 'decision', 'reason', 'source_kind', 'review_reference', 'reviewed_by', 'created_at'];

    protected $hidden = ['review_reference'];

    protected function casts(): array
    {
        return ['review_reference' => 'encrypted', 'created_at' => 'immutable_datetime'];
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new DomainException('Evidence decisions are immutable.');
        });
        static::deleting(static function (): void {
            throw new DomainException('Evidence decisions cannot be deleted.');
        });
    }
}

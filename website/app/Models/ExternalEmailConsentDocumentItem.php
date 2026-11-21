<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Immutable link to a shared evidence reference, not a consent decision. */
class ExternalEmailConsentDocumentItem extends Model
{
    protected $table = 'external_email_consent_document_items';

    public $timestamps = false;

    protected $fillable = ['batch_id', 'review_id'];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ExternalEmailConsentDocumentBatch::class, 'batch_id');
    }

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new DomainException('Document links are immutable.');
        });
        static::deleting(static function (): void {
            throw new DomainException('Document links cannot be deleted.');
        });
    }
}

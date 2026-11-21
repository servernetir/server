<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Immutable shared documentary pointer; never a consent grant. */
class ExternalEmailConsentDocumentBatch extends Model
{
    protected $table = 'external_email_consent_document_batches';

    public $timestamps = false;

    protected $fillable = ['document_reference', 'record_count', 'created_by', 'created_at'];

    protected $hidden = ['document_reference'];

    protected function casts(): array
    {
        return ['document_reference' => 'encrypted', 'created_at' => 'immutable_datetime', 'record_count' => 'integer'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ExternalEmailConsentDocumentItem::class, 'batch_id');
    }

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new DomainException('Document groups are immutable.');
        });
        static::deleting(static function (): void {
            throw new DomainException('Document groups cannot be deleted.');
        });
    }
}

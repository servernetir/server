<?php

namespace App\Models;

use App\Services\Madar\MadarHub;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MadarParticipation extends Model
{
    public const OPEN = ['requested', 'offered', 'working', 'submitted', 'changes_requested'];

    public const STATUSES = ['requested', 'offered', 'working', 'submitted', 'changes_requested', 'rewarded', 'rejected', 'cancelled'];

    protected $guarded = ['id'];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function resolveRouteBinding($value, $field = null)
    {
        abort_unless(app(MadarHub::class)->ready(), 503);

        return parent::resolveRouteBinding($value, $field);
    }

    protected function casts(): array
    {
        return ['reward_snapshot' => 'array', 'terms_accepted_at' => 'datetime', 'work_due_at' => 'datetime',
            'accepted_at' => 'datetime', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime',
            'customer_read_at' => 'datetime', 'staff_read_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(MadarMessage::class, 'participation_id');
    }

    public function grant(): BelongsTo
    {
        return $this->belongsTo(MarketingGrant::class, 'grant_id');
    }
}

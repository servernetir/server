<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class OvhOrder extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['account_hash', 'spec_hash', 'spec', 'private_key', 'public_key', 'lease_token', 'quote', 'fx_snapshot'];

    protected function casts(): array
    {
        return [
            'spec' => 'array', 'quote' => 'array', 'fx_snapshot' => 'array',
            'private_key' => 'encrypted',
            'retry_started_at' => 'datetime', 'next_attempt_at' => 'datetime', 'locked_until' => 'datetime',
            'checkout_sent_at' => 'datetime', 'payment_sent_at' => 'datetime',
            'install_sent_at' => 'datetime', 'key_downloaded_at' => 'datetime',
            'ready_at' => 'datetime', 'alerted_at' => 'datetime',
        ];
    }

    public static function forService(int $id): ?self
    {
        return Schema::hasTable('ovh_orders') ? self::where('service_id', $id)->first() : null;
    }

    public function claimLease(string $token): bool
    {
        return (bool) $this->newQuery()->whereKey($this->id)
            ->where(fn ($q) => $q->whereNull('locked_until')->orWhere('locked_until', '<', now()))
            ->update(['lease_token' => $token, 'locked_until' => now()->addSeconds((int) config('ovh.lease_seconds', 900))]);
    }

    public function service() { return $this->belongsTo(Service::class); }
    public function events() { return $this->hasMany(OvhOrderEvent::class); }

    public function hasExposure(): bool
    {
        return $this->checkout_sent_at !== null && ! in_array($this->phase, ['rejected', 'refunded'], true);
    }

    public function customerState(): string
    {
        return match ($this->phase) {
            'ready' => 'ready',
            'review', 'checkout_unknown', 'payment_unknown', 'install_unknown' => 'review',
            'rejected', 'refunded' => 'failed',
            'ordered', 'payment_submitting', 'paid', 'installing', 'install_submitting' => 'preparing',
            'new', 'dry_run' => 'pending',
            default => 'ordering',
        };
    }

    public function canRetry(): bool
    {
        return ! $this->hasExposure() && ! in_array($this->phase, ['ready', 'refunded', 'rejected'], true)
            && (! $this->locked_until || $this->locked_until->isPast());
    }
}

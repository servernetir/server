<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * یک اختلالِ اعلام‌شده روی صفحهٔ وضعیت.
 *
 * ⚠️ هر خواندنِ عمومی پشتِ `Schema::hasTable` است: صفحهٔ `/status` باید حتی
 * روی سروری که هنوز مهاجرت نخورده بالا بیاید. صفحهٔ وضعیتی که خودش ۵۰۰ بدهد،
 * از نبودش بدتر است — دقیقاً وقتی لازم می‌شود که اوضاع خراب است.
 */
class StatusIncident extends Model
{
    protected $fillable = [
        'title', 'state', 'impact', 'locations', 'body',
        'started_at', 'resolved_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'locations'   => 'array',
            'started_at'  => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** مرحله‌های استانداردِ اطلاع‌رسانیِ حادثه */
    public const STATES = [
        'investigating' => 'در حالِ بررسی',
        'identified'    => 'علت شناسایی شد',
        'monitoring'    => 'رفع شد — در حالِ پایش',
        'resolved'      => 'برطرف شد',
    ];

    public const IMPACTS = [
        'none'  => 'نگهداریِ برنامه‌ریزی‌شده',
        'minor' => 'اختلالِ جزئی',
        'major' => 'قطعی',
    ];

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereNull('resolved_at');
    }

    public function stateLabel(): string
    {
        return self::STATES[$this->state] ?? $this->state;
    }

    public function impactLabel(): string
    {
        return self::IMPACTS[$this->impact] ?? $this->impact;
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }

    /** رنگِ نشانگر — همان توکن‌های سایت */
    public function color(): string
    {
        if (! $this->isOpen()) {
            return '#34d399';
        }

        return match ($this->impact) {
            'major' => '#ff6b6b',
            'none'  => '#22d3ee',
            default => '#fbbf24',
        };
    }

    /** @return \Illuminate\Support\Collection<int,self> */
    public static function openNow()
    {
        if (! Schema::hasTable('status_incidents')) {
            return collect();
        }

        return static::query()->open()->orderByDesc('started_at')->get();
    }

    /** @return \Illuminate\Support\Collection<int,self> */
    public static function history(int $days = 90)
    {
        if (! Schema::hasTable('status_incidents')) {
            return collect();
        }

        return static::query()
            ->whereNotNull('resolved_at')
            ->where('started_at', '>=', now()->subDays($days))
            ->orderByDesc('started_at')
            ->limit(50)
            ->get();
    }
}

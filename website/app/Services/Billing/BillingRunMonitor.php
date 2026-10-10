<?php

namespace App\Services\Billing;

use Illuminate\Support\Facades\Cache;

class BillingRunMonitor
{
    private static function store()
    {
        return Cache::store(app()->environment('testing') ? 'array' : 'file');
    }

    public static function record(string $job, int $errors, array $counts = []): void
    {
        $previous = self::read($job);
        self::store()->forever('billing.run.'.$job, [
            'finished_at' => now()->toIso8601String(),
            'last_success_at' => $errors === 0 && empty($counts['pending']) ? now()->toIso8601String() : ($previous['last_success_at'] ?? null),
            'errors' => $errors,
            'counts' => $counts,
        ]);
    }

    public static function read(string $job): array
    {
        return (array) self::store()->get('billing.run.'.$job, []);
    }
}

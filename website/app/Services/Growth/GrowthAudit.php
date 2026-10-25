<?php

namespace App\Services\Growth;

use Illuminate\Support\Facades\DB;

final class GrowthAudit
{
    public static function record(string $action, string $subject, ?int $actor = null, array $meta = []): void
    {
        DB::table('growth_audits')->insert(['action' => $action, 'subject' => $subject, 'actor_id' => $actor,
            'meta' => json_encode($meta, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }
}

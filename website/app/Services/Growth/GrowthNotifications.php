<?php

namespace App\Services\Growth;

use App\Services\Notify\AdminNotifier;
use Illuminate\Support\Facades\DB;

class GrowthNotifications
{
    public function send(string $title, int $contentId): void
    {
        if (! DB::table('growth_controls')->where('id', 1)->value('notify')) {
            return;
        }
        // No customer text or private knowledge in external notifications.
        try {
            app(AdminNotifier::class)->event('AI Growth: '.$title, ['شناسه محتوا' => $contentId],
                'https://console.servernet.cloud/admin/ai-growth/content/'.$contentId);
        } catch (\Throwable) {
            GrowthAudit::record('notification.failed', 'content:'.$contentId);
        }
    }
}

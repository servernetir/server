<?php

namespace App\Services\Crm;

use App\Models\CrmLead;
use App\Models\CrmMessage;
use App\Services\Notify\AdminNotifier;
use App\Support\ErrorTracker;
use Illuminate\Support\Facades\DB;

class ChatLeadCapture
{
    /** One registration per n8n row, isolated from domain/email deduplication. */
    public function capture(array $data): array
    {
        [$lead, $created] = DB::transaction(function () use ($data) {
            $lead = CrmLead::firstOrCreate([
                'domain_hash' => hash('sha256', 'site-chat|'.$data['external_id']),
            ], [
                'source' => 'site-chat', 'stage' => 'new',
                'company' => filled($data['name'] ?? null) ? $data['name'] : 'گفتگوی سایت',
                'contact_name' => $data['name'] ?? null,
                'phone' => $data['phone'] ?? null,
                'email' => isset($data['email']) ? mb_strtolower(trim($data['email'])) : null,
                // No observation, website or next_action: never an outreach target.
            ]);
            $lead = CrmLead::whereKey($lead->id)->lockForUpdate()->firstOrFail();
            $message = CrmMessage::firstOrCreate([
                'lead_id' => $lead->id, 'provider_id' => 'site-chat:'.$data['external_id'],
            ], [
                'channel' => 'chat', 'direction' => 'in', 'status' => 'received',
                'subject' => $data['ticket'] ?? 'درخواست گفتگو',
                'body' => json_encode([
                    'ticket' => $data['ticket'] ?? null, 'name' => $data['name'] ?? null,
                    'phone' => $data['phone'] ?? null, 'email' => $data['email'] ?? null,
                    'locale' => $data['locale'], 'summary' => $data['summary'],
                    'occurred_at' => $data['occurred_at'] ?? null,
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
            return [$lead, $message->wasRecentlyCreated];
        }, 3);

        // Never roll back a committed request if a notification channel fails.
        if ($created && ($data['notify'] ?? true)) {
            try {
                app(AdminNotifier::class)->baleEvent('سرنخ جدید از گفتگوی سایت', [
                    'کد' => $data['ticket'] ?? 'CHAT-'.$lead->id,
                    'نام' => $lead->contact_name, 'تماس' => $lead->phone,
                    'ایمیل' => $lead->email,
                    'خلاصه' => mb_substr($data['summary'], 0, 500),
                ], 'https://console.servernet.cloud/admin/marketing/'.$lead->id);
            } catch (\Throwable) {
                ErrorTracker::noteOnce('notify', 'اعلان سرنخ گفتگو نیازمند بررسی است.', 300,
                    ['area' => 'chat-leads', 'lead_id' => $lead->id]);
            }
        }
        return ['lead' => $lead, 'created' => $created];
    }
}

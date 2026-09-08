<?php
namespace App\Services\Crm;

use App\Models\CrmLead;
use App\Services\Notify\AdminNotifier;
use App\Support\ErrorTracker;
use Illuminate\Support\Facades\DB;

class AssistantLeadCapture
{
    public function capture(array $lead, string $session, string $locale, ?string $pageUrl, ?int $customerId): ?CrmLead
    {
        if (($lead['detected'] ?? true) === false || trim($session) === '') {
            return null;
        }

        $email = trim((string) ($lead['email'] ?? ''));
        $phone = trim((string) ($lead['phone'] ?? ''));
        $name = trim((string) ($lead['name'] ?? $lead['contact_name'] ?? ''));
        if ($email === '' && $phone === '' && $name === '') {
            return null;
        }

        $key = hash('sha256', 'assistant|'.$session);
        $created = false;
        $model = DB::transaction(function () use ($lead, $key, $session, $locale, $pageUrl, $customerId, $email, $phone, $name, &$created) {
            $row = CrmLead::where('session_key', $key)->lockForUpdate()->first();
            if (! $row) {
                $created = true;
                $row = new CrmLead([
                    'domain_hash' => hash('sha256', 'assistant-session|'.$session),
                    'session_key' => $key,
                    'company' => $name ?: ($email ?: ($phone ?: 'سرنخ دستیار')),
                    'source' => 'assistant',
                    'stage' => 'new',
                ]);
            }
            $row->fill([
                'customer_id' => $customerId,
                'contact_name' => $name ?: $row->contact_name,
                'email' => $email ?: $row->email,
                'phone' => $phone ?: $row->phone,
                'page_url' => $pageUrl,
                'locale' => $locale,
                'interest' => trim((string) ($lead['product'] ?? $lead['interest'] ?? '')) ?: $row->interest,
                'observation' => trim((string) ($lead['summary'] ?? $lead['context'] ?? '')) ?: $row->observation,
            ]);
            $row->save();

            return $row;
        });

        if ($created) {
            try {
                // Email is already owned by the existing n8n workflow. Reusing the
                // same notifier for Bale avoids a second email for one lead.
                app(AdminNotifier::class)->event(
                    'سرنخ فروش جدید از دستیار سایت',
                    ['نام' => $name, 'موبایل' => $phone, 'ایمیل' => $email, 'علاقه' => $model->interest],
                    url('/admin/marketing/'.$model->id),
                    '🎯',
                    email: false,
                );
            } catch (\Throwable $e) {
                ErrorTracker::note('crm', $e, ['lead' => $model->id, 'channel' => 'admin-notify']);
            }
        }

        return $model;
    }
}

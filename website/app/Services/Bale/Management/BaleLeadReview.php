<?php

namespace App\Services\Bale\Management;

use App\Models\CrmLead;
use App\Models\CrmMessage;
use App\Models\Setting;
use App\Models\User;
use App\Services\Sms\SmsDispatcher;
use App\Support\IranianMobile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/** Explicit owner review with durable per-channel claims. Ambiguous delivery never retries. */
final class BaleLeadReview
{
    public const PATTERN_SETTING = 'bale_manager_lead_reviewed_pattern_code';

    public static function patternCode(): ?string
    {
        $value = trim((string) Setting::get(self::PATTERN_SETTING));
        return preg_match('/^[a-zA-Z0-9]{6,64}$/D', $value) ? $value : null;
    }

    public static function body(int $id): string
    {
        return 'درخواست شما با کد CHAT-'.$id.' توسط تیم سرورنت بررسی شد. ادامه پیگیری: servernet.cloud';
    }

    public function reviewed(int $id, int $actor): array
    {
        if (Setting::get('bale_manager_policy_v2_enabled') !== '1') { return ['status' => 'disabled']; }
        abort_unless(User::find($actor)?->isAdmin(), 403);
        $claims = DB::transaction(function () use ($id, $actor): array {
            $lead = CrmLead::whereKey($id)->lockForUpdate()->firstOrFail();
            if (! $lead->messages()->where('direction', 'in')->exists()) { throw new \DomainException('No inbound request is attached to this lead'); }
            if (in_array($lead->stage, ['new', 'replied'], true)) {
                $lead->forceFill(['stage' => 'reviewed', 'next_action_at' => null])->save();
            }
            $mobile = IranianMobile::national((string) $lead->phone);
            $out = [];
            foreach (['email' => trim((string) $lead->email), 'sms' => $mobile ? '0'.$mobile : ''] as $channel => $to) {
                if ($channel === 'email' ? ! filter_var($to, FILTER_VALIDATE_EMAIL) : $to === '') { continue; }
                $key = 'bale-reviewed:'.$lead->id.':'.$channel;
                $message = $lead->messages()->where('provider_id', $key)->first();
                $blocked = $channel === 'sms' && self::patternCode() === null;
                if ($message) {
                    // Only a known, never-attempted configuration block may retry on an explicit owner click.
                    if ($channel !== 'sms' || $blocked || $message->status !== 'blocked' || $message->error !== 'sms_pattern_not_configured') { continue; }
                    $message->forceFill(['status' => 'dispatching', 'error' => null])->save();
                } else {
                    $email = $channel === 'email' ? \App\Models\NotificationTemplate::email('lead_reviewed', ['code' => 'CHAT-'.$id]) : null;
                    $message = CrmMessage::create(['lead_id' => $lead->id, 'channel' => $channel,
                        'direction' => 'out', 'status' => $blocked ? 'blocked' : 'dispatching', 'provider_id' => $key,
                        'error' => $blocked ? 'sms_pattern_not_configured' : null,
                        'subject' => mb_substr($email['subject'] ?? 'بررسی درخواست شما در سرورنت', 0, 190),
                        'body' => $email ? trim(strip_tags(preg_replace('~</p>|<br\s*/?>~i', "\n", $email['html']))) : self::body($id)]);
                }
                if (! $blocked) { $out[] = ['message' => $message, 'to' => $to, 'channel' => $channel]; }
            }
            if (! $lead->messages()->where('provider_id', 'bale-reviewed:'.$lead->id.':note')->exists()) {
                CrmMessage::create(['lead_id' => $lead->id, 'channel' => 'note', 'direction' => 'internal',
                    'status' => 'logged', 'provider_id' => 'bale-reviewed:'.$lead->id.':note',
                    'subject' => 'بررسی مدیر از بله', 'body' => json_encode(['actor_id' => $actor, 'at' => now()->toIso8601String()], JSON_THROW_ON_ERROR)]);
            }
            return $out;
        }, 3);
        foreach ($claims as $claim) {
            $message = $claim['message'];
            try {
                if ($claim['channel'] === 'email') {
                    Mail::raw($message->body, fn ($m) => $m->to($claim['to'])->subject($message->subject));
                    $ok = true;
                } else {
                    $ok = app(SmsDispatcher::class)->event($claim['to'], 'lead_reviewed', ['code' => 'CHAT-'.$id], $message->body);
                }
                $state = $ok ? 'sent' : 'failed';
            } catch (\Throwable) { $state = 'uncertain'; }
            $message->forceFill(['status' => $state, 'sent_at' => $state === 'sent' ? now() : null])->save();
        }
        $lead = CrmLead::findOrFail($id);
        $outcome = [];
        if (filled($lead->phone) && IranianMobile::national((string) $lead->phone) === null) { $outcome['sms'] = 'unsupported_mobile'; }
        if (filled($lead->email) && ! filter_var(trim($lead->email), FILTER_VALIDATE_EMAIL)) { $outcome['email'] = 'invalid_email'; }
        foreach ($lead->messages()->whereIn('provider_id', ['bale-reviewed:'.$id.':email', 'bale-reviewed:'.$id.':sms'])->get() as $message) {
            $outcome[$message->channel] = $message->status === 'blocked' ? $message->error : $message->status;
        }
        return ['status' => 'reviewed', 'channels' => $outcome, 'duplicate' => $claims === []];
    }

    public function contacted(int $id, int $actor): void
    {
        if (Setting::get('bale_manager_policy_v2_enabled') !== '1') { return; }
        abort_unless(User::find($actor)?->isAdmin(), 403);
        DB::transaction(function () use ($id, $actor): void {
            $lead = CrmLead::whereKey($id)->lockForUpdate()->firstOrFail();
            if (! $lead->messages()->where('direction', 'in')->exists()) { throw new \DomainException('No inbound request is attached to this lead'); }
            if ($lead->stage === 'contacted') { return; }
            if (! in_array($lead->stage, ['won', 'lost'], true)) {
                $lead->forceFill(['stage' => 'contacted', 'last_contacted_at' => now(), 'next_action_at' => null])->save();
            }
            CrmMessage::create(['lead_id' => $id, 'channel' => 'note', 'direction' => 'internal', 'status' => 'logged',
                'subject' => 'ثبت تماس توسط مدیر', 'body' => json_encode(['actor_id' => $actor, 'manual_contact_attested' => true], JSON_THROW_ON_ERROR)]);
        }, 3);
    }
}

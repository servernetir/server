<?php

namespace App\Services\Notify;

use App\Mail\TemplateMail;
use App\Models\Setting;
use App\Services\Bale\BaleNotifier;
use App\Support\ErrorTracker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/** Immediate provider-credit incidents, independent of routine manager digests. */
class SaladCreditAlert
{
    public const EMAIL_SETTING = 'salad_credit_alert_email';

    public function __construct(private AdminNotifier $admin, private BaleNotifier $bale) {}

    /** Only called for a failed Salad response with type=no_credits_available. */
    public function report(string $method, string $path, int $status): array
    {
        $operation = match (true) {
            strtoupper($method) === 'POST' && str_ends_with($path, '/start') => 'روشن‌کردن GPU',
            strtoupper($method) === 'POST' && str_ends_with($path, '/containers') => 'ساخت GPU',
            default => 'درخواست زیرساخت GPU',
        };
        $service = preg_match('~/containers/sn-svc-([1-9][0-9]*)(?:/|$)~D', $path, $m) ? $m[1] : null;
        $text = "🚨 خطای اعتبار زیرساخت GPU (Salad)\n"
            .'کد: no_credits_available | HTTP '.$status."\nعملیات: ".$operation
            .($service !== null ? "\nسرویس: #".$service : '')
            ."\nزمان تهران: ".now()->timezone('Asia/Tehran')->format('Y-m-d H:i:s')
            ."\nاعتبار و مجوز مصرف حساب Salad را فوراً بررسی کنید؛ این خطا ممکن است پس از شارژ نیز تا اعمال اعتبار ادامه داشته باشد."
            ."\nhttps://console.servernet.cloud/admin/errors";

        // Deliberately excludes request/customer context, raw problem body and credentials.
        ErrorTracker::noteOnce('cloud', 'Salad provider credit denied: no_credits_available', 900,
            ['area' => 'salad-credit-alert', 'status' => $status, 'service_id' => $service], false);

        return $this->deliver('فوری: خطای اعتبار زیرساخت GPU', $text,
            'incident:'.hash('sha256', (string) Setting::get('salad_org', '')));
    }

    /** Explicit acceptance test; no provider call, power operation or incident cooldown. */
    public function testDelivery(): array
    {
        return $this->deliver('آزمایش هشدار اعتبار GPU',
            "🔔 آزمایش هشدار اعتبار GPU سرورنت\nاین پیام آزمایشی است و نشان‌دهنده کمبود اعتبار فعلی نیست."
            ."\nهشدار واقعی با مشاهده خطای no_credits_available فوراً ارسال می‌شود."
            ."\nزمان تهران: ".now()->timezone('Asia/Tehran')->format('Y-m-d H:i:s'),
            'test:'.Str::uuid());
    }

    private function deliver(string $subject, string $text, string $scope): array
    {
        $result = [];
        // Separate claims and catches: an SMTP outage cannot silence Bale or vice versa.
        $to = trim((string) Setting::get(self::EMAIL_SETTING, ''));
        $result['email'] = $this->channel($scope, 'email:'.hash('sha256', $to), function () use ($to, $subject, $text): bool {
            if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
                return false;
            }
            Mail::mailer('smtp')->to($to)->send(new TemplateMail('[سرورنت] '.$subject, nl2br(e($text)), 'fa'));

            return true;
        });

        try {
            $targets = $this->admin->baleDestinations();
            if ($targets === []) {
                $result['bale'] = 'failed';
                $this->failed('bale');
            }
            foreach ($targets as $i => [$phone, $chat]) {
                $result['bale_'.$i] = $this->channel($scope, 'bale:'.hash('sha256', $phone.'|'.$chat),
                    fn (): bool => $this->bale->deliverToAdminAt($phone, $chat, $text));
            }
        } catch (\Throwable) {
            $result['bale'] = 'failed';
            $this->failed('bale');
        }

        return $result;
    }

    private function channel(string $scope, string $destination, callable $send): string
    {
        $key = 'salad-credit-alert:'.hash('sha256', $scope.'|'.$destination);
        try {
            // Atomic claim covers concurrent failing customer requests. Retry failed
            // channels on the next observation after one minute; accepted ones wait 15m.
            if (! Cache::add($key, true, 120)) {
                return 'throttled';
            }
        } catch (\Throwable) {
            // A broken cache must not silence a critical incident.
        }

        $ok = false;
        try {
            $ok = $send();
        } catch (\Throwable) {
            // Do not log SMTP credentials, bot URLs or exception messages.
        }
        try {
            Cache::put($key, true, $ok ? 900 : 60);
        } catch (\Throwable) {
        }
        if (! $ok) {
            $this->failed(str_starts_with($destination, 'email:') ? 'email' : 'bale');
        }

        return $ok ? 'accepted' : 'failed';
    }

    private function failed(string $channel): void
    {
        ErrorTracker::noteOnce('notify', 'GPU credit alert channel failed', 60,
            ['area' => 'salad-credit-alert', 'channel' => $channel], false);
    }
}

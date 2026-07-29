<?php

namespace App\Services\Notify;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * اعلان به **مدیر** (نه مشتری) — بله + ایمیل.
 *
 * یک نقطهٔ واحد برای همهٔ رویدادهایی که مدیر باید بداند: تیکت تازه، مشتری تازه،
 * پرداخت، خریدِ سرویس، تعلیقِ خودکار به‌خاطرِ عدمِ تمدید و…
 *
 * ═══ قواعد ═══
 * • هرگز جریانِ اصلی را نمی‌شکند: هر کانال در try/catch جداست. اگر بله یا SMTP
 *   قطع باشد، خریدِ مشتری یا کرونِ تمدید نباید خطا بدهد.
 * • شمارهٔ بلهٔ مدیر از config('servernet.contact.notify_phone') و ایمیل از
 *   config('servernet.contact.email') می‌آید.
 * • متن‌ها کوتاه و تلگرافی‌اند؛ مدیر روی موبایل می‌خواندشان.
 */
class AdminNotifier
{
    public function __construct(private \App\Services\Bale\BaleNotifier $bale) {}

    /**
     * یک رویداد را به مدیر اطلاع بده.
     *
     * @param  string  $title   تیترِ کوتاه (در ایمیل موضوع می‌شود)
     * @param  array<string,string|int|null>  $rows  جفت‌های «برچسب => مقدار» برای بدنه
     * @param  string|null  $url  لینکِ مربوط در پنل مدیریت (اختیاری)
     */
    public function event(string $title, array $rows = [], ?string $url = null, string $emoji = '🔔'): void
    {
        $lines = [$emoji.' '.$title];

        foreach ($rows as $label => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $lines[] = is_int($label) ? (string) $value : $label.': '.$value;
        }

        if ($url !== null && $url !== '') {
            $lines[] = $url;
        }

        $text = implode("\n", $lines);

        $this->sendBale($text);
        $this->sendMail($title, $text);
    }

    private function sendBale(string $text): void
    {
        try {
            $phone = trim((string) config('servernet.contact.notify_phone', ''));

            if ($phone !== '') {
                $this->bale->notify($phone, $text);
            }
        } catch (\Throwable $e) {
            Log::warning('اعلانِ بلهٔ مدیر نرفت', ['error' => mb_substr($e->getMessage(), 0, 160)]);
        }
    }

    private function sendMail(string $subject, string $text): void
    {
        try {
            $to = trim((string) config('servernet.contact.email', ''));

            if ($to === '') {
                return;
            }

            Mail::mailer('smtp')->raw($text, fn ($m) => $m->to($to)->subject('[سرورنت] '.$subject));
        } catch (\Throwable $e) {
            Log::warning('ایمیلِ اعلانِ مدیر نرفت', ['error' => mb_substr($e->getMessage(), 0, 160)]);
        }
    }
}

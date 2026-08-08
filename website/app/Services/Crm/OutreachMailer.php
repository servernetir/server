<?php

namespace App\Services\Crm;

use App\Models\CrmLead;
use App\Models\CrmMessage;
use App\Models\CrmSuppression;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * تخلیهٔ صفِ ارسال — تنها جایی در کلِ سیستم که واقعاً ایمیل بیرون می‌فرستد.
 *
 * چهار قفل پیش از هر ارسال، به این ترتیب:
 *   ۱. خلبانِ خودکار روشن است؟        (پیش‌فرض: نه)
 *   ۲. الان ساعتِ کاریِ مقصد است؟      (نیمه‌شبِ دوبی = خوانده‌نشده)
 *   ۳. سقفِ امروز پر نشده؟             (با احتسابِ گرم‌کردنِ دامنه)
 *   ۴. این نشانی در فهرستِ سیاه نیست؟  (**دوباره**، همین لحظه)
 *
 * 🔴 قفلِ چهارم عمداً تکراری است. بینِ لحظه‌ای که پیام در صف نشست و لحظه‌ای که
 * می‌رود ممکن است روزها فاصله باشد و درست وسطش همان آدم «no» فرستاده باشد.
 * ارسالِ ایمیل بعد از «no» فقط بی‌ادبی نیست، نقضِ CAN-SPAM و CASL است.
 */
class OutreachMailer
{
    /** خروجی هر اجرا — برای لاگ و برای نمایش در پنل */
    public array $stats = ['sent' => 0, 'failed' => 0, 'skipped' => 0];

    public function drain(?int $limit = null, bool $force = false): array
    {
        if (! $force && ! config('crm.autopilot')) {
            Log::info('crm.send.autopilot_off');

            return $this->stats + ['halted' => 'autopilot_off'];
        }

        if (! $force && ! $this->inSendWindow()) {
            return $this->stats + ['halted' => 'outside_window'];
        }

        $room = $this->remainingToday();

        if ($room < 1) {
            return $this->stats + ['halted' => 'daily_cap'];
        }

        $take = $limit ? min($limit, $room) : $room;

        $queued = CrmMessage::where('status', 'queued')
            ->where('direction', 'out')
            ->where('channel', 'email')
            ->orderBy('id')
            ->limit($take * 2)          // بعضی‌شان سرِ قفلِ چهارم رد می‌شوند
            ->get();

        foreach ($queued as $message) {
            if ($this->stats['sent'] >= $take) {
                break;
            }

            // ادعای اتمیک: اگر یک اجرای دیگر زودتر برش داشته، رد شو.
            $claimed = CrmMessage::where('id', $message->id)
                ->where('status', 'queued')
                ->update(['status' => 'sending']);

            if (! $claimed) {
                continue;
            }

            $this->sendOne($message->refresh());

            // فاصلهٔ نامنظم بینِ ارسال‌ها. ده ایمیل در ده ثانیه، الگویِ یک
            // ماشین است؛ فیلترها دقیقاً دنبالِ همین می‌گردند.
            if ($this->stats['sent'] < $take) {
                sleep(random_int(25, 95));
            }
        }

        Log::info('crm.send.done', $this->stats);

        return $this->stats;
    }

    /**
     * ارسالِ یک پیامِ مشخص. همان مسیرِ صف است، پس قفلِ فهرستِ سیاه و ثبتِ مرحله
     * دقیقاً یکسان اجرا می‌شوند — دکمهٔ «تأیید و ارسال» در پنل هم از همین‌جا
     * می‌گذرد، نه از یک مسیرِ میان‌بر.
     */
    public function sendOne(CrmMessage $message): bool
    {
        $lead = $message->lead;

        if (! $lead || CrmSuppression::blocks($lead->email)) {
            $message->update(['status' => 'skipped', 'error' => 'suppressed_at_send']);
            $this->stats['skipped']++;
            Log::info('crm.send.skip.suppressed', ['message' => $message->id]);

            return false;
        }

        $from = config('crm.from');
        $to = $lead->email;

        try {
            $sent = Mail::mailer((string) config('crm.mailer'))->raw(
                $message->body,
                function ($m) use ($to, $message, $from) {
                    $m->to($to)
                        ->subject($message->subject)
                        ->from($from['email'], $from['name'])
                        ->replyTo($from['email'], $from['name']);

                    // «لغو با یک کلیک» را خودِ جیمیل و اوت‌لوک نشان می‌دهند.
                    // بودنش هم ادبِ کار است هم سیگنالِ مثبت برای فیلترِ اسپم:
                    // کسی که راهِ خروجِ آسان می‌گذارد، اسپمر نیست.
                    $m->getSymfonyMessage()->getHeaders()->addTextHeader(
                        'List-Unsubscribe',
                        '<mailto:'.$from['email'].'?subject=unsubscribe>',
                    );
                },
            );

            $message->update([
                'status'      => 'sent',
                'sent_at'     => now(),
                'provider_id' => $sent?->getMessageId(),
                'error'       => null,
            ]);

            $this->advance($lead, $message);
            $this->stats['sent']++;

            return true;
        } catch (Throwable $e) {
            $message->update([
                'status' => 'failed',
                'error'  => mb_substr($e->getMessage(), 0, 480),
            ]);
            $this->stats['failed']++;
            Log::error('crm.send.failed', ['message' => $message->id, 'err' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * مرحله و تاریخِ اقدامِ بعدی را جلو می‌برد.
     *
     * 🔴 پس از پیامِ سوم، سرنخ **بسته** می‌شود. نه اینکه فقط فالوآپ نداشته باشد —
     * `stage=lost` یعنی `isContactable()` دیگر false است و هیچ مسیری در کد
     * نمی‌تواند دوباره برایش پیام بسازد. کسی که سه بار جواب نداده مشتری نیست،
     * و پیامِ چهارم فقط شکایتِ اسپم می‌آورد.
     */
    private function advance(CrmLead $lead, CrmMessage $message): void
    {
        $lead->last_contacted_at = now();

        if ($message->sequence >= CrmMessage::MAX_SEQUENCE) {
            $lead->stage = 'lost';
            $lead->lost_at = now();
            $lead->lost_reason = 'بدون پاسخ پس از سه پیام';
            $lead->next_action_at = null;
            $lead->save();

            return;
        }

        $stage = $message->sequence === 0 ? 'contacted' : 'fu1';
        $lead->stage = $stage;
        $lead->next_action_at = now()->addDays(CrmLead::CADENCE[$stage] ?? 7)->toDateString();
        $lead->save();
    }

    // ───────────────────────── سقف و پنجره ─────────────────────────

    public function sentToday(): int
    {
        return CrmMessage::where('direction', 'out')
            ->where('status', 'sent')
            ->where('sent_at', '>=', now()->startOfDay())
            ->count();
    }

    public function remainingToday(): int
    {
        return max(0, $this->dailyCap() - $this->sentToday());
    }

    /**
     * سقفِ امروز = کمترینِ (سقفِ پیکربندی، پلهٔ گرم‌کردن).
     *
     * دامنه‌ای که تا دیروز فقط فاکتور می‌فرستاد و امروز ۳۰ ایمیلِ سرد، برای
     * فیلترِ اسپم شبیهِ اکانتِ هک‌شده است. پله‌ها از روزِ **اولین ارسال**
     * شمرده می‌شوند، نه از روزِ نصب.
     */
    public function dailyCap(): int
    {
        $cap = (int) config('crm.caps.email', 30);

        if (! config('crm.warmup.enabled')) {
            return $cap;
        }

        $first = CrmMessage::where('direction', 'out')
            ->where('status', 'sent')
            ->min('sent_at');

        if (! $first) {
            $steps = (array) config('crm.warmup.steps', []);

            return min($cap, (int) ($steps[array_key_first($steps)] ?? $cap));
        }

        $day = Carbon::parse($first)->startOfDay()->diffInDays(now()->startOfDay()) + 1;
        $allowed = $cap;

        foreach ((array) config('crm.warmup.steps', []) as $fromDay => $limit) {
            if ($day >= (int) $fromDay) {
                $allowed = (int) $limit;
            }
        }

        return min($cap, $allowed);
    }

    /** ساعتِ کاریِ مقصد؟ (پیکربندی به وقتِ UTC — تایم‌زونِ اپ روی UTC ثابت است) */
    public function inSendWindow(?Carbon $now = null): bool
    {
        $now ??= now();
        $w = (array) config('crm.send_window');

        if (in_array((int) $now->isoWeekday(), (array) ($w['skip_days'] ?? []), true)) {
            return false;
        }

        return $now->hour >= (int) ($w['from_hour'] ?? 0)
            && $now->hour < (int) ($w['to_hour'] ?? 24);
    }
}

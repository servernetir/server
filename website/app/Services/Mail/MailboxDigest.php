<?php

namespace App\Services\Mail;

use App\Models\MailboxMessage;
use App\Services\Bale\BaleNotifier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * گزارشِ صندوق‌ها در ربات بله.
 *
 * ═══ سه قاعده که کلِ ارزشِ این کلاس به آن‌هاست ═══
 *
 * 🔴 ۱. هر نامه **حداکثر یک بار** گزارش می‌شود. `reported_at` بعد از ارسالِ
 *    موفق زده می‌شود، نه قبلش — اگر بله قطع باشد، نامه‌ها گزارش‌نشده می‌مانند
 *    و اجرای بعدی می‌گیردشان. برعکسش یعنی یک قطعیِ لحظه‌ایِ بله، یک روز
 *    ایمیل را برای همیشه ناپیدا می‌کند.
 *
 * 🔴 ۲. اعلان‌های خودِ سیستم هرگز نمی‌آیند. آن‌ها همان لحظه که رخ داده‌اند
 *    یک‌بار در بله گفته شده‌اند؛ گفتنِ دوباره‌شان یعنی گزارش را کسی نمی‌خواند.
 *
 * 🔴 ۳. گزارشِ خالی فرستاده نمی‌شود. پیامِ «چیزی نبود» که روزی دو بار بیاید،
 *    ظرفِ یک هفته به نویزی تبدیل می‌شود که چشم از رویش رد می‌شود — و آن روزی
 *    که واقعاً چیزی هست هم دیده نمی‌شود.
 */
class MailboxDigest
{
    public function __construct(private BaleNotifier $bale) {}

    /**
     * @return array{reported:int, skipped:int, sent:bool, reason?:string}
     */
    public function send(bool $dry = false): array
    {
        $quiet = (array) config('mailboxes.quiet_categories', []);

        $pending = MailboxMessage::unreported()
            ->orderByDesc('importance')
            ->orderByDesc('received_at')
            ->limit(80)
            ->get();

        if ($pending->isEmpty()) {
            return ['reported' => 0, 'skipped' => 0, 'sent' => false, 'reason' => 'nothing_new'];
        }

        // خبرنامه و اسپم گزارش نمی‌شوند — ولی `reported_at` می‌خورند، وگرنه
        // تا ابد در صف می‌مانند و هر اجرا دوباره بررسی می‌شوند.
        $worth = $pending->reject(fn (MailboxMessage $m) => in_array((string) $m->category, $quiet, true));

        if ($worth->isEmpty()) {
            if (! $dry) {
                $this->stamp($pending);
            }

            return ['reported' => 0, 'skipped' => $pending->count(), 'sent' => false, 'reason' => 'nothing_worth_saying'];
        }

        $text = $this->compose($worth);

        if ($dry) {
            return ['reported' => $worth->count(), 'skipped' => $pending->count() - $worth->count(), 'sent' => false, 'reason' => $text];
        }

        $phone = trim((string) (config('mailboxes.digest_phone') ?: config('servernet.contact.notify_phone', '')));

        if ($phone === '') {
            Log::warning('mailbox.digest.no_phone');

            return ['reported' => 0, 'skipped' => 0, 'sent' => false, 'reason' => 'no_phone'];
        }

        try {
            $this->bale->notify($phone, $text);
        } catch (Throwable $e) {
            // 🔴 عمداً هیچ چیزی مهر نمی‌خورد. نامه‌ها برای اجرای بعدی می‌مانند.
            Log::error('mailbox.digest.bale_failed', ['err' => $e->getMessage()]);
            \App\Support\ErrorTracker::note('notify', $e, ['to' => 'admin', 'channel' => 'bale', 'what' => 'mailbox-digest']);

            return ['reported' => 0, 'skipped' => 0, 'sent' => false, 'reason' => 'bale_failed'];
        }

        $this->stamp($pending);

        Log::info('mailbox.digest.sent', ['reported' => $worth->count(), 'skipped' => $pending->count() - $worth->count()]);

        return [
            'reported' => $worth->count(),
            'skipped'  => $pending->count() - $worth->count(),
            'sent'     => true,
        ];
    }

    /**
     * متنِ گزارش — تلگرافی، چون روی موبایل خوانده می‌شود.
     *
     * @param  Collection<int, MailboxMessage>  $items
     */
    public function compose(Collection $items): string
    {
        $labels = (array) config('mailboxes.categories', []);
        $needs = $items->where('needs_reply', true);

        $lines = ['📬 گزارشِ صندوق‌های ایمیل'];
        $lines[] = str_repeat('─', 18);

        // سرشماریِ صندوق‌ها: یک نگاه، بفهمی کجا شلوغ است
        foreach ($items->groupBy('account') as $account => $group) {
            $lines[] = '• '.$group->first()->accountLabel().': '.$group->count().' تازه';
        }

        if ($needs->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '🔴 نیازمندِ جواب ('.$needs->count().')';

            foreach ($needs->sortByDesc('importance')->take(12) as $m) {
                $lines[] = '';
                $lines[] = '‹'.$m->accountLabel().'› '.($m->from_name ?: $m->from_email);
                $lines[] = ($m->subject ?: '(بدون موضوع)');

                if (filled($m->summary)) {
                    $lines[] = '↳ '.$m->summary;
                }
            }

            if ($needs->count() > 12) {
                $lines[] = '';
                $lines[] = '… و '.($needs->count() - 12).' موردِ دیگر در پنل.';
            }
        }

        // بقیه فقط شمارش می‌شوند؛ اسمشان در گزارش جا نمی‌گیرد.
        $rest = $items->where('needs_reply', false);

        if ($rest->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'بدونِ نیاز به جواب:';

            foreach ($rest->groupBy('category') as $category => $group) {
                $lines[] = '· '.($labels[$category] ?? $category).': '.$group->count();
            }
        }

        $lines[] = '';
        $lines[] = url('/admin/mail');

        return implode("\n", $lines);
    }

    /** @param  Collection<int, MailboxMessage>  $items */
    private function stamp(Collection $items): void
    {
        MailboxMessage::whereIn('id', $items->pluck('id'))->update(['reported_at' => now()]);
    }
}

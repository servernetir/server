<?php

namespace App\Services\Bale\Admin;

use App\Models\BankTransferReceipt;
use App\Models\Service;
use App\Services\Bale\BaleSender;
use App\Services\Billing\BankReceiptReviewer;
use App\Support\ErrorTracker;

/**
 * اجرای کارهای **پولی و برگشت‌ناپذیرِ** کنسولِ بله، بیرون از وب‌هوک.
 *
 * ⚠️ هر متد نتیجه را به چتِ متصل گزارش می‌دهد — نه به فرستندهٔ آپدیت. کارفرما
 * دکمه را زده و رفته؛ اگر نتیجه برنگردد، «انجام شد یا نه؟» را باید در پنل
 * چک کند و کلِ قابلیت بی‌معنی می‌شود.
 *
 * ⚠️ و هرگز throw نمی‌کند: این کلاس از داخلِ `schedule:run` صدا می‌شود و یک
 * استثنا کلِ آن دقیقهٔ کرون را می‌کشد.
 */
class AdminBaleWorker
{
    public function __construct(
        private AdminBaleGate $gate,
        private BaleSender $sender,
    ) {}

    /** @param  array{verb:string,args:array}  $job */
    public function run(array $job): void
    {
        $verb = (string) ($job['verb'] ?? '');
        $args = (array) ($job['args'] ?? []);

        try {
            match ($verb) {
                'receipt_approve' => $this->approveReceipt((int) ($args['id'] ?? 0)),
                'receipt_reject'  => $this->rejectReceipt((int) ($args['id'] ?? 0), (string) ($args['reason'] ?? '')),
                'service_terminate' => $this->terminateService((int) ($args['id'] ?? 0)),
                'mail_reply'      => $this->replyToMail((int) ($args['id'] ?? 0), (string) ($args['body'] ?? '')),
                default => $this->tell('⚠️ کارِ ناشناخته در صف بود و اجرا نشد.'),
            };
        } catch (\Throwable $e) {
            ErrorTracker::note('bale-admin', $e, ['job' => $verb]);
            $this->tell('⚠️ «'.$verb.'» اجرا نشد. جزئیات در /admin/errors.');
        }
    }

    // ───────────────────────── رسیدِ بانکی ─────────────────────────

    private function approveReceipt(int $id): void
    {
        $r = BankTransferReceipt::find($id);

        if ($r === null) {
            $this->tell('رسید پیدا نشد.');

            return;
        }

        $res = app(BankReceiptReviewer::class)->approve($r, $this->gate->boundUser()?->id);

        $this->tell(($res['ok'] ? '✅ ' : '⚠️ ').$res['message']
            .($r->customer ? "\n👤 ".$r->customer->displayName().' · '.$r->customer->code : '')
            ."\n💰 ".fa_num(number_format((int) $r->amount)).' تومان');
    }

    private function rejectReceipt(int $id, string $reason): void
    {
        $r = BankTransferReceipt::find($id);

        if ($r === null) {
            $this->tell('رسید پیدا نشد.');

            return;
        }

        $res = app(BankReceiptReviewer::class)->reject($r, $reason, $this->gate->boundUser()?->id);

        $this->tell(($res['ok'] ? '⛔️ ' : '⚠️ ').$res['message']
            .($res['ok'] ? "\nدلیل: ".mb_substr($reason, 0, 120) : ''));
    }

    // ───────────────────────── خاتمهٔ سرویس ─────────────────────────

    /**
     * 🔴 برگشت‌ناپذیرترین کارِ کلِ کنسول.
     *
     * `ProvisioningService::terminate()` سه گام دارد و گامِ اول **بی‌قیدوشرط**
     * است: وضعیتِ مالی مرده می‌شود پیش از هر تماسی با زیرساخت. یعنی حتی اگر
     * حذفِ ماشین شکست بخورد، صورت‌حساب بسته شده — و همان‌جا هم درست است، چون
     * مشتری گفته «پاکش کن» و نباید ساعتِ بعد دوباره کسر شود.
     *
     * ⚠️ پس گزارش باید صریح بگوید کدام گام موفق بوده. «خاتمه یافت» وقتی ماشین
     * هنوز زنده است، همان دروغی است که یک بار سرورِ بی‌مشتری روی دستمان
     * گذاشت.
     */
    private function terminateService(int $id): void
    {
        $s = Service::find($id);

        if ($s === null) {
            $this->tell('سرویس پیدا نشد.');

            return;
        }

        $name = (string) $s->name;
        $who  = $s->customer?->displayName();

        $r = app(\App\Services\Provisioning\ProvisioningService::class)->terminate($s);

        \App\Models\ActivityLog::forService($s, 'terminate',
            'خاتمه از رباتِ بله توسط مدیر «'.($this->gate->boundUser()?->name ?? 'مدیر').'»', 'staff');

        if ($r->ok) {
            $this->tell('🗑 «'.$name.'»'.($who ? ' — '.$who : '')."\nخاتمه یافت و نزدِ زیرساخت هم پاک شد.");

            return;
        }

        if ($r->manual) {
            $this->tell('🗑 «'.$name.'»'.($who ? ' — '.$who : '')
                ."\n⚠️ صورت‌حساب بسته شد، ولی حذفِ ماشین **دستی** لازم دارد.");

            return;
        }

        $this->tell('🗑 «'.$name.'»'.($who ? ' — '.$who : '')
            ."\n🔴 صورت‌حساب بسته شد ولی زیرساخت حذف را نپذیرفت."
            ."\nدر صفِ تلاشِ دوباره مانْد؛ تا بسته نشود اجاره‌اش پای ماست.");
    }

    // ───────────────────────── پاسخِ ایمیل ─────────────────────────

    /**
     * ⚠️ خودِ ارسال در `MailboxReplier` است، نه این‌جا: پنلِ ادمین هم روزی
     * همان دکمه را می‌خواهد و دو پیاده‌سازیِ ارسالِ ایمیل یعنی روزی یکی‌شان
     * هدرِ رشته را جا می‌اندازد و پاسخ‌ها از گفتگو جدا می‌افتند.
     */
    private function replyToMail(int $id, string $body): void
    {
        $m = \Illuminate\Support\Facades\Schema::hasTable('mailbox_messages')
            ? \App\Models\MailboxMessage::find($id) : null;

        if ($m === null) {
            $this->tell('ایمیل پیدا نشد؛ پاسخ فرستاده نشد.');

            return;
        }

        $res = app(\App\Services\Mail\MailboxReplier::class)->reply(
            $m, $body,
            $this->gate->boundUser()?->id,
            $this->gate->boundUser()?->name,
        );

        $this->tell(($res['ok'] ? '📧 ' : '⚠️ ').$res['message']
            ."\n\nموضوع: ".mb_substr((string) $m->subject, 0, 80)
            ."\nگیرنده: ".mb_substr((string) $m->from_email, 0, 80));
    }

    // ───────────────────────────── کمکی ─────────────────────────────

    /** گزارش همیشه به چتِ متصل — نه به هیچ مقصدِ دیگری */
    private function tell(string $text): void
    {
        try {
            $chat = (string) ($this->gate->binding()['chat_id'] ?? '');

            if ($chat === '') {
                return;
            }

            // ⚠️ `BaleSender`، نه `BaleNotifier::notify()` — آن یکی سفیرِ پولی است
            $this->sender->send($chat, mb_substr($text, 0, 3500));
        } catch (\Throwable) {
            // گزارش هرگز نباید خودِ کار را بشکند
        }
    }
}

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
            if (in_array($verb, ['receipt_evidence', 'lead_reviewed', 'lead_contacted', 'refund_approve', 'refund_reject'], true)) {
                $actor = $this->gate->boundUser();
                $bindingHash = hash('sha256', (string) \App\Models\Setting::get(AdminBaleGate::KEY_BIND));
                if (\App\Models\Setting::get('bale_manager_policy_v2_enabled') !== '1' || ! $this->gate->enabled()
                    || ! $actor?->isAdmin() || (int) ($args['manager_actor_id'] ?? 0) !== (int) $actor->id
                    || ! hash_equals($bindingHash, (string) ($args['manager_binding_hash'] ?? ''))) {
                    $this->tell('اتصال یا مجوز مدیر پس از ثبت کار تغییر کرده است؛ اجرا نشد.');
                    return;
                }
            }
            match ($verb) {
                'receipt_evidence' => $this->receiptEvidence((int) ($args['id'] ?? 0)),
                'lead_reviewed' => $this->reviewLead((int) ($args['id'] ?? 0)),
                'lead_contacted' => $this->contactLead((int) ($args['id'] ?? 0)),
                'refund_approve' => $this->reviewRefund((int) ($args['id'] ?? 0), (string) ($args['note'] ?? ''), false, (string) ($args['fingerprint'] ?? '')),
                'refund_reject' => $this->reviewRefund((int) ($args['id'] ?? 0), (string) ($args['note'] ?? ''), true),
                'receipt_approve' => $this->approveReceipt((int) ($args['id'] ?? 0)),
                'receipt_reject'  => $this->rejectReceipt((int) ($args['id'] ?? 0), (string) ($args['reason'] ?? '')),
                'service_terminate' => $this->terminateService((int) ($args['id'] ?? 0)),
                'mail_reply'      => $this->replyToMail((int) ($args['id'] ?? 0), (string) ($args['body'] ?? '')),
                'kyc_docs'        => $this->sendKycDocs((int) ($args['id'] ?? 0)),
                'kyc_approve'     => $this->kycApprove((int) ($args['id'] ?? 0)),
                'kyc_reject'      => $this->kycReject((int) ($args['id'] ?? 0), (string) ($args['reason'] ?? '')),
                default => $this->tell('⚠️ کارِ ناشناخته در صف بود و اجرا نشد.'),
            };
        } catch (\Throwable $e) {
            ErrorTracker::note('bale-admin', $e, ['job' => $verb]);
            $this->tell('⚠️ «'.$verb.'» اجرا نشد. جزئیات در /admin/errors.');
        }
    }

    private function receiptEvidence(int $id): void
    {
        $r = BankTransferReceipt::with(['customer', 'invoice'])->find($id);
        $path = $r ? app(\App\Services\Billing\BankReceiptEvidence::class)->path($r) : null;
        if (! $path) { $this->tell('برای این رسید فایل معتبر و قابل نمایش ثبت نشده است.'); return; }
        $chat = (string) ($this->gate->binding()['chat_id'] ?? '');
        if ($chat === '') { return; }
        $ok = $this->sender->sendDocument($chat, $path, 'رسید #'.$r->id.' · '.($r->customer?->code ?? ''));
        $cb = AdminBaleRouter::CB_PREFIX;
        $this->sender->sendButtons($chat, ($ok ? '📎 فایل رسید ارسال شد.' : '⚠️ ارسال فایل به بله انجام نشد؛ در پرونده محفوظ است.')
            ."\nمشتری: ".($r->customer?->displayName() ?? '—').' · '.($r->customer?->code ?? '—')
            ."\nمبلغ: ".AdminBaleScreens::receiptAmount($r).'؛ وضعیت: '.$r->status, [
                [['text' => 'بررسی و تصمیم رسید', 'data' => $cb.'r:'.$id], ['text' => 'پرونده مشتری', 'data' => $cb.'c:'.$r->customer_id]],
            ]);
    }

    private function reviewLead(int $id): void
    {
        $result = app(\App\Services\Bale\Management\BaleLeadReview::class)->reviewed($id, (int) $this->gate->boundUser()->id);
        $channels = [];
        foreach ($result['channels'] ?? [] as $channel => $status) {
            $channels[] = ($channel === 'sms' ? 'پیامک' : 'ایمیل').': '.match ($status) {
                'sent' => 'به سرویس ارسال تحویل شد (قبلی یا همین بررسی)',
                'sms_pattern_not_configured' => 'ارسال نشد؛ الگوی تأییدشده را در تنظیمات بله ثبت کنید: https://console.servernet.cloud/admin/settings?tab=bale',
                'unsupported_mobile' => 'ارسال نشد؛ شماره موبایل ایرانی معتبر در دسترس نیست',
                'invalid_email' => 'ارسال نشد؛ نشانی ایمیل معتبر نیست',
                'failed' => 'ارسال ناموفق؛ تنظیمات ارسال را بررسی کنید',
                'uncertain' => 'نتیجه نامشخص؛ پیش از تلاش دستی سوابق ارسال را بررسی کنید',
                default => 'ثبت شده؛ ارسال مجدد خودکار انجام نمی‌شود',
            };
        }
        $this->tell('✅ بررسی درخواست CHAT-'.$id.' ثبت شد.'
            ."\n".($channels ? implode("\n", $channels) : 'اطلاع‌رسانی قبلاً ثبت شده یا اطلاعات تماس معتبر در دسترس نیست؛ ارسال مجدد انجام نشد.')
            ."\nاین ثبت به معنی حل شدن درخواست نیست.");
    }

    private function contactLead(int $id): void
    {
        app(\App\Services\Bale\Management\BaleLeadReview::class)->contacted($id, (int) $this->gate->boundUser()->id);
        $this->tell('📞 انجام تماس با درخواست CHAT-'.$id.' طبق اعلام شما در پرونده ثبت شد.');
    }

    private function reviewRefund(int $id, string $note, bool $reject, string $fingerprint = ''): void
    {
        $r = \App\Models\WalletRefundRequest::findOrFail($id);
        if (! $reject && ! hash_equals(\App\Services\Bale\Management\BaleRefundReview::fingerprint($r), $fingerprint)) {
            $this->tell('جزئیات عودت پس از تأیید تغییر کرده است؛ دوباره بررسی کنید.'); return;
        }
        $actor = $this->gate->boundUser();
        $service = app(\App\Services\Finance\WalletRefunds::class);
        $reviewed = $reject ? $service->close($r, 'rejected', $note, $actor)
            : $service->approve($r, $actor, $note, null, $note);
        $this->tell(($reject ? '⛔ درخواست عودت رد شد.' : '✅ درخواست عودت تأیید شد؛ واریز بانکی انجام نشده است.')
            ."\nشناسه: ".$reviewed->public_id."\nتوضیح: ".mb_substr($note, 0, 200));
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
            ."\n💰 ".(\App\Models\Setting::get('bale_manager_policy_v2_enabled') === '1' ? AdminBaleScreens::receiptAmount($r) : fa_num(number_format((int) $r->amount)).' تومان'));
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

    // ───────────────────── احراز هویت (KYC) ─────────────────────

    /**
     * ارسالِ خودِ فایل‌های مدرک به چتِ متصل — این‌جا و نه در وب‌هوک، چون
     * آپلودِ چند فایلِ چندمگابایتی به بله از مهلتِ وب‌هوک بلندتر است.
     *
     * ⚠️ مقصد همیشه چتِ متصل است (tell/sendDocument همان‌جا می‌روند) — این
     * فایل‌ها مدرکِ هویتیِ مشتری‌اند و هیچ مقصدِ دیگری مجاز نیست.
     */
    private function sendKycDocs(int $id): void
    {
        $p = \App\Models\CustomerProfile::with('customer')->find($id);

        if ($p === null) {
            $this->tell('پروفایل پیدا نشد.');

            return;
        }

        $labels = [
            'passport' => 'پاسپورت', 'national_id' => 'کارت ملی',
            'driving_license' => 'گواهینامهٔ رانندگی', 'id_back' => 'پشتِ مدرک',
            'selfie' => 'سلفی با مدرک', 'address_proof' => 'مدرکِ آدرس',
            'rep_letter' => 'معرفی‌نامهٔ نماینده', 'articles' => 'اساسنامه',
        ];

        $chat = (string) ($this->gate->binding()['chat_id'] ?? '');
        $docs = \App\Models\CustomerDocument::where('customer_profile_id', $p->id)->where('status', '!=', 'superseded')->get();
        $sent = 0;

        foreach ($docs as $d) {
            $path = \Illuminate\Support\Facades\Storage::disk('local')->path($d->disk_path);

            if ($chat !== '' && $this->sender->sendDocument($chat, $path,
                ($labels[$d->kind] ?? $d->kind).' — '.($p->customer?->code ?? ''))) {
                $sent++;
            }
        }

        $who = $p->company_name ?: trim(($p->first_name ?? '').' '.($p->last_name ?? ''));

        // خلاصه + همان دکمه‌های تصمیم، تا بعد از دیدنِ مدارک لازم نباشد
        // به اعلانِ اولیه برگردد
        $cb = AdminBaleRouter::CB_PREFIX;
        $rows = [];
        if ($p->status === 'pending') { $rows[] = [
            ['text' => '✅ تأیید', 'data' => $cb.'ka:'.$p->id],
            ['text' => '⛔️ رد', 'data' => $cb.'kr:'.$p->id],
        ]; }
        if (\App\Models\Setting::get('bale_manager_policy_v2_enabled') === '1') { $rows[] = [
            ['text' => 'تطبیق پروفایل', 'data' => $cb.'kp:'.$p->id],
            ['text' => 'پرونده مشتری', 'data' => $cb.'c:'.$p->customer_id],
        ]; }
        $this->sender->sendButtons($chat, implode("\n", array_filter([
            '📄 '.fa_num($sent).' از '.fa_num($docs->count()).' مدرک فرستاده شد.',
            '👤 '.($who ?: '—').' · '.($p->customer?->code ?? ''),
            $p->country ? '🌍 '.\App\Support\Countries::name($p->country) : null,
            $p->birth_date ? '🎂 '.$p->birth_date->format('Y-m-d') : null,
            $p->address ? '🏠 '.mb_substr(trim($p->address.($p->city ? '، '.$p->city : '')), 0, 120) : null,
        ])), $rows);
    }

    private function kycApprove(int $id): void
    {
        $p = \App\Models\CustomerProfile::find($id);

        if ($p === null) {
            $this->tell('پروفایل پیدا نشد.');

            return;
        }

        $res = app(\App\Services\Customer\KycReview::class)->approve($p, $this->gate->boundUser()?->id);

        $this->tell(($res['ok'] ? '✅ ' : '⚠️ ').$res['message']);
    }

    private function kycReject(int $id, string $reason): void
    {
        $p = \App\Models\CustomerProfile::find($id);

        if ($p === null) {
            $this->tell('پروفایل پیدا نشد.');

            return;
        }

        $res = app(\App\Services\Customer\KycReview::class)->reject($p, $reason, $this->gate->boundUser()?->id);

        $this->tell(($res['ok'] ? '⛔️ ' : '⚠️ ').$res['message']
            .($res['ok'] ? "\nدلیل: ".mb_substr($reason, 0, 120) : ''));
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

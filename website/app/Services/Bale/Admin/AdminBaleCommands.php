<?php

namespace App\Services\Bale\Admin;

use App\Models\Ticket;
use App\Services\SystemHealth;

/**
 * متنِ کارتی که در بله دیده می‌شود. فقط **می‌سازد**؛ هیچ‌چیز نمی‌فرستد و
 * هیچ‌چیز نمی‌نویسد.
 *
 * ═══ قواعدِ محتوا — هر کدام یک دلیلِ واقعی دارد ═══
 *
 * 🔴 **یادداشتِ داخلی هرگز چاپ نمی‌شود.** کارت‌ها از `visibleMessages()`
 * می‌خوانند، همان اسکوپی که مشتری می‌بیند. یک ترنسکریپتِ چت قابلِ فوروارد است؛
 * یادداشتی که برای مشتری نامرئی نگه داشته‌ایم نباید با یک فوروارد بیرون برود.
 * فقط شمارشش می‌آید تا کارفرما بداند چیزی هست.
 *
 * 🔴 **مشخصاتِ تماسِ مشتری چاپ نمی‌شود** — نه ایمیل، نه موبایل. برای اینکه
 * بدانیم دربارهٔ کیست، نام و کدِ عمومیِ `SN-…` کافی است.
 *
 * ⚠️ **سن نسبی است، نه تاریخِ مطلق.** `config/app.php` ثابت روی UTC است و روزِ
 * کاریِ کارفرما تهران؛ «۲ ساعت منتظر» در هر دو تقویم یک معنی دارد،
 * «۰۸:۳۰» نه.
 */
class AdminBaleCommands
{
    /** بیشترین تیکتی که در یک پیامِ صف می‌آید */
    public const QUEUE_LIMIT = 8;

    /** چند پیامِ آخرِ تیکت در کارت */
    private const CARD_MESSAGES = 5;

    public function __construct(private AdminBaleAnchor $anchor) {}

    // ───────────────────────────── راهنما ─────────────────────────────

    public function panel(): string
    {
        return implode("\n", [
            '🛠 کنسولِ مدیرِ سرورنت',
            '',
            '📋 دیدن',
            '  تیکت‌ها — صفِ منتظرِ پاسخ',
            '  تیکت <شماره> — پروندهٔ کامل',
            '  سلامت — وضعیتِ سامانه',
            '  وضعیت — این ربات به چه حسابی وصل است',
            '',
            '✍️ نوشتن (روی اعلانِ تیکت ریپلای بزنید، شماره لازم نیست)',
            '  «هر متنی» → پاسخ به مشتری (همان لحظه می‌رود)',
            '  بستن [متن] → پاسخ و بستن',
            '  یادداشت <متن> → یادداشتِ داخلی، مشتری نمی‌بیند (بی‌تأیید)',
            '  بی‌ریپلای: پاسخ <شماره> <متن>',
            '',
                        '⛔️ عمداً از ربات در دسترس نیست — کارِ پولی و برگشت‌ناپذیر:',
            '  تأیید/ردِ رسیدِ بانکی · حذفِ سرویس یا مشتری · تغییرِ رمزِ مشتری',
            '  ورود به‌جای مشتری · ثبت/تمدیدِ دامنه · اعلانِ گروهی · تنظیماتِ توکن',
            'اینها فقط از پنلِ مدیریت انجام می‌شوند و این محدودیت عمدی است.',
        ]);
    }

    /**
     * «امروز چه چیزی منتظرِ من است؟» — تنها صفحه‌ای که یک مدیرِ گوشی‌به‌دست
     * واقعاً هر بار می‌خواهد.
     *
     * 🔴 این جایگزینِ متنِ راهنما در منوی اصلی شد، و دلیلش ارگونومی نیست،
     * ریاضیِ تپ است: منوی قبلی یک متنِ بیست‌خطی بود که دکمه‌ها را از صفحهٔ اولِ
     * گوشی بیرون می‌انداخت، و برای فهمیدنِ اینکه اصلاً چیزی خراب هست یا نه
     * باید بینِ شش دسته حدس می‌زد. حالا خودِ جواب اول می‌آید و دکمه‌ها شمارش را
     * روی برچسبشان دارند.
     *
     * @return array{text:string,counts:array<string,int>}
     */
    public function digest(): array
    {
        $n = ['tickets' => 0, 'bank' => 0, 'stuck' => 0, 'domains' => 0, 'mail' => 0];

        // ⚠️ هر شمارش جدا در try است: یک جدولِ مهاجرت‌نخورده نباید کلِ صفحهٔ
        // اولِ ربات را خالی کند.
        $count = function (callable $fn): int {
            try {
                return (int) $fn();
            } catch (\Throwable) {
                return 0;
            }
        };

        $n['tickets'] = $count(fn () => \App\Models\Ticket::query()->queue()->count());

        $n['bank'] = $count(fn () => \Illuminate\Support\Facades\Schema::hasTable('bank_transfer_receipts')
            ? \App\Models\BankTransferReceipt::where('status', 'pending')->count() : 0);

        $n['mail'] = $count(fn () => \Illuminate\Support\Facades\Schema::hasTable('mailbox_messages')
            ? \App\Models\MailboxMessage::open()->where('needs_reply', true)->count() : 0);

        $n['stuck'] = $count(fn () => \App\Models\Service::whereIn('provision_status', ['failed', 'manual'])
            ->whereNotIn('status', \App\Models\Service::DEAD_STATUSES)->count());

        $n['domains'] = $count(fn () => \App\Models\Domain::where('provision_status', 'manual')
            ->whereNotIn('status', \App\Models\Domain::DEAD_STATUSES)->count());

        $quiet = array_sum($n) === 0;

        $lines = ['☀️ امروز', ''];

        if ($quiet) {
            $lines[] = '✅ هیچ‌چیز منتظرِ شما نیست.';
        } else {
            foreach ([
                'tickets' => '🎫 تیکتِ منتظرِ پاسخ',
                'bank'    => '🏦 رسیدِ واریزِ بررسی‌نشده',
                'mail'    => '📬 ایمیلِ منتظرِ پاسخ',
                'stuck'   => '⚠️ تحویلِ گیرکرده',
                'domains' => '🌐 دامنهٔ منتظرِ اقدام',
            ] as $k => $label) {
                if ($n[$k] > 0) {
                    $lines[] = $label.': '.fa_num((string) $n[$k]);
                }
            }
        }

        return ['text' => implode("
", $lines), 'counts' => $n];
    }

    public function unknown(): string
    {
        return "فرمان را نمی‌شناسم.\nبرای فهرستِ کارها «راهنما» را بفرستید.";
    }

    // ───────────────────────────── دیدن ─────────────────────────────

    /**
     * صفِ پشتیبانی — **یک** پیام، نه یکی به‌ازای هر تیکت.
     *
     * ⚠️ یک پیام به‌ازای هر تیکت ارگونومیِ بهتری داشت (هر کدام لنگرِ ریپلایِ
     * خودش می‌شد) ولی هر ارسال یک `Http::timeout(12)` **همزمان** داخلِ همان
     * درخواستِ وب‌هوک است. ۸ تیکت یعنی تا ۹۶ ثانیه؛ بله مهلتش تمام می‌شود،
     * آپدیت را دوباره می‌فرستد، و آن ترافیکِ تکراری در همان سطلِ throttleای
     * می‌نشیند که `pre_checkout_query`ِ **پرداختِ مشتری** هم در آن است. یک
     * ۴۲۹ آن‌جا یعنی پرداختِ ناتمام. پس: یک پیام، و لنگرِ ریپلای از «تیکت
     * <شماره>» می‌آید که خودش یک ارسال است.
     */
    public function queue(): string
    {
        $rows = Ticket::query()->with('customer')->queue()->limit(self::QUEUE_LIMIT + 1)->get();

        if ($rows->isEmpty()) {
            return '✅ صفِ پشتیبانی خالی است.';
        }

        $more  = $rows->count() > self::QUEUE_LIMIT;
        $rows  = $rows->take(self::QUEUE_LIMIT);
        $lines = ['📋 تیکت‌های منتظرِ پاسخ — '.fa_num((string) $rows->count()).' تیکتِ منتظر', ''];

        foreach ($rows as $t) {
            $lines[] = $this->prio($t->priority).' '.$t->number.' — '.$this->clip((string) $t->subject, 60);
            $lines[] = '   '.($t->customer?->displayName() ?: 'بی‌مشتری')
                .' · '.$this->waited($t).' · '.$this->dept($t->department);
            $lines[] = '   تیکت '.$t->number;
            $lines[] = '';
        }

        if ($more) {
            $lines[] = 'بیش از '.fa_num((string) self::QUEUE_LIMIT).' تیکت در صف است؛ فقط قدیمی‌ترین‌ها آمد.';
        }

        return rtrim(implode("\n", $lines));
    }

    /** پروندهٔ یک تیکت — این پیام لنگرِ ریپلای می‌شود */
    public function ticket(Ticket $ticket): string
    {
        $c = $ticket->customer;

        $lines = [
            '🎫 '.$ticket->number.' — '.$this->clip((string) $ticket->subject, 90),
            $this->statusLabel($ticket).' · '.$this->prio($ticket->priority).' '.$this->prioLabel($ticket->priority)
                .' · '.$this->dept($ticket->department),
            $c ? ('👤 '.$c->displayName().' · '.$c->code) : '👤 بی‌مشتری',
            $this->waited($ticket),
            '',
        ];

        $messages = $ticket->visibleMessages()->orderByDesc('id')->limit(self::CARD_MESSAGES)->get()->reverse();

        foreach ($messages as $m) {
            $who = $m->author_role === 'staff' ? '🟢 پشتیبانی' : '🔵 مشتری';
            $lines[] = $who.' · '.$this->ago($m->created_at);
            $lines[] = $this->clip((string) $m->body, 700);
            $lines[] = '';
        }

        // فقط شمارش، هرگز خودِ متن — ترنسکریپتِ چت قابلِ فوروارد است
        $internal = $ticket->messages()->where('is_internal', true)->count();

        if ($internal > 0) {
            $lines[] = '🔒 '.fa_num((string) $internal).' یادداشتِ داخلی (در ربات نشان داده نمی‌شود)';
        }

        $lines[] = '';
        $lines[] = 'برای پاسخ، روی همین پیام ریپلای بزنید.';
        $lines[] = url('/admin/tickets/'.$ticket->id);

        return implode("\n", $lines);
    }

    public function health(): string
    {
        try {
            $checks = app(SystemHealth::class)->checks();
        } catch (\Throwable) {
            return '⚠️ وضعیتِ سلامت خوانده نشد.';
        }

        $worst = SystemHealth::worst($checks);
        $icon  = ['ok' => '✅', 'warn' => '🟡', 'fail' => '🔴'][$worst] ?? '❔';

        $lines = [$icon.' سلامتِ سامانه', ''];

        foreach ($checks as $c) {
            $mark = ($c['level'] ?? 'ok') === 'ok' ? '✅' : (($c['level'] ?? '') === 'warn' ? '🟡' : '🔴');
            $lines[] = $mark.' '.($c['title'] ?? '').' — '.$this->clip((string) ($c['detail'] ?? ''), 140);
        }

        return implode("\n", $lines);
    }

    /** ⚠️ هرگز chat_id یا هیچ رازی چاپ نمی‌کند */
    public function who(AdminBaleGate $gate): string
    {
        $user = $gate->boundUser();
        $bind = $gate->binding();

        if ($user === null || $bind === null) {
            return 'این ربات به هیچ حسابِ مدیری وصل نیست.';
        }

        $pending = $gate->pendingHuman();

        return implode("\n", array_filter([
            '🔐 کنسولِ مدیر',
            'حساب: '.$user->name,
            'وضعیت: '.($gate->enabled() ? 'روشن' : 'خاموش'),
            isset($bind['at']) ? ('اتصال: '.$this->ago(\Illuminate\Support\Carbon::parse($bind['at']))) : null,
            $pending ? ('⏳ در انتظارِ تأیید: '.$pending) : null,
        ]));
    }

    // ───────────────────────── متنِ تأیید ─────────────────────────

    /**
     * پیامی که پیش از هر نوشتنِ **دیده‌شدنی توسط مشتری** فرستاده می‌شود.
     *
     * ⚠️ هزینه صریح نوشته می‌شود و شماره و نامِ مشتری و خودِ متن تکرار می‌شوند:
     * یک رقمِ اشتباه در شماره باید **پیش از** رفتنِ پیام دیده شود، نه بعدش.
     */
    public function confirmPrompt(Ticket $ticket, string $body, bool $close, string $code): string
    {
        $c = $ticket->customer;

        return implode("\n", array_filter([
            ($close ? '✉️ پاسخ و بستنِ ' : '✉️ پاسخ به ').$ticket->number
                .($c ? ' — '.$c->displayName().' · '.$c->code : ''),
            '',
            '«'.$this->clip($body, 500).'»',
            '',
            '⚠️ برای مشتری پیامک و بله و ایمیل می‌رود و برگشت‌پذیر نیست.',
            $close ? 'وضعیتِ تیکت «بسته» می‌شود و نظرسنجی هم می‌رود.'
                   : 'وضعیتِ تیکت «پاسخ‌داده‌شده» می‌شود.',
            '',
            'تأیید: تأیید '.$code.'   (۳ دقیقه اعتبار)',
        ]));
    }

    public function closeOnlyPrompt(Ticket $ticket, string $code): string
    {
        $c = $ticket->customer;

        return implode("\n", [
            '🔒 بستنِ '.$ticket->number.($c ? ' — '.$c->displayName() : '').' بدونِ پاسخ',
            '',
            '⚠️ اعلانِ «بسته شد» و نظرسنجی برای مشتری می‌رود و برگشت‌پذیر نیست.',
            '',
            'تأیید: تأیید '.$code.'   (۳ دقیقه اعتبار)',
        ]);
    }

    // ───────────────────────────── کمکی ─────────────────────────────

    private function statusLabel(Ticket $t): string
    {
        return match ($t->status) {
            'open'     => '🟠 منتظرِ پاسخِ ما',
            'answered' => '🟢 پاسخ داده شده',
            'closed'   => '⚪️ بسته',
            default    => (string) $t->status,
        };
    }

    private function prio(?string $p): string
    {
        return ['low' => '🔽', 'normal' => '▫️', 'high' => '🔺', 'urgent' => '🚨'][$p] ?? '▫️';
    }

    private function prioLabel(?string $p): string
    {
        return ['low' => 'کم', 'normal' => 'معمولی', 'high' => 'زیاد', 'urgent' => 'فوری'][$p] ?? (string) $p;
    }

    private function dept(?string $d): string
    {
        return ['technical' => 'فنی', 'billing' => 'مالی', 'sales' => 'فروش'][$d] ?? (string) $d;
    }

    private function waited(Ticket $t): string
    {
        $at = $t->last_reply_at ?? $t->created_at;

        return $at ? (fa_num($this->span($at)).' منتظر') : '—';
    }

    private function ago($at): string
    {
        return $at ? (fa_num($this->span($at)).' پیش') : '—';
    }

    /** «۲ ساعت» / «۳ روز» — نسبی، چون ساعتِ اپ UTC است و کارفرما تهران */
    private function span($at): string
    {
        $mins = (int) round(abs(\Illuminate\Support\Carbon::parse($at)->diffInMinutes(now())));

        return match (true) {
            $mins < 1    => 'همین الان',
            $mins < 60   => $mins.' دقیقه',
            $mins < 1440 => intdiv($mins, 60).' ساعت',
            default      => intdiv($mins, 1440).' روز',
        };
    }

    private function clip(string $s, int $max): string
    {
        $s = trim(preg_replace('/[ \t]+/u', ' ', $s) ?? $s);

        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1).'…' : $s;
    }
}

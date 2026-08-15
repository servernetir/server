<?php

namespace App\Services\Bale\Admin;

use App\Models\ActivityLog;
use App\Models\Ticket;
use App\Services\Bale\BaleSender;
use App\Services\Ticket\TicketReplyService;
use App\Support\ErrorTracker;

/**
 * کنسولِ مدیر در بله — مسیریابیِ آپدیت به فرمان.
 *
 * ═══ جایگاهش در وب‌هوک، و چرا دقیقاً همان‌جا ═══
 *
 * این شاخه در `BaleWebhookController` **بعد از** هر چهار شاخهٔ مشتری می‌نشیند:
 *
 *   ۱ pre_checkout_query   ← مهلتِ ۱۰ ثانیه‌ای پرداخت. هیچ کارِ تازه‌ای بالای این نمی‌رود.
 *   ۲ successful_payment   ← کارفرما ممکن است با کیفِ بلهٔ **خودش** پول بدهد؛
 *                             آن آپدیت `from.id`ِ برابرِ چتِ متصل دارد و بدونِ این
 *                             ترتیب می‌توانست «فرمان» خوانده شود.
 *   ۳ chat_id خالی
 *   ۴ اشتراکِ شماره        ← زنجیرهٔ «ورود با بله»ی مشتری
 *   ─────────────────────────
 *   ۵ همین کلاس
 *   ۶ دکمهٔ اشتراکِ شماره (پاسخِ پیش‌فرض به هر پیامِ ناشناخته)
 *
 * پس هیچ جریانِ مشتری‌ای نمی‌تواند به این‌جا برسد، و اگر `matches()` فالس بدهد،
 * کنترل دقیقاً مثلِ امروز به شاخهٔ ۶ می‌رسد.
 *
 * ═══ قرارداد ═══
 *
 * • `matches()` و `handle()` **هرگز throw نمی‌کنند**. یک استثنا این‌جا یعنی
 *   وب‌هوک ۵۰۰ می‌دهد، بله دوباره می‌فرستد، و آپدیتِ تکراری در همان سطلِ
 *   throttle می‌نشیند که پرداختِ مشتری هم در آن است. بدتر: `/start`ِ مشتریِ
 *   تازه بی‌پاسخ می‌مانَد و او هرگز به بله وصل نمی‌شود.
 * • پاسخ **همیشه** به چتِ متصل می‌رود، نه به فرستندهٔ آپدیت. `replyToOwner()`
 *   عمداً آرگومانِ مقصد ندارد تا کسی روزی اشتباهی `$chatId` را پاس ندهد. اثرش:
 *   جعلِ خاموش به جعلِ **پرصدا** تبدیل می‌شود — کدِ تأیید روی گوشیِ خودِ
 *   کارفرما ظاهر می‌شود، برای کاری که او نکرده.
 */
class AdminBaleRouter
{
    /**
     * پیشوندِ `callback_data`ِ ما.
     *
     * ⚠️ نسخه‌دار است: اگر روزی معنیِ دکمه‌ها عوض شود، دکمه‌های **قدیمیِ** توی
     * تاریخچهٔ چت هنوز کلیک‌شدنی‌اند. بی‌نسخه، یک کلیکِ روی پیامِ سه‌ماه‌پیش
     * کارِ اشتباهی را اجرا می‌کرد.
     */
    private const CB_PREFIX = 'v1:';

    /** بیشترین پیامِ خروجی به‌ازای هر آپدیتِ ورودی */
    /**
     * ⚠️ ۳ و نه بیشتر: هر ارسال یک تماسِ **همزمانِ** ۱۲ ثانیه‌ای داخلِ وب‌هوک
     * است. بی‌سقف، یک فرمان می‌تواند مهلتِ بله را رد کند و آپدیتِ تکراری در
     * همان سطلِ throttleای بنشیند که پرداختِ مشتری هم در آن است.
     */
    private const MAX_SENDS = 3;

    private int $sends = 0;

    public function __construct(
        private AdminBaleGate $gate,
        private AdminBaleCommands $ui,
        private AdminBaleAnchor $anchor,
        private BaleSender $sender,
    ) {}

    // ───────────────────────── آیا مالِ ماست؟ ─────────────────────────

    /**
     * محضِ predicate — هیچ چیزی نمی‌نویسد و هیچ پیامی نمی‌فرستد.
     *
     * کلِ بدنه در try/catch است و در شک **false** می‌دهد: در بدترین حالت
     * کارفرما دکمهٔ اشتراکِ شماره می‌بیند، که بی‌ضرر و خودتوضیح است.
     */
    public function matches(array $update): bool
    {
        try {
            $m = $update['message'] ?? null;

            // ── شکل: ارزان‌ترین بررسی‌ها اول، تا آپدیتِ پرداخت چند lookup بیشتر نخورد ──
            if (! is_array($m) || ! isset($m['text']) || ! is_string($m['text'])) {
                return false;
            }

            // کمربند و بند: این‌ها بالاتر برگشته‌اند، ولی ترتیب روزی عوض می‌شود
            if (isset($update['pre_checkout_query'], )) {
                return false;
            }

            if (isset($m['successful_payment']) || isset($m['contact']) || isset($m['invoice'])) {
                return false;
            }

            /*
            | 🔴 پیامِ **فوروارد‌شده** هرگز فرمان نیست.
            |
            | در فوروارد، `from` همان کسی است که فوروارد کرده (کارفرما)، ولی متن
            | را شخصِ دیگری نوشته. کارِ کاملاً طبیعیِ «این را ببین که مشتری چه
            | نوشته» می‌توانست متنِ ساختگیِ یک نفرِ دیگر را به‌عنوانِ فرمانِ
            | کارفرما اجرا کند.
            */
            foreach (['forward_date', 'forward_from', 'forward_from_chat', 'forward_origin', 'forward_sender_name'] as $k) {
                if (isset($m[$k])) {
                    return false;
                }
            }

            $text = trim((string) $m['text']);

            if ($text === '' || str_starts_with($text, '/start')) {
                return false;                       // /start صددرصد مالِ مشتری می‌مانَد
            }

            // ── چتِ خصوصی و فرستندهٔ انسان ──
            $from = (string) ($m['from']['id'] ?? '');
            $chat = (string) ($m['chat']['id'] ?? '');

            if ($from === '' || $chat === '' || ! hash_equals($chat, $from)) {
                return false;                       // گروه/کانال — کنسول فقط خصوصی است
            }

            if (($m['from']['is_bot'] ?? false) === true) {
                return false;
            }

            if (($m['chat']['type'] ?? 'private') !== 'private') {
                return false;
            }

            // ── هویت: فقط از `settings`. `bale_contacts` هرگز پرسیده نمی‌شود ──
            //
            // 🔴 چرا: ردیفِ `bale_contacts` را خودِ همین وب‌هوکِ بی‌احراز می‌نویسد
            // (`link()` یک `updateOrCreate` روی `mobile` است، بی‌هیچ بررسیِ
            // مالکیت). یعنی یک آپدیتِ جعلیِ `contact` می‌توانست شمارهٔ پشتیبانی
            // را به چتِ مهاجم ببندد. برای **تحویلِ** پیام مشکلی نیست؛ به‌عنوانِ
            // منبعِ **هویت** یک حفرهٔ ترفیعِ دسترسی است.
            if ($this->gate->binding() === null) {
                /*
                | 🔴 پنجرهٔ اتصال عمداً به `enabled()` بند **نیست**.
                |
                | نسخهٔ اول کلیدِ خاموش/روشن را بالاتر می‌سنجید و بن‌بست می‌ساخت:
                | کلید پیش‌فرض خاموش است، و پنل روشن‌کردن را به «اول متصل شو»
                | مشروط می‌کرد. پس `/pair` هرگز به این‌جا نمی‌رسید و به دکمهٔ
                | «اشتراکِ شماره» می‌افتاد — همان چیزی که کارفرما گزارش داد.
                |
                | ⚠️ دروازه‌اش حالا **تنگ‌تر** از کلید است، نه گشادتر: رکوردِ
                | «اتصالِ در انتظار» فقط وقتی وجود دارد که مدیر همین چند دقیقه
                | پیش در پنلِ دومرحله‌ای دکمه را زده باشد.
                */
                if (! $this->gate->pairingPending()) {
                    return false;
                }

                return str_starts_with($text, '/pair ') || str_starts_with($text, 'اتصال ');
            }

            // ── کلیدِ خاموش/روشن (فقط برای کنسولِ **متصل**) ──
            if (! $this->gate->enabled()) {
                return false;
            }

            if (! $this->gate->isBoundChat($from)) {
                return false;
            }

            // ── نقش، هر بار دوباره ──
            return $this->gate->boundUser() !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    // ──────────────────── دکمه‌های شیشه‌ای (callback) ────────────────────

    /**
     * آیا این آپدیت، کلیکِ دکمهٔ کنسولِ مدیر است؟
     *
     * ⚠️ `callback_query` **هیچ `message` در سطحِ بالا ندارد**، پس شاخه‌اش باید
     * بالای جایی بنشیند که کنترلر روی `chat_id`ِ خالی برمی‌گردد — وگرنه کدِ
     * مرده است. جایش در وب‌هوک بلافاصله بعد از `pre_checkout_query` است، و
     * چون بله در هر آپدیت **حداکثر یک** فیلدِ اختیاری می‌گذارد، هیچ‌وقت با
     * پرداخت تصادم نمی‌کند.
     */
    public function matchesCallback(array $update): bool
    {
        try {
            $cb = $update['callback_query'] ?? null;

            if (! is_array($cb) || ! isset($cb['id'], $cb['data'])) {
                return false;
            }

            if (! str_starts_with((string) $cb['data'], self::CB_PREFIX)) {
                return false;      // دکمهٔ ما نیست
            }

            $from = (string) ($cb['from']['id'] ?? '');

            if ($from === '' || ($cb['from']['is_bot'] ?? false) === true) {
                return false;
            }

            if (! $this->gate->enabled() || ! $this->gate->isBoundChat($from)) {
                return false;
            }

            return $this->gate->boundUser() !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /** اجرای کلیک — هرگز throw نمی‌کند */
    public function handleCallback(array $update): void
    {
        $cb = (array) ($update['callback_query'] ?? []);
        $id = (string) ($cb['id'] ?? '');

        try {
            $data = substr((string) ($cb['data'] ?? ''), strlen(self::CB_PREFIX));
            [$verb, $arg] = array_pad(explode(':', $data, 2), 2, '');

            /*
            | 🔴 اول به خودِ کلیک جواب بده، بعد کارِ اصلی.
            |
            | بی‌این، دکمه در کلاینتِ کاربر تا ابد «در حالِ بارگذاری» می‌مانَد و
            | کارفرما فکر می‌کند ربات هنگ کرده — حتی اگر کار درست انجام شده باشد.
            */
            $this->sender->answerCallback($id, match ($verb) {
                'ping' => '✅ رسید',
                'td'   => '✍️ در حالِ نوشتن…',      // مدل چند ثانیه طول می‌کشد
                'ma'   => '📥 بایگانی شد',
                default => '',
            });

            match ($verb) {
                // آزمونِ زنده — نگه داشته شد چون تنها راهِ سنجیدنِ خودِ مسیر است
                'ping' => $this->replyToOwner(
                    "✅ دکمه‌های شیشه‌ای کار می‌کنند.\n"
                    ."کلیکِ شما به سرور رسید و پاسخش از همین‌جا رفت."
                ),
                'q'  => $this->showQueue(),
                't'  => $this->showTicket($arg),
                'tc' => $this->armCloseById($arg),
                'td' => $this->draft($arg),
                'ts' => $this->armStoredDraft($arg),
                'm'  => $this->showMailbox(),
                'mv' => $this->showMail($arg),
                'ma' => $this->archiveMail($arg),
                default => $this->replyToOwner('این دکمه دیگر معتبر نیست.'),
            };
        } catch (\Throwable $e) {
            ErrorTracker::note('bale-admin', $e, ['step' => 'callback']);

            if ($id !== '') {
                $this->sender->answerCallback($id, '⚠️ اجرا نشد');
            }
        }
    }

    // ───────────────────────────── اجرا ─────────────────────────────

    /** هرگز throw نمی‌کند و هرگز چیزی برنمی‌گرداند */
    public function handle(array $update): void
    {
        try {
            $m    = (array) ($update['message'] ?? []);
            $text = trim((string) ($m['text'] ?? ''));
            $from = (string) ($m['from']['id'] ?? '');

            // اتصال، تنها کاری که چتِ نامتصل هم می‌تواند بزند
            if ($this->gate->binding() === null) {
                $this->pair($text, $from);

                return;
            }

            // ارسالِ دوبارهٔ بله نباید کار را دو بار انجام دهد.
            // ⚠️ تنها محافظ نیست: کدِ تأیید هم یک‌بارمصرف است.
            if ($this->gate->seenUpdate(isset($update['update_id']) ? (int) $update['update_id'] : null)) {
                return;
            }

            $this->dispatch($text, $m);
        } catch (\Throwable $e) {
            ErrorTracker::note('bale-admin', $e, ['step' => 'handle']);

            $this->replyToOwner('⚠️ اجرای این فرمان به مشکل خورد. در /admin/errors ثبت شد.');
        }
    }

    private function pair(string $text, string $from): void
    {
        $code = trim((string) preg_replace('/^\S+\s*/u', '', $text));
        $code = $this->anchor->asciiDigits($code);

        $res = $this->gate->completePairing($code, $from);

        /*
        | ⚠️ این تنها جایی است که پاسخ به **فرستنده** می‌رود و نه به چتِ متصل —
        | چون هنوز چتِ متصلی وجود ندارد. بی‌خطر است: مهاجم فقط می‌فهمد کدش غلط
        | بود، و همان تلاش در سقفِ ۱۰تاییِ `OtpService` شمرده می‌شود و به ایمیلِ
        | کارفرما هم خبر می‌رود.
        */
        $this->send($from, $res['ok']
            ? "✅ اتصال برقرار شد.\nبرای شروع «راهنما» را بفرستید."
            : '⛔️ '.$res['message']);
    }

    /** متن → فرمان */
    private function dispatch(string $text, array $m): void
    {
        [$verb, $rest] = $this->split($text);

        // تأیید — پیش از هر چیزِ دیگر، چون کارِ مسلح منتظرِ آن است
        if (in_array($verb, ['/ok', 'ok', 'تایید', 'تأیید'], true)) {
            $this->confirm($rest);

            return;
        }

        if (in_array($verb, ['/panel', '/help', '/start', 'راهنما', 'پنل', 'منو'], true)) {
            $this->replyToOwner($this->ui->panel());

            return;
        }

        if (in_array($verb, ['/q', '/queue', 'کارها', 'صف', 'تیکتها', 'تیکت‌ها'], true)) {
            $this->showQueue();

            return;
        }

        if (in_array($verb, ['/mail', 'ایمیل', 'ایمیل‌ها', 'ایمیلها'], true)) {
            $this->showMailbox();

            return;
        }

        if (in_array($verb, ['/health', 'سلامت', 'وضعیت‌سامانه'], true)) {
            $this->replyToOwner($this->ui->health());

            return;
        }

        // آزمونِ زندهٔ دکمه‌ها — عمداً بی‌عارضه: هیچ‌چیز نمی‌نویسد
        if (in_array($verb, ['/test', 'دکمه', 'آزمون'], true)) {
            $this->sendButtons(
                "🧪 آزمونِ دکمه‌های شیشه‌ای

یکی از دکمه‌های زیر را بزنید. "
                ."اگر پاسخ گرفتید، یعنی بله کلیک را به ما می‌رساند و می‌توانیم "
                ."کلِ کنسول را دکمه‌ای کنیم.",
                [[
                    ['text' => '✅ دکمهٔ یک', 'data' => self::CB_PREFIX.'ping:1'],
                    ['text' => '🔵 دکمهٔ دو', 'data' => self::CB_PREFIX.'ping:2'],
                ]],
            );

            return;
        }

        if (in_array($verb, ['/who', 'وضعیت', 'کیستم'], true)) {
            $this->replyToOwner($this->ui->who($this->gate));

            return;
        }

        if (in_array($verb, ['/t', '/ticket', 'تیکت'], true)) {
            $t = $this->anchor->resolve($rest);

            if ($t === null) {
                $this->replyToOwner('تیکتی با این شماره پیدا نشد.');
            } else {
                $this->showTicket((string) $t->id);
            }

            return;
        }

        // ── از این‌جا به بعد نوشتن است؛ اول باید بدانیم روی کدام تیکت ──
        $anchored = $this->anchor->ticketFrom($m);

        if (in_array($verb, ['/note', 'یادداشت'], true)) {
            $this->note($anchored, $rest);

            return;
        }

        if (in_array($verb, ['/close', 'بستن'], true)) {
            $this->armClose($anchored, $rest);

            return;
        }

        if (in_array($verb, ['/r', '/reply', 'پاسخ'], true)) {
            $this->armReply($anchored, $rest, explicitRef: $anchored === null);

            return;
        }

        /*
        | متنِ آزاد **فقط** وقتی پاسخ است که روی یک اعلانِ تیکت ریپلای شده باشد.
        | بی‌ریپلای، به‌عمد «فرمان را نمی‌شناسم» می‌گیرد و نه «پاسخ به آخرین
        | تیکت»: حدسِ اشتباه در این‌جا یعنی پیامِ برگشت‌ناپذیر به مشتریِ اشتباه.
        */
        if ($anchored !== null) {
            $this->armReply($anchored, $text, explicitRef: false);

            return;
        }

        $this->replyToOwner($this->ui->unknown());
    }

    // ───────────────────────────── نوشتن ─────────────────────────────

    /** یادداشتِ داخلی — بی‌تأیید، چون نامرئی و قابلِ اصلاح است */
    private function note(?Ticket $ticket, string $body): void
    {
        $body = trim($body);

        if ($ticket === null) {
            $this->replyToOwner('روی اعلانِ همان تیکت ریپلای بزنید، یا «یادداشت» را با شماره بفرستید.');

            return;
        }

        if ($body === '') {
            $this->replyToOwner('متنِ یادداشت خالی است.');

            return;
        }

        app(TicketReplyService::class)->post(
            $ticket, null, 'پشتیبانی', $body, internal: true,
        );

        $this->audit($ticket, 'یادداشتِ داخلی');
        $this->replyToOwner('🔒 یادداشتِ داخلی روی '.$ticket->number.' ثبت شد. مشتری آن را نمی‌بیند.');
    }

    private function armReply(?Ticket $ticket, string $rest, bool $explicitRef): void
    {
        $body = $rest;

        if ($explicitRef) {
            [$ref, $body] = $this->split($rest);
            $ticket = $this->anchor->resolve($ref);
        }

        $body = trim($body);

        if ($ticket === null) {
            $this->replyToOwner('کدام تیکت؟ روی اعلانِ آن ریپلای بزنید یا «پاسخ <شماره> <متن>» بفرستید.');

            return;
        }

        if ($body === '') {
            $this->replyToOwner('متنِ پاسخ خالی است.');

            return;
        }

        $code = $this->gate->armConfirm('reply', ['ticket' => $ticket->id, 'body' => $body, 'close' => false],
            'پاسخ به '.$ticket->number);

        $this->replyToOwner($this->ui->confirmPrompt($ticket, $body, close: false, code: $code));
    }

    private function armClose(?Ticket $ticket, string $rest): void
    {
        $body = trim($rest);

        // «بستن <شماره> [متن]» وقتی ریپلای نیست
        if ($ticket === null) {
            [$ref, $body] = $this->split($rest);
            $ticket = $this->anchor->resolve($ref);
            $body   = trim($body);
        }

        if ($ticket === null) {
            $this->replyToOwner('کدام تیکت؟ روی اعلانِ آن ریپلای بزنید یا «بستن <شماره>» بفرستید.');

            return;
        }

        if ($ticket->isClosed()) {
            $this->replyToOwner('تیکتِ '.$ticket->number.' از قبل بسته است.');

            return;
        }

        if ($body === '') {
            $code = $this->gate->armConfirm('close', ['ticket' => $ticket->id], 'بستنِ '.$ticket->number);
            $this->replyToOwner($this->ui->closeOnlyPrompt($ticket, $code));

            return;
        }

        $code = $this->gate->armConfirm('reply', ['ticket' => $ticket->id, 'body' => $body, 'close' => true],
            'پاسخ و بستنِ '.$ticket->number);

        $this->replyToOwner($this->ui->confirmPrompt($ticket, $body, close: true, code: $code));
    }

    /** مصرفِ کدِ تأیید و اجرای کارِ مسلح */
    private function confirm(string $rest): void
    {
        $code = $this->anchor->asciiDigits(trim($rest));
        $job  = $this->gate->takeConfirm($code);

        if ($job === null) {
            $this->replyToOwner('کدِ تأیید درست نیست یا منقضی شده. فرمان را دوباره بفرستید.');

            return;
        }

        $ticket = Ticket::find((int) ($job['args']['ticket'] ?? 0));

        if ($ticket === null) {
            $this->replyToOwner('تیکتِ این کار دیگر موجود نیست.');

            return;
        }

        $service = app(TicketReplyService::class);

        if ($job['verb'] === 'close') {
            $service->closeOnly($ticket, notify: true);
            $this->audit($ticket, 'بستنِ تیکت');
            $this->replyToOwner('🔒 '.$ticket->number.' بسته شد و به مشتری خبر رفت.');

            return;
        }

        $close = (bool) ($job['args']['close'] ?? false);

        $service->post(
            $ticket, null, 'پشتیبانی', (string) ($job['args']['body'] ?? ''),
            internal: false, close: $close,
        );

        $this->audit($ticket, $close ? 'پاسخ و بستنِ تیکت' : 'پاسخ به تیکت');

        $this->replyToOwner($close
            ? '✅ پاسخ رفت و '.$ticket->number.' بسته شد.'
            : '✅ پاسخ به '.$ticket->number.' برای مشتری رفت.');
    }

    // ───────────────────────────── کمکی ─────────────────────────────

    // ─────────────────── کارهای دکمه‌ای ───────────────────

    private function showQueue(): void
    {
        $rows = Ticket::query()->with('customer')->queue()
            ->limit(AdminBaleCommands::QUEUE_LIMIT)->get();

        if ($rows->isEmpty()) {
            $this->replyToOwner('✅ صفِ پشتیبانی خالی است.');

            return;
        }

        /*
        | ⚠️ یک پیام با چند دکمه، نه یک پیام به‌ازای هر تیکت.
        |
        | هر ارسال یک `Http::timeout(12)`ِ **همزمان** داخلِ درخواستِ وب‌هوک است.
        | هشت تیکت یعنی تا ۹۶ ثانیه؛ بله مهلتش تمام می‌شود، آپدیت را دوباره
        | می‌فرستد، و آن ترافیک در همان سطلِ throttleای می‌نشیند که
        | `pre_checkout_query`ِ پرداختِ مشتری هم در آن است.
        */
        $buttons = $rows->map(fn ($t) => [[
            'text' => '🎫 '.$t->number.' — '.mb_substr((string) $t->subject, 0, 24),
            'data' => self::CB_PREFIX.'t:'.$t->id,
        ]])->all();

        $this->sendButtons($this->ui->queue(), $buttons);
    }

    private function showTicket(string $arg): void
    {
        $t = Ticket::find((int) $arg);

        if ($t === null) {
            $this->replyToOwner('تیکت پیدا نشد.');

            return;
        }

        $rows = [[
            ['text' => '✍️ پیش‌نویسِ هوشمند', 'data' => self::CB_PREFIX.'td:'.$t->id],
        ]];

        if (! $t->isClosed()) {
            $rows[] = [['text' => '🔒 بستنِ تیکت', 'data' => self::CB_PREFIX.'tc:'.$t->id]];
        }

        $this->sendButtons($this->ui->ticket($t), $rows);
    }

    private function armCloseById(string $arg): void
    {
        $this->armClose(Ticket::find((int) $arg), '');
    }

    /**
     * پیش‌نویسِ هوشمند.
     *
     * 🔴 خروجی **ارسال نمی‌شود**؛ ذخیره می‌شود و با دکمه نشان داده می‌شود.
     * ارسالش از همان گیتِ کدِ تأیید رد می‌شود که پاسخِ دستی. مدل قیمت و مهلت و
     * سیاستِ بازگشتِ وجه را از خودش می‌سازد و آن یک تعهدِ واقعی است.
     */
    private function draft(string $arg): void
    {
        [$id, $tone] = array_pad(explode(':', $arg, 2), 2, 'n');
        $ticket = Ticket::find((int) $id);

        if ($ticket === null) {
            $this->replyToOwner('تیکت پیدا نشد.');

            return;
        }

        $text = app(\App\Services\Ticket\TicketDraftWriter::class)->draft($ticket, $tone ?: 'n');

        if ($text === null) {
            $this->replyToOwner('پیش‌نویس ساخته نشد (مدل جواب نداد). دوباره بزنید یا خودتان بنویسید.');

            return;
        }

        $this->gate->putDraft($ticket->id, $text);

        $labels = ['n' => '🙂 معمولی', 's' => '✂️ کوتاه', 'f' => '🎩 رسمی', 'a' => '🙏 با عذرخواهی'];

        $others = array_values(array_filter(
            array_keys(\App\Services\Ticket\TicketDraftWriter::TONES),
            fn ($k) => $k !== ($tone ?: 'n'),
        ));

        $this->sendButtons(
            '✍️ پیش‌نویس برای '.$ticket->number."\n\n".$text
            ."\n\n⚠️ هنوز چیزی نرفته. «ارسال» کدِ تأیید می‌خواهد.",
            [
                [['text' => '📤 ارسال به مشتری', 'data' => self::CB_PREFIX.'ts:'.$ticket->id]],
                array_map(fn ($k) => [
                    'text' => $labels[$k] ?? $k,
                    'data' => self::CB_PREFIX.'td:'.$ticket->id.':'.$k,
                ], array_slice($others, 0, 3)),
            ],
        );
    }

    /** ارسالِ پیش‌نویسِ ذخیره‌شده — از همان گیتِ تأییدِ پاسخِ دستی رد می‌شود */
    private function armStoredDraft(string $arg): void
    {
        $ticket = Ticket::find((int) $arg);
        $body   = $ticket ? $this->gate->takeDraft($ticket->id) : null;

        if ($ticket === null || $body === null) {
            $this->replyToOwner('پیش‌نویس پیدا نشد یا منقضی شده. دوباره بسازید.');

            return;
        }

        $this->armReply($ticket, $body, explicitRef: false);
    }

    // ─────────────────── صندوقِ ایمیل ───────────────────

    private function showMailbox(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('mailbox_messages')) {
            $this->replyToOwner('صندوقِ ایمیل روی این نصب فعال نیست.');

            return;
        }

        $rows = \App\Models\MailboxMessage::open()->where('needs_reply', true)
            ->orderByDesc('importance')->orderByDesc('id')->limit(6)->get();

        if ($rows->isEmpty()) {
            $this->replyToOwner('✅ ایمیلی که منتظرِ پاسخ باشد نیست.');

            return;
        }

        $lines = ['📬 ایمیل‌های منتظرِ پاسخ', ''];

        foreach ($rows as $m) {
            $lines[] = '• '.mb_substr((string) $m->subject, 0, 70);
            $lines[] = '   '.mb_substr((string) ($m->summary ?: $m->from_email), 0, 90);
            $lines[] = '';
        }

        $this->sendButtons(rtrim(implode("\n", $lines)),
            $rows->map(fn ($m) => [[
                'text' => '📧 '.mb_substr((string) $m->subject, 0, 30),
                'data' => self::CB_PREFIX.'mv:'.$m->id,
            ]])->all());
    }

    private function showMail(string $arg): void
    {
        $m = \Illuminate\Support\Facades\Schema::hasTable('mailbox_messages')
            ? \App\Models\MailboxMessage::find((int) $arg) : null;

        if ($m === null) {
            $this->replyToOwner('ایمیل پیدا نشد.');

            return;
        }

        $text = implode("\n", array_filter([
            '📧 '.mb_substr((string) $m->subject, 0, 120),
            'از: '.mb_substr((string) $m->from_email, 0, 90),
            $m->summary ? ('خلاصه: '.$m->summary) : null,
            '',
            mb_substr(trim((string) ($m->body_text ?: '')), 0, 2200),
        ]));

        $this->sendButtons($text, [[
            ['text' => '📥 بایگانی', 'data' => self::CB_PREFIX.'ma:'.$m->id],
        ]]);
    }

    /** ⚠️ نامه **حذف نمی‌شود** — فقط از صفِ «منتظرِ پاسخ» بیرون می‌رود */
    private function archiveMail(string $arg): void
    {
        try {
            $m = \App\Models\MailboxMessage::find((int) $arg);

            if ($m === null) {
                return;
            }

            $m->forceFill(['needs_reply' => false])->save();

            $this->replyToOwner('📥 «'.mb_substr((string) $m->subject, 0, 60).'» از صفِ پاسخ برداشته شد.');
        } catch (\Throwable $e) {
            ErrorTracker::note('bale-admin', $e, ['step' => 'archiveMail']);
        }
    }

    /**
     * ردِ بازرسی.
     *
     * ⚠️ عبارتِ «از رباتِ بله» اجباری است: `$request` این‌جا درخواستِ وب‌هوک
     * است، پس IP و user-agentِ ثبت‌شده مالِ **بله** است نه کارفرما. بی‌این
     * جمله، ردِ بازرسی دروغ می‌گوید.
     */
    private function audit(Ticket $ticket, string $what): void
    {
        try {
            $name = $this->gate->boundUser()?->name ?? 'مدیر';

            ActivityLog::record(
                $ticket->customer_id,
                'ticket',
                'مدیر «'.$name.'» از رباتِ بله: '.$what.' — '.$ticket->number,
                request(),
                'staff',
            );
        } catch (\Throwable) {
            // ردِ بازرسی نباید خودِ کار را بشکند
        }
    }

    /**
     * پاسخ **همیشه** به چتِ متصل.
     *
     * عمداً آرگومانِ مقصد ندارد. اگر روزی کسی `$chatId`ِ آپدیت را پاس بدهد،
     * تمامِ استدلالِ امنیتیِ این کلاس فرو می‌ریزد: کدِ تأیید به دستِ همان کسی
     * می‌رسد که آپدیت را جعل کرده.
     */
    private function replyToOwner(string $text): void
    {
        $chat = $this->gate->binding()['chat_id'] ?? '';

        if ($chat === '') {
            return;
        }

        $this->send((string) $chat, $text);
    }

    /**
     * پیامِ دکمه‌دار، همیشه به چتِ متصل.
     *
     * ⚠️ مثلِ `replyToOwner()` آرگومانِ مقصد ندارد — همان استدلالِ امنیتی.
     *
     * @param  array<int,array<int,array{text:string,data:string}>>  $rows
     */
    private function sendButtons(string $text, array $rows): void
    {
        $chat = $this->gate->binding()['chat_id'] ?? '';

        if ($chat === '' || $this->sends >= self::MAX_SENDS) {
            return;
        }

        $this->sends++;

        if ($this->sender->sendButtons((string) $chat, mb_substr($text, 0, 3500), $rows) === null) {
            /*
            | 🔴 اگر بله دکمه را رد کند، **ساکت نمی‌مانیم**: کارفرما پیامی
            | نمی‌بیند و از «ربات خراب است» قابلِ تشخیص نیست. متنِ ساده را
            | جایگزین می‌فرستیم تا دستِ‌کم محتوا برسد.
            */
            ErrorTracker::noteOnce('bale-admin', 'بله دکمهٔ شیشه‌ای را نپذیرفت.', 900);
            $this->sender->send((string) $chat, mb_substr($text, 0, 3500));
        }
    }

    /**
     * ارسال با سقف.
     *
     * ⚠️ هر ارسال یک `Http::timeout(12)`ِ **همزمان** داخلِ درخواستِ وب‌هوک است.
     * بی‌سقف، یک فرمان می‌تواند وب‌هوک را آن‌قدر نگه دارد که بله مهلتش تمام
     * شود و آپدیت را دوباره بفرستد — و آن ترافیکِ تکراری در همان سطلِ throttle
     * می‌نشیند که `pre_checkout_query`ِ پرداختِ مشتری هم در آن است.
     */
    private function send(string $chatId, string $text): void
    {
        if ($this->sends >= self::MAX_SENDS) {
            return;
        }

        $this->sends++;

        // ⚠️ `BaleSender::send`، نه `BaleNotifier::notify`: آن یکی اول سفیر را
        // صدا می‌زند که به‌ازای هر پیام پول می‌گیرد و فقط برای مشتری است.
        if (! $this->sender->send($chatId, mb_substr($text, 0, 3500))) {
            ErrorTracker::noteOnce('bale-admin', 'پاسخِ کنسولِ مدیر تحویل نشد.', 900);
        }
    }

    /** «فعل بقیه» — با نیم‌فاصله و فاصلهٔ چندتایی هم کنار می‌آید */
    private function split(string $text): array
    {
        $text = trim($text);
        $pos  = preg_match('/\s/u', $text, $m, PREG_OFFSET_CAPTURE) === 1 ? $m[0][1] : false;

        if ($pos === false) {
            return [mb_strtolower($text), ''];
        }

        return [mb_strtolower(substr($text, 0, $pos)), ltrim(substr($text, $pos))];
    }
}

<?php

namespace App\Services\Bale\Admin;

use App\Http\Controllers\Admin\ServiceController;
use App\Models\ActivityLog;
use App\Models\BankTransferReceipt;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\MailboxMessage;
use App\Models\Product;
use App\Models\Service;
use App\Models\Ticket;
use App\Services\Bale\BaleSender;
use App\Services\Billing\InvoiceCanceller;
use App\Services\CloudPhone\OutgoingCallService;
use App\Services\Customer\CustomerBriefWriter;
use App\Services\Customer\QuickCustomerCreator;
use App\Services\Mail\MailReplyDraftWriter;
use App\Services\Otp\OtpService;
use App\Services\Provisioning\ProvisioningService;
use App\Services\Sales\PhoneSale;
use App\Services\Ticket\TicketDraftWriter;
use App\Services\Ticket\TicketReplyService;
use App\Support\ErrorTracker;
use App\Support\IranianPhone;
use Illuminate\Support\Facades\Schema;

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
    /*
    | ⚠️ `public` است چون فرستندگانِ اعلان هم دکمه می‌سازند.
    |
    | `TicketController` روی اعلانِ «پاسخِ مشتری» دکمهٔ شیشه‌ای می‌گذارد و
    | باید **همین** پیشوند را بزند. کپی‌کردنِ رشته آن‌جا یعنی روزی نسخه
    | بالا برود و آن دکمه‌ها بی‌صدا «معتبر نیست» بگیرند.
    */
    public const CB_PREFIX = 'v1:';

    /**
     * افعالی که یک بار مصرف می‌شوند — دکمه‌شان پس از کلیک برداشته می‌شود.
     *
     * ⚠️ فهرست عمداً **سفید** است نه سیاه: فعلِ تازه‌ای که کسی فردا اضافه کند،
     * پیش‌فرضش «دکمه می‌مانَد» است. اگر برعکس بود، یک فعلِ نوشتنیِ جامانده
     * بی‌صدا دوباره‌کلیک‌شدنی می‌مانْد.
     */
    private const CONSUMING = ['su', 'sr', 'sv', 'sp', 'ic', 'tc', 'ts', 'ma', 'tps', 'ray', 'sxy', 'sey', 'mes', 'cc', 'rm', 'dn'];

    /** بیشترین پیامِ خروجی به‌ازای هر آپدیتِ ورودی */
    /**
     * ⚠️ ۳ و نه بیشتر: هر ارسال یک تماسِ **همزمانِ** ۱۲ ثانیه‌ای داخلِ وب‌هوک
     * است. بی‌سقف، یک فرمان می‌تواند مهلتِ بله را رد کند و آپدیتِ تکراری در
     * همان سطلِ throttleای بنشیند که پرداختِ مشتری هم در آن است.
     */
    private const MAX_SENDS = 3;

    private int $sends = 0;

    /**
     * پیامی که کارفرما رویش کلیک کرد — برای **پاک‌کردنِ دکمه‌ها** پس از انجام.
     *
     * 🔴 خواستهٔ کارفرما: «وقتی روی دکمه‌ها کلیک کردم پاک بشه تا دچار اشتباه
     * نشیم.» و از محافظِ مهرِ تازگی هم بهتر است، چون **دیدنی** است: دکمه‌ای که
     * نیست، اشتباه کلیک نمی‌شود. مهر می‌مانَد به‌عنوانِ لایهٔ دوم برای وقتی که
     * ویرایشِ پیام شکست بخورد یا کارفرما اسکرول کند به کارتِ خیلی قدیمی که
     * بله دیگر اجازهٔ ویرایشش را نمی‌دهد (سقفِ ۴۸ ساعت).
     */
    private ?int $clickedMessageId = null;

    private string $clickedText = '';

    public function __construct(
        private AdminBaleGate $gate,
        private AdminBaleCommands $ui,
        private AdminBaleAnchor $anchor,
        private BaleSender $sender,
        private AdminBaleScreens $screens,
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
            if (isset($update['pre_checkout_query'])) {
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

            if ($text === '') {
                return false;
            }

            /*
            | ⚠️ `/start` دیگر بی‌قیدوشرط ردّ نمی‌شود.
            |
            | کارفرما: «وقتی شروع را می‌زنم دکمه‌ها بیایند، نخواهم بنویسم.» پس
            | برای **چتِ متصل** منوی مدیر می‌آید. برای هر چتِ دیگری — یعنی هر
            | مشتری — دقیقاً مثلِ قبل به دکمهٔ «اشتراکِ شماره» می‌رسد، چون شاخهٔ
            | هویت پایین‌تر ردّش می‌کند.
            |
            | ⚠️ و راهِ پیوندِ شمارهٔ خودِ کارفرما بسته نشد: فرمانِ «پیوند شماره»
            | همان کیبوردِ اشتراکِ شماره را برمی‌گرداند.
            */

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

        $this->clickedMessageId = isset($cb['message']['message_id'])
            ? (int) $cb['message']['message_id'] : null;
        $this->clickedText = (string) ($cb['message']['text'] ?? '');

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
                'td' => '✍️ در حالِ نوشتن…',      // مدل چند ثانیه طول می‌کشد
                'cs' => '🧠 در حالِ خواندنِ پرونده…',
                'me' => '✍️ در حالِ نوشتن…',
                'ma' => '📥 بایگانی شد',
                // تماس چند صدم ثانیه طول می‌کشد؛ بی‌این، دکمه «در حالِ بارگذاری» می‌مانَد
                'cc' => '📞 در حالِ برقراری…',
                'rm' => '✅ ثبت شد',
                'dn' => '📞 در حالِ برقراری…',
                default => '',
            });

            /*
            | 🔴 افعالی که **می‌نویسند**: پس از انجام، دکمه‌های همان کارت برداشته
            | می‌شوند تا دوباره کلیک نشوند.
            |
            | ⚠️ ویرایش **پیش از** اجرا انجام می‌شود، نه بعدش: اگر کار طول بکشد
            | یا خطا بدهد، دکمه‌ها همان لحظه باید رفته باشند — وگرنه کارفرما
            | دوباره می‌زند و کار دو بار انجام می‌شود.
            */
            if (in_array($verb, self::CONSUMING, true)) {
                $this->consumeButtons();
            }

            match ($verb) {
                // آزمونِ زنده — نگه داشته شد چون تنها راهِ سنجیدنِ خودِ مسیر است
                'ping' => $this->replyToOwner(
                    "✅ دکمه‌های شیشه‌ای کار می‌کنند.\n"
                    .'کلیکِ شما به سرور رسید و پاسخش از همین‌جا رفت.'
                ),
                'q' => $this->showQueue(),
                't' => $this->showTicket($arg),
                'tc' => $this->armCloseById($arg),
                'td' => $this->draft($arg),
                'tw' => $this->howToWrite($arg),
                'ts' => $this->armStoredDraft($arg),
                'h' => $this->replyToOwner($this->ui->health()),
                'x' => $this->menu(),
                'fx' => $this->cancelFlow(),
                '?' => $this->replyToOwner($this->ui->panel()),
                'cm' => $this->customersMenu(),
                'cf' => $this->armSearch(),
                'cl' => $this->customerList($arg === '' ? null : (int) $arg),
                'c' => $this->customerCard((int) $arg),
                'sl' => $this->serviceList((int) $arg),
                's' => $this->serviceCard((int) $arg),
                'il' => $this->invoiceList((int) $arg),
                'i' => $this->invoiceCard((int) $arg),
                'rl' => $this->receiptList(),
                'r' => $this->receiptCard((int) $arg),
                'dl' => $this->domainList(),
                'd' => $this->domainCard((int) $arg),
                'sq' => $this->stuckList(),
                'su' => $this->serviceSuspend($arg, true),
                'sr' => $this->serviceSuspend($arg, false),
                'sv' => $this->serviceRenew($arg),
                'sp' => $this->serviceRetry($arg),
                'ic' => $this->invoiceCancel($arg),
                'tp' => $this->ticketStatusMenu($arg),
                'tps' => $this->ticketStatusSet($arg),
                'ra' => $this->receiptApproveAsk($arg),
                'ray' => $this->receiptApproveDo($arg),
                'rj' => $this->receiptRejectAsk($arg),
                'sx' => $this->serviceTerminateAsk($arg),
                'sxy' => $this->serviceTerminateDo($arg),
                'cn' => $this->newCustomerStart(),
                'cne' => $this->newCustomerFinish(null),
                'cs' => $this->customerBrief((int) $arg),
                'cc' => $this->customerCall($arg),
                'rm' => $this->releasedManually($arg),
                'dn' => $this->dialConfirmed($arg),
                'sell' => $this->sellStart((int) $arg),
                'sep' => $this->sellPickedProduct((int) $arg),
                'seo' => $this->sellPickedCountry($arg),
                'sec' => $this->sellPickedCycle($arg),
                'sed' => $this->sellFreeSubdomain(),
                'sey' => $this->sellConfirm($arg),
                'w'  => $this->replyToOwner($this->ui->who($this->gate)),
                // هزینهٔ شرکت — در همان دفترِ مالیِ پنل ثبت می‌شود
                'xp' => $this->expenseMenu(),
                'xc' => $this->expensePickedCategory($arg),
                'm'  => $this->showMailbox(),
                'mv' => $this->showMail($arg),
                'ma' => $this->archiveMail($arg),
                'me' => $this->mailDraft($arg),
                'mes' => $this->mailSend($arg),
                'mew' => $this->mailWriteMyself($arg),
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
            $m = (array) ($update['message'] ?? []);
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
            $this->menu();

            return;
        }

        if (in_array($verb, ['/t', '/tickets', '/q', '/queue', 'تیکت‌ها', 'تیکتها', 'کارها', 'صف'], true)) {
            $this->showQueue();

            return;
        }

        if (in_array($verb, ['/link', 'پیوند', 'پیوندشماره'], true)
            || mb_substr($text, 0, 12) === 'پیوند شماره') {
            $chat = (string) ($this->gate->binding()['chat_id'] ?? '');

            if ($chat !== '') {
                $this->sends++;
                $this->sender->sendWithContactButton($chat,
                    'برای پیوندِ دوبارهٔ شمارهٔ خودتان، دکمهٔ زیر را بزنید.');
            }

            return;
        }

        if (in_array($verb, ['/c', 'مشتری', 'مشتری‌ها', 'مشتریها'], true)) {
            if (trim($rest) !== '') {
                $this->runSearch($rest);
            } else {
                $this->customersMenu();
            }

            return;
        }

        if (in_array($verb, ['/mail', 'ایمیل', 'ایمیل‌ها', 'ایمیلها'], true)) {
            $this->showMailbox();

            return;
        }

        /*
        | تماس با یک شمارهٔ دلخواه — «مشتریم نبود هم بتوانم تماس بگیرم».
        |
        | ⚠️ خودِ این فرمان **هیچ تماسی نمی‌گیرد**؛ فقط شماره را می‌خوانَد و یک
        | دکمهٔ تأیید می‌سازد. دلیلش یک ریسکِ واقعیِ همین محیط است: در بله متن
        | با یک تپ فرستاده می‌شود و اصلاحش ممکن نیست، پس یک رقمِ جاافتاده یعنی
        | زنگ‌زدن به یک غریبه با شمارهٔ شرکت روی کالر آی‌دی. کارفرما پیش از
        | زنگ، شماره را روی دکمه می‌بیند.
        */
        if (in_array($verb, ['/call', '/dial', 'تماس', 'زنگ'], true)) {
            $this->dialAsk($rest);

            return;
        }

        if (in_array($verb, ['/health', 'سلامت', 'وضعیت‌سامانه'], true)) {
            $this->replyToOwner($this->ui->health());

            return;
        }

        // آزمونِ زندهٔ دکمه‌ها — عمداً بی‌عارضه: هیچ‌چیز نمی‌نویسد
        if (in_array($verb, ['/test', 'دکمه', 'آزمون'], true)) {
            $this->sendButtons(
                '🧪 آزمونِ دکمه‌های شیشه‌ای

یکی از دکمه‌های زیر را بزنید. '
                .'اگر پاسخ گرفتید، یعنی بله کلیک را به ما می‌رساند و می‌توانیم '
                .'کلِ کنسول را دکمه‌ای کنیم.',
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

        /*
        | 🔴 جریانِ بازِ جستجو و لنگرِ ریپلای می‌توانند هم‌زمان صادق باشند، و آن
        | حالت **خطرناک** است: کارفرما «جستجو» را زده، بعد روی یک کارتِ تیکتِ
        | قدیمی سوایپ می‌کند (روی گوشی، ریپلای همان کارِ طبیعی است) و نامِ
        | مشتری را می‌نویسد — آن متن به‌عنوانِ **پاسخ به مشتری** می‌رفت.
        |
        | پس هیچ‌کدام اجرا نمی‌شود و از خودش پرسیده می‌شود.
        */
        if ($this->gate->flow() !== null && $anchored !== null) {
            $this->sendButtons(
                "شما در میانهٔ یک کارِ نیمه‌تمام هستید و هم‌زمان روی یک تیکت ریپلای زده‌اید.\n"
                ."برای امنیت هیچ‌کدام اجرا نشد.\n\n«".mb_substr($text, 0, 200).'»',
                [[['text' => '✖️ لغوِ کارِ نیمه‌تمام', 'data' => self::CB_PREFIX.'fx']]],
            );

            return;
        }

        $flow = $this->gate->flow();

        if ($flow === 'search') {
            $this->gate->clearFlow();
            $this->runSearch($text);

            return;
        }

        // جریانِ چندمرحله‌ای: هر مرحله فقط داده جمع می‌کند و هیچ‌چیز نمی‌نویسد
        if ($flow !== null && str_starts_with($flow, 'cn:')) {
            $this->newCustomerStep(substr($flow, 3), $text);

            return;
        }

        if ($flow !== null && str_starts_with($flow, 'mailreply:')) {
            $this->gate->clearFlow();
            $this->mailQueue((int) substr($flow, 10), $text);

            return;
        }

        /*
        | مبلغِ هزینه. جریان عمر دارد، پس پیامِ بی‌ربطِ فردا به‌عنوانِ مبلغ
        | خوانده نمی‌شود.
        */
        if ($flow !== null && str_starts_with($flow, 'expense:')) {
            $this->expenseAmount(substr($flow, 8), $text);

            return;
        }

        if ($flow === 'sell:domain') {
            $this->sellGotDomain($text);

            return;
        }

        if ($flow !== null && str_starts_with($flow, 'reject:')) {
            $this->gate->clearFlow();
            $this->enqueue('receipt_reject',
                ['id' => (int) substr($flow, 7), 'reason' => mb_substr($text, 0, 190)],
                'ردِ رسید');

            return;
        }

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
        | «تصحیح» — متنِ خودِ کارفرما را صیقل می‌دهد و برای تأیید نشان می‌دهد.
        |
        | ⚠️ عمداً **بعد** از «پاسخ» نشسته و فعلِ جداست: اگر با «پاسخ» یکی
        | می‌شد، هر پاسخِ عادی هم از مدل می‌گذشت — یعنی تأخیر و هزینه روی
        | مسیری که کارفرما بارها در روز استفاده می‌کند.
        |
        | 🔴 و هرگز خودش نمی‌فرستد؛ خروجی با دکمهٔ «ارسال» می‌رود.
        */
        if (in_array($verb, ['/expense', 'هزینه'], true)) {
            $this->expenseMenu();

            return;
        }

        if (in_array($verb, ['/polish', 'تصحیح', 'صیقل'], true)) {
            $this->polishDraft($anchored, $rest, explicitRef: $anchored === null);

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

    /**
     * پاسخ به مشتری — **بی‌واسطه**.
     *
     * ═══ چرا کدِ تأیید برداشته شد ═══
     *
     * کارفرما: «وقتی خودم دکمهٔ ارسال را می‌زنم، تأییدِ دوباره لزومی ندارد.»
     * حق دارد: خودِ کلیک تأیید است و یک کدِ شش‌رقمی روی گوشی فقط اصطکاک
     * می‌شود — و اصطکاک یعنی برگشتن به پنل، یعنی مرگِ خودِ قابلیت.
     *
     * ⚠️ ولی آن کد نقشِ دومی هم داشت: دارندهٔ آدرسِ وب‌هوک بی‌آن کد نمی‌توانست
     * پیامی به مشتری بفرستد. آن محافظ حالا نیست. جایگزینش **پرصداییِ** جعل
     * است: هر ارسال بلافاصله به چتِ متصل گزارش می‌شود، پس پیامی که کارفرما
     * نفرستاده روی گوشیِ خودش ظاهر می‌شود. جعلِ خاموش به جعلِ دیدنی تبدیل
     * می‌شود، نه به جعلِ ناممکن — و این معاملهٔ آگاهانهٔ کارفراست.
     */
    private function armReply(?Ticket $ticket, string $rest, bool $explicitRef): void
    {
        $body = $rest;

        if ($explicitRef) {
            [$ref, $body] = $this->split($rest);
            $ticket = $this->anchor->resolve($ref);
        }

        $body = trim($body);

        if ($ticket === null) {
            $this->replyToOwner('کدام تیکت؟ روی کارتِ آن ریپلای بزنید یا «پاسخ <شماره> <متن>» بفرستید.');

            return;
        }

        if ($body === '') {
            $this->replyToOwner('متنِ پاسخ خالی است.');

            return;
        }

        $this->send_reply($ticket, $body, close: false);
    }

    private function armClose(?Ticket $ticket, string $rest): void
    {
        $body = trim($rest);

        // «بستن <شماره> [متن]» وقتی ریپلای نیست
        if ($ticket === null) {
            [$ref, $body] = $this->split($rest);
            $ticket = $this->anchor->resolve($ref);
            $body = trim($body);
        }

        if ($ticket === null) {
            $this->replyToOwner('کدام تیکت؟ روی کارتِ آن ریپلای بزنید یا «بستن <شماره>» بفرستید.');

            return;
        }

        if ($ticket->isClosed()) {
            $this->replyToOwner('تیکتِ '.$ticket->number.' از قبل بسته است.');

            return;
        }

        if ($body === '') {
            app(TicketReplyService::class)->closeOnly($ticket, notify: true);
            $this->audit($ticket, 'بستنِ تیکت');
            $this->replyToOwner('🔒 '.$ticket->number.' بسته شد و به مشتری خبر رفت.');

            return;
        }

        $this->send_reply($ticket, $body, close: true);
    }

    /** تنها جایی که پیامِ دیده‌شدنیِ مشتری واقعاً نوشته می‌شود */
    private function send_reply(Ticket $ticket, string $body, bool $close): void
    {
        app(TicketReplyService::class)->post(
            $ticket, null, 'پشتیبانی', $body, internal: false, close: $close,
        );

        $this->audit($ticket, $close ? 'پاسخ و بستنِ تیکت' : 'پاسخ به تیکت');

        $c = $ticket->customer;

        /*
        | ⚠️ گزارشِ بعد از ارسال **اختیاری نیست**. حالا که کدِ تأیید نیست، این
        | تنها چیزی است که یک ارسالِ جعلی را دیدنی می‌کند.
        */
        $this->replyToOwner(
            ($close ? '✅ پاسخ رفت و '.$ticket->number.' بسته شد.' : '✅ پاسخ به '.$ticket->number.' رفت.')
            .($c ? ' — '.$c->displayName() : '')
            ."\n\n«".mb_substr($body, 0, 300).'»'
        );
    }

    /** مصرفِ کدِ تأیید و اجرای کارِ مسلح */
    private function confirm(string $rest): void
    {
        $code = $this->anchor->asciiDigits(trim($rest));
        $job = $this->gate->takeConfirm($code);

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

    /**
     * منوی اصلی — دکمه، نه متن.
     *
     * کارفرما روی گوشی است؛ تایپِ «تیکت‌ها» هر بار، همان اصطکاکی است که آدم را
     * به پنل برمی‌گردانَد.
     */
    private function menu(): void
    {
        $d = $this->ui->digest();
        $n = $d['counts'];

        // شمارش روی **برچسبِ دکمه** — تا بی‌بازکردن بفهمد کجا کار هست
        $tag = fn (string $base, int $c) => $c > 0 ? $base.' ('.fa_num((string) $c).')' : $base;

        $this->sendButtons($d['text'], [
            [
                ['text' => $tag('🎫 تیکت‌ها', $n['tickets']), 'data' => self::CB_PREFIX.'q'],
                ['text' => $tag('🏦 رسیدها', $n['bank']),     'data' => self::CB_PREFIX.'rl'],
            ],
            [
                ['text' => $tag('📬 ایمیل‌ها', $n['mail']),   'data' => self::CB_PREFIX.'m'],
                ['text' => $tag('⚠️ تحویل', $n['stuck']),     'data' => self::CB_PREFIX.'sq'],
            ],
            [
                ['text' => '👥 مشتری‌ها', 'data' => self::CB_PREFIX.'cm'],
                ['text' => $tag('🌐 دامنه‌ها', $n['domains']), 'data' => self::CB_PREFIX.'dl'],
            ],
            [
                ['text' => '💚 سلامت', 'data' => self::CB_PREFIX.'h'],
                ['text' => '📖 راهنما', 'data' => self::CB_PREFIX.'?'],
            ],
        ]);
    }

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
            ['text' => '✏️ خودم می‌نویسم', 'data' => self::CB_PREFIX.'tw:'.$t->id],
        ], [
            ['text' => '🏷 وضعیت/اولویت', 'data' => self::CB_PREFIX.'tp:'.$t->id],
            ['text' => '👤 مشتری', 'data' => self::CB_PREFIX.'c:'.((int) $t->customer_id)],
        ]];

        /*
        | تماس از داخلِ خودِ تیکت — خواستهٔ کارفرما: «وقتی تیکتش را می‌خوانم
        | بتوانم همان‌جا زنگ بزنم».
        |
        | ⚠️ شناسهٔ **مشتری** مهر می‌خورد نه شناسهٔ تیکت: مقصدِ تماس مالِ مشتری
        | است، و اگر تیکت را مبنا بگیریم دو مسیرِ متفاوت به یک کار می‌رسند و
        | دیر یا زود یکی‌شان نگهبانِ دیگری را ندارد.
        */
        if ($t->customer && ($label = $this->callButtonLabel($t->customer)) !== null) {
            $rows[] = [$this->stamped($label, 'cc', (int) $t->customer_id)];
        }

        if (! $t->isClosed()) {
            $rows[] = [['text' => '🔒 بستنِ تیکت', 'data' => self::CB_PREFIX.'tc:'.$t->id]];
        }

        $rows[] = $this->nav('q');

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

        $text = app(TicketDraftWriter::class)->draft($ticket, $tone ?: 'n');

        if ($text === null) {
            $this->replyToOwner('پیش‌نویس ساخته نشد (مدل جواب نداد). دوباره بزنید یا خودتان بنویسید.');

            return;
        }

        $this->gate->putDraft($ticket->id, $text);

        $labels = ['n' => '🙂 معمولی', 's' => '✂️ کوتاه', 'f' => '🎩 رسمی', 'a' => '🙏 با عذرخواهی'];

        $others = array_values(array_filter(
            array_keys(TicketDraftWriter::TONES),
            fn ($k) => $k !== ($tone ?: 'n'),
        ));

        $this->sendButtons(
            '✍️ پیش‌نویس برای '.$ticket->number."\n\n".$text
            ."\n\n⚠️ هنوز چیزی نرفته. با «ارسال» همان لحظه می‌رود.",
            [
                [['text' => '📤 ارسال به مشتری', 'data' => self::CB_PREFIX.'ts:'.$ticket->id]],
                array_map(fn ($k) => [
                    'text' => $labels[$k] ?? $k,
                    'data' => self::CB_PREFIX.'td:'.$ticket->id.':'.$k,
                ], array_slice($others, 0, 3)),
            ],
        );
    }


    /**
     * «تصحیح نگارش» — متنِ **خودِ کارفرما** را صیقل می‌دهد و برای تأیید
     * نشان می‌دهد.
     *
     * 🔴 هیچ‌چیز ارسال نمی‌شود. خروجی مثل پیش‌نویسِ AI در همان انبار می‌نشیند
     * و با دکمهٔ «ارسال» (`ts`) می‌رود — یعنی کارفرما همیشه یک بار می‌بیند.
     *
     * ⚠️ چرا فرمانِ متنی و نه دکمه: در بله، پاسخ با «ریپلای روی کارت» **فوراً**
     * می‌رود. دکمه‌ای که بخواهد وسطِ آن بنشیند یعنی عوض‌کردنِ جریانی که کار
     * می‌کند و کارفرما به آن عادت دارد. این مسیر اختیاری است و کنارش می‌ماند.
     *
     * ⚠️ متنِ اصلی در پیام نگه داشته می‌شود تا اگر صیقل بدتر شد، کارفرما
     * بتواند همان را دستی بفرستد. بی‌آن، نوشته‌اش از دست می‌رفت.
     */

    // ───────────────────────── هزینهٔ شرکت ─────────────────────────

    /**
     * منوی دسته‌های هزینه.
     *
     * 🔴 در **همان** دفترِ مالیِ پنل ثبت می‌شود (`BusinessLedger::manual()`)،
     * نه جدولی جدا. انبارِ موازی یعنی جمعِ داشبورد با جمعِ ربات نخوانَد و
     * هیچ‌کدام معلوم نباشد کدام درست است.
     *
     * ⚠️ دسته‌ها و برچسب‌هایشان از خودِ `BusinessLedger` می‌آیند؛ فهرستِ
     * دستی این‌جا روزی از پنل عقب می‌افتاد.
     */
    private function expenseMenu(): void
    {
        if (! app(\App\Services\Finance\BusinessLedger::class)->ready()) {
            $this->replyToOwner('دفترِ مالی هنوز مهاجرت نشده است.');

            return;
        }

        $cats = \App\Services\Finance\BusinessLedger::EXPENSE_CATEGORIES;
        $rows = [];

        foreach (array_chunk($cats, 2) as $pair) {
            $rows[] = array_map(fn ($c) => [
                'text' => \App\Services\Finance\BusinessLedger::categoryLabel($c),
                'data' => self::CB_PREFIX.'xc:'.$c,
            ], $pair);
        }

        $this->sendButtons('💸 هزینه در کدام دسته بود؟', $rows);
    }

    /**
     * دسته انتخاب شد — حالا منتظرِ مبلغ.
     *
     * ⚠️ هیچ ردیفی هنوز نوشته نمی‌شود. جریان عمر دارد و اگر نیمه‌کاره رها
     * شود خودش می‌میرد؛ یعنی هزینهٔ نیمه‌ثبت‌شده در دفتر نمی‌مانَد.
     */
    private function expensePickedCategory(string $cat): void
    {
        if (! in_array($cat, \App\Services\Finance\BusinessLedger::EXPENSE_CATEGORIES, true)) {
            $this->replyToOwner('این دسته معتبر نیست.');

            return;
        }

        $this->gate->armFlow('expense:'.$cat);

        $this->replyToOwner(
            '💸 دستهٔ «'.\App\Services\Finance\BusinessLedger::categoryLabel($cat)."».

"
            ."حالا مبلغ را به **تومان** بفرستید. می‌توانید توضیح را هم بعدش بنویسید:
"
            ."«۲۵۰۰۰۰ اجارهٔ سرور هتزنر»"
        );
    }

    /**
     * مبلغ (و توضیحِ اختیاری) رسید — ثبت کن.
     *
     * ⚠️ ارقامِ فارسی هم پذیرفته می‌شوند. کارفرما از گوشی می‌نویسد و
     * صفحه‌کلیدِ فارسی «۲۵۰۰۰۰» می‌دهد؛ ردکردنش یعنی ویژگی‌ای که در عمل
     * کار نمی‌کند.
     *
     * 🔴 صفر یا منفی رد می‌شود. `manual()` خودش هم نگهبان دارد ولی سکوت
     * می‌کند — و کارفرما باید بفهمد ثبت **نشد**، نه اینکه فکر کند شد.
     */
    private function expenseAmount(string $cat, string $text): void
    {
        $this->gate->clearFlow();

        $text = trim($text);
        [$rawAmount, $note] = array_pad(explode(' ', $text, 2), 2, '');

        $digits = strtr($rawAmount, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
        $amount = (int) preg_replace('~[^0-9]~', '', $digits);

        if ($amount <= 0) {
            $this->replyToOwner('مبلغ خوانده نشد. دوباره «هزینه» بزنید و عدد را اول بنویسید.');

            return;
        }

        $entry = app(\App\Services\Finance\BusinessLedger::class)->manual(
            'expense',
            $amount,
            $cat,
            null,
            trim($note) !== '' ? trim($note) : null,
            // ⚠️ `boundUser()` است نه `userId()` — گیت متدِ دوم را ندارد و
            //    فرضش یعنی شکستِ زمانِ اجرا روی اولین ثبتِ واقعی.
            $this->gate->boundUser()?->id,
        );

        if ($entry === null) {
            $this->replyToOwner('ثبت نشد. دفترِ مالی آماده نیست.');

            return;
        }

        $this->replyToOwner(
            "✅ هزینه ثبت شد.
"
            .'دسته: '.\App\Services\Finance\BusinessLedger::categoryLabel($cat)."
"
            .'مبلغ: '.fa_num(number_format($amount))." تومان
"
            .(trim($note) !== '' ? 'توضیح: '.trim($note)."
" : '')
            ."
در /admin/finance دیده می‌شود."
        );
    }

    private function polishDraft(?Ticket $ticket, string $rest, bool $explicitRef): void
    {
        $body = $rest;

        /*
        | ⚠️ همان الگوی `armReply()`: مرجعِ صریح («تصحیح TK-123 …») از لنگرِ
        | ریپلای جدا حساب می‌شود. کپی‌نکردنِ این منطق عمدی است — دو راهِ
        | متفاوتِ یافتنِ تیکت یعنی روزی یکی‌شان تیکتِ اشتباه را پیدا کند.
        */
        if ($explicitRef) {
            [$ref, $body] = $this->split($rest);
            $ticket = $this->anchor->resolve($ref);
        }

        if ($ticket === null) {
            $this->replyToOwner('کدام تیکت؟ روی کارتِ آن ریپلای بزنید یا «تصحیح <شماره> <متن>» بفرستید.');

            return;
        }

        $body = trim($body);

        if (mb_strlen($body) < 12) {
            $this->replyToOwner('متنِ کوتاه چیزی برای تصحیح ندارد.');

            return;
        }

        $out = app(\App\Services\Ticket\ReplyPolisher::class)->polish($body);

        if ($out === null) {
            $this->replyToOwner('تصحیح انجام نشد (مدل جواب نداد). متنِ خودتان دست‌نخورده است.');

            return;
        }

        $this->gate->putDraft($ticket->id, $out);

        $this->sendButtons(
            '✨ متنِ تصحیح‌شده برای '.$ticket->number."

".$out
            ."

⚠️ هنوز چیزی نرفته. یک بار بخوانید؛ با «ارسال» می‌رود.",
            [[['text' => '📤 ارسال به مشتری', 'data' => self::CB_PREFIX.'ts:'.$ticket->id]]],
        );
    }

    /**
     * «خودم می‌نویسم» — هوشِ مصنوعی را دور بزن.
     *
     * ⚠️ حالتِ تازه‌ای ذخیره نمی‌شود: لنگرِ ریپلای از قبل کار می‌کند، پس این
     * دکمه فقط همان راه را یادآوری می‌کند. حالتِ ذخیره‌شده یعنی چیزی که روزی
     * کهنه می‌شود و متن به تیکتِ اشتباه می‌رود.
     */
    private function howToWrite(string $arg): void
    {
        $t = Ticket::find((int) $arg);

        $this->replyToOwner($t === null
            ? 'تیکت پیدا نشد.'
            : '✏️ روی کارتِ '.$t->number." **ریپلای** بزنید و متنتان را بنویسید.\n"
              ."همان لحظه برای مشتری می‌رود.\n\n"
              .'برای بستنِ هم‌زمان: «بستن '.$t->number.' متنِ شما»');
    }

    /** ارسالِ پیش‌نویسِ ذخیره‌شده */
    private function armStoredDraft(string $arg): void
    {
        $ticket = Ticket::find((int) $arg);
        $body = $ticket ? $this->gate->takeDraft($ticket->id) : null;

        if ($ticket === null || $body === null) {
            $this->replyToOwner('پیش‌نویس پیدا نشد یا منقضی شده. دوباره بسازید.');

            return;
        }

        $this->send_reply($ticket, $body, close: false);
    }

    // ─────────────────── فاز ۲: صفحه‌های خواندنی ───────────────────

    /**
     * دکمه‌های کارتِ کلیک‌شده را بردار و نشان بده که مصرف شده.
     *
     * ⚠️ `editMessageText` بی‌`reply_markup` صدا زده می‌شود؛ همین کیبورد را
     * برمی‌دارد. متنِ اصلی نگه داشته می‌شود تا کارفرما بعداً هم بفهمد آن کارت
     * دربارهٔ چه بود.
     *
     * ⚠️ شکستش بی‌صداست و باید هم باشد: بله ویرایشِ پیامِ کهنه‌تر از ۴۸ ساعت را
     * رد می‌کند. آن حالت با محافظِ مهرِ تازگی پوشش داده می‌شود — این‌جا فقط
     * راحتیِ چشم است، نه تنها محافظ.
     */
    private function consumeButtons(): void
    {
        $chat = (string) ($this->gate->binding()['chat_id'] ?? '');

        if ($chat === '' || $this->clickedMessageId === null) {
            return;
        }

        $text = $this->clickedText !== ''
            ? mb_substr($this->clickedText, 0, 3400).'

☑️ انجام شد'
            : '☑️ انجام شد';

        try {
            $this->sender->editText($chat, $this->clickedMessageId, $text);
        } catch (\Throwable) {
            // بی‌صدا: این لایهٔ راحتی است، نه محافظِ اصلی
        }
    }

    /** ردیفِ ناوبریِ ثابت — هیچ صفحه‌ای نباید بن‌بست باشد */
    private function nav(string $backVerb = ''): array
    {
        $row = [];

        if ($backVerb !== '') {
            $row[] = ['text' => '⬅️ برگشت', 'data' => self::CB_PREFIX.$backVerb];
        }

        $row[] = ['text' => '🏠 منو', 'data' => self::CB_PREFIX.'x'];

        return $row;
    }

    private function runSearch(string $term): void
    {
        $r = $this->screens->search($term);

        $rows = array_map(fn ($x) => [
            ['text' => $x['label'], 'data' => self::CB_PREFIX.'c:'.$x['id']],
        ], $r['rows']);

        $rows[] = $this->nav('cm');

        $this->sendButtons($r['text'], $rows);
    }

    private function cancelFlow(): void
    {
        $this->gate->clearFlow();
        $this->menu();
    }

    private function customersMenu(): void
    {
        $this->sendButtons("👥 مشتری‌ها\n\nبا نام، کدِ SN، ایمیل یا موبایل جستجو کنید.", [
            [
                ['text' => '🔎 جستجو', 'data' => self::CB_PREFIX.'cf'],
                ['text' => '🕒 تازه‌ترین‌ها', 'data' => self::CB_PREFIX.'cl'],
            ],
            [['text' => '🆕 مشتریِ جدید', 'data' => self::CB_PREFIX.'cn']],
            $this->nav(),
        ]);
    }

    private function armSearch(): void
    {
        $this->gate->armFlow('search');

        $this->sendButtons(
            "🔎 نام، کدِ SN، ایمیل یا موبایلِ مشتری را بفرستید.\n"
            .'(دستِ‌کم دو حرف — ۱۰ دقیقه فرصت دارید)',
            [[['text' => '✖️ انصراف', 'data' => self::CB_PREFIX.'fx']]],
        );
    }

    private function customerList(?int $cursor): void
    {
        $r = $this->screens->customers($cursor);

        $rows = array_map(fn ($x) => [
            ['text' => $x['label'], 'data' => self::CB_PREFIX.'c:'.$x['id']],
        ], $r['rows']);

        if ($r['next'] !== null) {
            $rows[] = [['text' => 'بعدی ▶️', 'data' => self::CB_PREFIX.'cl:'.$r['next']]];
        }

        $rows[] = $this->nav('cm');

        $this->sendButtons($r['text'], $rows);
    }

    private function customerCard(int $id): void
    {
        $c = Customer::find($id);

        if ($c === null) {
            $this->replyToOwner('مشتری پیدا نشد.');

            return;
        }

        $rows = [
            [
                ['text' => '🖥 سرویس‌ها', 'data' => self::CB_PREFIX.'sl:'.$c->id],
                ['text' => '🧾 فاکتورها', 'data' => self::CB_PREFIX.'il:'.$c->id],
            ],
            [
                ['text' => '🛒 فروشِ سرویس', 'data' => self::CB_PREFIX.'sell:'.$c->id],
                ['text' => '🧠 خلاصه', 'data' => self::CB_PREFIX.'cs:'.$c->id],
            ],
        ];

        if (($label = $this->callButtonLabel($c)) !== null) {
            $rows[] = [$this->stamped($label, 'cc', $c->id)];
        }

        $rows[] = $this->nav('cm');

        $this->sendButtons($this->screens->customer($c), $rows);
    }

    /*
    |==========================================================================
    | تماس با مشتری از داخلِ کنسولِ بله
    |==========================================================================
    |
    | ═══ چرا دکمه فقط گاهی ظاهر می‌شود ═══
    |
    | دکمه‌ای که کلیکش خطا بدهد، در بله بدتر از پنل است: کارفرما وسطِ کار و
    | معمولاً روی موبایل است، و یک پیامِ خطا یعنی باید برود سراغِ پنل تا بفهمد
    | چه چیزی کم بوده. پس اگر تماس شدنی نیست، اصلاً دکمه‌ای نمی‌سازیم.
    |
    | ═══ امنیت ═══
    |
    | سه لایه، و هیچ‌کدام تازه نیست:
    |   ۱) `matchesCallback` فقط چتِ **متصل‌شده** را می‌پذیرد
    |   ۲) دکمه مهردار است، پس کلیکِ روی کارتِ سه‌ماه‌پیش اجرا نمی‌شود
    |   ۳) `cc` در `CONSUMING` است، پس دکمه بعدِ کلیک برداشته می‌شود و
    |      دوباره‌کلیک مشتری را دو بار زنگ نمی‌زند
    |
    | 🔴 شمارهٔ مقصد از **دیتابیس** خوانده می‌شود، نه از `callback_data`.
    | همان قاعدهٔ پنل: وگرنه هر کسی که بتواند callback بسازد، از خطِ شرکت به
    | هر شماره‌ای زنگ می‌زند.
    */

    /**
     * برچسبِ دکمهٔ تماس، یا `null` اگر تماس شدنی نیست.
     */
    private function callButtonLabel(Customer $c): ?string
    {
        $svc = app(OutgoingCallService::class);

        if (! $svc->enabled() || $svc->agentNumberFor(null) === null) {
            return null;
        }

        $number = $this->customerCallNumber($c);

        if ($number === null) {
            return null;
        }

        // ⚠️ شماره روی خودِ دکمه: کارفرما پیش از کلیک می‌بیند به چه کسی زنگ می‌زند
        return '📞 تماس با '.$number;
    }

    /**
     * شمارهٔ قابلِ شماره‌گیریِ مشتری — یا `null`.
     *
     * ⚠️ ترتیب همان ترتیبِ پنل است. اگر این دو واگرا شوند، دکمهٔ بله و دکمهٔ
     * پنل به دو شماره زنگ می‌زنند و هیچ‌کس نمی‌فهمد چرا.
     */
    private function customerCallNumber(Customer $c): ?string
    {
        $number = $c->phone
            ?: optional($c->profiles->firstWhere('is_default', true))->mobile
            ?: optional($c->profiles->first())->mobile;

        if (! $number) {
            return null;
        }

        $kind = IranianPhone::kind((string) $number);

        // شمارهٔ محلیِ بی‌پیش‌شماره شماره‌گیری‌شدنی نیست — دکمه‌اش هم ساخته نشود
        return in_array($kind, [
            IranianPhone::KIND_MOBILE,
            IranianPhone::KIND_LANDLINE,
        ], true) ? (string) $number : null;
    }

    private function customerCall(string $arg): void
    {
        [$id, $fresh] = $this->unstamp($arg, 'cc');

        if (! $fresh) {
            $this->staleButton();

            return;
        }

        $c = Customer::find($id);

        if ($c === null) {
            $this->replyToOwner('مشتری پیدا نشد.');

            return;
        }

        $number = $this->customerCallNumber($c);

        if ($number === null) {
            $this->replyToOwner('برای این مشتری شماره‌ی قابلِ تماسی ثبت نشده.');

            return;
        }

        $svc = app(OutgoingCallService::class);
        $agent = $svc->agentNumberFor(null);

        $result = $svc->place($number, null);

        /*
        | ⚠️ سه حالت، همان‌طور که در پنل. «نمی‌دانم» نباید شبیهِ شکست گزارش شود
        | — یک بار همین باعث شد پنل بگوید تماس نرفته در حالی که تلفن زنگ خورده
        | بود.
        */
        $this->replyToOwner(match ($result['status']) {
            OutgoingCallService::OK => "📞 تماس با {$c->displayName()} در حالِ برقراری است.\n"
                ."ابتدا {$agent} زنگ می‌خورد، بعد {$number}.",

            OutgoingCallService::UNKNOWN => "⚠️ پاسخِ رله قابلِ فهم نبود.\n"
                ."ممکن است تماس برقرار شده باشد — چند لحظه صبر کنید.\n"
                .'جزئیات در ردیابِ خطا ثبت شد.',

            default => "❌ تماس برقرار نشد.\n".$result['message'],
        });
    }

    private function serviceList(int $customerId): void
    {
        $c = Customer::find($customerId);

        if ($c === null) {
            $this->replyToOwner('مشتری پیدا نشد.');

            return;
        }

        $rows = array_map(fn ($x) => [
            ['text' => $x['label'], 'data' => self::CB_PREFIX.'s:'.$x['id']],
        ], $this->screens->serviceRows($c));

        $rows[] = $this->nav('c:'.$c->id);

        $this->sendButtons($rows === [] ? 'سرویسی ندارد.' : '🖥 سرویس‌های '.$c->displayName(), $rows);
    }

    private function serviceCard(int $id): void
    {
        $s = Service::find($id);

        if ($s === null) {
            $this->replyToOwner('سرویس پیدا نشد.');

            return;
        }

        $rows = [];

        if ($s->status === 'suspended') {
            $rows[] = [$this->stamped('▶️ رفعِ تعلیق', 'sr', $s->id)];
        } elseif (! in_array($s->status, Service::DEAD_STATUSES, true)) {
            $rows[] = [$this->stamped('⏸ تعلیق', 'su', $s->id)];
        }

        if (in_array($s->provision_status, ['failed', 'manual'], true)) {
            $rows[] = [$this->stamped('🔁 تلاشِ دوبارهٔ تحویل', 'sp', $s->id)];
        }

        if ($s->isRecurring()) {
            $rows[] = [$this->stamped('🧾 صدورِ فاکتورِ تمدید', 'sv', $s->id)];
        }

        if (! in_array($s->status, Service::DEAD_STATUSES, true)) {
            $rows[] = [['text' => '🗑 خاتمهٔ سرویس', 'data' => self::CB_PREFIX.'sx:'.$s->id]];
        }

        /*
        | «خودم دستی پاکش کردم» — تنها راهِ بستنِ صفی که زیرساخت هرگز تأییدش
        | نمی‌کند، چون ماشین از پنلِ دیتاسنتر با دست پاک شده و API دیگر
        | نمی‌شناسدش.
        |
        | ⚠️ فقط وقتی ظاهر می‌شود که سرویس واقعاً در صفِ آزادسازی باشد. دکمه‌ای
        | که همیشه باشد، دیر یا زود روی سرویسی زده می‌شود که ماشینش **واقعاً**
        | زنده است — و آن‌وقت نشتی را از رادار پاک می‌کنیم بی‌آنکه بسته باشیمش.
        */
        if ($s->provision_status === Service::PROVISION_RELEASING) {
            $rows[] = [$this->stamped('✅ آزادسازی دستی انجام شد', 'rm', $s->id)];
        }

        $rows[] = $this->nav($s->customer_id ? 'c:'.$s->customer_id : '');

        $this->sendButtons($this->screens->service($s), $rows);
    }

    private function invoiceList(int $customerId): void
    {
        $c = Customer::find($customerId);

        if ($c === null) {
            $this->replyToOwner('مشتری پیدا نشد.');

            return;
        }

        $rows = array_map(fn ($x) => [
            ['text' => $x['label'], 'data' => self::CB_PREFIX.'i:'.$x['id']],
        ], $this->screens->invoiceRows($c));

        $rows[] = $this->nav('c:'.$c->id);

        $this->sendButtons($rows === [] ? 'فاکتوری ندارد.' : '🧾 فاکتورهای '.$c->displayName(), $rows);
    }

    private function invoiceCard(int $id): void
    {
        $i = Invoice::find($id);

        if ($i === null) {
            $this->replyToOwner('فاکتور پیدا نشد.');

            return;
        }

        $rows = [];

        // فقط فاکتورِ پرداخت‌نشده و دست‌نخورده لغو می‌شود
        if ((int) $i->paid <= 0 && in_array($i->status, ['unpaid', 'draft'], true)) {
            $rows[] = [$this->stamped('⚪️ لغوِ فاکتور', 'ic', $i->id)];
        }

        $rows[] = $this->nav($i->customer_id ? 'c:'.$i->customer_id : '');

        $this->sendButtons($this->screens->invoice($i), $rows);
    }

    private function receiptList(): void
    {
        $r = $this->screens->receipts();

        $rows = array_map(fn ($x) => [
            ['text' => $x['label'], 'data' => self::CB_PREFIX.'r:'.$x['id']],
        ], $r['rows']);

        $rows[] = $this->nav();

        $this->sendButtons($r['text'], $rows);
    }

    private function receiptCard(int $id): void
    {
        $r = Schema::hasTable('bank_transfer_receipts')
            ? BankTransferReceipt::find($id) : null;

        if ($r === null) {
            $this->replyToOwner('رسید پیدا نشد.');

            return;
        }

        /*
        | ⚠️ دکمهٔ تأیید/رد عمداً هنوز این‌جا نیست.
        |
        | تأییدِ رسید یک زنجیرهٔ پولی راه می‌اندازد که هیچ‌جای اپ «لغوِ تأیید»
        | ندارد. تا وقتی محافظِ nonce روی کارهای ارزان‌تر جواب نداده، این دکمه
        | نمی‌آید. فعلاً کارت **همه‌چیزِ لازم برای تصمیم** را نشان می‌دهد و
        | خودِ کار در پنل انجام می‌شود.
        */
        $rows = [];

        if ($r->isPending()) {
            $rows[] = [
                ['text' => '✅ تأیید', 'data' => self::CB_PREFIX.'ra:'.$r->id],
                ['text' => '⛔️ رد', 'data' => self::CB_PREFIX.'rj:'.$r->id],
            ];
        }

        if ($r->customer_id) {
            $rows[] = [['text' => '👤 پروندهٔ مشتری', 'data' => self::CB_PREFIX.'c:'.$r->customer_id]];
        }

        $rows[] = $this->nav('rl');

        $this->sendButtons($this->screens->receipt($r), $rows);
    }

    private function domainList(): void
    {
        $r = $this->screens->domains();

        $rows = array_map(fn ($x) => [
            ['text' => $x['label'], 'data' => self::CB_PREFIX.'d:'.$x['id']],
        ], $r['rows']);

        $rows[] = $this->nav();

        $this->sendButtons($r['text'], $rows);
    }

    private function domainCard(int $id): void
    {
        $d = Schema::hasTable('domains')
            ? Domain::find($id) : null;

        if ($d === null) {
            $this->replyToOwner('دامنه پیدا نشد.');

            return;
        }

        $rows = [];

        if ($d->customer_id) {
            $rows[] = [['text' => '👤 پروندهٔ مشتری', 'data' => self::CB_PREFIX.'c:'.$d->customer_id]];
        }

        $rows[] = $this->nav('dl');

        $this->sendButtons($this->screens->domain($d), $rows);
    }

    private function stuckList(): void
    {
        $r = $this->screens->stuck();

        $rows = array_map(fn ($x) => [
            ['text' => $x['label'], 'data' => self::CB_PREFIX.'s:'.$x['id']],
        ], $r['rows']);

        $rows[] = $this->nav();

        $this->sendButtons($r['text'], $rows);
    }

    /**
     * «/تماس ۰۹۱۲…» — شماره را بخوان و دکمهٔ تأیید بساز.
     *
     * ⚠️ هیچ تماسی این‌جا برقرار نمی‌شود. علتِ دو مرحله‌ای بودن در دستور
     * توضیح داده شده: متنِ بله اصلاح‌شدنی نیست و یک رقمِ اشتباه پول خرج
     * می‌کند و به یک غریبه زنگ می‌زند.
     *
     * ⚠️ اعتبارسنجی **پیش از** ساختِ دکمه است، نه بعدش. دکمه‌ای که کلیکش خطا
     * بدهد، روی موبایل بدتر از نبودنِ دکمه است.
     */
    private function dialAsk(string $rest): void
    {
        $raw = $this->anchor->asciiDigits(trim($rest));
        $number = IranianPhone::normalize($raw);
        $kind = IranianPhone::kind($raw);

        if ($number === null || $raw === '') {
            $this->replyToOwner(
                "شماره را همراهِ فرمان بفرستید.\n"
                .'مثال: «تماس ۰۹۱۲۳۴۵۶۷۸۹»'
            );

            return;
        }

        if (! in_array($kind, [IranianPhone::KIND_MOBILE, IranianPhone::KIND_LANDLINE], true)) {
            /*
            | ⚠️ پیام می‌گوید **چه چیزی** کم است، نه فقط «نامعتبر». شمارهٔ محلیِ
            | بی‌پیش‌شماره رایج‌ترین ورودیِ اشتباه است و حدس‌زدنِ پیش‌شماره یعنی
            | زنگ‌زدن به یک غریبه در شهرِ دیگر.
            */
            $this->replyToOwner(
                "این شماره قابلِ شماره‌گیری نیست: «{$raw}»\n"
                .'شمارهٔ کامل با پیش‌شماره لازم است — مثلاً ۰۹۱۲۳۴۵۶۷۸۹ یا ۰۲۱۷۱۰۵۷۷۵۷.'
            );

            return;
        }

        $svc = app(OutgoingCallService::class);

        if (! $svc->enabled()) {
            $this->replyToOwner('رلهٔ تلفن ابری پیکربندی نشده؛ تماس ممکن نیست.');

            return;
        }

        $agent = $svc->agentNumberFor(null);

        if ($agent === null || $svc->extension() === null) {
            $this->replyToOwner('شمارهٔ تماس‌گیرنده یا خطِ ابری تنظیم نشده؛ تماس ممکن نیست.');

            return;
        }

        $this->sendButtons(
            "📞 تماس با «{$number}»؟\n"
            ."ابتدا {$agent} زنگ می‌خورد، بعد این شماره.",
            [[$this->stamped('✅ بگیر '.$number, 'dn', (int) $number)]],
        );
    }

    /** دکمهٔ تأییدِ شماره‌گیریِ دستی خورده شد. */
    private function dialConfirmed(string $arg): void
    {
        [$id, $fresh] = $this->unstamp($arg, 'dn');

        if (! $fresh) {
            $this->staleButton();

            return;
        }

        $number = (string) $id;
        $svc = app(OutgoingCallService::class);
        $agent = $svc->agentNumberFor(null);

        $result = $svc->place($number, null);

        /*
        | ⚠️ رد در `ActivityLog` — همان دلیلِ پنل: تماس با غریبه به هیچ پرونده‌ای
        | نمی‌چسبد، پس بی‌این لاگ تنها ردش صورت‌حسابِ تأمین‌کننده بود.
        */
        try {
            \App\Models\ActivityLog::record(null, 'call',
                'شماره‌گیریِ دستی از بله: '.$number.' — نتیجه: '.$result['status'], null, 'staff');
        } catch (\Throwable $e) {
            /*
            | 🔴 عمداً `catch` خالی نیست.
            |
            | این تنها ردِ ماندگارِ تماس با یک غریبه است — به هیچ پرونده‌ای
            | نمی‌چسبد، پس اگر بی‌صدا بیفتد تنها جای دیگرش صورت‌حسابِ
            | تأمین‌کننده است و آن فقط جمعِ کل را می‌گوید.
            */
            ErrorTracker::note('cloud-phone', $e, ['area' => 'bale-dial-log', 'to' => $number]);
        }

        $this->replyToOwner(match ($result['status']) {
            OutgoingCallService::OK => "📞 تماس با {$number} در حالِ برقراری است.\n"
                ."ابتدا {$agent} زنگ می‌خورد.",

            OutgoingCallService::UNKNOWN => "⚠️ پاسخِ رله قابلِ فهم نبود.\n"
                ."ممکن است تماس برقرار شده باشد — چند لحظه صبر کنید.\n"
                .'جزئیات در ردیابِ خطا ثبت شد.',

            default => "❌ تماس برقرار نشد.\n".$result['message'],
        });
    }

    /**
     * اعلامِ «این سرور را خودم دستی پاک کردم».
     *
     * 🔴 خطرش واقعی است: اگر ماشین هنوز زنده باشد و ما صف را ببندیم، نشتی از
     * رادار پاک می‌شود و اجاره‌اش تا ابد از حسابِ ما می‌رود، بی‌هیچ هشداری. پس
     * پیامِ تأیید صریح می‌گوید چه چیزی را پذیرفته‌ایم.
     */
    private function releasedManually(string $arg): void
    {
        [$id, $fresh] = $this->unstamp($arg, 'rm');

        if (! $fresh) {
            $this->staleButton();

            return;
        }

        $s = Service::find($id);

        if ($s === null) {
            $this->replyToOwner('سرویس پیدا نشد.');

            return;
        }

        $done = app(ProvisioningService::class)->markReleasedManually($s, 'bale');

        $this->replyToOwner($done
            ? "✅ سرویسِ «{$s->name}» (#{$s->id}) از صفِ آزادسازی خارج شد.\n"
              ."تلاشِ خودکار و هشدارِ ساعتی متوقف شد.\n\n"
              .'⚠️ اگر ماشین در واقع هنوز زنده باشد، دیگر هشداری نمی‌آید — '
              .'پس مطمئن شوید واقعاً پاک شده.'
            : 'این سرویس در صفِ آزادسازی نیست؛ کاری لازم نبود.');
    }

    // ─────────────────── فاز ۳: کارهای برگشت‌پذیر ───────────────────

    /**
     * دکمهٔ مهردار — تازگی‌اش سنجیده می‌شود، ولی در جریانِ عادی نامرئی است.
     *
     * @return array{text:string,data:string}
     */
    private function stamped(string $text, string $verb, int $id): array
    {
        return [
            'text' => $text,
            'data' => self::CB_PREFIX.$verb.':'.$id.':'.$this->gate->stamp($verb.':'.$id),
        ];
    }

    /**
     * @return array{0:int,1:bool} شناسه و اینکه مهر تازه بود یا نه
     */
    private function unstamp(string $arg, string $verb): array
    {
        [$id, $stamp] = array_pad(explode(':', $arg, 2), 2, '');

        return [(int) $id, $this->gate->verifyStamp($verb.':'.(int) $id, $stamp)];
    }

    private function staleButton(): void
    {
        $this->replyToOwner(
            "⌛️ این دکمه کهنه است.\n"
            .'برای امنیت اجرا نشد — کارت را دوباره باز کنید و از همان‌جا بزنید.'
        );
    }

    private function serviceSuspend(string $arg, bool $suspend): void
    {
        [$id, $fresh] = $this->unstamp($arg, $suspend ? 'su' : 'sr');

        if (! $fresh) {
            $this->staleButton();

            return;
        }

        $s = Service::find($id);

        if ($s === null) {
            $this->replyToOwner('سرویس پیدا نشد.');

            return;
        }

        $prov = app(ProvisioningService::class);
        $r = $suspend ? $prov->suspend($s) : $prov->unsuspend($s);

        if (! $r->ok && ! $r->manual) {
            $this->replyToOwner('⚠️ انجام نشد: '.mb_substr((string) $r->error, 0, 200));

            return;
        }

        ActivityLog::forService($s, $suspend ? 'suspend' : 'reactivate',
            'از رباتِ بله توسط مدیر «'.($this->gate->boundUser()?->name ?? 'مدیر').'»', 'staff');

        // ⚠️ دکمهٔ برگشت همان‌جا: کارِ برگشت‌پذیر باید برگشتش هم یک تپ باشد
        $this->sendButtons(
            ($suspend ? '⏸ «'.$s->name.'» معلق شد' : '▶️ «'.$s->name.'» فعال شد')
            .($s->customer ? ' — '.$s->customer->displayName() : '')
            .($r->manual ? "\n⚠️ روی سرور دستی انجام دهید." : ''),
            [[$this->stamped($suspend ? '↩️ برگرداندن' : '↩️ تعلیقِ دوباره',
                $suspend ? 'sr' : 'su', $s->id)],
                $this->nav('s:'.$s->id)],
        );
    }

    private function serviceRenew(string $arg): void
    {
        [$id, $fresh] = $this->unstamp($arg, 'sv');

        if (! $fresh) {
            $this->staleButton();

            return;
        }

        $s = Service::find($id);

        if ($s === null || ! $s->isRecurring()) {
            $this->replyToOwner('این سرویس دوره‌ای نیست یا پیدا نشد.');

            return;
        }

        try {
            // ⚠️ همان متدِ پنل، نه نسخهٔ دوم: مبلغ و مالیات و سررسید یک‌جا حساب
            // می‌شوند و دو پیاده‌سازی روزی واگرا می‌شوند.
            $inv = app(ServiceController::class)->issueInvoice($s);
        } catch (\Throwable $e) {
            ErrorTracker::note('bale-admin', $e, ['step' => 'renew', 'service' => $s->id]);
            $this->replyToOwner('⚠️ صدورِ فاکتور انجام نشد.');

            return;
        }

        ActivityLog::forService($s, 'renew',
            'فاکتورِ تمدید از رباتِ بله صادر شد', 'staff');

        $this->sendButtons(
            '🧾 فاکتورِ تمدیدِ «'.$s->name.'» صادر شد.'
            ."\n".'مبلغ: '.fa_num(number_format((int) $inv->total)).' تومان'
            ."\n".'پس از پرداخت، سررسید یک دوره جلو می‌رود.',
            [[['text' => '🧾 دیدنِ فاکتور', 'data' => self::CB_PREFIX.'i:'.$inv->id]],
                $this->nav('s:'.$s->id)],
        );
    }

    /**
     * تلاشِ دوبارهٔ تحویل.
     *
     * ⚠️ فقط پرچم را به `pending` برمی‌گرداند و **خودش تماسی نمی‌گیرد**:
     * `createacct` تا ۱۸۰ ثانیه طول می‌کشد و مهلتِ وب‌هوکِ بله را رد می‌کند.
     * کرونِ `provision:run` همان دقیقه برش می‌دارد.
     */
    private function serviceRetry(string $arg): void
    {
        [$id, $fresh] = $this->unstamp($arg, 'sp');

        if (! $fresh) {
            $this->staleButton();

            return;
        }

        $s = Service::find($id);

        if ($s === null) {
            $this->replyToOwner('سرویس پیدا نشد.');

            return;
        }

        if (! in_array($s->provision_status, ['failed', 'manual'], true)) {
            $this->replyToOwner('این سرویس در صفِ تلاشِ دوباره نیست (وضعیت: '.$s->provision_status.').');

            return;
        }

        $s->forceFill(['provision_status' => 'pending', 'provision_error' => null])->save();

        $this->sendButtons(
            '🔁 «'.$s->name.'» به صفِ تحویل برگشت. کرون ظرفِ یک دقیقه سراغش می‌رود.',
            [$this->nav('s:'.$s->id)],
        );
    }

    /** لغوِ فاکتورِ پرداخت‌نشده — همان تعریفی که مشتری و کرون از آن استفاده می‌کنند */
    private function invoiceCancel(string $arg): void
    {
        [$id, $fresh] = $this->unstamp($arg, 'ic');

        if (! $fresh) {
            $this->staleButton();

            return;
        }

        $i = Invoice::find($id);

        if ($i === null) {
            $this->replyToOwner('فاکتور پیدا نشد.');

            return;
        }

        $ok = app(InvoiceCanceller::class)
            ->cancel($i, 'لغو توسط مدیر از رباتِ بله', rejectPendingReceipt: false);

        $this->sendButtons(
            $ok ? '⚪️ فاکتورِ '.$i->number.' لغو شد.'
                : '⚠️ لغو نشد — پول رویش حرکت کرده یا از قبل لغو شده.',
            [$this->nav($i->customer_id ? 'c:'.$i->customer_id : '')],
        );
    }

    private function ticketStatusMenu(string $arg): void
    {
        $t = Ticket::find((int) $arg);

        if ($t === null) {
            $this->replyToOwner('تیکت پیدا نشد.');

            return;
        }

        $this->sendButtons('🏷 وضعیت و اولویتِ '.$t->number, [
            [
                ['text' => '🟠 باز', 'data' => self::CB_PREFIX.'tps:'.$t->id.':s_open'],
                ['text' => '🟢 پاسخ‌داده', 'data' => self::CB_PREFIX.'tps:'.$t->id.':s_answered'],
            ],
            [
                ['text' => '🔺 زیاد', 'data' => self::CB_PREFIX.'tps:'.$t->id.':p_high'],
                ['text' => '🚨 فوری', 'data' => self::CB_PREFIX.'tps:'.$t->id.':p_urgent'],
            ],
            [
                ['text' => '▫️ معمولی', 'data' => self::CB_PREFIX.'tps:'.$t->id.':p_normal'],
                ['text' => '🔽 کم', 'data' => self::CB_PREFIX.'tps:'.$t->id.':p_low'],
            ],
            $this->nav('t:'.$t->id),
        ]);
    }

    /**
     * ⚠️ این یکی مهر ندارد و لازم هم ندارد: وضعیت و اولویتِ تیکت **هیچ پیامی به
     * مشتری نمی‌فرستد** و با یک تپ برمی‌گردد. مهر برای کارهایی است که مشتری
     * می‌بیندشان.
     */
    private function ticketStatusSet(string $arg): void
    {
        [$id, $what] = array_pad(explode(':', $arg, 2), 2, '');
        $t = Ticket::find((int) $id);

        if ($t === null || $what === '') {
            $this->replyToOwner('تیکت پیدا نشد.');

            return;
        }

        [$kind, $value] = array_pad(explode('_', $what, 2), 2, '');

        if ($kind === 's' && in_array($value, ['open', 'answered', 'closed'], true)) {
            $t->forceFill(['status' => $value, 'closed_at' => $value === 'closed' ? now() : null])->save();
        } elseif ($kind === 'p' && in_array($value, ['low', 'normal', 'high', 'urgent'], true)) {
            $t->forceFill(['priority' => $value])->save();
        } else {
            $this->replyToOwner('مقدارِ ناشناخته.');

            return;
        }

        $this->showTicket((string) $t->id);
    }

    // ─────────────────── فاز ۴: کارهای پولی ───────────────────

    /**
     * 🔴 تنها جای کنسول که **دو تپ** لازم دارد، و دلیلش بوروکراسی نیست.
     *
     * کارفرما تأییدِ اضافه را برای پاسخِ تیکت برداشت و حق داشت. ولی این‌جا
     * فرق دارد: تأییدِ رسید و خاتمهٔ سرویس **هیچ‌جای اپ «لغو» ندارند**، و روی
     * گوشی دکمه‌ها کنارِ هم‌اند. صفحهٔ دوم یک مرحلهٔ اداری نیست — همان‌جایی است
     * که **نام مشتری و مبلغ** نوشته می‌شود تا پیش از اجرا دیده شود.
     *
     * ⚠️ و تایپی در کار نیست: یک تپِ دیگر، نه یک کدِ شش‌رقمی.
     */
    private function receiptApproveAsk(string $arg): void
    {
        $r = BankTransferReceipt::find((int) $arg);

        if ($r === null || ! $r->isPending()) {
            $this->replyToOwner('رسید پیدا نشد یا قبلاً بررسی شده.');

            return;
        }

        $inv = $r->invoice;
        $due = $inv ? (int) $inv->due() : 0;

        $this->sendButtons(implode("\n", array_filter([
            '✅ تأییدِ واریز؟',
            '',
            $r->customer ? ('👤 '.$r->customer->displayName().' · '.$r->customer->code) : null,
            '💰 ادعای مشتری: '.fa_num(number_format((int) $r->amount)).' تومان',
            $inv ? ('🧾 تسویه می‌شود: '.fa_num(number_format($due)).' تومان — فاکتورِ '.$inv->number) : null,
            (int) $r->amount !== $due ? '⚠️ این دو یکی نیستند.' : null,
            '',
            'با تأیید، فاکتور تسویه و سرویس فعال می‌شود. برگشت ندارد.',
        ])), [
            [$this->stamped('✅ بله، تأیید کن', 'ray', $r->id)],
            [['text' => '✖️ انصراف', 'data' => self::CB_PREFIX.'r:'.$r->id]],
        ]);
    }

    private function receiptApproveDo(string $arg): void
    {
        [$id, $fresh] = $this->unstamp($arg, 'ray');

        if (! $fresh) {
            $this->staleButton();

            return;
        }

        $this->enqueue('receipt_approve', ['id' => $id], 'تأییدِ واریز');
    }

    /** ردِ رسید دلیل می‌خواهد — مشتری آن متن را می‌بیند */
    private function receiptRejectAsk(string $arg): void
    {
        $r = BankTransferReceipt::find((int) $arg);

        if ($r === null || ! $r->isPending()) {
            $this->replyToOwner('رسید پیدا نشد یا قبلاً بررسی شده.');

            return;
        }

        $this->gate->armFlow('reject:'.$r->id);

        $this->sendButtons(
            '⛔️ ردِ رسیدِ '.fa_num(number_format((int) $r->amount))." تومان\n\n"
            .'دلیلِ رد را بنویسید — مشتری همین متن را می‌بیند.',
            [[['text' => '✖️ انصراف', 'data' => self::CB_PREFIX.'fx']]],
        );
    }

    private function serviceTerminateAsk(string $arg): void
    {
        $s = Service::find((int) $arg);

        if ($s === null) {
            $this->replyToOwner('سرویس پیدا نشد.');

            return;
        }

        $this->sendButtons(implode("\n", array_filter([
            '🗑 خاتمهٔ سرویس؟',
            '',
            $s->customer ? ('👤 '.$s->customer->displayName().' · '.$s->customer->code) : null,
            '🖥 '.$s->name.($s->domain ? ' · '.$s->domain : ''),
            '',
            '⚠️ ماشین و همهٔ داده‌هایش نزدِ زیرساخت پاک می‌شود و صورت‌حساب بسته می‌شود.',
            'برگشت ندارد.',
        ])), [
            [$this->stamped('🗑 بله، «'.mb_substr((string) $s->name, 0, 20).'» را پاک کن', 'sxy', $s->id)],
            [['text' => '✖️ انصراف', 'data' => self::CB_PREFIX.'s:'.$s->id]],
        ]);
    }

    private function serviceTerminateDo(string $arg): void
    {
        [$id, $fresh] = $this->unstamp($arg, 'sxy');

        if (! $fresh) {
            $this->staleButton();

            return;
        }

        $this->enqueue('service_terminate', ['id' => $id], 'خاتمهٔ سرویس');
    }

    /**
     * کار را در صف بگذار و بلافاصله جواب بده.
     *
     * ⚠️ خودِ کار این‌جا اجرا **نمی‌شود**: تأییدِ رسید ممکن است سرور بخرد و
     * خاتمه با زیرساخت تماس می‌گیرد؛ هیچ‌کدام در مهلتِ وب‌هوکِ بله جا
     * نمی‌شوند، و ردشدن از آن مهلت یعنی بله آپدیت را دوباره می‌فرستد — یعنی
     * کارِ پولی **دو بار**.
     */
    private function enqueue(string $verb, array $args, string $human): void
    {
        if (! $this->gate->queueJob($verb, $args)) {
            $this->replyToOwner('⏳ کارِ دیگری در صف است. چند لحظه صبر کنید و دوباره بزنید.');

            return;
        }

        $this->replyToOwner('⏳ «'.$human."» در صف قرار گرفت.\nنتیجه را همین‌جا می‌فرستم (تا یک دقیقه).");
    }

    // ─────────────────── مشتریِ جدید (سه پرسش) ───────────────────

    /**
     * ⚠️ تا تپِ آخر **هیچ ردیفی نوشته نمی‌شود**. اگر کارفرما وسطِ کار رهایش
     * کند، جریان بعد از ۱۰ دقیقه خودش می‌میرد و پروندهٔ نیمه‌کاره‌ای نمی‌مانَد.
     */
    private function newCustomerStart(): void
    {
        $this->gate->armFlow('cn:name');

        $this->sendButtons(
            "🆕 مشتریِ جدید — گامِ ۱ از ۳\n\nنام و نامِ خانوادگی را بفرستید.",
            [[['text' => '✖️ انصراف', 'data' => self::CB_PREFIX.'fx']]],
        );
    }

    private function newCustomerStep(string $step, string $text): void
    {
        $data = $this->gate->flowData();

        if ($step === 'name') {
            if (mb_strlen(trim($text)) < 3) {
                $this->replyToOwner('نام خیلی کوتاه است. دوباره بفرستید.');

                return;
            }

            $this->gate->armFlow('cn:mobile', ['name' => mb_substr(trim($text), 0, 120)]);

            $this->sendButtons(
                "🆕 گامِ ۲ از ۳\n\nشمارهٔ موبایل را بفرستید (۰۹…).",
                [[['text' => '✖️ انصراف', 'data' => self::CB_PREFIX.'fx']]],
            );

            return;
        }

        if ($step === 'mobile') {
            // ⚠️ همان نرمال‌سازیِ ثبت‌نام (`09…`)، نه نسخهٔ دوم — دلیلش در
            //    `QuickCustomerCreator` نوشته شده.
            $mobile = app(OtpService::class)->normalize('sms', $text) ?: null;

            if ($mobile === null) {
                $this->replyToOwner('شماره معتبر نیست. مثلِ ۰۹۱۲۳۴۵۶۷۸۹ بفرستید.');

                return;
            }

            $this->gate->armFlow('cn:email', $data + ['mobile' => $mobile]);

            $this->sendButtons(
                "🆕 گامِ ۳ از ۳\n\nایمیل را بفرستید — یا اگر ندارد دکمهٔ زیر را بزنید.",
                [
                    [['text' => '⏭ ایمیل ندارد', 'data' => self::CB_PREFIX.'cne']],
                    [['text' => '✖️ انصراف', 'data' => self::CB_PREFIX.'fx']],
                ],
            );

            return;
        }

        $this->newCustomerFinish(trim($text));
    }

    private function newCustomerFinish(?string $email): void
    {
        $data = $this->gate->flowData();
        $this->gate->clearFlow();

        if (($data['name'] ?? '') === '' || ($data['mobile'] ?? '') === '') {
            $this->replyToOwner('⌛️ این جریان منقضی شده. از «مشتریِ جدید» دوباره شروع کنید.');

            return;
        }

        $res = app(QuickCustomerCreator::class)->create(
            (string) $data['name'], (string) $data['mobile'], $email,
        );

        if (! $res['ok'] || $res['customer'] === null) {
            $this->replyToOwner('⚠️ '.$res['message']);

            return;
        }

        $c = $res['customer'];

        /*
        | فقط ساختِ واقعی لاگ می‌خورد. بازشدنِ پروندهٔ موجود هیچ‌چیز را عوض
        | نکرده و ثبتش فقط تاریخچهٔ مشتری را شلوغ می‌کند.
        */
        if (! $res['existing']) {
            ActivityLog::record($c->id, 'register',
                'مشتری از رباتِ بله توسط مدیر ثبت شد — بدونِ احرازِ هویت',
                null, 'staff');
        }

        $this->sendButtons(
            ($res['existing'] ? 'ℹ️ ' : '✅ ').$res['message']
            ."\n\n👤 ".$c->displayName().' · '.$c->code
            .($res['existing'] ? '' : "\n⚠️ این حساب **احراز نشده** است و رمز ندارد؛"
                .' مشتری از «فراموشی رمز» خودش وارد می‌شود.'),
            [
                [['text' => '🛒 فروشِ سرویس', 'data' => self::CB_PREFIX.'sell:'.$c->id]],
                [['text' => '👤 پرونده', 'data' => self::CB_PREFIX.'c:'.$c->id]],
                $this->nav('cm'),
            ],
        );
    }

    // ───────────────────────── فروشِ تلفنی ─────────────────────────

    /*
    | 🔴 قیمت در هیچ گامی تایپ نمی‌شود — فقط از کاتالوگ می‌آید.
    |
    | دلیلِ کاملش در `App\Services\Sales\PhoneSale` است. خلاصه‌اش: عددی که
    | این‌جا ثبت شود، تا ابد هر دوره صورت‌حساب می‌شود و هیچ سقفِ منطقی ندارد.
    |
    | ⚠️ گام‌ها در `flowData` جمع می‌شوند نه در `callback_data`: سقفِ ۶۴ بایت
    | جای پکیج و مکان و دوره و دامنه را با هم ندارد.
    */

    private function sellStart(int $customerId): void
    {
        $c = Customer::find($customerId);

        if ($c === null) {
            $this->replyToOwner('مشتری پیدا نشد.');

            return;
        }

        $products = Schema::hasTable('products')
            ? Product::where('is_active', true)
                ->orderBy('sort')->orderBy('name')->limit(AdminBaleScreens::PAGE)->get()
            : collect();

        if ($products->isEmpty()) {
            $this->replyToOwner('پکیجِ فعالی در کاتالوگ نیست. اول از /admin/products اضافه کنید.');

            return;
        }

        $this->gate->armFlow('sell:product', ['cid' => $c->id]);

        $rows = $products->map(fn ($p) => [[
            'text' => '📦 '.mb_substr((string) $p->name, 0, 30),
            'data' => self::CB_PREFIX.'sep:'.$p->id,
        ]])->all();

        $rows[] = [['text' => '✖️ انصراف', 'data' => self::CB_PREFIX.'fx']];

        $this->sendButtons('🛒 فروش به '.$c->displayName()."\n\nپکیج را انتخاب کنید.", $rows);
    }

    private function sellPickedProduct(int $productId): void
    {
        $data = $this->gate->flowData();
        $p = Product::find($productId);

        if (($data['cid'] ?? 0) === 0 || $p === null) {
            $this->staleFlow();

            return;
        }

        $data['pid'] = $p->id;

        /*
        | مکان فقط وقتی پرسیده می‌شود که واقعاً انتخابی در کار باشد. پکیجی که
        | یک مکان دارد یا اصلاً مکان‌محور نیست، یک تپِ بی‌معنی اضافه می‌کرد.
        */
        $countries = $p->availableCountries();

        if (count($countries) > 1) {
            $this->gate->armFlow('sell:country', $data);

            $rows = array_map(fn ($code) => [[
                'text' => trim((string) (config('billing.locations.'.$code.'.flag') ?? ''))
                    .' '.(config('billing.locations.'.$code.'.label.fa') ?? $code),
                'data' => self::CB_PREFIX.'seo:'.$code,
            ]], $countries);

            $rows[] = [['text' => '✖️ انصراف', 'data' => self::CB_PREFIX.'fx']];

            $this->sendButtons('📦 «'.$p->name."»\n\nمحلِ سرور را انتخاب کنید.", $rows);

            return;
        }

        $data['country'] = $countries[0] ?? null;

        $this->askCycle($data, $p);
    }

    private function sellPickedCountry(string $code): void
    {
        $data = $this->gate->flowData();
        $p = Product::find((int) ($data['pid'] ?? 0));

        if ($p === null) {
            $this->staleFlow();

            return;
        }

        // ⚠️ فقط از فهرستِ خودِ پکیج — نه هر کدی که در دکمه آمده
        if (! in_array($code, $p->availableCountries(), true)) {
            $this->replyToOwner('این مکان دیگر موجود نیست. از اول انتخاب کنید.');

            return;
        }

        $data['country'] = $code;

        $this->askCycle($data, $p);
    }

    private function askCycle(array $data, Product $p): void
    {
        $this->gate->armFlow('sell:cycle', $data);

        $country = $data['country'] ?? null;

        $rows = array_map(fn ($cycle) => [[
            'text' => Service::labelFor($cycle).' · '
                .fa_num(number_format($p->priceForCycle($cycle, $country))).' ت',
            'data' => self::CB_PREFIX.'sec:'.$cycle,
        ]], Service::cycles());

        $rows[] = [['text' => '✖️ انصراف', 'data' => self::CB_PREFIX.'fx']];

        $this->sendButtons('📦 «'.$p->name."»\n\nدورهٔ پرداخت را انتخاب کنید.", $rows);
    }

    private function sellPickedCycle(string $cycle): void
    {
        $data = $this->gate->flowData();
        $p = Product::find((int) ($data['pid'] ?? 0));

        if ($p === null || ! in_array($cycle, Service::cycles(), true)) {
            $this->staleFlow();

            return;
        }

        $data['cycle'] = $cycle;

        /*
        | 🔴 پکیجی که دامنه می‌خواهد، بی‌دامنه تحویل نمی‌شود.
        |
        | بی‌این گام، فروش ثبت می‌شد، مشتری پول می‌داد و `createacct` روی
        | دامنهٔ خالی شکست می‌خورد — یعنی همان «پول گرفته، سرور نیامده» که
        | یک بار رخ داد.
        */
        if ($p->requires_domain) {
            $this->gate->armFlow('sell:domain', $data);

            $this->sendButtons(
                "🌐 دامنهٔ این سرویس را بفرستید (مثلاً example.com).\n"
                .'اگر دامنه ندارد، زیردامنهٔ رایگانِ سرورنت را بزنید.',
                [
                    [['text' => '🆓 زیردامنهٔ رایگان', 'data' => self::CB_PREFIX.'sed']],
                    [['text' => '✖️ انصراف', 'data' => self::CB_PREFIX.'fx']],
                ],
            );

            return;
        }

        $this->sellRecap($data);
    }

    private function sellGotDomain(string $text): void
    {
        $domain = strtolower(trim($this->anchor->asciiDigits($text)));
        $domain = ltrim(preg_replace('#^https?://#i', '', $domain) ?? $domain, '/');
        $domain = rtrim(explode('/', $domain)[0], '.');

        if (! preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain)) {
            $this->replyToOwner('دامنه معتبر نیست. مثلِ example.com بفرستید.');

            return;
        }

        $data = $this->gate->flowData();
        $data['domain'] = $domain;

        $this->sellRecap($data);
    }

    /** زیردامنهٔ رایگان — از کدِ مشتری ساخته می‌شود، پس تکراری نمی‌شود */
    private function sellFreeSubdomain(): void
    {
        $data = $this->gate->flowData();
        $c = Customer::find((int) ($data['cid'] ?? 0));

        if ($c === null) {
            $this->staleFlow();

            return;
        }

        $zone = (string) config('servernet.subdomain_zone', 'servernet.cloud');
        $label = strtolower(str_replace('-', '', (string) $c->code));

        for ($i = 0; $i < 20; $i++) {
            $try = $label.($i === 0 ? '' : $i);

            if (! Service::where('domain', $try.'.'.$zone)
                ->whereNotIn('status', Service::DEAD_STATUSES)->exists()) {
                $data['domain'] = $try.'.'.$zone;

                $this->sellRecap($data);

                return;
            }
        }

        $this->replyToOwner('زیردامنهٔ آزادی برای این مشتری ساخته نشد؛ دامنه را دستی بفرستید.');
    }

    /** آخرین صفحه پیش از پول — همه‌چیز پیشِ چشم، بعد یک تپ */
    private function sellRecap(array $data): void
    {
        $c = Customer::find((int) ($data['cid'] ?? 0));
        $p = Product::find((int) ($data['pid'] ?? 0));
        $cycle = (string) ($data['cycle'] ?? '');

        if ($c === null || $p === null || $cycle === '') {
            $this->staleFlow();

            return;
        }

        $this->gate->armFlow('sell:confirm', $data);

        $country = $data['country'] ?? null;
        $price = $p->priceForCycle($cycle, $country);
        $setup = $p->effectiveSetup();
        $tax = (int) round(($price + $setup) * (int) $p->tax_percent / 100);

        $key = 'sey:'.$c->id.':'.$p->id.':'.$cycle;

        $this->sendButtons(
            implode("\n", array_filter([
                '🧾 پیش از ثبت، یک بار بخوانید:',
                '',
                '👤 '.$c->displayName().' · '.$c->code,
                '📦 '.$p->name.' · '.Service::labelFor($cycle),
                $country ? ('📍 '.(config('billing.locations.'.$country.'.label.fa') ?? $country)) : null,
                ($data['domain'] ?? null) ? ('🌐 '.$data['domain']) : null,
                '',
                'مبلغِ دوره: '.fa_num(number_format($price)).' تومان',
                $setup > 0 ? ('راه‌اندازی: '.fa_num(number_format($setup)).' تومان') : null,
                $tax > 0 ? ('مالیات: '.fa_num(number_format($tax)).' تومان') : null,
                '💰 جمعِ فاکتور: '.fa_num(number_format($price + $setup + $tax)).' تومان',
                '',
                '⚠️ سرویس پس از **پرداخت** تحویل می‌شود، نه حالا.',
            ])),
            [
                [['text' => '✅ ثبت و صدورِ پیش‌فاکتور',
                    'data' => self::CB_PREFIX.'sey:'.$this->gate->stamp($key)]],
                [['text' => '✖️ انصراف', 'data' => self::CB_PREFIX.'fx']],
            ],
        );
    }

    private function sellConfirm(string $stamp): void
    {
        $data = $this->gate->flowData();

        $c = Customer::find((int) ($data['cid'] ?? 0));
        $p = Product::find((int) ($data['pid'] ?? 0));
        $cycle = (string) ($data['cycle'] ?? '');

        if ($c === null || $p === null || $cycle === '') {
            $this->staleFlow();

            return;
        }

        /*
        | مهر روی **همان سه چیزی** است که در بازبینی نشان داده شد. یعنی اگر
        | جریان بینِ نمایش و تپ عوض شده باشد (کارفرما وسطش فروشِ دیگری شروع
        | کرده)، این دکمه دیگر نمی‌خوانَد و اجرا نمی‌شود.
        */
        if (! $this->gate->verifyStamp('sey:'.$c->id.':'.$p->id.':'.$cycle, $stamp)) {
            $this->staleButton();

            return;
        }

        // 🔴 پیش از فروش مصرف می‌شود: تپِ دوم دیگر جریانی برای اجرا ندارد
        $this->gate->clearFlow();

        $res = app(PhoneSale::class)->sell(
            $c, $p, $cycle,
            $data['country'] ?? null,
            $data['domain'] ?? null,
            $this->gate->boundUser()?->id,
            $this->gate->boundUser()?->name,
        );

        if (! $res['ok'] || $res['invoice'] === null) {
            $this->replyToOwner('⚠️ '.$res['message']);

            return;
        }

        $inv = $res['invoice'];

        $this->sendButtons(
            "✅ فروش ثبت و پیش‌فاکتور صادر شد.\n\n"
            .'👤 '.$c->displayName().' · '.$c->code."\n"
            .'📦 '.$p->name.' · '.Service::labelFor($cycle)."\n"
            .'💰 '.fa_num(number_format((int) $inv->total)).' تومان',
            [
                [['text' => '🧾 دیدنِ فاکتور', 'data' => self::CB_PREFIX.'i:'.$inv->id]],
                $this->nav('c:'.$c->id),
            ],
        );
    }

    private function staleFlow(): void
    {
        $this->gate->clearFlow();

        $this->replyToOwner(
            "⌛️ این جریان منقضی شده یا نیمه‌کاره ماند.\n"
            .'برای امنیت اجرا نشد — از پروندهٔ مشتری دوباره شروع کنید.'
        );
    }

    // ─────────────────── خلاصهٔ هوشمندِ مشتری ───────────────────

    /** فقط **خلاصه**: هیچ کاری نمی‌کند و هیچ‌چیز به مشتری نمی‌رود */
    private function customerBrief(int $id): void
    {
        $c = Customer::find($id);

        if ($c === null) {
            $this->replyToOwner('مشتری پیدا نشد.');

            return;
        }

        $text = app(CustomerBriefWriter::class)->brief($c);

        $this->sendButtons(
            $text !== null
                ? "🧠 خلاصهٔ پرونده\n\n".$text
                : 'خلاصه ساخته نشد (مدل جواب نداد). خودِ پرونده را ببینید.',
            [$this->nav('c:'.$c->id)],
        );
    }

    // ─────────────────── صندوقِ ایمیل ───────────────────

    private function showMailbox(): void
    {
        if (! Schema::hasTable('mailbox_messages')) {
            $this->replyToOwner('صندوقِ ایمیل روی این نصب فعال نیست.');

            return;
        }

        $rows = MailboxMessage::open()->where('needs_reply', true)
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
        $m = Schema::hasTable('mailbox_messages')
            ? MailboxMessage::find((int) $arg) : null;

        if ($m === null) {
            $this->replyToOwner('ایمیل پیدا نشد.');

            return;
        }

        /*
        | ⚠️ متن از `snippet` می‌آید. این‌جا قبلاً `body_text` خوانده می‌شد —
        | ستونی که **در این جدول وجود ندارد**؛ Eloquent برای صفتِ ناموجود null
        | می‌دهد، پس کارت بی‌هیچ خطایی همیشه بی‌متن بود.
        */
        $text = implode("\n", array_filter([
            '📧 '.mb_substr((string) $m->subject, 0, 120),
            'از: '.mb_substr((string) $m->from_email, 0, 90),
            $m->summary ? ('خلاصه: '.$m->summary) : null,
            '',
            mb_substr(trim((string) $m->snippet), 0, 2200),
        ]));

        $this->sendButtons($text, [
            [['text' => '✍️ پیش‌نویسِ پاسخ', 'data' => self::CB_PREFIX.'me:'.$m->id]],
            [['text' => '📥 بایگانی', 'data' => self::CB_PREFIX.'ma:'.$m->id]],
            $this->nav('m'),
        ]);
    }

    /**
     * پیش‌نویسِ پاسخِ ایمیل — **فقط متن**.
     *
     * ⚠️ دکمهٔ «ارسال» عمداً نیست و نبودش یک تصمیمِ محافظه‌کارانه نیست:
     * صندوق فقط با IMAP خوانده می‌شود و هیچ مسیرِ ارسالی در اپ وجود ندارد.
     * پس متن برای کپی‌کردن در برنامهٔ ایمیلِ خودِ کارفرما ساخته می‌شود، و
     * پیام هم همین را می‌گوید تا کسی منتظرِ ارسالِ خودکار نمانَد.
     */
    private function mailDraft(string $arg): void
    {
        $m = Schema::hasTable('mailbox_messages')
            ? MailboxMessage::find((int) $arg) : null;

        if ($m === null) {
            $this->replyToOwner('ایمیل پیدا نشد.');

            return;
        }

        $draft = app(MailReplyDraftWriter::class)->draft($m);

        if ($draft === null) {
            $this->sendButtons(
                'پیش‌نویسی ساخته نشد — یا مدل جواب نداد، یا این نامه پاسخ نمی‌خواهد.',
                [
                    [['text' => '✏️ خودم می‌نویسم', 'data' => self::CB_PREFIX.'mew:'.$m->id]],
                    $this->nav('mv:'.$m->id),
                ],
            );

            return;
        }

        $this->gate->putMailDraft($m->id, $draft);

        $this->sendButtons(
            '✍️ پیش‌نویسِ پاسخ به «'.mb_substr((string) $m->subject, 0, 60)."»\n"
            ."(از روی چکیدهٔ نامه — بدنهٔ کامل ذخیره نمی‌شود)\n\n"
            .$draft
            ."\n\n⚠️ هنوز چیزی نرفته.",
            [
                [['text' => '📤 ارسالِ پاسخ', 'data' => self::CB_PREFIX.'mes:'.$m->id]],
                [['text' => '✏️ خودم می‌نویسم', 'data' => self::CB_PREFIX.'mew:'.$m->id]],
                [['text' => '📥 بایگانی بی‌پاسخ', 'data' => self::CB_PREFIX.'ma:'.$m->id]],
                $this->nav('mv:'.$m->id),
            ],
        );
    }

    /** «خودم می‌نویسم» — متنِ آزاد به‌جای پیش‌نویسِ مدل */
    private function mailWriteMyself(string $arg): void
    {
        $m = Schema::hasTable('mailbox_messages')
            ? MailboxMessage::find((int) $arg) : null;

        if ($m === null) {
            $this->replyToOwner('ایمیل پیدا نشد.');

            return;
        }

        $this->gate->armFlow('mailreply:'.$m->id);

        $this->sendButtons(
            '✏️ متنِ پاسخ به «'.mb_substr((string) $m->subject, 0, 60)."» را بفرستید.\n"
            .'گیرنده: '.mb_substr((string) $m->from_email, 0, 90)."\n"
            .'(۱۰ دقیقه فرصت دارید)',
            [[['text' => '✖️ انصراف', 'data' => self::CB_PREFIX.'fx']]],
        );
    }

    /** ارسالِ پیش‌نویسِ ذخیره‌شده */
    private function mailSend(string $arg): void
    {
        $id = (int) $arg;
        $draft = $this->gate->takeMailDraft($id);

        if ($draft === null) {
            $this->replyToOwner('پیش‌نویس پیدا نشد یا منقضی شده. دوباره بسازید.');

            return;
        }

        $this->mailQueue($id, $draft);
    }

    /**
     * 🔴 ارسال **در صف** می‌رود، نه داخلِ وب‌هوک.
     *
     * دو دلیل، و هر دو یک بار در همین پروژه گاز گرفته‌اند:
     *
     *   • یک اتصالِ SMTP می‌تواند ده‌ها ثانیه طول بکشد. مهلتِ وب‌هوکِ بله که
     *     تمام شود، بله **همان آپدیت را دوباره می‌فرستد** — یعنی نامه دو بار
     *     برای مشتری می‌رود، و ایمیل برگشت‌پذیر نیست.
     *   • همان قاعدهٔ فاز ۴: هر کارِ برگشت‌ناپذیر از `bale:work` می‌رود.
     *
     * ⚠️ نتیجه تا یک دقیقه بعد در همان چت گزارش می‌شود و متن همین را می‌گوید،
     * تا سکوتِ یک‌دقیقه‌ای «نرفت» خوانده نشود و کارفرما دوباره نزند.
     */
    private function mailQueue(int $mailId, string $body): void
    {
        $m = Schema::hasTable('mailbox_messages')
            ? MailboxMessage::find($mailId) : null;

        if ($m === null) {
            $this->replyToOwner('ایمیل پیدا نشد.');

            return;
        }

        $body = trim($body);

        if (mb_strlen($body) < 2) {
            $this->replyToOwner('متنِ پاسخ خالی است.');

            return;
        }

        $this->enqueue('mail_reply',
            ['id' => $m->id, 'body' => mb_substr($body, 0, 3000)],
            'پاسخ به '.mb_substr((string) $m->from_email, 0, 60));
    }

    /** ⚠️ نامه **حذف نمی‌شود** — فقط از صفِ «منتظرِ پاسخ» بیرون می‌رود */
    private function archiveMail(string $arg): void
    {
        try {
            $m = MailboxMessage::find((int) $arg);

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
        $pos = preg_match('/\s/u', $text, $m, PREG_OFFSET_CAPTURE) === 1 ? $m[0][1] : false;

        if ($pos === false) {
            return [mb_strtolower($text), ''];
        }

        return [mb_strtolower(substr($text, 0, $pos)), ltrim(substr($text, $pos))];
    }
}

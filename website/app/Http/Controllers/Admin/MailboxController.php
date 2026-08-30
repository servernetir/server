<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CalendarEvent;
use App\Models\MailboxMessage;
use App\Services\Mail\MailboxReader;
use App\Services\Mail\MailboxReplier;
use App\Services\Mail\MailHtmlSanitizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * صندوق‌های مدیریتی در پنل.
 *
 * ⚠️ **این متن تا مرداد ۱۴۰۵ می‌گفت «کلاینتِ ایمیل نیست و نباید بشود».**
 * کارفرما صریح خواست که بشود: خواندنِ کاملِ نامه و پاسخ از داخلِ پنل. تصمیم
 * عوض شد، پس این متن هم عوض شد — کامنتی که با کد نخوانَد، بدتر از نبودنش است.
 *
 * 🔴 ولی **یک نیمهٔ آن تصمیم سرِ جایش ماند**: متنِ کاملِ نامه هنوز ذخیره
 * نمی‌شود. `MailboxReader` هر بار در لحظه از IMAP می‌خوانَد. دلیلش در
 * مهاجرتِ `mailbox_messages` نوشته شده و با این قابلیت عوض نشده: صندوقِ
 * support@ پر از دادهٔ مشتری است و کپی‌اش در دیتابیس یعنی همان داده در هر
 * بکاپ و هر دامپِ عیب‌یابی.
 *
 * ⚠️ پس فهرست بی‌IMAP کار می‌کند ولی **باز کردنِ نامه نه**. این عمدی است.
 */
class MailboxController extends Controller
{
    /** سقفِ پیوستِ پاسخ. اعداد از سقفِ عملیِ سرورهای ایمیل می‌آیند، نه از سلیقه. */
    private const MAX_FILES = 5;

    private const MAX_FILE_KB = 6144;      // ۶ مگابایت برای هر فایل

    private const MAX_TOTAL_KB = 10240;    // ۱۰ مگابایت روی هم

    /**
     * پسوندهایی که سرویس‌های ایمیل رد می‌کنند.
     *
     * ⚠️ این فهرست از مستنداتِ جیمیل و اوت‌لوک آمده، نه از تصورِ ما. نامه‌ای
     * با این پیوست‌ها اغلب **کامل** رد می‌شود، نه فقط پیوستش.
     */
    private const BLOCKED_EXTENSIONS = [
        'exe', 'bat', 'cmd', 'com', 'cpl', 'dll', 'js', 'jse', 'jar', 'lnk',
        'msi', 'msc', 'pif', 'ps1', 'reg', 'scr', 'sct', 'vb', 'vbe', 'vbs', 'wsf', 'wsh',
    ];

    /** برچسبِ فارسیِ همان گزینه‌ها — کلیدها باید با `REMINDER_PRESETS` یکی بمانند. */
    private const REMINDER_LABELS = [
        'tomorrow'   => 'فردا',
        'three_days' => '۳ روز دیگر',
        'next_week'  => 'هفتهٔ آینده',
        'two_weeks'  => 'دو هفتهٔ دیگر',
    ];

    /** گزینه‌های یادآوری → چند روز بعد. عمداً کوتاه: انتخابِ زیاد یعنی انتخاب‌نکردن. */
    private const REMINDER_PRESETS = [
        'tomorrow'  => 1,
        'three_days' => 3,
        'next_week' => 7,
        'two_weeks' => 14,
    ];

    public function index(Request $request): View
    {
        if (! Schema::hasTable('mailbox_messages')) {
            return view('admin.mail', ['notReady' => true]);
        }

        $account = (string) $request->query('box', '');
        $filter = (string) $request->query('show', 'open');

        // فیلترِ فرستنده: از صفحهٔ نامه می‌آید («۷ نامهٔ دیگر از این فرستنده»)
        $from = trim((string) $request->query('from', ''));

        $messages = MailboxMessage::query()
            ->when($account !== '', fn ($q) => $q->where('account', $account))
            ->when($from !== '', fn ($q) => $q->where('from_email', $from))
            ->when($filter === 'open', fn ($q) => $q->open())
            ->when($filter === 'reply', fn ($q) => $q->open()->where('needs_reply', true))
            ->when($filter === 'system', fn ($q) => $q->where('is_system', true))
            ->orderByDesc('received_at')
            ->limit(150)
            ->get();

        // نوارِ بالا: به ازای هر صندوق، «باز» و «نیازمندِ جواب»
        $boxes = [];

        foreach ((array) config('mailboxes.accounts', []) as $a) {
            $boxes[] = [
                'key'   => $a['key'],
                'label' => $a['label'],
                'user'  => $a['user'],
                'open'  => MailboxMessage::open()->where('account', $a['key'])->count(),
                'reply' => MailboxMessage::open()->where('account', $a['key'])->where('needs_reply', true)->count(),
                'last'  => MailboxMessage::where('account', $a['key'])->max('received_at'),
            ];
        }

        /*
        | 🔴 «هیچ نامهٔ تازه‌ای نیست» و «صندوق اصلاً خوانده نمی‌شود» روی این صفحه
        | تا امروز **یک شکل** داشتند.
        |
        | شکستِ IMAP فقط یک خط در `laravel.log` می‌گذاشت — لاگی که روی پروداکشن
        | ۱۰ مگابایت است و از پنل بیرون نمی‌آید. پس متنِ واقعیِ خطا این‌جا آورده
        | می‌شود، نه یک «خطایی رخ داد».
        */
        $labels = collect($boxes)->pluck('label', 'key')->all();

        $syncErrors = collect(\App\Services\Mail\MailboxSync::state())
            ->filter(fn ($s) => ($s['ok'] ?? true) === false)
            ->map(fn ($s, $key) => [
                'label' => $labels[$key] ?? $key,
                'error' => (string) ($s['error'] ?? '—'),
            ])->values()->all();

        return view('admin.mail', [
            'notReady'   => false,
            'configured' => $boxes !== [],
            'boxes'      => $boxes,
            'syncErrors' => $syncErrors,
            'messages'   => $messages,
            'account'    => $account,
            'filter'     => $filter,
            'from'       => $from,
            'systemSeen' => MailboxMessage::where('is_system', true)->count(),
            'pending'    => MailboxMessage::unreported()->whereNull('category')->count(),
        ]);
    }

    /**
     * خواندنِ کاملِ یک نامه.
     *
     * 🔴 **تصویرهای بیرونی پیش‌فرض بسته‌اند** و `?images=1` بازشان می‌کند.
     * یک `<img>` در نامه، در لحظهٔ باز شدن به فرستنده می‌گوید نشانی زنده است و
     * خوانده شد — برای اسپمر تأییدِ طلا و برای ما موجِ بعدیِ اسپم. انتخاب دستِ
     * کاربر است، ولی پیش‌فرض باید امن باشد نه راحت.
     */
    public function show(MailboxMessage $message, Request $request): View
    {
        $images = $request->boolean('images');
        $blocker = $this->replyBlocker($message);
        $res = app(MailboxReader::class)->read($message);

        /*
        | ناوبری: «بعدی» یعنی قدیمی‌تر، چون فهرست از تازه به کهنه مرتب است.
        |
        | ⚠️ `id` هم در مرتب‌سازی هست: دو نامه می‌توانند دقیقاً یک ثانیه
        | `received_at` داشته باشند (ارسالِ انبوه)، و بدونِ شکستنِ تساوی،
        | «بعدی» بینِ همان دو تا می‌رفت و برمی‌گشت — حلقه‌ای که کاربر گیرش
        | می‌افتد و هیچ خطایی هم نیست.
        */
        $sameBox = fn () => MailboxMessage::where('account', $message->account);

        $older = (clone $sameBox())
            ->where(fn ($q) => $q->where('received_at', '<', $message->received_at)
                ->orWhere(fn ($x) => $x->where('received_at', $message->received_at)->where('id', '<', $message->id)))
            ->orderByDesc('received_at')->orderByDesc('id')->first();

        $newer = (clone $sameBox())
            ->where(fn ($q) => $q->where('received_at', '>', $message->received_at)
                ->orWhere(fn ($x) => $x->where('received_at', $message->received_at)->where('id', '>', $message->id)))
            ->orderBy('received_at')->orderBy('id')->first();

        $fromCount = filled($message->from_email)
            ? MailboxMessage::where('from_email', $message->from_email)->where('id', '!=', $message->id)->count()
            : 0;

        $html = '';
        $text = '';
        $blocked = 0;

        if ($res['ok']) {
            $mail = $res['mail'];

            if (trim((string) $mail['html']) !== '') {
                $clean = MailHtmlSanitizer::clean(
                    (string) $mail['html'],
                    $images,
                    fn (string $cid): ?string => $this->cidUrl($message, $mail['attachments'], $cid),
                );

                $html = $clean['html'];
                $blocked = $clean['blocked'];
            }

            $text = (string) $mail['text'];
        }

        return view('admin.mail-message', [
            'm'          => $message,
            'ok'         => $res['ok'],
            'error'      => $res['message'],
            'mail'       => $res['mail'],
            'html'       => $html,
            'text'       => $text,
            'blocked'    => $blocked,
            'images'     => $images,
            'truncated'  => $res['truncated'],
            'size'       => $res['size'],
            'canReply'   => $blocker === null,
            'replyBlock' => $blocker,
            'older'      => $older,
            'newer'      => $newer,
            'fromCount'  => $fromCount,
            'reminders'  => self::REMINDER_LABELS,
        ]);
    }

    /**
     * دانلودِ یک پیوست.
     *
     * 🔴 **همیشه `attachment`، هرگز `inline`** — مگر تصویری که خودِ نامه
     * درون‌خط نشانش می‌دهد و کاربر تصاویر را باز کرده. فایلِ فرستندهٔ ناشناس
     * که روی دامنهٔ پنل `inline` سرو شود، یک HTMLِ ساده هم کافی است تا
     * نشستِ مدیر را بردارد. `nosniff` هم لازم است چون بدونش مرورگر نوعِ
     * اعلامی را نادیده می‌گیرد و خودش حدس می‌زند.
     */
    public function attachment(MailboxMessage $message, int $index, Request $request): Response
    {
        $res = app(MailboxReader::class)->attachment($message, $index);

        abort_if(! $res['ok'], 404, $res['message']);

        $a = $res['attachment'];
        $inline = $request->boolean('inline') && MailHtmlSanitizer::isDisplayableImage((string) $a['mime']);

        return response((string) $a['data'], 200, [
            /*
            | ⚠️ نوعِ اعلامیِ خودِ نامه فقط وقتی پذیرفته می‌شود که تصویرِ
            | درون‌خطی باشد و از فهرستِ کوتاهِ تصاویر رد شده باشد. در حالتِ
            | دانلود، نوع را عمداً خنثی می‌گذاریم تا مرورگر وسوسه نشود بازش
            | کند.
            */
            'Content-Type'           => $inline ? (string) $a['mime'] : 'application/octet-stream',
            'Content-Disposition'    => ($inline ? 'inline' : 'attachment')
                                        .'; filename="'.$this->safeName((string) $a['name']).'"',
            'Content-Length'         => (string) strlen((string) $a['data']),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control'          => 'private, no-store',
        ]);
    }

    /**
     * پاسخ — روی همان `MailboxReplier`ی که کنسولِ بله استفاده می‌کند.
     *
     * 🔴 سرویسِ دومی نوشته نمی‌شود. آن کلاس تصمیم‌های گران‌قیمتی در خود دارد
     * (ارسال از SMTPِ همان صندوق، ردِ صریح به‌جای سقوطِ بی‌صدا، علامت‌زدن فقط
     * پس از ارسالِ موفق). نسخهٔ موازیِ پنل یعنی روزی یکی‌شان اصلاح شود و
     * دیگری همان باگ را نگه دارد.
     */
    public function reply(MailboxMessage $message, Request $request): RedirectResponse
    {
        $data = $request->validate([
            'body'  => ['required', 'string', 'min:2', 'max:60000'],
            'files' => ['nullable', 'array', 'max:'.self::MAX_FILES],
            'files.*' => ['file', 'max:'.self::MAX_FILE_KB],
        ], [], [
            'body' => 'متنِ پاسخ', 'files' => 'پیوست‌ها', 'files.*' => 'پیوست',
        ]);

        /*
        | 🔴 متنِ ساده از خودِ HTML ساخته می‌شود، نه از یک فیلدِ دوم.
        |
        | اگر کاربر دو کادر داشت، دیر یا زود یکی‌شان کهنه می‌مانْد و نیمی از
        | گیرنده‌ها نسخهٔ قدیمیِ حرف را می‌خواندند — خرابی‌ای که هیچ خطایی
        | نمی‌سازد و فقط طرفِ مقابل می‌بیندش.
        */
        $html = (string) $data['body'];
        $text = MailHtmlSanitizer::toText($html);

        if (trim($text) === '') {
            return back()->withInput()->withErrors(['body' => 'متنِ پاسخ خالی است.']);
        }

        $files = $this->collectAttachments($request);

        if (is_string($files)) {
            return back()->withInput()->withErrors(['files' => $files]);
        }

        $user = $request->user();

        $res = app(MailboxReplier::class)->reply($message, $text, $user?->id, $user?->name, [
            'html'        => $html,
            'attachments' => $files,
        ]);

        return back()->with($res['ok'] ? 'ok' : 'err', $res['message']);
    }

    /**
     * پیوست‌های فرمِ پاسخ → آرایه‌ای که `MailboxReplier` می‌فهمد، یا یک پیامِ خطا.
     *
     * 🔴 پسوندهای اجرایی رد می‌شوند. نه به‌خاطرِ **ما** — فایل از دستِ خودِ
     * مدیر می‌آید — بلکه به‌خاطرِ **گیرنده**: نامه‌ای با `.exe` یا `.js` را
     * جیمیل و اوت‌لوک یا کامل رد می‌کنند یا مستقیم به اسپم می‌برند، و آن‌وقت
     * کلِ پاسخ گم می‌شود نه فقط پیوستش.
     *
     * ⚠️ سقفِ **جمع** جدا از سقفِ تک‌فایل است: پنج فایلِ ۲ مگابایتی از
     * اعتبارسنجیِ `max:` رد می‌شوند ولی با هم از سقفِ پیوستِ اکثرِ سرورها
     * بزرگ‌ترند — نامه‌ای که سرور پس بزند، برای فرستنده شبیهِ «رفت» است.
     *
     * @return list<array{name:string, mime:string, data:string}>|string
     */
    private function collectAttachments(Request $request)
    {
        $files = $request->file('files');

        if (blank($files)) {
            return [];
        }

        $out = [];
        $total = 0;

        foreach ((array) $files as $f) {
            if ($f === null || ! $f->isValid()) {
                return 'یکی از فایل‌ها درست آپلود نشد.';
            }

            $name = $f->getClientOriginalName();
            $ext  = strtolower((string) $f->getClientOriginalExtension());

            if (in_array($ext, self::BLOCKED_EXTENSIONS, true)) {
                return 'فایلِ «'.$name.'» پسوندِ اجرایی دارد. سرویس‌های ایمیل چنین نامه‌ای را رد می‌کنند؛ داخلِ zip بگذاریدش.';
            }

            $total += $f->getSize();

            if ($total > self::MAX_TOTAL_KB * 1024) {
                return 'جمعِ پیوست‌ها از '.fa_num(self::MAX_TOTAL_KB / 1024).' مگابایت بیشتر شد.';
            }

            $data = (string) file_get_contents($f->getRealPath());

            /*
            | 🔴 فایلِ خالی **رد** می‌شود، بی‌صدا حذف نمی‌شود.
            |
            | `MailboxReplier::normalizeFiles()` ردیفِ بی‌داده را دور می‌ریزد
            | (چون پیوستِ صفربایتی بعضی کلاینت‌ها را خراب نشان می‌دهد). ولی اگر
            | این‌جا هم ساکت بمانیم، کاربر فایل را ضمیمه می‌کند، «فرستاده شد»
            | می‌بیند، و نامه بی‌پیوست می‌رود — بدترین ترکیبِ ممکن.
            */
            if ($data === '') {
                return 'فایلِ «'.$name.'» خالی است.';
            }

            $out[] = [
                'name' => mb_substr($name, 0, 120),
                'mime' => (string) ($f->getMimeType() ?: 'application/octet-stream'),
                'data' => $data,
            ];
        }

        return $out;
    }

    /**
     * بردنِ نامه به سطلِ زباله / هرزنامه / بایگانی.
     *
     * ⚠️ «حذف» یعنی سطلِ زباله، نه نابودی — و از وب‌میل برگشت‌پذیر است. متنِ
     * تأییدِ روی دکمه هم همین را می‌گوید؛ اگر روزی رفتار عوض شد، آن متن هم
     * باید عوض شود.
     */
    public function move(MailboxMessage $message, string $kind, Request $request): RedirectResponse
    {
        abort_unless(in_array($kind, ['trash', 'junk', 'archive'], true), 404);

        $res = app(MailboxReader::class)->move($message, $kind);

        if ($res['ok']) {
            /*
            | ⚠️ ردیف پاک نمی‌شود، «رسیدگی‌شده» می‌خورد. حذفِ ردیف یعنی
            | `mailbox:sync` در اجرای بعدی همان نامه را دوباره بیاورد (پنجرهٔ
            | عقب‌گردش چند روزه است) و کاربر فکر کند حذف کار نکرده.
            */
            $fill = ['handled_at' => now(), 'needs_reply' => false];

            if ($kind === 'junk') {
                $fill['category'] = 'spam';
            }

            $message->forceFill($fill)->save();

            return redirect('/admin/mail?box='.$message->account)->with('ok', $res['message']);
        }

        return back()->with('err', $res['message']);
    }

    /**
     * از این نامه یک یادآوری در تقویمِ کسب‌وکار بساز.
     *
     * 🔴 نامه در تقویم **کپی نمی‌شود** — فقط عنوان و نشانیِ فرستنده و یک لینکِ
     * برگشت. تقویم از پنل خوانده می‌شود و اگر متنِ نامه هم آن‌جا بنشیند،
     * همان دادهٔ مشتری که عمداً در دیتابیس ذخیره نمی‌کنیم، از درِ پشتی وارد
     * می‌شود.
     */
    public function remind(MailboxMessage $message, Request $request): RedirectResponse
    {
        $data = $request->validate([
            'when'  => ['required', 'string', Rule::in(array_keys(self::REMINDER_PRESETS))],
            'note'  => ['nullable', 'string', 'max:500'],
        ], [], ['when' => 'زمان', 'note' => 'یادداشت']);

        $date = now()->addDays(self::REMINDER_PRESETS[$data['when']])->startOfDay();

        $title = 'پیگیریِ ایمیل: '.mb_substr((string) ($message->subject ?: '(بدون موضوع)'), 0, 150);

        $body = trim(implode("\n", array_filter([
            'فرستنده: '.($message->from_name ?: '').' <'.$message->from_email.'>',
            'صندوق: '.$message->accountLabel(),
            trim((string) ($data['note'] ?? '')) !== '' ? 'یادداشت: '.trim((string) $data['note']) : null,
            'نامه: /admin/mail/'.$message->id,
        ])));

        CalendarEvent::create([
            'type'        => 'task',
            'title'       => mb_substr($title, 0, 200),
            'description' => mb_substr($body, 0, 2000),
            'event_date'  => $date,
            'status'      => 'pending',
            'user_id'     => $request->user()?->id,
            'repeat'      => 'none',
            'meta'        => ['source' => 'mailbox', 'mailbox_message_id' => $message->id],
        ]);

        return back()->with('ok', 'یادآوری برای '.sdate($date).' در تقویم ثبت شد.');
    }

    /**
     * چرا نمی‌شود از این صندوق پاسخ داد — یا `null` یعنی می‌شود.
     *
     * ⚠️ **پیش از نشان‌دادنِ دکمه** پرسیده می‌شود، نه بعد از زدنش. دکمه‌ای که
     * همیشه خطا می‌دهد، کاربر را وامی‌دارد متن را دوباره جای دیگری بنویسد.
     */
    private function replyBlocker(MailboxMessage $m): ?string
    {
        if (! filter_var(trim((string) $m->from_email), FILTER_VALIDATE_EMAIL)) {
            return 'نشانیِ فرستندهٔ این نامه معتبر نیست.';
        }

        foreach ((array) config('mailboxes.accounts', []) as $a) {
            if (($a['key'] ?? null) !== $m->account) {
                continue;
            }

            if (blank($a['pass'] ?? null)) {
                return 'برای این صندوق رمزی در پیکربندی نیست.';
            }

            if (blank($a['smtp_host'] ?? null) && filled($a['host'] ?? null)) {
                return 'این صندوق روی سرورِ ما نیست و SMTPش تعریف نشده.';
            }

            return null;
        }

        return 'این صندوق دیگر در پیکربندی نیست.';
    }

    /**
     * نشانیِ تصویرِ درون‌خطی از روی Content-ID.
     *
     * ⚠️ اگر cid به هیچ پیوستی نخورد `null` می‌دهد و پاک‌سازی‌کننده تصویر را
     * می‌بندد — بهتر از ساختنِ نشانی‌ای که ۴۰۴ بدهد.
     *
     * @param  list<array<string,mixed>>  $attachments
     */
    private function cidUrl(MailboxMessage $m, array $attachments, string $cid): ?string
    {
        foreach ($attachments as $i => $a) {
            if ((string) ($a['cid'] ?? '') !== $cid) {
                continue;
            }

            if (! MailHtmlSanitizer::isDisplayableImage((string) ($a['mime'] ?? ''))) {
                return null;
            }

            return '/admin/mail/'.$m->id.'/attachment/'.$i.'?inline=1';
        }

        return null;
    }

    /** نامِ فایل برای هدر — بی‌کوتیشن، بی‌خطِ تازه، بی‌مسیر. */
    private function safeName(string $name): string
    {
        $name = str_replace(["\r", "\n", '"', '\\', '/'], '_', $name);
        $name = trim(preg_replace('~\s+~u', ' ', $name) ?? '');

        return mb_substr($name === '' ? 'attachment' : $name, 0, 100);
    }

    /** «رسیدگی شد» — از فهرستِ باز بیرون می‌رود، پاک نمی‌شود. */
    public function handled(MailboxMessage $message): RedirectResponse
    {
        $message->update(['handled_at' => now()]);

        return back()->with('ok', 'علامت خورد: رسیدگی شد.');
    }

    public function reopen(MailboxMessage $message): RedirectResponse
    {
        $message->update(['handled_at' => null]);

        return back()->with('ok', 'دوباره باز شد.');
    }

    /**
     * بستنِ گروهیِ همهٔ نامه‌های یک صندوق.
     *
     * برای روزِ اولِ راه‌اندازی است: صندوقی که سه سال است پر شده، ۴۰۰ نامهٔ
     * «باز» می‌سازد که هیچ‌کدام واقعاً کاری ندارند. بدونِ این دکمه، همان روزِ
     * اول صفحه بی‌فایده می‌شود.
     */
    public function clear(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'box'    => ['nullable', 'string', 'max:40'],
            'before' => ['nullable', 'date'],
        ]);

        $q = MailboxMessage::open()
            ->when(filled($data['box'] ?? null), fn ($x) => $x->where('account', $data['box']))
            ->when(filled($data['before'] ?? null), fn ($x) => $x->where('received_at', '<', $data['before']));

        $n = $q->update(['handled_at' => now()]);

        return back()->with('ok', "{$n} نامه بسته شد.");
    }
}

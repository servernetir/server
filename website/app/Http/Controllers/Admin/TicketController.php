<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use App\Services\Ticket\AttachmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * تیکت پشتیبانی — سمت کارکنان.
 *
 * روی همان احراز هویت «web» موجود می‌نشیند (پنل مدیریت). کارمند برخلاف
 * مشتری همهٔ تیکت‌ها را می‌بیند، و یادداشت داخلی هم می‌بیند و می‌نویسد.
 */
class TicketController extends Controller
{
    private const MAX_BODY = 5000;

    public function index(Request $request): View
    {
        $filter   = $request->string('status', 'open')->toString();
        $priority = $request->string('priority', '')->toString();
        $dept     = $request->string('department', '')->toString();
        $q        = trim($request->string('q', '')->toString());

        // CASE و نه field(): field مخصوص MariaDB است و تست محلی روی SQLite
        // را می‌شکند. ترتیب: باز، بعد پاسخ‌داده، بعد بسته؛ و داخل هر گروه
        // قدیمی‌ترینِ منتظر اول.
        $query = Ticket::with('customer')
            ->orderByRaw("case status when 'open' then 0 when 'answered' then 1 when 'held' then 2 else 3 end")
            ->orderBy('last_reply_at');

        if (array_key_exists($filter, Ticket::STATUSES)) {
            $query->where('status', $filter);
        }
        if (in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
            $query->where('priority', $priority);
        }
        if (in_array($dept, ['technical', 'billing', 'sales'], true)) {
            $query->where('department', $dept);
        }

        // جستجو: شمارهٔ تیکت، موضوع، یا مشتری (کد/ایمیل/موبایل). نامِ مشتری از
        // احراز هویت می‌آید (ستونِ ساده نیست) پس با کد/ایمیل/موبایل می‌جوییم که
        // ستون‌اند و ایندکس دارند.
        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('number', 'like', "%{$q}%")
                    ->orWhere('subject', 'like', "%{$q}%")
                    ->orWhereHas('customer', function ($c) use ($q) {
                        $c->where('code', 'like', "%{$q}%")
                            ->orWhere('email', 'like', "%{$q}%")
                            ->orWhere('phone', 'like', "%{$q}%");
                    });
            });
        }

        return view('admin.tickets', [
            'tickets'  => $query->paginate(20)->withQueryString(),
            'filter'   => $filter,
            'priority' => $priority,
            'dept'     => $dept,
            'q'        => $q,
            /*
            | یک پرس‌وجو برای همهٔ وضعیت‌ها. ⚠️ ادغام با صفرها حیاتی است:
            | groupBy فقط وضعیت‌های موجود را برمی‌گرداند و ویو `$counts['open']`
            | را مستقیم می‌خواند — روی دیتابیسِ خالی undefined key می‌شد.
            */
            'counts'   => array_map('intval', Ticket::query()
                ->selectRaw('status, count(*) as c')->groupBy('status')
                ->pluck('c', 'status')->all())
                + array_fill_keys(array_keys(Ticket::STATUSES), 0),
        ]);
    }

    public function show(Request $request, Ticket $ticket): View
    {
        /*
        | فیلترِ فهرست را به یاد بسپار تا پس از پاسخ به **همان** نما برگردیم.
        |
        | 🔴 فقط query string ذخیره می‌شود، نه کلِ آدرسِ ارجاع‌دهنده: ریدایرکت به
        | مقداری که مهاجم در هدرِ Referer می‌گذارد یعنی open-redirect. این‌جا
        | مقصد همیشه روتِ خودمان است و فقط پرسمانش از بیرون می‌آید.
        */
        $ref = (string) $request->headers->get('referer', '');

        if ($ref !== '' && str_contains(parse_url($ref, PHP_URL_PATH) ?? '', '/admin/tickets')) {
            $request->session()->put('tickets.back', (string) (parse_url($ref, PHP_URL_QUERY) ?? ''));
        }

        return view('admin.ticket', [
            'ticket'   => $ticket->load('customer'),
            // نگهبان hasTable: تا مهاجرت ticket_attachments روی سرور نرفته، ۵۰۰ نشود
            'messages' => $ticket->messages()
                ->when(\Illuminate\Support\Facades\Schema::hasTable('ticket_attachments'),
                    fn ($q) => $q->with('attachments'))
                ->orderBy('id')->get(),  // شامل یادداشت داخلی
            // فهرستِ «به نامِ چه کسی» — فقط برای مدیر پر می‌شود
            'staff'    => $request->user()?->isAdmin() ? User::staffMembers() : collect(),
        ]);
    }

    public function reply(Request $request, Ticket $ticket): RedirectResponse
    {
        $data = $request->validate([
            'body'     => ['required', 'string', 'max:'.self::MAX_BODY],
            'internal' => ['nullable', 'boolean'],
            'close'    => ['nullable', 'boolean'],
            'as_user'  => ['nullable', 'integer'],
        ] + AttachmentService::rules());

        $user     = $request->user();
        $internal = (bool) ($data['internal'] ?? false);

        /*
        | ═══ پاسخ «به نامِ» یک کارشناسِ دیگر ═══
        |
        | 🔴 فقط مدیر، و فقط به نامِ کسی که واقعاً کارمندِ پشتیبانی است.
        |
        | بی‌این دو قید، هر کاربرِ واردشده می‌توانست با یک فیلدِ دست‌ساز
        | (`as_user=<هر شناسه‌ای>`) پاسخی به نامِ **هر کسی** ثبت کند — یعنی
        | جعلِ امضا در همان جایی که تاریخچه‌اش سندِ گفتگو با مشتری است.
        | نامِ ثبت‌شده تزیین نیست؛ مشتری همان را می‌بیند.
        |
        | ⚠️ شناسهٔ نامعتبر بی‌صدا به خودِ نویسنده برمی‌گردد، نه خطا: فرم برای
        | پشتیبان اصلاً این فیلد را ندارد و یک مقدارِ کهنه نباید پاسخ را
        | بیندازد.
        */
        $author = $user;

        if ($user->isAdmin() && filled($data['as_user'] ?? null)) {
            $picked = User::find((int) $data['as_user']);

            if ($picked !== null && $picked->isStaff()) {
                $author = $picked;
            }
        }

        /*
        | 🔴 منطقِ واقعی در `TicketReplyService` است، نه این‌جا.
        |
        | از وقتی رباتِ بله هم می‌تواند پاسخ بدهد، دو فراخوان داریم و «همان کار»
        | چیزِ کوچکی نیست: بستن باید بلافاصله بعد از `addMessage` بیاید (وگرنه
        | `Ticket.php:95` دوباره بازش می‌کند) و سه اعلان ترتیبِ معناداری دارند.
        | دو پیاده‌سازیِ موازی یعنی دیر یا زود یکی کهنه می‌شود.
        |
        | ⚠️ قلّابِ `afterMessage` عمداً هست: پیوست‌ها باید **پیش از** بستن ذخیره
        | شوند، دقیقاً مثلِ قبل. ترتیبِ این کنترلر مو نخورده.
        */
        app(\App\Services\Ticket\TicketReplyService::class)->post(
            $ticket,
            $author->id,
            $author->name,
            $data['body'],
            internal: $internal,
            close: (bool) ($data['close'] ?? false),
            afterMessage: fn ($message) => app(AttachmentService::class)
                ->store($message, $request->file('attachments', [])),
        );

        $byOther = $author->id !== $user->id ? ' (به نامِ '.$author->name.')' : '';

        /*
        | ═══ پس از پاسخ، برگرد به فهرست ═══
        |
        | خواستهٔ سرعتِ کار: پشتیبان تیکت را جواب می‌دهد و باید فوراً سراغِ
        | بعدی برود؛ ماندن در همان صفحه یعنی یک کلیکِ «بازگشت» در هر تیکت.
        |
        | ⚠️ یادداشتِ داخلی **استثناست** و در همان صفحه می‌مانَد: یادداشت یعنی
        | کارِ روی همین تیکت هنوز تمام نشده (دارد برای خودش یا همکار می‌نویسد و
        | بعد پاسخ می‌دهد). پرت‌کردنش به فهرست یعنی از دست‌دادنِ همان زمینه.
        |
        | فیلترِ فهرست از session برمی‌گردد تا اگر در نمای «در انتظار بررسی»
        | بود، به همان‌جا برگردد نه به فهرستِ خام.
        */
        if ($internal) {
            return back()->with('ok', 'یادداشت داخلی ثبت شد.');
        }

        $back = (string) $request->session()->get('tickets.back', '');

        return redirect()->to('/admin/tickets'.($back !== '' ? '?'.$back : ''))
            ->with('ok', 'پاسخِ تیکت '.$ticket->number.' ثبت شد'.$byOther.'.');
    }


    /**
     * تصحیحِ نگارشِ پیش‌نویسِ کارفرما با AI.
     *
     * 🔴 هیچ‌چیز ارسال نمی‌شود. خروجی برمی‌گردد تا کارفرما ببیند و خودش
     * تصمیم بگیرد؛ متنِ اصلی هم دست‌نخورده باقی می‌مانَد.
     *
     * ⚠️ اعتبارسنجیِ **صریح** و نه `$request->validate()`.
     *
     * تلهٔ ثبت‌شدهٔ این پروژه: `shouldRenderJsonWhen(api/*)` یعنی روی مسیرهای
     * `/admin` شکستِ اعتبارسنجی یک ریدایرکتِ ۳۰۲ِ HTML می‌دهد، نه ۴۲۲. جاوااسکریپت
     * آن را `response.json()` می‌کند و با خطای پارس می‌میرد — بی‌هیچ پیامی
     * برای کاربر.
     */
    public function polish(Request $request, Ticket $ticket): \Illuminate\Http\JsonResponse
    {
        $body = trim((string) $request->input('body', ''));

        if ($body === '') {
            return response()->json(['ok' => false, 'error' => 'متنی برای تصحیح نیست.'], 422);
        }

        if (mb_strlen($body) > self::MAX_BODY) {
            return response()->json(['ok' => false, 'error' => 'متن بلندتر از حدِ مجاز است.'], 422);
        }

        $polisher = app(\App\Services\Ticket\ReplyPolisher::class);

        if (! $polisher->enabled()) {
            return response()->json([
                'ok' => false,
                'error' => 'سرویسِ هوش مصنوعی تنظیم نشده است.',
            ], 503);
        }

        $out = $polisher->polish($body);

        if ($out === null) {
            /*
            | ⚠️ ۲۰۰ با `ok=false` و نه ۵۰۰: نرسیدنِ پاسخ از مدل خرابیِ ما
            | نیست و نباید در ردیابِ خطا سروصدا کند. رابط پیام را نشان
            | می‌دهد و متنِ کارفرما دست‌نخورده می‌مانَد.
            */
            return response()->json(['ok' => false, 'error' => 'تصحیح انجام نشد؛ دوباره تلاش کنید.']);
        }

        return response()->json(['ok' => true, 'text' => $out]);
    }

    public function update(Request $request, Ticket $ticket): RedirectResponse
    {
        $data = $request->validate([
            'status'   => ['nullable', 'in:'.implode(',', array_keys(Ticket::STATUSES))],
            'priority' => ['nullable', 'in:low,normal,high,urgent'],
        ]);

        if (! empty($data['status'])) {
            // closed_at را قاعدهٔ متمرکزِ مدل مدیریت می‌کند، نه این‌جا
            $ticket->transitionTo($data['status']);
        }

        if (! empty($data['priority'])) {
            $ticket->priority = $data['priority'];
            $ticket->save();
        }

        return back()->with('ok', 'تیکت به‌روزرسانی شد.');
    }

    /**
     * عملیاتِ گروهی روی تیکت‌ها — بستن، نگه‌داشتن، بازگشایی، پاسخ‌داده.
     *
     * ⚠️ فرمِ معمولی و ریدایرکت، نه JSON. تلهٔ ثبت‌شدهٔ این پروژه:
     * `shouldRenderJsonWhen(api/*)` یعنی شکستِ اعتبارسنجی روی `/admin` یک
     * ریدایرکتِ HTML می‌دهد نه ۴۲۲ — برای فرمِ کلاسیک این دقیقاً رفتارِ درست
     * است، پس این‌جا `validate()` امن است.
     *
     * 🔴 سقفِ ۱۰۰: «همه را انتخاب کن» فقط صفحهٔ جاری را می‌گیرد (۲۰ ردیف)،
     * پس ۱۰۰ سخاوتمندانه است؛ بی‌سقف، یک فرمِ دست‌ساز می‌تواند کلِ جدول را
     * یک‌جا ببندد.
     *
     * شناسهٔ ناموجود بی‌صدا رد می‌شود و شمارِ گزارش‌شده = واقعاً تغییرکرده،
     * نه تعدادِ انتخاب — تا پیام هرگز بیشتر از واقعیت ادعا نکند.
     */
    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids'    => ['required', 'array', 'min:1', 'max:100'],
            'ids.*'  => ['integer'],
            'status' => ['required', 'in:'.implode(',', array_keys(Ticket::STATUSES))],
        ], [], ['ids' => 'تیکت‌ها', 'status' => 'وضعیت']);

        $changed = 0;

        foreach (Ticket::whereIn('id', $data['ids'])->get() as $ticket) {
            if ($ticket->transitionTo($data['status'])) {
                $changed++;
            }
        }

        \App\Models\ActivityLog::record(
            null,
            'ticket',
            'گروهی: '.$changed.' تیکت → '.Ticket::STATUSES[$data['status']],
            $request,
            'staff'
        );

        return back()->with('ok', $changed > 0
            ? fa_num((string) $changed).' تیکت به «'.Ticket::STATUSES[$data['status']].'» تغییر کرد.'
            : 'چیزی تغییر نکرد — تیکت‌ها از قبل در همان وضعیت بودند.');
    }

    /**
     * پیشنهادِ پاسخ با AI — همان موتورِ رباتِ بله (`TicketDraftWriter`).
     *
     * 🔴 **پیش‌نویس، نه ارسال.** خروجی فقط برمی‌گردد و در کادرِ پاسخ می‌نشیند؛
     * کارفرما می‌خوانَد، ویرایش می‌کند و خودش می‌فرستد. هیچ مسیری در این متد
     * پیامی نمی‌سازد.
     *
     * ⚠️ اعتبارسنجیِ صریح، نه `validate()` — پاسخ JSON است و شکستِ
     * `validate()` روی /admin ریدایرکتِ HTML می‌دهد که `r.json()` را می‌کشد.
     */
    public function draft(Request $request, Ticket $ticket): \Illuminate\Http\JsonResponse
    {
        $tone = (string) $request->input('tone', 'n');

        if (! array_key_exists($tone, \App\Services\Ticket\TicketDraftWriter::TONES)) {
            $tone = 'n';
        }

        $writer = app(\App\Services\Ticket\TicketDraftWriter::class);

        if (! $writer->enabled()) {
            return response()->json(['ok' => false, 'error' => 'سرویسِ هوش مصنوعی تنظیم نشده است.'], 503);
        }

        $text = $writer->draft($ticket, $tone);

        if ($text === null) {
            // ⚠️ ۲۰۰ با ok=false و نه ۵۰۰: نرسیدنِ جوابِ مدل خرابیِ ما نیست
            return response()->json(['ok' => false, 'error' => 'پیش‌نویس ساخته نشد؛ دوباره تلاش کنید.']);
        }

        return response()->json(['ok' => true, 'text' => $text]);
    }

    /** دانلود پیوست — کارکنان همه‌چیز را می‌بینند، حتی پیوستِ یادداشت داخلی */
    public function attachment(Ticket $ticket, TicketAttachment $attachment): StreamedResponse
    {
        abort_if($attachment->ticket_id !== $ticket->id, 404);

        return app(AttachmentService::class)->download($attachment);
    }
}

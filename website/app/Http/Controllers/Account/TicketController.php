<?php

namespace App\Http\Controllers\Account;

use App\Services\Bale\Admin\AdminBaleRouter;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Services\Ticket\AttachmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * تیکت پشتیبانی — سمت مشتری.
 *
 * همان قاعدهٔ مالکیت که در فاکتورها: تیکت مشتری دیگر ۴۰۴ می‌دهد نه ۴۰۳،
 * چون ۴۰۳ وجودش را تأیید می‌کند.
 */
class TicketController extends Controller
{
    /** سقف طول پیام — جلوی چسباندن یک فایل کامل در متن */
    private const MAX_BODY = 5000;

    public function index(): View
    {
        $customer = Auth::guard('customer')->user();

        return view('account.tickets', AccountController::shell('tickets') + [
            'tickets' => $customer->tickets()
                ->orderByRaw("status = 'closed'")          // بازها بالا
                ->orderByDesc('last_reply_at')
                ->paginate(15),
        ]);
    }

    public function create(): View
    {
        return view('account.ticket-new', AccountController::shell('tickets'));
    }

    public function store(Request $request): RedirectResponse
    {
        $customer = Auth::guard('customer')->user();

        $data = $request->validate([
            'subject'    => ['required', 'string', 'max:200'],
            'department' => ['required', 'in:technical,billing,sales'],
            'priority'   => ['required', 'in:low,normal,high,urgent'],
            'body'       => ['required', 'string', 'max:'.self::MAX_BODY],
        ] + AttachmentService::rules(), [], [
            'subject' => __('ui.tk_subject'),
            'body'    => __('ui.tk_message'),
        ]);

        $ticket = $customer->tickets()->create([
            'subject'         => $data['subject'],
            'department'      => $data['department'],
            'priority'        => $data['priority'],
            'status'          => 'open',
            'last_reply_role' => 'customer',
            'last_reply_at'   => now(),
        ]);

        $message = $ticket->addMessage('customer', $customer->id, $customer->displayName(), $data['body']);
        app(AttachmentService::class)->store($message, $request->file('attachments', []));

        // اعلان به مدیر — تیکت باید سریع دیده شود. best-effort: اگر بله/SMTP
        // قطع باشد، ثبتِ تیکتِ مشتری نباید شکست بخورد.
        $depts = ['technical' => 'فنی', 'billing' => 'مالی', 'sales' => 'فروش'];
        $prios = ['low' => 'کم', 'normal' => 'معمولی', 'high' => 'زیاد', 'urgent' => 'فوری'];

        /*
        | ⚠️ از قیفِ واحد می‌رود، نه `AdminNotifier` مستقیم.
        |
        | قبلاً فقط مدیر خبردار می‌شد و مشتری هیچ رسیدی نمی‌گرفت — یعنی کسی که
        | تیکت زده بود نمی‌دانست اصلاً ثبت شده یا نه، و معمولاً چند دقیقه بعد
        | تیکتِ دوم می‌زد. `NotifyEvent::ticket_new` مخاطبش `both` است.
        */
        app(\App\Services\Notify\Notifier::class)->fire(
            'ticket_new',
            $customer,
            ['number' => (string) $ticket->number, 'subject' => $data['subject']],
            '🎫 تیکتِ شما با شمارهٔ '.fa_num((string) $ticket->number).' ثبت شد. '
            .'به‌محضِ پاسخ، همین‌جا خبرتان می‌کنیم: '.console_lroute('account.tickets'),
            [
                'بخش'    => $depts[$data['department']] ?? $data['department'],
                'اولویت' => $prios[$data['priority']] ?? $data['priority'],
            ],
            url('/admin/tickets/'.$ticket->id),
            '🎫',
        );

        return redirect()->route($this->rp().'account.ticket', $ticket)
            ->with('ok', __('ui.tk_created'));
    }

    public function show(Ticket $ticket): View
    {
        $this->authorizeTicket($ticket);

        return view('account.ticket', AccountController::shell('tickets') + [
            'ticket'   => $ticket,
            // نگهبان: تا جدول ticket_attachments روی سرور ساخته نشده، eager-load
            // نکن وگرنه باز کردن تیکت ۵۰۰ می‌شود
            'messages' => $ticket->visibleMessages()
                ->when(\Illuminate\Support\Facades\Schema::hasTable('ticket_attachments'),
                    fn ($q) => $q->with('attachments'))
                ->orderBy('id')->get(),
        ]);
    }

    public function reply(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorizeTicket($ticket);

        // پاسخ به تیکت بسته اجازه دارد — بازش می‌کند. مشتری‌ای که مشکلش
        // برگشته نباید مجبور به ساختن تیکت تازه و از دست دادن سابقه شود.
        $data = $request->validate([
            'body' => ['required', 'string', 'max:'.self::MAX_BODY],
        ] + AttachmentService::rules(), [], ['body' => __('ui.tk_message')]);

        $customer = Auth::guard('customer')->user();
        $message  = $ticket->addMessage('customer', $customer->id, $customer->displayName(), $data['body']);
        app(AttachmentService::class)->store($message, $request->file('attachments', []));

        /*
        | 🔴 اعلانِ مدیر — این متد تا امروز **هیچ‌چیز** شلیک نمی‌کرد.
        |
        | گزارشِ کارفرما: «تیکتِ تازه اعلان می‌آید، ولی وقتی مشتری به پاسخِ من
        | دوباره جواب می‌دهد هیچ خبری نمی‌شود.» دقیقاً همین‌جا بود: `store()`
        | از قیفِ اعلان رد می‌شد و `reply()` فقط ذخیره می‌کرد و ریدایرکت.
        |
        | و پیامدش از «یک اعلانِ جاافتاده» بزرگ‌تر است: مکالمهٔ پشتیبانی
        | **متوقف** می‌شد. مشتری جواب می‌داد و منتظر می‌مانْد؛ مدیر خبر نداشت
        | و تیکت روزها باز می‌مانْد بی‌آنکه کسی مقصر باشد.
        |
        | ⚠️ عمداً `ticket_customer_reply` است نه `ticket_reply`: آن یکی
        | «کارمند جواب داد ← به مشتری خبر بده» است. چرایش در `NotifyEvent`.
        |
        | ⚠️ خارج از try/catch نیست چون `Notifier::fire()` خودش هرگز throw
        | نمی‌کند — ولی اگر روزی آن قرارداد عوض شد، پاسخِ مشتری نباید گم شود.
        */
        app(\App\Services\Notify\Notifier::class)->fire(
            'ticket_customer_reply',
            $customer,
            ['number' => (string) $ticket->number, 'subject' => (string) $ticket->subject],
            '',                                   // مخاطب فقط مدیر است؛ متنِ مشتری لازم نیست
            ['وضعیت' => $ticket->isOpen() ? 'باز' : 'بسته بود و دوباره باز شد'],
            url('/admin/tickets/'.$ticket->id),
            '💬',

            /*
            | ══ دکمه‌های شیشه‌ای روی همین اعلان ══
            |
            | 🔴 کارفرما: «وقتی پاسخِ مشتری می‌آید بتوانم همان‌جا جواب بدهم و
            | مشتری را ببینم، مثلِ تیکت‌ها.» تا امروز این اعلان فقط متن و یک
            | لینک بود؛ یعنی برای یک جملهٔ کوتاه باید پنل باز می‌شد و عملاً
            | جواب عقب می‌افتاد.
            |
            | ⚠️ افعال **موجود**اند، نه تازه: `t` کارتِ تیکت، `tw` جریانِ
            | پاسخ، `td` پیش‌نویسِ AI، `c` کارتِ مشتری. فعلِ تازه یعنی یک
            | مسیرِ نسنجیده در روتر و یک `default` که «این دکمه معتبر نیست»
            | می‌گوید.
            |
            | ⚠️ `CB_PREFIX` نسخه‌دار است و از خودِ روتر خوانده می‌شود تا اگر
            | نسخه بالا رفت، این‌جا جا نمانَد.
            */
            $ticket->customer_id ? [
                [
                    ['text' => '💬 پاسخ', 'data' => AdminBaleRouter::CB_PREFIX.'tw:'.$ticket->id],
                    ['text' => '🧠 پیش‌نویس', 'data' => AdminBaleRouter::CB_PREFIX.'td:'.$ticket->id],
                ],
                [
                    ['text' => '🎫 تیکت', 'data' => AdminBaleRouter::CB_PREFIX.'t:'.$ticket->id],
                    ['text' => '👤 مشتری', 'data' => AdminBaleRouter::CB_PREFIX.'c:'.$ticket->customer_id],
                ],
            ] : [],
        );

        return redirect()->route($this->rp().'account.ticket', $ticket)
            ->with('ok', __('ui.tk_reply_sent'));
    }

    /**
     * دانلود پیوست — فقط اگر تیکت مالِ همین مشتری باشد و پیام داخلی نباشد.
     * یادداشت داخلیِ کارکنان (و پیوستش) هرگز به مشتری نمی‌رسد.
     */
    public function attachment(Ticket $ticket, TicketAttachment $attachment): StreamedResponse
    {
        $this->authorizeTicket($ticket);

        abort_if($attachment->ticket_id !== $ticket->id, 404);
        abort_if($attachment->message?->is_internal === true, 404);

        return app(AttachmentService::class)->download($attachment);
    }

    public function close(Ticket $ticket): RedirectResponse
    {
        $this->authorizeTicket($ticket);

        if ($ticket->isOpen()) {
            $ticket->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
        }

        return redirect()->route($this->rp().'account.ticket', $ticket)
            ->with('ok', __('ui.tk_closed_ok'));
    }

    // ───────────────────────────── کمکی ─────────────────────────────

    private function authorizeTicket(Ticket $ticket): void
    {
        abort_if($ticket->customer_id !== Auth::guard('customer')->id(), 404);
    }

    private function rp(): string
    {
        return \App\Providers\AppServiceProvider::LOCALES[app()->getLocale()] ?? '';
    }
}

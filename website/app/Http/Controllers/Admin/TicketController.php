<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketAttachment;
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
            ->orderByRaw("case status when 'open' then 0 when 'answered' then 1 else 2 end")
            ->orderBy('last_reply_at');

        if (in_array($filter, ['open', 'answered', 'closed'], true)) {
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
            'counts'   => [
                'open'     => Ticket::where('status', 'open')->count(),
                'answered' => Ticket::where('status', 'answered')->count(),
                'closed'   => Ticket::where('status', 'closed')->count(),
            ],
        ]);
    }

    public function show(Ticket $ticket): View
    {
        return view('admin.ticket', [
            'ticket'   => $ticket->load('customer'),
            // نگهبان hasTable: تا مهاجرت ticket_attachments روی سرور نرفته، ۵۰۰ نشود
            'messages' => $ticket->messages()
                ->when(\Illuminate\Support\Facades\Schema::hasTable('ticket_attachments'),
                    fn ($q) => $q->with('attachments'))
                ->orderBy('id')->get(),  // شامل یادداشت داخلی
        ]);
    }

    public function reply(Request $request, Ticket $ticket): RedirectResponse
    {
        $data = $request->validate([
            'body'     => ['required', 'string', 'max:'.self::MAX_BODY],
            'internal' => ['nullable', 'boolean'],
            'close'    => ['nullable', 'boolean'],
        ] + AttachmentService::rules());

        $user     = $request->user();
        $internal = (bool) ($data['internal'] ?? false);

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
            $user->id,
            $user->name,
            $data['body'],
            internal: $internal,
            close: (bool) ($data['close'] ?? false),
            afterMessage: fn ($message) => app(AttachmentService::class)
                ->store($message, $request->file('attachments', [])),
        );

        return back()->with('ok', $internal ? 'یادداشت داخلی ثبت شد.' : 'پاسخ ثبت شد.');
    }

    public function update(Request $request, Ticket $ticket): RedirectResponse
    {
        $data = $request->validate([
            'status'   => ['nullable', 'in:open,answered,closed'],
            'priority' => ['nullable', 'in:low,normal,high,urgent'],
        ]);

        if (! empty($data['status'])) {
            $ticket->status = $data['status'];
            $ticket->closed_at = $data['status'] === 'closed' ? now() : null;
        }

        if (! empty($data['priority'])) {
            $ticket->priority = $data['priority'];
        }

        $ticket->save();

        return back()->with('ok', 'تیکت به‌روزرسانی شد.');
    }

    /** دانلود پیوست — کارکنان همه‌چیز را می‌بینند، حتی پیوستِ یادداشت داخلی */
    public function attachment(Ticket $ticket, TicketAttachment $attachment): StreamedResponse
    {
        abort_if($attachment->ticket_id !== $ticket->id, 404);

        return app(AttachmentService::class)->download($attachment);
    }
}

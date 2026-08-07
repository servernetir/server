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

        $message = $ticket->addMessage('staff', $user->id, $user->name, $data['body'], internal: $internal);
        app(AttachmentService::class)->store($message, $request->file('attachments', []));

        // «پاسخ و بستن» در یک حرکت — کار رایج پشتیبانی
        if (! empty($data['close']) && ! $internal) {
            $ticket->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
        }

        // اعلان به مشتری — پیامک و بله. یادداشت داخلی اعلان ندارد (مشتری
        // نمی‌بیندش، پس نباید خبردار شود).
        if (! $internal && $ticket->customer) {
            $notifier = app(\App\Services\Notify\Notifier::class);
            $link = console_lroute('account.tickets');

            $notifier->fire('ticket_reply', $ticket->customer,
                ['number' => (string) $ticket->number],
                'پاسخ جدیدی به تیکتِ '.fa_num((string) $ticket->number).' شما داده شد: '.$link);

            /*
            | بستن و نظرسنجی — دو رویدادی که تا امروز اصلاً وجود نداشتند.
            |
            | ⚠️ نظرسنجی **بعد از** اعلانِ بستن می‌رود و نه به‌جایش: مشتری اول
            | باید بداند مشکلش بسته شده، بعد ازش نظر بخواهیم. برعکسش، نظرسنجی
            | برای کسی می‌رود که هنوز فکر می‌کند تیکتش باز است.
            */
            if (! empty($data['close'])) {
                $notifier->fire('ticket_closed', $ticket->customer,
                    ['number' => (string) $ticket->number],
                    '✅ تیکتِ '.fa_num((string) $ticket->number).' بسته شد. '
                    .'اگر مشکل برطرف نشده، همان‌جا پاسخ بدهید تا دوباره باز شود: '.$link);

                $notifier->fire('ticket_survey', $ticket->customer,
                    ['number' => (string) $ticket->number, 'link' => $link],
                    'از پشتیبانیِ تیکتِ '.fa_num((string) $ticket->number).' راضی بودید؟ '
                    .'نظرتان کمکمان می‌کند بهتر شویم: '.$link);
            }
        }

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

<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

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
        ], [], [
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

        $ticket->addMessage('customer', $customer->id, $customer->displayName(), $data['body']);

        return redirect()->route($this->rp().'account.ticket', $ticket)
            ->with('ok', __('ui.tk_created'));
    }

    public function show(Ticket $ticket): View
    {
        $this->authorizeTicket($ticket);

        return view('account.ticket', AccountController::shell('tickets') + [
            'ticket'   => $ticket,
            'messages' => $ticket->visibleMessages()->orderBy('id')->get(),
        ]);
    }

    public function reply(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorizeTicket($ticket);

        // پاسخ به تیکت بسته اجازه دارد — بازش می‌کند. مشتری‌ای که مشکلش
        // برگشته نباید مجبور به ساختن تیکت تازه و از دست دادن سابقه شود.
        $data = $request->validate([
            'body' => ['required', 'string', 'max:'.self::MAX_BODY],
        ], [], ['body' => __('ui.tk_message')]);

        $customer = Auth::guard('customer')->user();
        $ticket->addMessage('customer', $customer->id, $customer->displayName(), $data['body']);

        return redirect()->route($this->rp().'account.ticket', $ticket)
            ->with('ok', __('ui.tk_reply_sent'));
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

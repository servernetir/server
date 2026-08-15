<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MailboxMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * صندوق‌های مدیریتی در پنل.
 *
 * ⚠️ این یک کلاینتِ ایمیل **نیست** و نباید بشود. متنِ کاملِ نامه اصلاً ذخیره
 * نمی‌شود و دکمهٔ «جواب بده» هم عمداً وجود ندارد — جواب دادن کارِ Roundcube
 * است. کارِ این صفحه یک سؤال است و بس: «چه چیزی هست که هنوز کسی حواسش به آن
 * نبوده؟»
 */
class MailboxController extends Controller
{
    public function index(Request $request): View
    {
        if (! Schema::hasTable('mailbox_messages')) {
            return view('admin.mail', ['notReady' => true]);
        }

        $account = (string) $request->query('box', '');
        $filter = (string) $request->query('show', 'open');

        $messages = MailboxMessage::query()
            ->when($account !== '', fn ($q) => $q->where('account', $account))
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
            'systemSeen' => MailboxMessage::where('is_system', true)->count(),
            'pending'    => MailboxMessage::unreported()->whereNull('category')->count(),
        ]);
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

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SnappPayOrder;
use App\Services\Payment\SnappPay\SnappPayAdmin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * سفارش‌های اسنپ‌پی در پنلِ ادمین.
 *
 * ═══ چرا این صفحه وجود دارد ═══
 *
 * 🔴 خواستهٔ صریحِ اسنپ‌پی، و یکی از مواردی که در بازبینی می‌سنجند:
 *
 *   «ارتباط اسنپ‌پی و پذیرنده در خصوص سفارش‌های ثبت شده با تراکنش آیدی خواهد
 *   بود. تراکنش آیدی ارسال شده توسط پذیرنده به اسنپ‌پی لازم است در پنل ادمین
 *   سایت پذیرنده نمایش‌داده شود و قابلیت جستجو در قسمت سفارشات داشته باشد و
 *   ادمین از آن قسمت بتواند برروی سفارش تغییرات اعمال کند.»
 *
 * پس این صفحه سه کار می‌کند و هر سه اجباری‌اند: نشان‌دادن، جست‌وجو، و
 * تغییر دادن (کاهش/لغو).
 *
 * ⚠️ هر دو عملِ تغییر برگشت‌ناپذیرند و مستندات تأییدیهٔ ادمین خواسته. تأییدیه
 * در ویو با `confirm()` گرفته می‌شود و **ثبتش** در `snapppay_order_events`
 * می‌نشیند — پاپ‌آپی که جایی ثبت نشود، کنترل نیست.
 */
class SnappPayController extends Controller
{
    public function __construct(private SnappPayAdmin $admin) {}

    public function index(Request $request): View
    {
        if (! Schema::hasTable('snapppay_orders')) {
            return view('admin.snapppay', [
                'ready' => false, 'orders' => collect(), 'q' => '', 'state' => 'all', 'kpis' => [],
            ]);
        }

        $q = trim((string) $request->string('q'));
        $state = $request->string('state', 'all')->toString();

        $orders = SnappPayOrder::query()
            ->with(['customer', 'invoice'])
            ->when($state !== 'all', fn ($b) => $b->where('state', $state))
            /*
            | جست‌وجو عمداً چند لنگر دارد: پشتیبانیِ اسنپ‌پی با شمارهٔ تراکنش
            | می‌آید، ولی مشتری با شمارهٔ فاکتور یا ایمیلش تماس می‌گیرد.
            | یک کادرِ جست‌وجو که فقط یکی را بشناسد، نیمی از تماس‌ها را
            | بی‌جواب می‌گذارد.
            */
            ->when($q !== '', function ($b) use ($q) {
                $b->where(function ($w) use ($q) {
                    $w->where('transaction_id', 'like', "%{$q}%")
                        ->orWhereHas('invoice', fn ($i) => $i->where('number', 'like', "%{$q}%"))
                        ->orWhereHas('customer', fn ($c) => $c
                            ->where('email', 'like', "%{$q}%")
                            ->orWhere('code', 'like', "%{$q}%"));
                });
            })
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return view('admin.snapppay', [
            'ready'  => true,
            'orders' => $orders,
            'q'      => $q,
            'state'  => $state,
            'kpis'   => [
                'settled' => SnappPayOrder::where('state', 'settled')->count(),
                'open'    => SnappPayOrder::whereIn('state', ['pending', 'verified'])->count(),
                'canceled' => SnappPayOrder::where('state', 'canceled')->count(),
                'volume'  => (int) SnappPayOrder::where('state', 'settled')->sum('amount'),
            ],
        ]);
    }

    public function show(SnappPayOrder $order): View
    {
        return view('admin.snapppay-order', [
            'order'  => $order->load(['customer', 'invoice.items', 'payment', 'events']),
        ]);
    }

    /** استعلامِ وضعیت — بی‌خطر، پس بدونِ تأییدیه. */
    public function status(SnappPayOrder $order): RedirectResponse
    {
        $res = $this->admin->refreshStatus($order, $this->userId());

        return back()->with($res['ok'] ? 'ok' : 'err',
            $res['ok'] ? 'وضعیتِ اسنپ‌پی: '.$res['status'] : $res['message']);
    }

    /**
     * لغوِ کامل — برگشت‌ناپذیر.
     *
     * ⚠️ تأییدیهٔ متنی هم لازم است، نه فقط `confirm()` مرورگر: یک کلیکِ
     * اشتباه روی دکمه‌ای که بدهیِ مشتری را برمی‌گرداند، جبران‌پذیر نیست.
     */
    public function cancel(Request $request, SnappPayOrder $order): RedirectResponse
    {
        $request->validate(['confirm' => ['required', 'in:CANCEL']], [
            'confirm.in' => 'برای لغو، باید عبارتِ CANCEL را دقیقاً بنویسید.',
        ]);

        $res = $this->admin->cancel($order, $this->userId());

        return back()->with($res['ok'] ? 'ok' : 'err', $res['message']);
    }

    /** کاهشِ سفارش — برگشت‌ناپذیر. */
    public function update(Request $request, SnappPayOrder $order): RedirectResponse
    {
        $request->validate([
            'confirm'   => ['required', 'in:UPDATE'],
            'qty'       => ['required', 'array'],
            'qty.*'     => ['required', 'integer', 'min:0'],
        ], [
            'confirm.in' => 'برای اعمالِ کاهش، باید عبارتِ UPDATE را دقیقاً بنویسید.',
        ]);

        /** @var array<int,int> $qty */
        $qty = array_map('intval', $request->array('qty'));

        $res = $this->admin->update($order, $qty, $this->userId());

        return back()->with($res['ok'] ? 'ok' : 'err', $res['message']);
    }

    private function userId(): ?int
    {
        return Auth::guard('web')->id();
    }
}

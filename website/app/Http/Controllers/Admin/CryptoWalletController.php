<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CryptoPayment;
use App\Models\CryptoWallet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * استخرِ آدرس‌های دریافت + پرداخت‌های نیازمندِ بازبینی.
 *
 * ⚠️ این صفحه فقط **آدرس** می‌گیرد. اگر روزی کسی خواست کلیدِ خصوصی یا عبارتِ
 * بازیابی را هم این‌جا ذخیره کند، نکند: کلِ ایمنیِ این طراحی بر این استوار
 * است که سرور توانِ خرج‌کردن ندارد.
 */
class CryptoWalletController extends Controller
{
    public function index(): View
    {
        $ready = Schema::hasTable('crypto_wallets');

        $hasPayments = $ready && Schema::hasTable('crypto_payments');

        return view('admin.crypto-wallets', [
            'wallets' => $ready ? CryptoWallet::orderBy('chain')->orderBy('id')->get() : collect(),
            // پرداخت‌هایی که خودکار تسویه نشدند و منتظرِ چشمِ آدم‌اند
            'review' => $hasPayments
                ? CryptoPayment::whereIn('status', ['manual', 'unmatched'])->latest('id')->limit(50)->get()
                : collect(),

            /*
            | پرداخت‌های **در جریان**.
            |
            | ⚠️ بی‌این فهرست، مدیر هیچ راهی نداشت بفهمد چرا یک آدرس «مشغول»
            | است یا چرا گزینهٔ رمزارز به مشتریِ بعدی نشان داده نمی‌شود. همان
            | سکوتی که یک بار به «قابلیت اصلاً کار نمی‌کند» تعبیر شد.
            |
            | منقضی‌های ۲۴ ساعتِ اخیر هم می‌آیند: پرداختی که نیمه‌کاره رها شده
            | خودش یک خبر است، و آدرسش تا پایانِ دورهٔ خنک‌شدن برنمی‌گردد.
            */
            'inflight' => $hasPayments
                ? CryptoPayment::where(fn ($q) => $q
                    ->whereIn('status', ['pending', 'seen'])
                    ->orWhere(fn ($e) => $e->where('status', 'expired')->where('updated_at', '>=', now()->subDay())))
                    ->latest('id')->limit(50)->get()
                : collect(),

            'notReady' => ! $ready,
        ]);
    }

    /**
     * افزودنِ دسته‌ای — مدیر آدرس‌ها را از کیفش می‌سازد و یکجا می‌چسباند.
     *
     * ⚠️ آدرسِ تکراری **خطا نمی‌دهد**، رد می‌شود. چسباندنِ دوبارهٔ یک فهرست کارِ
     * رایجی است و نباید کلِ عملیات را بشکند.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'chain' => ['required', 'string', 'max:16'],
            'addresses' => ['required', 'string', 'max:20000'],
        ]);

        $added = 0;
        $skipped = 0;

        foreach (preg_split('~[\s,;]+~', $data['addresses']) as $addr) {
            $addr = trim($addr);

            /*
            | 🔴 اعتبارسنجیِ شکلِ آدرسِ ترون.
            |
            | یک آدرسِ تایپی یعنی مشتری پول را به جایی می‌فرستد که کلیدش دستِ
            | هیچ‌کس نیست — برگشت‌ناپذیر. ترون همیشه با T شروع می‌شود و ۳۴
            | نویسهٔ Base58 است. این کاملِ اعتبارسنجی نیست (checksum را بدونِ
            | کتابخانه نمی‌سنجیم) ولی جلوی خطای تایپی و آدرسِ زنجیرهٔ دیگر را
            | می‌گیرد.
            */
            if ($data['chain'] === 'tron' && ! preg_match('~^T[1-9A-HJ-NP-Za-km-z]{33}$~', $addr)) {
                $skipped++;
                continue;
            }

            if ($addr === '') {
                continue;
            }

            $exists = CryptoWallet::where('chain', $data['chain'])->where('address', $addr)->exists();

            if ($exists) {
                $skipped++;
                continue;
            }

            CryptoWallet::create(['chain' => $data['chain'], 'address' => $addr, 'is_active' => true]);
            $added++;
        }

        return back()->with('ok', "{$added} آدرس اضافه شد".($skipped ? " · {$skipped} مورد رد شد (تکراری یا نامعتبر)" : '.'));
    }

    /** فعال/غیرفعال — حذف نمی‌کنیم چون پرداخت‌های قبلی به آن ارجاع دارند */
    public function toggle(CryptoWallet $wallet): RedirectResponse
    {
        $wallet->forceFill(['is_active' => ! $wallet->is_active])->save();

        return back()->with('ok', 'وضعیت آدرس تغییر کرد.');
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CryptoWallet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * استخرِ آدرس‌های دریافت + پرداخت‌های نیازمندِ بازبینی.
 *
 * ⚠️ این صفحه فقط **آدرس** می‌گیرد. اگر روزی کسی خواست کلیدِ خصوصی یا عبارتِ
 * بازیابی را هم این‌جا ذخیره کند، نکند: کلِ ایمنیِ این طراحی بر این استوار
 * است که سرور توانِ خرج‌کردن ندارد.
 */
class CryptoWalletController extends Controller
{
    /**
     * صفحهٔ این بخش به تبِ «حساب‌ها»ی تنظیمات منتقل شد؛ دادهٔ همان جدول‌ها
     * حالا در SettingsController جمع می‌شود. مسیر زنده مانده ولی ویو ندارد —
     * دو نسخه از یک صفحه دیر یا زود از هم فاصله می‌گیرند.
     */
    public function index(): RedirectResponse
    {
        return redirect()->to('/admin/settings?tab=accounts');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'chain' => ['required', 'string', 'in:tron,bsc'],
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

            if ($data['chain'] === 'bsc') {
                if (! preg_match('/^0x[0-9a-fA-F]{40}$/D', $addr) || strtolower($addr) === '0x'.str_repeat('0', 40)) {
                    $skipped++;

                    continue;
                }
                $addr = strtolower($addr);
            }

            if ($addr === '') {
                continue;
            }

            $exists = CryptoWallet::where('chain', $data['chain'])->whereRaw('LOWER(address) = ?', [strtolower($addr)])->exists();

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

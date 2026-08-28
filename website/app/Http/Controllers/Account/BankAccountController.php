<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Services\Identity\IranianKyc;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

/**
 * حساب بانکی مشتری.
 *
 * کاربر شمارهٔ کارت می‌دهد؛ ما نامِ صاحب کارت را از بانک می‌پرسیم و با نام
 * رسمی او (از ثبت احوال) می‌سنجیم. نخواند، هیچ چیز ذخیره نمی‌شود.
 *
 * چیزی که در نهایت نگه می‌داریم شبا و شماره حساب است، نه کارت — چون برای
 * تسویه و بازگشت وجه آن دو لازم‌اند و کارت فقط وسیلهٔ رسیدن به آن‌ها بود.
 *
 * هزینه: هر استعلام کارت پول دارد، پس لومن (Luhn) و طول کارت محلی بررسی
 * می‌شوند و سقف تلاش روزانه گذاشته شده است.
 */
class BankAccountController extends Controller
{
    private const MAX_PER_DAY = 5;

    public function __construct(private IranianKyc $kyc) {}

    public function index(Request $request)
    {
        $customer = Auth::guard('customer')->user();

        return view('account.bank', AccountController::shell('bank') + [
            'accounts'   => $customer->bankAccounts()->latest('id')->get(),
            'identity'   => $customer->identityVerification,
            'nameLocked' => $customer->isNameLocked(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $customer = Auth::guard('customer')->user();

        $request->validate(['card' => ['required', 'string', 'max:32']], [], ['card' => 'شمارهٔ کارت']);

        $card = $this->digits($request->string('card')->toString());

        // ── بررسی‌های رایگان اول ──
        if (strlen($card) !== 16) {
            return back()->withErrors(['card' => __('ui.bnk_len')]);
        }

        if (! $this->luhn($card)) {
            return back()->withErrors(['card' => __('ui.bnk_invalid')]);
        }

        if ($customer->identityVerification?->status !== 'verified') {
            return back()->withErrors(['card' => __('ui.bnk_kyc_first')]);
        }

        $key = 'bank:'.$customer->id;

        if (RateLimiter::tooManyAttempts($key, self::MAX_PER_DAY)) {
            return back()->withErrors([
                'card' => 'تعداد تلاش امروز تمام شد. فردا دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.',
            ]);
        }

        RateLimiter::hit($key, 86400);

        // ── و حالا استعلام پولی ──
        $outcome = $this->kyc->addBankAccount($customer, $card);

        if (! $outcome->ok) {
            return back()->withErrors([
                'card' => $outcome->serviceDown
                    ? 'سرویس استعلام بانکی موقتاً در دسترس نیست. کمی بعد تلاش کنید.'
                    : $outcome->error,
            ]);
        }

        return back()->with('ok', __('ui.bnk_ok'));
    }

    /** الگوریتم لومن — همان بررسی‌ای که درگاه‌های بانکی می‌کنند */
    private function luhn(string $number): bool
    {
        $sum = 0;

        for ($i = 0; $i < 16; $i++) {
            $d = (int) $number[15 - $i];

            if ($i % 2 === 1) {
                $d *= 2;

                if ($d > 9) {
                    $d -= 9;
                }
            }

            $sum += $d;
        }

        return $sum % 10 === 0;
    }

    private function digits(string $s): string
    {
        $s = strtr($s, [
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        ]);

        return preg_replace('/[^0-9]/', '', $s) ?? '';
    }
}

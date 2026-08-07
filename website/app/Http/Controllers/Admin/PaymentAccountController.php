<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * مدیریتِ حساب‌های دریافتِ آفلاین — حوالهٔ ارزی و کیفِ رمزارز.
 *
 * چرا از پنل و نه از config: کارفرما حساب‌های یورو/پوند/لیر را جداگانه و در
 * زمان‌های مختلف باز می‌کند. اگر هر حساب یک دیپلوی لازم داشته باشد، عملاً
 * ماه‌ها با یک حساب کار می‌کند.
 */
class PaymentAccountController extends Controller
{
    public function index(): View
    {
        $ready = Schema::hasTable('payment_accounts');

        return view('admin.payment-accounts', [
            'accounts' => $ready ? PaymentAccount::ordered()->get() : collect(),
            'notReady' => ! $ready,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        PaymentAccount::create($data);

        return back()->with('ok', 'حساب اضافه شد.');
    }

    public function update(Request $request, PaymentAccount $account): RedirectResponse
    {
        $account->update($this->validated($request));

        return back()->with('ok', 'حساب به‌روزرسانی شد.');
    }

    public function destroy(PaymentAccount $account): RedirectResponse
    {
        /*
        | ⚠️ حذف نمی‌کنیم، **بایگانی** می‌کنیم.
        |
        | رسیدهای پرداختِ قدیمی با `payment_account_id` به همین ردیف اشاره دارند.
        | حذفِ واقعی یعنی تاریخچهٔ مالی‌ای که بعداً باید به آن استناد شود، به یک
        | شناسهٔ مرده اشاره کند — و اختلافِ حسابِ یک سال بعد قابلِ بررسی نباشد.
        */
        $account->update(['is_active' => false]);

        return back()->with('ok', 'حساب بایگانی شد (به‌خاطر رسیدهای قبلی حذف نمی‌شود).');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(PaymentAccount::KINDS)],
            'currency_code' => ['required', 'string', 'max:8'],
            'label' => ['nullable', 'string', 'max:80'],
            'holder' => ['nullable', 'string', 'max:120'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'iban' => ['nullable', 'string', 'max:64'],
            'swift' => ['nullable', 'string', 'max:24'],
            'account_no' => ['nullable', 'string', 'max:64'],
            'country' => ['nullable', 'string', 'max:60'],
            'network' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:160'],
            'note' => ['nullable', 'string', 'max:2000'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:999'],
            'is_active' => ['nullable'],
        ]);

        $data['currency_code'] = strtoupper(trim($data['currency_code']));
        $data['is_active'] = $request->boolean('is_active');
        $data['sort'] = (int) ($data['sort'] ?? 0);

        /*
        | 🔴 رمزارز بدونِ شبکه ثبت نمی‌شود.
        |
        | انتقالِ USDT روی شبکهٔ اشتباه **برگشت‌ناپذیر** است. اگر آدرس را بدونِ
        | شبکه نشان دهیم، مشتری خودش حدس می‌زند و روزی یکی پولش را از دست
        | می‌دهد. اعتبارسنجی این‌جاست نه فقط در ویو، چون ویو را می‌شود دور زد.
        */
        if ($data['kind'] === 'crypto') {
            $request->validate([
                'network' => ['required', 'string', 'max:32'],
                'address' => ['required', 'string', 'max:160'],
            ], [], ['network' => 'شبکه', 'address' => 'آدرس کیف']);

            $data['network'] = strtoupper(trim((string) $data['network']));
        } else {
            $request->validate([
                'iban' => ['required_without:account_no', 'nullable', 'string', 'max:64'],
            ], [], ['iban' => 'IBAN']);

            // شبکه و آدرس فقط مالِ رمزارزند؛ روی حسابِ بانکی گمراه‌کننده‌اند
            $data['network'] = null;
            $data['address'] = null;
        }

        return $data;
    }
}

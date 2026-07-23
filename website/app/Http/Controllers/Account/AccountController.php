<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * پنل واقعی مشتری — برخلاف PanelPreviewController، اینجا داده از دیتابیس
 * می‌آید و پشت guard «customer» است.
 *
 * فعلاً سرویس و فاکتور نداریم (فاز بعد)، پس داشبورد روی چیزی تمرکز می‌کند که
 * واقعاً هست: وضعیت احراز هویت و کارهایی که کاربر باید تمام کند.
 */
class AccountController extends Controller
{
    public function home(): View
    {
        $customer = Auth::guard('customer')->user();

        return view('account.home', $this->shell('dash') + [
            'customer' => $customer,
            'identity' => $customer->identityVerification,
            'bank'     => $customer->bankAccounts()->where('status', 'verified')->first(),
        ]);
    }

    public function profile(): View
    {
        $customer = Auth::guard('customer')->user();

        return view('account.profile', $this->shell('profile') + [
            'customer'   => $customer,
            'identity'   => $customer->identityVerification,
            'profile'    => $customer->defaultProfile(),
            'nameLocked' => $customer->isNameLocked(),
        ]);
    }

    /** پوستهٔ پنل — همان ساختار پیش‌نمایش، ولی با دادهٔ واقعی */
    public static function shell(string $active): array
    {
        $customer = Auth::guard('customer')->user();

        return [
            'pnlActive' => $active,
            'pnlUser'   => [
                'name' => $customer?->displayName() ?? '',
                'code' => $customer?->code ?? '',
            ],
            'pnlNav' => [
                ['label' => null, 'items' => [
                    ['key' => 'dash',     'icon' => 'gauge',  'label' => 'داشبورد',  'url' => lroute('account.home')],
                    ['key' => 'services', 'icon' => 'server', 'label' => 'سرویس‌ها'],
                    ['key' => 'domains',  'icon' => 'globe',  'label' => 'دامنه‌ها'],
                ]],
                ['label' => 'مالی', 'items' => [
                    ['key' => 'invoices', 'icon' => 'coins', 'label' => 'فاکتورها و اعتبار', 'url' => lroute('account.invoices')],
                    ['key' => 'bank',     'icon' => 'db',    'label' => 'حساب بانکی', 'url' => lroute('account.bank')],
                ]],
                ['label' => 'پشتیبانی', 'items' => [
                    ['key' => 'tickets', 'icon' => 'lifebuoy', 'label' => 'تیکت‌ها'],
                ]],
                ['label' => 'حساب', 'items' => [
                    ['key' => 'profile',  'icon' => 'user',   'label' => 'پروفایل و احراز هویت', 'url' => lroute('account.profile')],
                    ['key' => 'security', 'icon' => 'shield', 'label' => 'امنیت'],
                ]],
            ],
        ];
    }
}

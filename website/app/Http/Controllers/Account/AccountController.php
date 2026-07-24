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
        $identity = $customer->identityVerification;
        $bank     = $customer->bankAccounts()->where('status', 'verified')->first();

        $openInvoices = $customer->invoices()
            ->whereIn('status', ['unpaid', 'draft'])
            ->orderBy('due_at')
            ->get();

        /*
         * «کارهای باقی‌مانده» عمداً از وضعیت واقعی ساخته می‌شود، نه از یک
         * فهرست ثابت. داشبوردی که همیشه همان سه کارت را نشان می‌دهد، بعد از
         * دو بار دیدن نامرئی می‌شود.
         */
        $todo = [];

        if ($customer->locale === 'fa' && $identity?->status !== 'verified') {
            $todo[] = [
                'icon' => 'user', 'tone' => 'd',
                'title' => __('ui.auth_kyc_title'),
                'note'  => __('ui.auth_kyc_sub'),
                'url'   => lroute('account.profile'),
            ];
        }

        if ($identity?->status === 'verified' && $bank === null) {
            $todo[] = [
                'icon' => 'db', 'tone' => 'w',
                'title' => __('ui.pnl_bank_add'),
                'note'  => __('ui.pnl_bank_why'),
                'url'   => lroute('account.bank'),
            ];
        }

        foreach ($openInvoices as $inv) {
            $todo[] = [
                'icon' => 'coins', 'tone' => 'w',
                'title' => __('ui.pnl_invoice_due', ['number' => $inv->number]),
                'note'  => fa_num(number_format($inv->due())).' '.__('ui.pnl_toman'),
                'url'   => lroute('account.invoice', $inv),
            ];
        }

        return view('account.home', $this->shell('dash') + [
            'customer'     => $customer,
            'identity'     => $identity,
            'bank'         => $bank,
            'todo'         => $todo,
            'openInvoices' => $openInvoices,
            'credit'       => $customer->creditBalance(),
            'recent'       => $customer->payments()->latest('id')->limit(5)->get(),
            // لاگ فعالیت و IP — حس پویایی و امنیت
            'activity'     => \Illuminate\Support\Facades\Schema::hasTable('activity_logs')
                ? \App\Models\ActivityLog::where('customer_id', $customer->id)->latest('id')->limit(8)->get()
                : collect(),
            'currentIp'    => request()->ip(),
            // شمارش واقعی به‌جای صفرِ ثابت
            'serviceCount' => \Illuminate\Support\Facades\Schema::hasTable('services')
                ? $customer->services()->whereIn('status', ['active', 'pending'])->count() : 0,
            'ticketOpen'   => \Illuminate\Support\Facades\Schema::hasTable('tickets')
                ? $customer->tickets()->whereIn('status', ['open', 'answered'])->count() : 0,
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
                'name'   => $customer?->displayName() ?? '',
                'code'   => $customer?->code ?? '',
                'email'  => $customer?->email,
                'avatar' => avatar_url($customer?->email),
            ],
            'pnlNav' => [
                ['label' => null, 'items' => [
                    ['key' => 'dash', 'icon' => 'gauge', 'label' => __('ui.nav_dash'), 'url' => lroute('account.home')],
                    ['key' => 'services', 'icon' => 'server', 'label' => __('ui.nav_services'), 'url' => lroute('account.services')],
                    ['key' => 'domains', 'icon' => 'globe', 'label' => __('ui.nav_domains')],
                ]],
                ['label' => __('ui.nav_finance'), 'items' => [
                    ['key' => 'invoices', 'icon' => 'coins', 'label' => __('ui.nav_invoices'), 'url' => lroute('account.invoices')],
                    ['key' => 'bank', 'icon' => 'db', 'label' => __('ui.nav_bank'), 'url' => lroute('account.bank')],
                ]],
                ['label' => __('ui.tk_title'), 'items' => [
                    ['key' => 'tickets', 'icon' => 'lifebuoy', 'label' => __('ui.tk_title'), 'url' => lroute('account.tickets')],
                ]],
                ['label' => __('ui.nav_account'), 'items' => [
                    ['key' => 'profile', 'icon' => 'user', 'label' => __('ui.nav_profile'), 'url' => lroute('account.profile')],
                    ['key' => 'security', 'icon' => 'shield', 'label' => __('ui.nav_security')],
                ]],
            ],
        ];
    }
}

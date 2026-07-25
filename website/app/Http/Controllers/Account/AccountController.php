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

    /**
     * پروفایل + احراز هویت در **یک** صفحه.
     *
     * قبلاً /account/profile و /account/verify دو صفحهٔ جدا بودند و کاربر
     * نمی‌فهمید تفاوتشان چیست. حالا این صفحه هم وضعیتِ هویتِ شخصی را نشان
     * می‌دهد و هم فرمِ مدارکِ شرکت (برای مشتریِ حقوقی) را در خودش دارد.
     */
    public function profile(): View
    {
        $customer = Auth::guard('customer')->user();
        $profile = app(VerificationController::class)->profileFor($customer);

        return view('account.profile', $this->shell('profile') + [
            'customer'   => $customer,
            'identity'   => $customer->identityVerification,
            'profile'    => $profile,
            'nameLocked' => $customer->isNameLocked(),
            'docs'       => \App\Models\CustomerDocument::where('customer_profile_id', $profile->id)
                ->latest('id')->get()->keyBy('kind'),
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
                    ['key' => 'store', 'icon' => 'box', 'label' => __('ui.nav_store'), 'url' => lroute('account.store')],
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
                    // «پروفایل و احراز هویت» یک صفحه است؛ آیتمِ جدای verify حذف شد
                    // چون کاربر نمی‌فهمید تفاوتش با پروفایل چیست.
                    ['key' => 'profile', 'icon' => 'user', 'label' => __('ui.nav_profile'), 'url' => lroute('account.profile')],
                    ['key' => 'security', 'icon' => 'shield', 'label' => __('ui.nav_security'), 'url' => lroute('account.security')],
                ]],
            ],
        ];
    }
}

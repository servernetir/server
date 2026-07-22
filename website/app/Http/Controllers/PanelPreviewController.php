<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

/**
 * پیش‌نمایش طراحی پنل کاربری — موقتی و بدون دیتابیس.
 *
 * فقط برای تأیید ظاهر و چیدمان ساخته شده است. داده‌ها ثابت و ساختگی‌اند.
 * وقتی پنل واقعی ساخته شد، این کنترلر و روت‌هایش حذف می‌شوند.
 */
class PanelPreviewController extends Controller
{
    /** کاربر نمونه و منوی پنل — مشترک بین همهٔ صفحه‌های پیش‌نمایش */
    private function shell(string $active): array
    {
        return [
            'pnlActive' => $active,
            'pnlUser'   => [
                'name'  => 'احسان ابراهیمی',
                'first' => 'احسان',
                'code'  => 'SN-104829',
            ],
            'pnlNav' => [
                ['label' => null, 'items' => [
                    ['key' => 'dash',     'icon' => 'gauge',    'label' => 'داشبورد'],
                    ['key' => 'services', 'icon' => 'server',   'label' => 'سرویس‌ها'],
                    ['key' => 'domains',  'icon' => 'globe',    'label' => 'دامنه‌ها'],
                ]],
                ['label' => 'مالی', 'items' => [
                    ['key' => 'invoices', 'icon' => 'coins',    'label' => 'فاکتورها', 'badge' => 1],
                    ['key' => 'wallet',   'icon' => 'db',       'label' => 'اعتبار حساب'],
                ]],
                ['label' => 'پشتیبانی', 'items' => [
                    ['key' => 'tickets',  'icon' => 'lifebuoy', 'label' => 'تیکت‌ها'],
                ]],
                ['label' => 'حساب', 'items' => [
                    ['key' => 'profile',  'icon' => 'user',     'label' => 'پروفایل و احراز هویت'],
                    ['key' => 'security', 'icon' => 'shield',   'label' => 'امنیت'],
                ]],
            ],
        ];
    }

    public function dashboard(): View
    {
        return view('panel.dashboard', $this->shell('dash'));
    }

    public function server(): View
    {
        return view('panel.server', $this->shell('services'));
    }
}

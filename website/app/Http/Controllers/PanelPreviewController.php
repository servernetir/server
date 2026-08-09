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
                // ⚠️ نسخهٔ دومِ **سخت‌کدِ** منو، فقط برای /panel-preview. با منویِ
                // واقعیِ AccountController::shell() هم‌شکل نگه داشته می‌شود تا
                // پیش‌نمایش کهنه به نظر نرسد؛ خودش در فهرستِ «پیش از راه‌اندازی
                // حذف شود»ِ CLAUDE.md §۸.۵ است.
                ['label' => null, 'items' => [
                    ['key' => 'dash',     'icon' => 'gauge',    'label' => 'داشبورد',   'url' => lroute('panel.preview')],
                ]],
                ['label' => 'دارایی‌های من', 'items' => [
                    ['key' => 'services', 'icon' => 'layout',   'label' => 'همه',       'url' => lroute('panel.preview.server')],
                    ['key' => 'hosting',  'icon' => 'hdd',      'label' => 'هاست'],
                    ['key' => 'servers',  'icon' => 'cloud',    'label' => 'سرور'],
                    ['key' => 'domains',  'icon' => 'globe',    'label' => 'دامنه'],
                    ['key' => 'other',    'icon' => 'wrench',   'label' => 'خدمات'],
                ]],
                ['label' => 'مالی', 'items' => [
                    ['key' => 'invoices', 'icon' => 'coins',    'label' => 'فاکتورها', 'badge' => 1],
                    ['key' => 'wallet',   'icon' => 'db',       'label' => 'اعتبار حساب'],
                ]],
                ['label' => 'پشتیبانی', 'items' => [
                    ['key' => 'tickets',  'icon' => 'lifebuoy', 'label' => 'تیکت‌ها',   'url' => lroute('panel.preview.tickets')],
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

    /* ============================ پنل مدیریت ============================ */

    /** پوستهٔ مدیریت — کاربر و منوی متفاوت با پنل مشتری */
    private function adminShell(string $active): array
    {
        return [
            'pnlActive' => $active,
            'pnlUser'   => [
                'name'  => 'مدیر سیستم',
                'first' => 'مدیر',
                'code'  => 'ادمین',
            ],
            'pnlNav' => [
                ['label' => null, 'items' => [
                    ['key' => 'dash',      'icon' => 'gauge',   'label' => 'داشبورد', 'url' => lroute('panel.preview.admin')],
                    ['key' => 'orders',    'icon' => 'coins',   'label' => 'سفارش‌ها', 'badge' => 4],
                    ['key' => 'customers', 'icon' => 'user',    'label' => 'مشتریان'],
                    ['key' => 'services',  'icon' => 'server',  'label' => 'سرویس‌ها'],
                ]],
                ['label' => 'فروش', 'items' => [
                    ['key' => 'catalog',   'icon' => 'box',     'label' => 'محصولات و قیمت'],
                    ['key' => 'domains',   'icon' => 'globe',   'label' => 'دامنه‌ها'],
                    ['key' => 'promo',     'icon' => 'trend',   'label' => 'تخفیف و همکاری فروش'],
                ]],
                ['label' => 'زیرساخت', 'items' => [
                    ['key' => 'nodes',     'icon' => 'cpu',     'label' => 'سرورها و نودها'],
                    ['key' => 'provision', 'icon' => 'zap',     'label' => 'صف تحویل', 'badge' => 1],
                ]],
                ['label' => 'پشتیبانی', 'items' => [
                    ['key' => 'tickets',   'icon' => 'lifebuoy','label' => 'تیکت‌ها', 'badge' => 3, 'url' => lroute('panel.preview.admin.tickets')],
                ]],
                ['label' => 'سیستم', 'items' => [
                    ['key' => 'settings',  'icon' => 'wrench',  'label' => 'تنظیمات'],
                ]],
            ],
        ];
    }

    public function tickets(): View
    {
        return view('panel.tickets', $this->shell('tickets'));
    }

    public function adminTickets(): View
    {
        return view('panel.admin-tickets', $this->adminShell('tickets'));
    }

    public function adminDashboard(\App\Services\ExchangeRate $fx): View
    {
        // نرخ دلار زنده. اگر کش نداریم، همین‌جا یک بار می‌گیریم و یک ساعت کش می‌کنیم.
        // این صفحه فقط ادمین می‌بیند (کم‌ترافیک)، پس دریافت هم‌زمان پذیرفتنی است؛
        // در نسخهٔ واقعی، کران ساعتی این کار را می‌کند و صفحه فقط از کش می‌خواند.
        $rate = $fx->current();
        if ($rate === null) {
            try {
                $rate = \Illuminate\Support\Facades\Cache::remember(
                    'fx.usd_irt.attempt', now()->addMinutes(30),
                    fn () => $fx->refresh()
                );
            } catch (\Throwable $e) {
                $rate = null;
            }
        }

        return view('panel.admin', $this->adminShell('dash') + ['usd' => $rate]);
    }
}

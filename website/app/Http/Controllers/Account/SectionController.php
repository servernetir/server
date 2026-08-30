<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Support\PanelSections;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * «چهار اتاق» — هاست / سرور / دامنه / خدمات، هرکدام صفحهٔ خودش.
 *
 * ═══ چرا روتِ جدا و نه `?kind=` ═══
 *
 *  • حالتِ فعالِ منو یک مقایسهٔ رشته‌ایِ خام است
 *    (`panel/layout.blade.php:57`) و `$pnlActive` را کنترلر سخت می‌دهد — پس یک
 *    فیلترِ query هیچ آیتمی را روشن نمی‌کند.
 *  • `/account/domains` از قبل دقیقاً همین الگو را دارد (مدل، روت، کنترلر، ویو
 *    و کلیدِ منویِ خودش). یعنی الگو در همین کدبیس اثبات شده است.
 *  • هر اتاق باید نشانی‌پذیر و بوکمارک‌شدنی باشد تا بشود از ایمیل و تیکت
 *    مستقیم به آن لینک داد.
 *
 * 🔴 و `/account/services` **هیچ‌چیز از دست نمی‌دهد**: همان روت، همان کنترلرِ
 * قفل‌شده، همان ویو — ولی حالا هر چهار بخش را روی هم می‌چیند. هشت ارجاعِ ورودی
 * (از جمله اعلانِ بازگشتِ وجهِ `ProvisioningService`) سالم می‌مانند و پنج تستی
 * که محتوای ردیف را روی همان نشانی می‌سنجند بی‌هیچ ویرایشی سبز می‌مانند.
 *
 * ⚠️ این کنترلر عمداً `ServiceController` را دست نمی‌زند (مالکِ دیگری دارد) و
 * فقط از `Service::PANEL_STATUSES` و همان eager loadها استفاده می‌کند.
 */
class SectionController extends Controller
{
    public function hosting(): View
    {
        return $this->room('hosting', 'account.hosting');
    }

    public function servers(): View
    {
        return $this->room('server', 'account.servers');
    }

    public function other(): View
    {
        return $this->room('other', 'account.other');
    }

    /**
     * یک اتاق: همان دادهٔ نمای «همه»، ولی فقط سطلِ خودش.
     *
     * ⚠️ سبدهای دیگر **خالی** فرستاده می‌شوند نه حذف — قالبِ بخش‌ها یکی است و
     * ویو نباید بداند روی کدام صفحه است تا دو رفتار پیدا کند.
     */
    private function room(string $kind, string $view): View
    {
        $customer = Auth::guard('customer')->user();
        $services = $this->scoped($customer);

        $data = PanelSections::build($customer, $services);

        // اتاقِ دامنه صفحهٔ خودش را دارد (`/account/domains`)؛ این سه اتاق
        // فهرستِ دامنه را لازم ندارند، ولی شمارشِ سوییچر لازمش دارد.
        return view($view, AccountController::shell($this->navKey($kind)) + $data + [
            'services' => $data['secBuckets'][$kind] ?? collect(),
            'secKind'  => $kind,
            'secLens'  => $this->navKey($kind),
        ]);
    }

    private function navKey(string $kind): string
    {
        return match ($kind) {
            'hosting' => 'hosting',
            'server'  => 'servers',
            default   => 'other',
        };
    }

    /**
     * همان اسکوپِ `ServiceController::index()` — کلمه‌به‌کلمه، از روی ثابتِ
     * مشترک. اگر این‌جا فهرستِ دیگری بنویسیم، روزی یکی از دو صفحه سرویسی را
     * نشان می‌دهد که دیگری پنهانش می‌کند.
     *
     * @return Collection<int,Service>
     */
    private function scoped($customer): Collection
    {
        if ($customer === null || ! Schema::hasTable('services')) {
            return collect();
        }

        return $customer->services()
            ->whereIn('status', Service::PANEL_STATUSES)
            ->with(['invoices' => fn ($q) => $q->latest('id'), 'server', 'cloudInstance'])
            ->latest('id')
            ->get();
    }
}

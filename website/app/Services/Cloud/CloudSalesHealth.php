<?php

namespace App\Services\Cloud;

use App\Models\CloudPlan;
use Illuminate\Support\Facades\Schema;

/** Local catalogue eligibility, grouped by supplier. No API calls, purchases or sales switches. */
class CloudSalesHealth
{
    public function snapshot(): array
    {
        if (! Schema::hasTable('cloud_plans')) { return []; }
        $snapshot = app(CloudPricingSnapshot::class);
        $reasons = $snapshot->exclusionReasons();
        $disabled = CloudPlan::disabledProviders();
        $manager = app(CloudManager::class);
        $out = [];
        foreach (CloudPlan::query()->distinct()->pluck('provider') as $provider) {
            $base = CloudPlan::where('provider', $provider);
            $active = (clone $base)->where('is_active', true)->where('admin_disabled', false);
            $count = (clone $active)->count();
            $sellable = (clone $base)->sellable()->count();
            $reason = match (true) {
                in_array($provider, $disabled, true) => 'admin_disabled',
                ! $manager->driver($provider)?->isConfigured() => 'not_configured',
                ! $snapshot->current() => 'stale_price_snapshot',
                ! $snapshot->current($provider) => $reasons[$provider] ?? 'provider_quote_unverified',
                $provider === 'vpstc' && ! app(VpsTcSales::class)->enabled() => 'sales_preflight',
                $count === 0 => 'no_active_plan',
                ! (clone $active)->where('in_stock', true)->exists() => 'no_stock',
                $sellable === 0 => 'no_valid_price',
                default => 'available',
            };
            if ($provider === 'salad' && $reason === 'available' && ! app(GpuSalesHealth::class)->snapshot()['ok']) {
                $reason = 'no_orderable_app';
            }
            $out[$provider] = ['ok' => $reason === 'available', 'reason' => $reason,
                'offers' => $reason === 'available' ? $sellable : 0,
                'label' => $manager->realLabel($provider)];
        }
        return $out;
    }

    public static function reasonText(string $reason): string
    {
        if (preg_match('/^upstream_http_(5[0-9]{2})$/D', $reason, $match)) {
            return 'API زیرساخت پاسخ HTTP '.$match[1].' می‌دهد؛ ارتباط سمت تأمین‌کننده کامل نشده است.';
        }
        return match ($reason) {
            'admin_disabled' => 'فروش این زیرساخت در تنظیمات مدیریتی بسته است.',
            'account_currency_unverified' => 'واحد ارز حساب زیرساخت از دادهٔ معتبر مالی تأیید نشد؛ قیمت بدون آن منتشر نمی‌شود.',
            'authentication_failed' => 'API زیرساخت ورود حساب را رد کرده است؛ اطلاعات اتصال و مجوز API بررسی شود.',
            'connection_failed' => 'ارتباط امن با API زیرساخت برقرار نشد؛ شبکه و TLS بررسی شود.',
            'rate_limited' => 'API زیرساخت تعداد درخواست‌ها را محدود کرده است؛ تلاش تازه پس از رفع محدودیت انجام شود.',
            'access_denied' => 'حساب API مجوز دسترسی به این منبع را ندارد.',
            'not_configured' => 'اطلاعات اتصال زیرساخت کامل یا قابل استفاده نیست.',
            'stale_price_snapshot' => 'قیمت کاتالوگ با نرخ ارز فعلی هماهنگ نیست؛ انتشار قیمت کامل نشده است.',
            'supplier_cost_read_failed' => 'خواندن بهای تازه از API زیرساخت ناموفق بود؛ قیمت قدیمی اجازهٔ فروش ندارد.',
            'missing_quote' => 'بهای معتبر همهٔ پلن‌های موجود دریافت نشد؛ پلن بدون تأیید تازه فروخته نمی‌شود.',
            'invalid_quote' => 'پاسخ قیمت یا هویت/منطقهٔ پلن معتبر نبود؛ قیمت مشکوک کنار گذاشته شد.',
            'provider_quote_unverified' => 'قیمت و شرایط فعلی همین زیرساخت در snapshot فروش تأیید نشده است.',
            'sales_preflight' => 'پیش‌شرط‌های قیمت، چرخهٔ عمر یا مجوز فروش همین زیرساخت کامل نیست.',
            'no_active_plan' => 'هیچ پلن فعال و باز برای فروش باقی نمانده است.',
            'no_stock' => 'هیچ پلن فعالی با موجودی قابل عرضه ثبت نشده است.',
            'no_valid_price' => 'هیچ پلن دارای موجودی و قیمت معتبرِ قابل فروش باقی نمانده است.',
            'no_orderable_app' => 'کارت GPU موجود است ولی هیچ برنامهٔ آمادهٔ قابل سفارش وجود ندارد.',
            default => 'بررسی قابلیت فروش کامل نشد؛ سلامت عرضه تأیید نشده است.',
        };
    }
}

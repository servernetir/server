<?php

namespace App\Services\Provisioning;

use App\Models\Service;

/**
 * درایورِ دستی — برای سرورهایی که هنوز تحویلِ خودکار (API) ندارند:
 * VPS، سرور اختصاصی، Plesk، DirectAdmin.
 *
 * چیزی روی API نمی‌سازد؛ فقط سرویس را «در انتظار تحویل دستی» علامت می‌زند تا
 * در صفِ کارِ ادمین بیفتد. ادمین بعد از ساختِ دستی، اطلاعاتِ ورود را وارد و
 * «تحویل‌شده» می‌زند. ساختارش مثلِ LogSmsSender (درایورِ بی‌اثر) است.
 */
class ManualProvisioner implements Provisioner
{
    public function slug(): string
    {
        return 'manual';
    }

    public function create(Service $service): ProvisionResult
    {
        return ProvisionResult::manual('این نوع سرور تحویلِ خودکار ندارد؛ منتظرِ آماده‌سازیِ دستی توسطِ پشتیبانی است.');
    }

    public function suspend(Service $service): ProvisionResult
    {
        return ProvisionResult::manual('تعلیق باید به‌صورت دستی روی سرور انجام شود.');
    }

    public function unsuspend(Service $service): ProvisionResult
    {
        return ProvisionResult::manual('رفعِ تعلیق باید به‌صورت دستی انجام شود.');
    }

    public function terminate(Service $service): ProvisionResult
    {
        return ProvisionResult::manual('حذف باید به‌صورت دستی روی سرور انجام شود.');
    }
}

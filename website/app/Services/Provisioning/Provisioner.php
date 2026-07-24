<?php

namespace App\Services\Provisioning;

use App\Models\Service;

/**
 * قراردادِ درایورِ فراهم‌سازی — هر نوع سرور (WHM، Plesk، DirectAdmin، دستی)
 * این را پیاده می‌کند. الگو از PaymentGateway گرفته شده.
 *
 * قاعدهٔ طلایی: create باید idempotent باشد — اگر حساب از قبل ساخته شده،
 * دوباره نسازد (تا پرداختِ دوباره یا اجرای دوبارهٔ کرون، حسابِ تکراری نسازد).
 */
interface Provisioner
{
    /** شناسهٔ درایور (whm|manual|…) */
    public function slug(): string;

    /** ساختِ حساب برای این سرویس (idempotent) */
    public function create(Service $service): ProvisionResult;

    public function suspend(Service $service): ProvisionResult;

    public function unsuspend(Service $service): ProvisionResult;

    public function terminate(Service $service): ProvisionResult;
}

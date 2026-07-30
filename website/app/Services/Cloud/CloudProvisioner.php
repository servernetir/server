<?php

namespace App\Services\Cloud;

use App\Models\CloudImage;
use App\Models\CloudInstance;
use App\Models\CloudPlan;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * تحویلِ خودکارِ سرورِ ابری — از «پرداخت شد» به «سرورِ روشن».
 *
 * چرا کلاسِ جدا و نه یک درایورِ `Provisioner`: قراردادِ موجود حولِ مدلِ `Server`
 * (سرورهای WHM/DirectAdmin که مدیر دستی ثبت می‌کند) ساخته شده، ولی سرورِ ابری
 * **پیش از خرید وجود ندارد** و لحظهٔ تحویل ساخته می‌شود. زور زدن به آن قرارداد،
 * ردیف‌های قلابی در `servers` می‌خواست.
 *
 * ═══ idempotency: مهم‌ترین بخشِ این فایل ═══
 *
 * `provision:run` هر دقیقه می‌دود و می‌تواند روی یک سرویس دو بار بیفتد. اگر
 * محافظت نکنیم، **دو سرور می‌خریم و پولِ واقعی می‌سوزد**. سه لایه محافظ:
 *
 *  ۱) قفلِ وضعیتیِ اتمی (pending → running با یک UPDATE شرطی)، همان الگوی
 *     `ProvisioningService`.
 *  ۲) نامِ سرور **قطعی** است (`sn-svc-{id}`) نه تصادفی. اگر تلاشِ اول سرور را
 *     ساخت ولی پاسخ به ما نرسید، تلاشِ دوم خطای «نامِ تکراری» می‌گیرد و درایور
 *     همان سرورِ موجود را برمی‌گرداند — نه اینکه دومی بخرد.
 *  ۳) ردیفِ `cloud_instances` **قبل** از تماسِ API ساخته می‌شود؛ اگر وسطِ کار
 *     برق برود، دفعهٔ بعد می‌دانیم سراغِ کدام سرویس رفته بودیم.
 */
class CloudProvisioner
{
    public function __construct(private CloudManager $manager) {}

    /** آیا این سرویس، سرورِ ابری است؟ */
    public static function handles(Service $service): bool
    {
        return filled($service->cloud_plan_id);
    }

    /**
     * تلاش برای تحویل. هرگز استثنا پرت نمی‌کند.
     *
     * @return bool موفقیت
     */
    public function provision(Service $service): bool
    {
        if ($service->provision_status === 'done') {
            return true;
        }

        // ── لایهٔ ۱: قفلِ اتمی ──
        $claimed = Service::whereKey($service->id)
            ->where(function ($q) {
                $q->whereIn('provision_status', ['pending', 'failed', 'manual'])->orWhereNull('provision_status');
            })
            ->update(['provision_status' => 'running']);

        if ($claimed === 0) {
            return $service->fresh()?->provision_status === 'done';
        }

        $service->refresh();

        try {
            return $this->deliver($service);
        } catch (\Throwable $e) {
            Log::error('cloud.provision.failed', ['service' => $service->id, 'err' => $e->getMessage()]);
            $this->fail($service, 'خطای غیرمنتظره: '.mb_substr($e->getMessage(), 0, 160));

            return false;
        }
    }

    private function deliver(Service $service): bool
    {
        // ── انتخابِ زیرساخت در **لحظهٔ تحویل**، نه لحظهٔ سفارش ──
        // بین سفارش و پرداخت ممکن است ارزان‌ترین زیرساخت پر شده باشد. با انتخابِ
        // دیرهنگام، خودکار سراغِ بعدی می‌رویم و مشتری هیچ تفاوتی نمی‌بیند.
        $ordered = CloudPlan::find($service->cloud_plan_id);

        if ($ordered === null) {
            $this->fail($service, 'پلنِ سفارش‌شده پیدا نشد.');

            return false;
        }

        $plan = CloudPlan::bestForSlug((string) $ordered->slug) ?? $ordered;

        $driver = $this->manager->forPlan($plan);

        if ($driver === null || ! $driver->isConfigured()) {
            // «pending» می‌مانَد تا کرونِ بعدی دوباره تلاش کند — چون این خرابیِ
            // ما است نه خطای مشتری، و با وارد کردنِ توکن خودش حل می‌شود.
            $this->retryLater($service, 'زیرساختِ تحویل در دسترس نیست؛ تلاشِ خودکار ادامه دارد.');

            return false;
        }

        if (! $plan->in_stock) {
            $this->retryLater($service, 'ظرفیتِ این پلن موقتاً تمام است؛ تلاشِ خودکار ادامه دارد.');

            return false;
        }

        // ── سیستم‌عاملِ انتخابیِ مشتری → شناسهٔ بومیِ همین زیرساخت ──
        $imageKey = (string) ($service->cloud_image_key ?: config('cloud.default_image', 'ubuntu-24.04'));
        $imageRef = CloudImage::refFor($plan->provider, $imageKey);

        if ($imageRef === null) {
            // نبودِ همان سیستم‌عامل روی این زیرساخت: به‌جای شکست، سراغِ
            // زیرساختِ دیگری برو که داردش. مشتری اوبونتو خواسته، نه یک برند.
            $alt = $this->planWithImage((string) $ordered->slug, $imageKey);

            if ($alt === null) {
                $this->fail($service, 'سیستم‌عاملِ انتخابی برای این پلن در دسترس نیست.');

                return false;
            }

            $plan = $alt;
            $driver = $this->manager->forPlan($plan);
            $imageRef = CloudImage::refFor($plan->provider, $imageKey);

            if ($driver === null || $imageRef === null) {
                $this->fail($service, 'سیستم‌عاملِ انتخابی برای این پلن در دسترس نیست.');

                return false;
            }
        }

        // ── لایهٔ ۳: ردیفِ نمونه پیش از تماس ──
        $instance = CloudInstance::firstOrNew(['service_id' => $service->id]);
        $instance->fill([
            'provider'      => $plan->provider,
            'location_code' => $plan->location_code,
            'image_key'     => $imageKey,
            'hostname'      => $this->serverName($service),
            'status'        => 'building',
            'specs'         => [
                'vcpu' => $plan->vcpu, 'ram_mb' => $plan->ram_mb,
                'disk_gb' => $plan->disk_gb, 'disk_type' => $plan->disk_type,
                'traffic_gb' => $plan->traffic_gb, 'cpu_kind' => $plan->cpu_kind,
                'plan_name' => $plan->public_name,
            ],
        ]);
        $instance->save();

        // ── لایهٔ ۲: نامِ قطعی ──
        $result = $driver->createServer([
            'name'         => $this->serverName($service),
            'plan_ref'     => (string) $plan->provider_ref,
            'location_ref' => (string) ($plan->provider_location ?: $plan->location_code),
            'image_ref'    => $imageRef,
            'labels'       => ['snet-service' => (string) $service->id],
        ]);

        if (! ($result['ok'] ?? false)) {
            $instance->update(['status' => 'error', 'last_error' => mb_substr((string) $result['message'], 0, 500)]);
            $this->fail($service, mb_substr('تحویلِ سرور ناموفق: '.$result['message'], 0, 290));

            return false;
        }

        // ── رمزِ root ──
        // زیرساختِ ۱ رمز را در پاسخِ ساخت می‌دهد؛ زیرساختِ ۲ نمی‌دهد، پس همان‌جا
        // یکی ست می‌کنیم. بی‌این، مشتری سرور دارد ولی راهی به داخلش ندارد.
        $password = $result['root_password'] ?? null;

        if (blank($password) && filled($result['ref'] ?? null) && ! str_starts_with((string) $result['ref'], 'order:')) {
            $pw = $driver->resetPassword((string) $result['ref']);
            $password = $pw['root_password'] ?? null;
        }

        $instance->fill([
            'provider_ref' => $result['ref'] ?? null,
            'ipv4'         => $result['ipv4'] ?? null,
            'ipv6'         => $result['ipv6'] ?? null,
            'status'       => $result['status'] ?? 'building',
            'last_error'   => null,
            'synced_at'    => now(),
        ]);

        if (filled($password)) {
            $instance->setPassword($password);
        }

        $instance->save();

        // ── سرویس: فعال ──
        DB::transaction(function () use ($service, $instance, $plan, $password) {
            $service->forceFill([
                'cloud_plan_id'    => $plan->id,      // زیرساختِ واقعیِ تحویل
                'username'         => 'root',
                'password'         => $password ?: $service->password,
                'domain'           => $service->domain ?: ($instance->ipv4 ?: null),
                'panel_url'        => url('/account/cloud/'.$service->id),
                'provision_status' => 'done',
                'provision_error'  => null,
                'provisioned_at'   => now(),
                'provision_meta'   => [
                    'kind' => 'cloud',
                    'ip'   => $instance->ipv4,
                    'ipv6' => $instance->ipv6,
                    'plan' => $plan->public_name,
                    'location' => $plan->location_code,
                ],
                'status'           => 'active',
                'activated_at'     => $service->activated_at ?? now(),
            ])->save();
        });

        $this->notify($service, $instance);

        return true;
    }

    /** پلنِ هم‌اسلاگ روی زیرساختی که این سیستم‌عامل را دارد */
    private function planWithImage(string $slug, string $imageKey): ?CloudPlan
    {
        $providers = CloudImage::query()->usable()->where('key', $imageKey)->pluck('provider')->unique();

        if ($providers->isEmpty()) {
            return null;
        }

        return CloudPlan::query()
            ->sellable()
            ->where('slug', $slug)
            ->whereIn('provider', $providers)
            ->orderBy('cost_eur_cents')
            ->first();
    }

    /**
     * نامِ قطعیِ سرور — پایهٔ idempotency.
     *
     * قواعدِ نامِ میزبان: حروفِ کوچک، رقم و خط تیره؛ باید با حرف شروع شود. با
     * شناسهٔ سرویس، تلاشِ دوباره همان نام را می‌سازد و «تکراری» می‌خورد، پس
     * سرورِ دومی خریده نمی‌شود.
     */
    private function serverName(Service $service): string
    {
        return 'sn-svc-'.$service->id;
    }

    // ───────────────────────── وضعیت‌ها ─────────────────────────

    private function fail(Service $service, string $reason): void
    {
        $service->forceFill([
            'provision_status' => 'failed',
            'provision_error'  => mb_substr($reason, 0, 290),
            'status'           => 'awaiting_provision',
        ])->save();

        try {
            \App\Models\ActivityLog::record(null, 'service',
                'تحویلِ سرورِ ابریِ سرویس #'.$service->id.' ناموفق: '.$reason, null, 'system');
        } catch (\Throwable) {
        }
    }

    /** خرابیِ گذرا: pending بمان تا کرونِ بعدی دوباره تلاش کند */
    private function retryLater(Service $service, string $reason): void
    {
        $service->forceFill([
            'provision_status' => 'pending',
            'provision_error'  => mb_substr($reason, 0, 290),
            'status'           => 'awaiting_provision',
        ])->save();
    }

    private function notify(Service $service, CloudInstance $instance): void
    {
        try {
            app(\App\Services\Notify\CustomerNotifier::class)->event(
                $service->customer, 'service_ready', [],
                'سرورِ «'.$service->name.'» شما آماده شد. IP: '.($instance->ipv4 ?: '—')
            );
        } catch (\Throwable) {
            // اعلان نباید تحویل را بشکند
        }

        try {
            $customer = $service->customer;

            if ($customer && filled($customer->email)) {
                \Illuminate\Support\Facades\Mail::mailer('smtp')->to($customer->email)->send(
                    new \App\Mail\ServiceReadyMail(
                        $service->name,
                        $instance->ipv4 ?: $service->domain,
                        $service->panel_url,
                        'root',
                        $instance->password(),
                        $customer->locale ?: 'fa',
                    )
                );
            }
        } catch (\Throwable) {
        }

        try {
            app(\App\Services\Notify\AdminNotifier::class)->event(
                'سرورِ ابری تحویل شد',
                [
                    'سرویس' => $service->name,
                    'مشتری' => $service->customer?->name ?? '—',
                    'IP'    => $instance->ipv4 ?: '—',
                    'مکان'  => $instance->location_code ?: '—',
                ],
                url('/admin/services'),
                '🖥️'
            );
        } catch (\Throwable) {
        }
    }

    // ───────────────────────── چرخهٔ عمر ─────────────────────────

    /**
     * تعلیق = خاموش کردن.
     *
     * سرورِ ابری «تعلیقِ نرم» ندارد (مثلِ cPanel که حساب می‌ماند و دسترسی بسته
     * می‌شود). خاموش کردن نزدیک‌ترین معادل است: داده سرِ جایش می‌ماند و با
     * پرداخت، روشن می‌شود. **حذف نمی‌کنیم** — مشتریِ بدهکار هم حقِ داده‌اش را
     * دارد و ما هنوز اجارهٔ سرور را می‌دهیم؛ خاتمه تصمیمِ آگاهانهٔ مدیر است.
     */
    public function suspend(Service $service): bool
    {
        return $this->act($service, fn ($d, $ref) => $d->power($ref, 'off'), 'off');
    }

    public function unsuspend(Service $service): bool
    {
        return $this->act($service, fn ($d, $ref) => $d->power($ref, 'on'), 'running');
    }

    /** خاتمه = حذفِ واقعیِ سرور نزدِ زیرساخت (وگرنه هزینه‌اش را ما می‌دهیم) */
    public function terminate(Service $service): bool
    {
        $instance = CloudInstance::where('service_id', $service->id)->first();

        if ($instance === null || blank($instance->provider_ref)) {
            return true;                              // چیزی برای حذف نیست
        }

        $driver = $this->manager->forInstance($instance);

        if ($driver === null) {
            return false;
        }

        $r = $driver->deleteServer((string) $instance->provider_ref);

        if ($r['ok'] ?? false) {
            $instance->update(['status' => 'deleted', 'last_error' => null]);

            return true;
        }

        $instance->update(['last_error' => mb_substr((string) $r['message'], 0, 500)]);

        return false;
    }

    private function act(Service $service, callable $fn, string $expectStatus): bool
    {
        $instance = CloudInstance::where('service_id', $service->id)->first();

        if ($instance === null || blank($instance->provider_ref)) {
            return false;
        }

        $driver = $this->manager->forInstance($instance);

        if ($driver === null) {
            return false;
        }

        $r = $fn($driver, (string) $instance->provider_ref);

        if ($r['ok'] ?? false) {
            $instance->update(['status' => $expectStatus, 'last_error' => null, 'synced_at' => now()]);

            return true;
        }

        $instance->update(['last_error' => mb_substr((string) $r['message'], 0, 500)]);

        return false;
    }
}

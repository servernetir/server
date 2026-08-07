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
    public function __construct(
        private CloudManager $manager,
        private CloudAddons $addons,
    ) {}

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
                $q->whereIn('provision_status', ['pending', 'failed', 'manual'])
                    ->orWhereNull('provision_status')
                    // قفلِ کهنه: پروسهٔ قبلی وسطِ کار مرده است. بی‌این، سرویس
                    // تا ابد در 'running' گیر می‌کرد و هیچ‌کس بیرونش نمی‌آورد.
                    ->orWhere(fn ($s) => $s->where('provision_status', 'running')
                        ->where('updated_at', '<', now()->subMinutes(15)));
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
            // «تحویل شکست نمی‌خورد، فقط اتفاق نمی‌افتد» — بدترین نوعِ خرابی
            // در این پروژه. باید در /admin/errors دیده شود، نه فقط در
            // laravel.logِ غیرقابلِ‌خواندن روی cPanel.
            \App\Support\ErrorTracker::note('provision', $e, ['service' => $service->id]);
            $this->fail($service, 'خطای غیرمنتظره: '.mb_substr($e->getMessage(), 0, 160));

            return false;
        }
    }

    private function deliver(Service $service): bool
    {
        // ── 🔴 محافظِ سوءاستفاده، پیش از هر تماسِ پولی ──
        //
        // تحویل کاملاً خودکار است، پس هر حسابِ تازه یک دکمهٔ مستقیم به APIِ
        // زیرساختِ خارجی دارد. با کارتِ دزدیده‌شده می‌شود در چند دقیقه ده‌ها
        // سرور گرفت؛ بعد chargeback می‌خورد و هم صورتحساب پای ماست هم گزارشِ
        // abuse — و بدتر، حسابِ مادرِ ما تعلیق می‌شود که یعنی سرورِ **همهٔ**
        // مشتریانِ خارج هم‌زمان می‌رود.
        //
        // عمداً «رد» نمی‌کند: به صفِ بازبینیِ دستی می‌رود تا مدیر ببیند. یک
        // تأخیرِ کوتاه برای مشتریِ واقعی، از قطعِ فروش خیلی ارزان‌تر است.
        if ($service->customer !== null) {
            $verdict = app(CloudFraudGuard::class)->check($service->customer);

            if ($verdict['hold']) {
                $this->needsReview($service, (string) $verdict['reason']);

                return false;
            }
        }

        // ── انتخابِ زیرساخت در **لحظهٔ تحویل**، نه لحظهٔ سفارش ──
        // بین سفارش و پرداخت ممکن است ارزان‌ترین زیرساخت پر شده باشد. با انتخابِ
        // دیرهنگام، خودکار سراغِ بعدی می‌رویم و مشتری هیچ تفاوتی نمی‌بیند.
        $ordered = CloudPlan::find($service->cloud_plan_id);

        if ($ordered === null) {
            $this->fail($service, 'پلنِ سفارش‌شده پیدا نشد.');

            return false;
        }

        // انتخابِ دیرهنگامِ زیرساخت — با دو قید: موجودی، و توانِ تحویلِ
        // **افزودنی‌های خریده‌شده**. اگر مشتری IP اضافه خریده و ارزان‌ترین
        // زیرساخت آن را نمی‌دهد، سراغِ بعدی می‌رویم؛ وگرنه پولِ چیزی را گرفته‌ایم
        // که تحویلش ممکن نیست.
        $wanted = $this->addons->sanitize($service->cloud_addons);

        $plan = $this->addons->bestPlanFor((string) $ordered->slug, $wanted, $this->manager)
            ?? CloudPlan::bestForSlug((string) $ordered->slug)
            ?? $ordered;

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
        $imageRef = CloudImage::refFor($plan->provider, $imageKey, $plan->arch);

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
            $imageRef = CloudImage::refFor($plan->provider, $imageKey, $plan->arch);

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

        // ── لایهٔ ۲الف: اگر از قبل سرور خریده‌ایم، دوباره نخر ──
        //
        // 🔴 چرا صریح لازم است: محافظِ «نامِ تکراری» فقط روی زیرساختی کار می‌کند
        // که نامِ سرور را یکتا می‌گیرد و خطای uniqueness می‌دهد. زیرساختِ دوم
        // سفارش‌محور است و هر POST یک **سفارشِ تازه و پولِ تازه** است. سناریوی
        // واقعی: تماسِ اول در سمتِ آنها موفق می‌شود ولی پاسخ به ما نمی‌رسد
        // (تایم‌اوت) → ما 'failed' ثبت می‌کنیم → ادمین «تلاش دوباره» می‌زند →
        // سرورِ دوم خریده می‌شود و سرورِ اول یتیم می‌مانَد و اجاره‌اش تا ابد از
        // حسابِ ما کم می‌شود، بی‌آنکه کسی بفهمد.
        //
        // پس اگر شناسه‌ای داریم، فقط وضعیت را می‌گیریم و همان را ادامه می‌دهیم.
        if (filled($instance->provider_ref)) {
            return $this->adoptExisting($service, $instance, $plan, $driver);
        }

        // ── کلیدِ SSH ──
        // باید **پیش** از ساختِ سرور در حسابِ ما نزدِ زیرساخت باشد، چون سرِ ساخت
        // فقط اشاره به کلیدِ موجود پذیرفته می‌شود نه متنِ کلید.
        $sshRefs = $this->sshKeyRefs($service, $plan);

        // ── لایهٔ ۲: نامِ قطعی ──
        $result = $driver->createServer([
            'name'         => $this->serverName($service),
            'plan_ref'     => (string) $plan->provider_ref,
            'location_ref' => (string) ($plan->provider_location ?: $plan->location_code),
            'image_ref'    => $imageRef,
            'ssh_keys'     => $sshRefs,
            // بعضی زیرساخت‌ها (آروان) اندازهٔ دیسک را جدا می‌خواهند
            'disk_gb'      => (int) $plan->disk_gb,
            'labels'       => ['snet-service' => (string) $service->id],
        ]);

        if (! ($result['ok'] ?? false)) {
            $instance->update(['status' => 'error', 'last_error' => mb_substr((string) $result['message'], 0, 500)]);

            // 🔴 اگر ایراد **حسابِ زیرساخت** است، فروشِ آن پلن‌ها را ببند
            $this->quarantineProvider($plan, (string) $result['message']);

            $this->fail($service, mb_substr('تحویلِ سرور ناموفق: '.$result['message'], 0, 290));

            return false;
        }

        // ── رمزِ root ──
        // زیرساختِ ۱ رمز را در پاسخِ ساخت می‌دهد؛ زیرساختِ ۲ نمی‌دهد، پس همان‌جا
        // یکی ست می‌کنیم. بی‌این، مشتری سرور دارد ولی راهی به داخلش ندارد.
        $password = $result['root_password'] ?? null;

        // ⚠️ اگر کلیدِ SSH داده شده، زیرساخت عمداً رمز نمی‌سازد — و ما هم نباید
        // بسازیم. ساختنِ رمز برای سروری که کلید دارد، همان امنیتی را که مشتری با
        // انتخابِ کلید خواسته بود پس می‌گیرد (ورودِ رمزی باز می‌مانَد).
        $keyOnly = $sshRefs !== [];

        if (! $keyOnly && blank($password) && filled($result['ref'] ?? null)
            && ! str_starts_with((string) $result['ref'], 'order:')) {
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

        // ── IP اضافه ──
        // بعد از ساختِ سرور، چون به شناسه‌اش بسته می‌شود. شکستش تحویل را
        // شکست‌خورده **نمی‌کند** (سرور کار می‌کند و مشتری منتظرش است)، ولی مدیر
        // خبردار می‌شود تا دستی کامل کند — وگرنه چیزی که پولش گرفته شده بی‌صدا
        // تحویل نمی‌شود.
        $this->attachExtraIps($service, $instance, $driver, $wanted);

        $this->finalize($service, $instance, $plan, $password);

        return true;
    }

    /**
     * شناسهٔ کلیدِ SSH مشتری نزدِ این زیرساخت — با بارگذاریِ یک‌بارهٔ تنبل.
     *
     * @return array<int,string>
     */
    private function sshKeyRefs(Service $service, CloudPlan $plan): array
    {
        if (blank($service->cloud_ssh_key_id)) {
            return [];
        }

        $key = \App\Models\CloudSshKey::find($service->cloud_ssh_key_id);

        if ($key === null || (int) $key->customer_id !== (int) $service->customer_id) {
            return [];                       // کلیدِ حذف‌شده یا مالِ کسِ دیگر
        }

        $provider = (string) $plan->provider;

        // از قبل بارگذاری شده؟ همان را بزن.
        if ($ref = $key->refFor($provider)) {
            return [$ref];
        }

        $driver = $this->manager->forPlan($plan);

        if ($driver === null || ! ($driver->capabilities()['ssh_key'] ?? false)) {
            return [];
        }

        // نامِ یکتا نزدِ زیرساخت: نامِ دلخواهِ مشتری می‌تواند با نامِ مشتریِ
        // دیگری یکی باشد و «تکراری» بخورد.
        $r = $driver->uploadSshKey('snet-'.$key->customer_id.'-'.$key->id, (string) $key->public_key);

        if (! ($r['ok'] ?? false) || blank($r['ref'] ?? null)) {
            Log::warning('cloud.sshkey.upload', ['key' => $key->id, 'err' => $r['message'] ?? '']);

            return [];
        }

        $key->rememberRef($provider, (string) $r['ref']);
        $key->update(['last_used_at' => now()]);

        return [(string) $r['ref']];
    }

    /** IPهای اضافهٔ خریداری‌شده را به سرور ببند و در نمونه ثبت کن */
    private function attachExtraIps(Service $service, CloudInstance $instance, CloudProvider $driver, array $wanted): void
    {
        $count = (int) ($wanted['extra_ipv4'] ?? 0);

        if ($count < 1 || blank($instance->provider_ref)
            || str_starts_with((string) $instance->provider_ref, 'order:')) {
            return;
        }

        $r = $driver->addExtraIps((string) $instance->provider_ref, $count);
        $ips = (array) ($r['ips'] ?? []);

        $meta = (array) ($instance->meta ?? []);
        $meta['extra_ips'] = $ips;
        $instance->update(['meta' => $meta]);

        if (count($ips) === $count) {
            return;
        }

        try {
            app(\App\Services\Notify\AdminNotifier::class)->event(
                'IP اضافه کامل تحویل نشد',
                [
                    'سرویس'       => $service->name.' (#'.$service->id.')',
                    'خریداری‌شده' => (string) $count,
                    'تحویل‌شده'   => (string) count($ips),
                    'علت'         => mb_substr((string) ($r['message'] ?? '—'), 0, 160),
                ],
                url('/admin/services'),
                '⚠️'
            );
        } catch (\Throwable) {
        }
    }

    /**
     * ثبتِ نهاییِ تحویل — تنها جایی که سرویس «done» می‌شود.
     *
     * عمداً یک متدِ مشترک است تا مسیرِ ساختِ تازه و مسیرِ «به فرزندی گرفتن» دقیقاً
     * یک کار بکنند. دو نسخهٔ موازیِ این منطق، همان‌جایی است که تفاوت‌های ریز
     * (مثلاً یادنکردنِ panel_url در یک شاخه) بی‌صدا می‌نشینند.
     */
    private function finalize(Service $service, CloudInstance $instance, CloudPlan $plan, ?string $password): void
    {
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
    }

    /**
     * تازه‌کردنِ وضعیتِ نمونه‌های زنده + **بستنِ سفارش‌های نیمه‌کاره**.
     *
     * ⚠️ چرا حیاتی است: زیرساختِ ۲ دومرحله‌ای است — `POST orders` یک سفارش
     * می‌سازد و شناسهٔ سرویس چند لحظه بعد در `createdServiceIds` ظاهر می‌شود.
     * اگر در همان چند ثانیه نرسد، `provider_ref` با پیشوندِ `order:` ذخیره
     * می‌شود. بی‌این متد، آن ref **برای همیشه** `order:…` می‌ماند:
     * `isActionable()` نادرست است، پس مشتری سرورِ پول‌داده‌اش را هرگز نمی‌تواند
     * روشن/خاموش کند و IP هم ندارد. یک تحویلِ «موفقِ» بی‌فایده.
     *
     * هم‌زمان وضعیتِ `building` را پی می‌گیرد تا وقتی سرور بالا آمد، IP و
     * وضعیتِ درست در پنل بنشیند بی‌آنکه مشتری منتظرِ کلیکِ خودش بماند.
     *
     * @return array{resolved:int,refreshed:int,failed:int}
     */
    public function syncInstances(int $limit = 40): array
    {
        $out = ['resolved' => 0, 'refreshed' => 0, 'failed' => 0];

        // ⚠️ گروه‌بندیِ شرط لازم است: بی‌آن، اگر روزی شرطِ دیگری (مثلِ محدودکردن
        // به یک مشتری) اضافه شود، `OR` آن را دور می‌زند و روی **همهٔ** ردیف‌ها
        // می‌دود.
        $rows = CloudInstance::query()
            ->where(function ($q) {
                $q->whereIn('status', ['building', 'unknown'])
                    ->orWhere('provider_ref', 'like', 'order:%');
            })
            ->whereNotIn('status', ['deleted'])
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        foreach ($rows as $instance) {
            $driver = $this->manager->forInstance($instance);

            if ($driver === null || blank($instance->provider_ref)) {
                continue;
            }

            try {
                // ① سفارشِ نیمه‌کاره → شناسهٔ سرویسِ واقعی
                if (str_starts_with((string) $instance->provider_ref, 'order:')
                    && $driver instanceof AezaClient) {
                    $real = $driver->resolveOrder((string) $instance->provider_ref);

                    if ($real === null) {
                        continue;               // هنوز آماده نیست؛ دفعهٔ بعد
                    }

                    $instance->update(['provider_ref' => $real]);
                    $out['resolved']++;

                    // 🔴 رمز: سفارشِ دومرحله‌ای لحظهٔ ساخت رمز نمی‌گیرد (سرور هنوز
                    // وجود ندارد). اگر همین‌جا نسازیم، مشتری سرورِ روشن و IP دارد
                    // ولی **هیچ رمزی** — نه در ایمیل نه در پنل — و بلوکِ رمز در
                    // ویو هم چون hasPassword نادرست است اصلاً رندر نمی‌شود.
                    if (! $instance->hasPassword()) {
                        $pw = $driver->resetPassword($real);

                        if (filled($pw['root_password'] ?? null)) {
                            $instance->setPassword($pw['root_password']);
                            $instance->save();

                            $instance->service?->forceFill(['password' => $pw['root_password']])->save();
                        }
                    }
                }

                // ② وضعیتِ زنده
                $r = $driver->serverStatus((string) $instance->provider_ref);

                if (! ($r['ok'] ?? false)) {
                    $out['failed']++;

                    continue;
                }

                $wasBuilding = $instance->status === 'building';

                $instance->update([
                    'status'    => $r['status'],
                    'ipv4'      => $r['ipv4'] ?: $instance->ipv4,
                    'ipv6'      => $r['ipv6'] ?: $instance->ipv6,
                    'synced_at' => now(),
                ]);
                $out['refreshed']++;

                // تازه بالا آمد و IP گرفت → مشخصاتِ سرویس را کامل کن.
                if ($wasBuilding && $r['status'] === 'running' && filled($instance->ipv4)) {
                    $service = $instance->service;

                    if ($service) {
                        $meta = (array) ($service->provision_meta ?? []);

                        // ⚠️ اعلانِ «آماده شد» لحظهٔ تحویل فرستاده شده است. اگر
                        // این‌جا بی‌قید دوباره بفرستیم، هر مشتری **دو ایمیل**
                        // می‌گیرد. فقط وقتی می‌فرستیم که اعلانِ اول IP نداشته
                        // باشد (حالتِ سفارشِ دومرحله‌ای) — یعنی مشتری هنوز
                        // آدرسِ سرورش را ندیده.
                        $hadIp = filled($meta['ip'] ?? null);

                        $meta['ip'] = $instance->ipv4;
                        $meta['ipv6'] = $instance->ipv6;

                        $service->forceFill([
                            'domain'         => $service->domain ?: $instance->ipv4,
                            'provision_meta' => $meta,
                        ])->save();

                        if (! $hadIp) {
                            $this->notify($service, $instance);
                        }
                    }
                }
            } catch (\Throwable $e) {
                // یک نمونهٔ خراب نباید بقیه را بخواباند
                Log::warning('cloud.sync-instance', ['id' => $instance->id, 'err' => $e->getMessage()]);
                $out['failed']++;
            }
        }

        return $out;
    }

    /**
     * سرورِ ازقبل‌خریده‌شده را «به فرزندی بگیر» به‌جای خریدنِ دوباره.
     *
     * برای سفارشِ نیمه‌کارهٔ دومرحله‌ای (`order:…`) هم کار می‌کند: اول تلاش
     * می‌کند شناسهٔ واقعی را بگیرد، و اگر هنوز آماده نیست، `pending` می‌گذارد تا
     * `cloud:sync-instances` پی‌اش را بگیرد.
     */
    private function adoptExisting(Service $service, CloudInstance $instance, CloudPlan $plan, CloudProvider $driver): bool
    {
        $ref = (string) $instance->provider_ref;

        if (str_starts_with($ref, 'order:') && $driver instanceof AezaClient) {
            $real = $driver->resolveOrder($ref);

            if ($real === null) {
                $this->retryLater($service, 'سرور در حالِ آماده‌سازیِ زیرساخت است؛ پی‌گیری خودکار ادامه دارد.');

                return false;
            }

            $instance->update(['provider_ref' => $real]);
            $ref = $real;
        }

        $info = $driver->serverStatus($ref);

        if (! ($info['ok'] ?? false)) {
            $this->retryLater($service, 'وضعیتِ سرور خوانده نشد؛ تلاشِ خودکار ادامه دارد.');

            return false;
        }

        // رمز اگر نداریم، همین‌جا یکی بساز — وگرنه مشتری سرور دارد و راهی
        // به داخلش ندارد.
        $password = null;

        if (! $instance->hasPassword()) {
            $pw = $driver->resetPassword($ref);
            $password = $pw['root_password'] ?? null;

            if (filled($password)) {
                $instance->setPassword($password);
            }
        }

        $instance->fill([
            'ipv4'       => $info['ipv4'] ?: $instance->ipv4,
            'ipv6'       => $info['ipv6'] ?: $instance->ipv6,
            'status'     => $info['status'],
            'last_error' => null,
            'synced_at'  => now(),
        ])->save();

        $this->finalize($service, $instance, $plan, $password);

        Log::info('cloud.provision.adopted', ['service' => $service->id, 'ref' => $ref]);

        return true;
    }

    /** پلنِ هم‌اسلاگ روی زیرساختی که این سیستم‌عامل را دارد */
    private function planWithImage(string $slug, string $imageKey): ?CloudPlan
    {
        $providers = CloudImage::query()->usable()->where('key', $imageKey)->pluck('provider')->unique();

        if ($providers->isEmpty()) {
            return null;
        }

        // «زیرساخت این کلید را دارد» کافی نیست: یک اسلاگ می‌تواند هم پلنِ x86
        // داشته باشد هم arm (مشخصاتشان یکی است و معماری در اسلاگ نیست)، و ممکن
        // است ایمیج فقط برای یکی از آن دو موجود باشد. پس ارزان‌ترین پلنی را
        // برمی‌داریم که ایمیج **برای معماریِ خودش** واقعاً موجود باشد.
        return CloudPlan::query()
            ->sellable()
            ->where('slug', $slug)
            ->whereIn('provider', $providers)
            ->orderBy('cost_eur_cents')
            ->get()
            ->first(fn (CloudPlan $p) => CloudImage::refFor($p->provider, $imageKey, $p->arch) !== null);
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

    /**
     * پلن‌های یک زیرساخت را از فروش بردار، وقتی خودِ **حساب** خراب است.
     *
     * ═══ 🔴 چرا این لازم شد ═══
     *
     * دو سفارشِ واقعی شکست خوردند با این پیام‌ها:
     *
     *   «You don't have enough permissions for this action»
     *   «Proxy internal server error» (HTTP 500)
     *
     * هیچ‌کدام گذرا نبودند: توکن دسترسی نداشت و حساب اعتبار نداشت. ولی پلن‌ها
     * سرِ جایشان در فروشگاه ماندند، پس **هر مشتریِ بعدی هم همان تجربه را
     * می‌گرفت**: پول از کیفِ پول کسر می‌شد و سروری تحویل نمی‌شد.
     *
     * قاعده‌ای که کارفرما گذاشت و درست است: «یا حتماً تحویل شود، یا اصلاً برای
     * فروش موجود نباشد.» این متد نیمهٔ دومش را خودکار می‌کند.
     *
     * ⚠️ فقط برای خطاهای **ساختاری**. خطای گذرا (ظرفیتِ لحظه‌ای، تایم‌اوت) نباید
     * کاتالوگ را ببندد، وگرنه یک قطعیِ دو دقیقه‌ای فروشِ یک زیرساخت را تا
     * دخالتِ دستی می‌خواباند.
     *
     * ⚠️ `admin_disabled` عمداً ست می‌شود نه `is_active`: کرونِ سینک هرگز
     * `admin_disabled` را لمس نمی‌کند، پس تصمیم تا وقتی مدیر بازش نکند
     * برنمی‌گردد. با `is_active`، سینکِ دو روزه بی‌صدا دوباره بازش می‌کرد.
     */
    private function quarantineProvider(CloudPlan $plan, string $message): void
    {
        $structural = [
            'permission',      // توکن دسترسیِ لازم را ندارد
            'unauthor',        // unauthorized / unauthenticated
            'forbidden',
            'invalid token',
            'proxy_internal_server_error',
            'insufficient',    // موجودیِ حساب
            'balance',
            'payment',
            'quota',
        ];

        $needle = mb_strtolower($message);
        $hit = null;

        foreach ($structural as $s) {
            if (str_contains($needle, $s)) {
                $hit = $s;
                break;
            }
        }

        if ($hit === null) {
            return;
        }

        $note = 'خودکار بسته شد: زیرساخت سفارش را نپذیرفت ('.mb_substr($message, 0, 120).')';

        $closed = CloudPlan::query()
            ->where('provider', $plan->provider)
            ->where('admin_disabled', false)
            ->update(['admin_disabled' => true, 'admin_note' => mb_substr($note, 0, 250)]);

        if ($closed === 0) {
            return;
        }

        \App\Support\ErrorTracker::note('cloud',
            'فروشِ '.$closed.' پلنِ یک زیرساخت خودکار بسته شد — '.$note);

        try {
            app(\App\Services\Notify\AdminNotifier::class)->event('فروشِ پلن‌های یک زیرساخت بسته شد', [
                'تعداد پلن' => (string) $closed,
                'علت'       => mb_substr($message, 0, 200),
                'اقدام'     => 'اعتبار و دسترسیِ توکن را در پنلِ آن زیرساخت بررسی کنید، بعد از /admin/cloud دوباره بازشان کنید.',
            ], url('/admin/cloud'), '🛑');
        } catch (\Throwable) {
        }
    }

    /**
     * پولِ کسرشده را برگردان، وقتی تحویل انجام نشد.
     *
     * 🔴 `orderHourly()` ساعتِ اول را **پیش از** تحویل از کیفِ پول کم می‌کند.
     * اگر تحویل شکست بخورد و برنگردانیم، مشتری پول داده و چیزی نگرفته — بدترین
     * تجربهٔ ممکن، و از دستِ خودش هم کاری برنمی‌آید.
     *
     * ⚠️ فقط یک بار: کرون ممکن است چند بار `fail()` بزند و بی‌این محافظ، هر بار
     * یک برگشتِ تازه می‌خورد و اعتبارِ مشتری بی‌دلیل بالا می‌رفت.
     */
    private function refundIfPrepaid(Service $service): void
    {
        if ($service->billing_mode !== 'hourly' || (int) $service->hourly_rate_irt <= 0) {
            return;
        }

        $already = \App\Models\CreditEntry::where('customer_id', $service->customer_id)
            ->where('reason', 'cloud_hourly_refund')
            ->where('note', 'like', '%#'.$service->id.'%')
            ->exists();

        if ($already || $service->customer === null) {
            return;
        }

        try {
            $amount = (int) $service->hourly_rate_irt;

            \App\Models\CreditEntry::create([
                'customer_id'   => $service->customer_id,
                'currency_code' => 'IRT',
                'amount'        => $amount,
                'balance_after' => $service->customer->creditBalance('IRT') + $amount,
                'reason'        => 'cloud_hourly_refund',
                'source_type'   => Service::class,
                'source_id'     => $service->id,
                'note'          => 'بازگشتِ ساعتِ اول — تحویل انجام نشد (سرویس #'.$service->id.')',
            ]);
        } catch (\Throwable $e) {
            \App\Support\ErrorTracker::note('cloud', $e, ['area' => 'hourly-refund', 'service' => $service->id]);
        }
    }

    private function fail(Service $service, string $reason): void
    {
        $service->forceFill([
            'provision_status' => 'failed',
            'provision_error'  => mb_substr($reason, 0, 290),
            'status'           => 'awaiting_provision',
        ])->save();

        try {
            // ⚠️ `forService` و نه `record(null,…)`: با نال، ردیف به سرویس
            //    نمی‌چسبد و در `/admin/services/{id}/history` **دیده نمی‌شود**.
            //    یعنی تنها جایی که مدیر دنبالِ علت می‌گردد، خالی می‌مانَد.
            \App\Models\ActivityLog::forService($service, 'provision',
                'تحویلِ سرورِ ابری ناموفق: '.$reason, 'system');
        } catch (\Throwable) {
        }

        /*
        | 🔴 دو حفرهٔ خاموش که این‌جا بسته شد.
        |
        | قبلاً `fail()` فقط ستونِ ردیف و `ActivityLog` را می‌نوشت. نتیجه:
        |
        |   • `/admin/errors` هیچ‌چیز نشان نمی‌داد — مدیر فقط می‌دید «سرور
        |     ساخته نشد» و برای یافتنِ علت باید ستونِ `provision_error` را در
        |     دیتابیس می‌خواند.
        |   • **مشتری هیچ خبری نمی‌گرفت** — پولش رفته بود، سروری نبود، و تنها
        |     نشانه سکوت بود.
        |
        | ⚠️ علتِ فنی فقط به مدیر می‌رود: پیامِ خامِ زیرساخت ممکن است نامِ
        | تأمین‌کننده را لو بدهد و سفیدبرچسبی را بشکند.
        */
        // پولِ پیش‌گرفته‌شده برگردد — پیش از هر اعلانی، تا اگر اعلان شکست
        // خورد هم مشتری پولش را پس گرفته باشد
        $this->refundIfPrepaid($service);

        \App\Support\ErrorTracker::note('provision',
            'تحویلِ سرورِ ابری ناموفق: '.$reason, ['service' => $service->id]);

        try {
            if ($service->customer !== null) {
                app(\App\Services\Notify\Notifier::class)->fire(
                    'service_failed', $service->customer,
                    ['service' => (string) $service->name],
                    'تحویلِ «'.$service->name.'» انجام نشد. تیمِ پشتیبانی در حالِ بررسی است؛ مبلغی از دست نمی‌رود.',
                    ['سرویس' => '#'.$service->id.' — '.$service->name, 'علت' => $reason],
                    url('/admin/services/'.$service->id), '⚠️',
                );
            }
        } catch (\Throwable $e) {
            \App\Support\ErrorTracker::note('notify', $e, ['area' => 'cloud-provision-failed']);
        }
    }

    /** خرابیِ گذرا: pending بمان تا کرونِ بعدی دوباره تلاش کند */
    /**
     * صفِ بازبینیِ دستی — نه شکست، نه تحویل.
     *
     * `provision_status = 'manual'` را کرونِ `provision:run` برنمی‌دارد (فقط
     * 'pending' را می‌گیرد)، پس تا وقتی مدیر تصمیم نگیرد هیچ پولی خرج نمی‌شود.
     */
    private function needsReview(Service $service, string $reason): void
    {
        $service->forceFill([
            'provision_status' => 'manual',
            'status'           => 'awaiting_provision',
            'provision_error'  => mb_substr('نیازمندِ تأییدِ دستی: '.$reason, 0, 290),
        ])->save();

        try {
            app(\App\Services\Notify\AdminNotifier::class)->event('سفارشِ سرور نیازمندِ بازبینی', [
                'سرویس' => '#'.$service->id.' — '.$service->name,
                'مشتری' => (string) ($service->customer?->code ?? $service->customer_id),
                'علت'   => $reason,
            ]);
        } catch (\Throwable $e) {
            \App\Support\ErrorTracker::note('provision', $e, ['service' => $service->id]);
        }

        \App\Support\ErrorTracker::note('fraud-guard',
            'سفارشِ سرور به بازبینیِ دستی رفت: '.$reason, ['service' => $service->id]);
    }
    /**
     * خرابیِ گذرا: `pending` بمان تا کرونِ بعدی دوباره تلاش کند.
     *
     * ═══ 🔴 چرا دیگر ساکت نیست ═══
     *
     * این متد هیچ ردی نمی‌گذاشت. برای یک شکستِ گذرا درست است — ولی اگر خرابی
     * **پایدار** باشد (توکنِ منقضی، پلنِ ناموجود نزدِ زیرساخت، ظرفیتِ تمام‌شده)،
     * کرون هر دقیقه تلاش می‌کند، هر بار شکست می‌خورد، و سرویس تا ابد «در حالِ
     * آماده‌سازی» می‌مانَد: مشتری پول داده، سروری نیست، و **هیچ‌جا هیچ خطایی
     * ثبت نمی‌شود**. دقیقاً همان چیزی که کارفرما گزارش کرد.
     *
     * ⚠️ ثبت **گلوگاه‌دار** است: بی‌آن، یک سرویسِ گیرکرده روزی ۱۴۴۰ ردیف در
     * ردیابِ خطا می‌ریخت و هشدارهای واقعی را زیرِ نویز دفن می‌کرد — که از
     * نداشتنِ هشدار بدتر است.
     */
    private function retryLater(Service $service, string $reason): void
    {
        $service->forceFill([
            'provision_status' => 'pending',
            'provision_error'  => mb_substr($reason, 0, 290),
            'status'           => 'awaiting_provision',
        ])->save();

        $key = 'provision:stuck:'.$service->id;

        // اولین تلاشِ ناموفق زمان را ثبت می‌کند؛ اگر بعد از این آستانه هنوز
        // گیر باشد، یعنی «گذرا» نبوده و باید دیده شود.
        $since = \Illuminate\Support\Facades\Cache::get($key);

        if ($since === null) {
            \Illuminate\Support\Facades\Cache::put($key, now()->toIso8601String(), now()->addDay());

            return;
        }

        if (\Illuminate\Support\Carbon::parse($since)->gt(now()->subMinutes(10))) {
            return;   // هنوز در پنجرهٔ «شاید واقعاً گذرا باشد»
        }

        // ⚠️ فقط یک بار در ساعت، وگرنه ردیاب پر می‌شود
        $shout = 'provision:stuck-shout:'.$service->id;

        if (\Illuminate\Support\Facades\Cache::has($shout)) {
            return;
        }

        \Illuminate\Support\Facades\Cache::put($shout, 1, now()->addHour());

        \App\Support\ErrorTracker::note('provision',
            'سرویس #'.$service->id.' بیش از ۱۰ دقیقه در صفِ تحویل گیر کرده: '.$reason,
            ['service' => $service->id]);

        try {
            app(\App\Services\Notify\AdminNotifier::class)->event('سرویس در صفِ تحویل گیر کرده', [
                'سرویس' => '#'.$service->id.' — '.$service->name,
                'مشتری' => (string) ($service->customer?->code ?? $service->customer_id),
                'علت'   => mb_substr($reason, 0, 200),
            ], url('/admin/customers/'.$service->customer_id), '⏳');
        } catch (\Throwable) {
        }
    }

    private function notify(Service $service, CloudInstance $instance): void
    {
        try {
            // ⚠️ متغیرها باید پاس داده شوند وگرنه الگوی /admin/templates بی‌اثر
            // است: `NotificationTemplate::body()` اگر بعد از جایگزینی هنوز
            // `{service}` ببیند عمداً متنِ کد را می‌فرستد. یعنی مدیر متن را
            // ویرایش می‌کرد و هیچ اتفاقی نمی‌افتاد.
            app(\App\Services\Notify\CustomerNotifier::class)->event(
                $service->customer, 'service_ready',
                ['service' => $service->name, 'ip' => $instance->ipv4 ?: '—'],
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

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
        /*
        | ⚠️ نبودِ مشتری یعنی **نگه دار**، نه «رد شو».
        |
        | `services.customer_id` کلیدِ خارجیِ واقعی ندارد، پس مشتریِ حذف‌شده یک
        | سرویسِ یتیم جا می‌گذارد. شکلِ قبلی (`if ($service->customer !== null)`)
        | آن سرویس را **بی‌هیچ بررسیِ سوءاستفاده‌ای** مستقیم به تماسِ پولی
        | می‌رساند. تنها مسیرِ باقی‌مانده به پولِ واقعی که هیچ‌کس نگاهش نمی‌کرد.
        */
        $verdict = $service->customer !== null
            ? app(CloudFraudGuard::class)->check($service->customer)
            : ['hold' => true, 'reason' => 'مشتریِ این سفارش پیدا نشد'];

        if ($verdict['hold'] && ! $this->consumeOverride($service, (string) $verdict['reason'])) {
            $this->needsReview($service, (string) $verdict['reason']);

            return false;
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

        /*
        | 🔴 شاخهٔ `?? $ordered` **قرنطینه را دور می‌زد**.
        |
        | هر دو انتخاب‌کنندهٔ بالا `sellable()` می‌زنند و پلنِ `admin_disabled`
        | را کنار می‌گذارند — ولی وقتی هیچ‌کدام چیزی پیدا نکنند، همان ردیفِ
        | سفارش‌شده برمی‌گردد، حتی اگر خودِ سیستم چند دقیقه پیش تصمیم گرفته باشد
        | فروشِ آن زیرساخت امن نیست. یعنی «تلاشِ دوباره»ی مدیر روی سرویسِ ۶ یا
        | ۱۳ دقیقاً از همان زیرساختی سفارش می‌داد که به‌خاطرِ همان سفارش بسته
        | شده بود.
        |
        | ⚠️ فقط قرنطینهٔ **خودکار** این‌جا جلوی کار را می‌گیرد. پلنی که مدیر
        | آگاهانه بسته، همچنان برای سرویسِ فروخته‌شده تحویل می‌شود — بستنِ فروش
        | یعنی «تازه نفروش»، نه «سفارشِ پرداخت‌شده را تحویل نده».
        */
        if ($plan->is($ordered) && $ordered->admin_disabled
            && str_starts_with((string) $ordered->admin_note, self::QUARANTINE_PREFIX)) {
            $this->fail($service, 'زیرساختِ این پلن پس از یک خطای ساختاری خودکار بسته شده؛ '
                .'تا بازبینیِ مدیر دوباره سفارش داده نمی‌شود.');

            return false;
        }

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

            /*
            | 🔴 چرخهٔ خریدِ ما از زیرساخت باید همان چرخهٔ فروش به مشتری باشد.
            |
            | بی‌این، سرویسِ ساعتی از زیرساخت **ماهانه** خریده می‌شد: مشتری یک
            | ساعت پول می‌داد و ما یک ماه. زیرساختی که این را نپذیرد خودش
            | `month` می‌گیرد (درایور هر مقدارِ ناشناخته را به `month` می‌برد)،
            | پس فرستادنش برای همه بی‌خطر است.
            */
            'term'         => $service->isHourly() ? 'hour' : 'month',
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

        $this->notifyIfReady($service, $instance);
    }

    /**
     * اعلانِ مشتری فقط وقتی سرور **واقعاً** آماده است؛ وگرنه بدهی ثبت می‌شود.
     *
     * ═══ 🔴 باگِ واقعی که این‌جا بسته شد ═══
     *
     * `notify()` بی‌قید همین‌جا صدا زده می‌شد. زیرساختِ ۱ در پاسخِ ساخت IP
     * می‌دهد، ولی زیرساختِ ۲ نه: ماشین `activating` است و IP چند ده ثانیه بعد
     * می‌آید. پس مشتری ایمیلی می‌گرفت با `IP: —` و بی‌هیچ رمزی — و کد از قبل
     * می‌دانست که ممکن است IP نباشد، چون خودش `?: '—'` نوشته بود.
     *
     * حالا «سفارش پذیرفته شد» با «سرور آماده شد» یکی گرفته نمی‌شود:
     *
     *   آماده  → همین‌جا بفرست
     *   نه     → `ready_notified_at` نال می‌مانَد ⇒ بدهی. کرونِ هر-دقیقه‌ای
     *            (`cloud:sync-instances`) به‌محضِ رسیدنِ IP می‌فرستد.
     *
     * ⚠️ مدیر در **هر دو** حالت خبردار می‌شود، ولی با متنِ درست. اگر این‌جا هم
     * سکوت می‌کردیم، هیچ‌کس نمی‌فهمید سفارشی ثبت شده و در صف مانده است — همان
     * الگوی «شکست نمی‌خورد، فقط اتفاق نمی‌افتد».
     */
    private function notifyIfReady(Service $service, CloudInstance $instance): void
    {
        if ($instance->readyForNotice()) {
            $this->notify($service, $instance);

            return;
        }

        try {
            \App\Models\ActivityLog::forService($service, 'provision',
                'سفارشِ سرور ثبت شد؛ ایمیلِ تحویل تا رسیدنِ IP نگه داشته شد.', 'system');
        } catch (\Throwable) {
        }

        try {
            app(\App\Services\Notify\AdminNotifier::class)->event(
                'سفارشِ سرورِ ابری ثبت شد — در حالِ آماده‌سازی',
                [
                    'سرویس' => $service->name.' (#'.$service->id.')',
                    'مشتری' => $service->customer?->name ?? '—',
                    'مکان'  => $instance->location_code ?: '—',
                    'وضعیت' => $instance->statusLabel('fa'),
                ],
                url('/admin/services'),
                '⏳'
            );
        } catch (\Throwable) {
        }
    }

    /**
     * ایمیل‌های تحویلِ **بدهی‌مانده** را بفرست — بی‌هیچ تماسِ API.
     *
     * چرا یک گذرِ جدا و نه ادامهٔ حلقهٔ وضعیت: ممکن است IP را حلقهٔ بالا کشف
     * نکرده باشد. صفحهٔ خودِ مشتری هم هر چند ثانیه وضعیت را می‌پرسد و ردیف را
     * به‌روز می‌کند؛ در آن حالت ردیف دیگر `building` نیست، پس از پرس‌وجوی حلقهٔ
     * بالا بیرون می‌افتد و ایمیل **تا ابد** نفرستاده می‌مانْد. این پرس‌وجو فقط
     * از «بدهی» می‌پرسد، پس هر راهی که IP از آن آمده باشد پوشش دارد.
     *
     * @return int تعداد اعلانِ فرستاده‌شده
     */
    public function deliverOwedNotices(int $limit = 40): int
    {
        $sent = 0;

        $rows = CloudInstance::query()
            ->whereNull('ready_notified_at')
            ->whereIn('status', CloudInstance::READY_STATUSES)
            ->whereNotNull('ipv4')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($rows as $instance) {
            // ⚠️ هر ردیف در try خودش: یک ردیفِ خراب (مشتریِ حذف‌شده، ایمیلِ
            // بدشکل) نباید بقیه را زمین بزند — و مهم‌تر، نباید استثنا به
            // `schedule:run` برسد. یک استثنا آن دقیقه را کامل می‌کشد و با آن
            // تحویلِ سرور و ثبتِ دامنه هم می‌ایستد (حادثهٔ مستندشده).
            try {
                $service = $instance->service;

                // سرویسی که تحویلش تمام نشده هنوز «آماده» نیست؛ سرویسِ مرده هم
                // اعلان نمی‌خواهد (لغوشده/خاتمه‌یافته).
                if ($service === null || $service->provision_status !== 'done' || $service->isDead()) {
                    continue;
                }

                if ($this->notify($service, $instance)) {
                    $sent++;
                }
            } catch (\Throwable $e) {
                Log::warning('cloud.owed-notice', ['instance' => $instance->id, 'err' => $e->getMessage()]);
            }
        }

        return $sent;
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
     * @return array{resolved:int,refreshed:int,failed:int,notified:int,stuck:int}
     */
    public function syncInstances(int $limit = 40): array
    {
        // ⚠️ `stuck` عمداً یک شمارندهٔ جداست. پیش از این، ردیفی که شناسه‌اش
        // نهایی نمی‌شد فقط `continue` می‌خورد و **هیچ شمارنده‌ای** را تکان
        // نمی‌داد؛ فرمان هم خروجی‌اش را پشتِ `array_sum($r) > 0` گذاشته بود، پس
        // خروجی کاملاً خالی بود. یعنی همان ردیفِ گیرکرده هر دقیقه دیده می‌شد و
        // هر دقیقه بی‌صدا رد می‌شد.
        $out = ['resolved' => 0, 'refreshed' => 0, 'failed' => 0, 'notified' => 0, 'stuck' => 0];

        // ⚠️ گروه‌بندیِ شرط لازم است: بی‌آن، اگر روزی شرطِ دیگری (مثلِ محدودکردن
        // به یک مشتری) اضافه شود، `OR` آن را دور می‌زند و روی **همهٔ** ردیف‌ها
        // می‌دود.
        $rows = CloudInstance::query()
            ->where(function ($q) {
                $q->whereIn('status', ['building', 'unknown'])
                    ->orWhere('provider_ref', 'like', 'order:%')
                    // 🔴 ردیفِ **بی‌شناسه** هم باید دیده شود. پیش از این از
                    // پرس‌وجو بیرون بود و بعد هم با `blank()` رد می‌شد، پس
                    // سروری که شناسه‌اش را از پاسخ بیرون نکشیده بودیم برای
                    // همیشه گم می‌شد — با سرویسی که «تحویل‌شده» ثبت شده بود.
                    ->orWhereNull('provider_ref')
                    ->orWhere('provider_ref', '');
            })
            ->whereNotIn('status', ['deleted'])
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        foreach ($rows as $instance) {
            $driver = $this->manager->forInstance($instance);

            if ($driver === null) {
                $out['stuck']++;

                continue;
            }

            try {
                // ① سفارشِ نیمه‌کاره (یا ردیفِ بی‌شناسه) → شناسهٔ سرویسِ واقعی
                $ref = (string) $instance->provider_ref;

                if (($ref === '' || str_starts_with($ref, 'order:'))
                    && $driver instanceof AezaClient) {
                    // نامِ قطعیِ `sn-svc-{id}` دومین راه است و به شکلِ پاسخِ
                    // سفارش وابسته نیست — بی‌آن، مسیرِ استنتاجیِ خواندنِ سفارش
                    // تنها امیدِ ما بود و شکستش یعنی بن‌بستِ ابدی.
                    $real = $driver->resolveOrder($ref, $instance->hostname);

                    if ($real === null) {
                        $out['stuck']++;        // هنوز آماده نیست؛ دفعهٔ بعد — ولی **شمرده** می‌شود

                        continue;
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
                // ⚠️ با شناسهٔ خالی نپرس: بعضی درایورها `/servers/` را «فهرست»
                // می‌فهمند و پاسخِ بی‌ربط می‌دهد که روی ردیفِ مشتری می‌نشیند.
                if (blank($instance->provider_ref)) {
                    $out['stuck']++;

                    continue;
                }

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
                if ($wasBuilding && $instance->readyForNotice()) {
                    $service = $instance->service;

                    if ($service) {
                        $meta = (array) ($service->provision_meta ?? []);
                        $meta['ip'] = $instance->ipv4;
                        $meta['ipv6'] = $instance->ipv6;

                        $service->forceFill([
                            'domain'         => $service->domain ?: $instance->ipv4,
                            'provision_meta' => $meta,
                        ])->save();
                    }
                }
            } catch (\Throwable $e) {
                // یک نمونهٔ خراب نباید بقیه را بخواباند
                Log::warning('cloud.sync-instance', ['id' => $instance->id, 'err' => $e->getMessage()]);
                $out['failed']++;
            }
        }

        /*
        | ═══ ایمیل‌های بدهی‌مانده ═══
        |
        | ⚠️ عمداً **بیرونِ** حلقهٔ بالا و با پرس‌وجوی خودش. حلقهٔ بالا فقط
        | ردیف‌های `building`/`unknown`/`order:` را می‌بیند؛ ردیفی که وضعیتش را
        | خودِ صفحهٔ مشتری تازه کرده از آن فهرست بیرون است و اگر اعلانش این‌جا
        | بند بود، **هرگز** فرستاده نمی‌شد.
        |
        | قبلاً شرطِ «فرستاده شد یا نه» استنتاجِ `provision_meta['ip']` بود؛ حالا
        | `ready_notified_at` است — یک واقعیتِ ثبت‌شده، نه یک نتیجه‌گیری.
        */
        $out['notified'] = $this->deliverOwedNotices($limit);
        $this->warnAboutStalledDeliveries();

        return $out;
    }

    /**
     * 🔴 اگر ایمیلی **گیر** کرد، کسی باید بفهمد.
     *
     * ایمیل حالا شرطی است: تا زیرساخت نگوید بالا آمده و IP ندهد، نمی‌رود. این
     * درست است، ولی یک حفرهٔ خاموشِ تازه می‌سازد — اگر ماشینی در وضعیتی گیر کند
     * که هرگز به `running` نرسد (رشتهٔ ناشناخته از سمتِ زیرساخت، خرابیِ واقعیِ
     * ساخت)، ایمیل **هیچ‌وقت** نمی‌رود و هیچ خطایی هم تولید نمی‌شود. دقیقاً همان
     * الگویی که این پروژه سه بار خورده: «شکست نمی‌خورد، فقط اتفاق نمی‌افتد».
     *
     * ⚠️ گلوگاه‌دار (ساعتی یک بار): بی‌آن، یک ردیفِ گیرکرده روزی ۱۴۴۰ هشدار
     * می‌ساخت و هشدارهای واقعی را زیرِ نویز دفن می‌کرد — از نداشتنِ هشدار بدتر.
     */
    private function warnAboutStalledDeliveries(): void
    {
        try {
            // یک تعریف برای همهٔ ناظرها. پرس‌وجوی دست‌نویسِ موازی همان چیزی است
            // که روزی بی‌صدا کهنه می‌شود و می‌گوید «چیزی گیر نکرده».
            $stalled = CloudDeliveryWatch::stalled();

            if ($stalled->isEmpty()) {
                return;
            }

            /*
            | 🔴 گلوگاه هست، ولی روی **فایل** — نه روی کش.
            |
            | نسخهٔ قبلی `Cache::has()` می‌زد. کشِ پیش‌فرضِ این پروژه روی
            | **دیتابیس** است و کلِ متد در یک `catch` است که فقط `Log::warning`
            | می‌کند — یعنی یک قطعیِ گذرای همان دیتابیس (که در همین ردیاب ۱۹ بار
            | ثبت شده) این هشدار را کاملاً می‌بلعید. همان قاعدهٔ CLAUDE.md:
            | «هیچ چیزی که قرار است از مرگِ یک وابستگی خبر دهد، نباید روی همان
            | وابستگی بنشیند.» گلوگاهِ فایلی به هیچ سرویسی وابسته نیست و اگر
            | خودش هم خطا بدهد **باز می‌شود** (پیامِ تکراری بهتر از پیامِ نرفته).
            |
            | ⚠️ ولی گلوگاه لازم است: این متد هر دقیقه می‌دود و پنجرهٔ ردیاب
            | ۴۰۰ خط است. یک ردیفِ گیرکرده بی‌گلوگاه روزی ۱۴۴۰ خط می‌نوشت و
            | همان خطاهایی را که باید کنارش دیده شوند بیرون می‌انداخت — دقیقاً
            | خرابیِ سیلِ ۴۰۴.
            |
            | ⚠️ امضا شاملِ **کدام** سرویس‌ها گیر کرده‌اند است، نه فقط «چیزی گیر
            | کرده». همان درسِ `SystemHealthCheck`: با گلوگاهِ ساعتیِ بی‌امضا،
            | مشتریِ دومی که ده دقیقه بعد گیر می‌کرد تا یک ساعت هیچ ردی
            | نمی‌ساخت — و آن یک ساعت، ساعتی است که ماشینش پول می‌سوزاند.
            |
            | ⚠️ سکوتِ این متد بینِ دو شلیک بی‌خطر است، چون
            | `SystemHealth::undeliveredCloud()` همان وضعیت را **دائمی** بالای
            | `/admin/errors` قرمز نگه می‌دارد و به هیچ گلوگاهی بند نیست.
            */
            if (! $this->shoutAllowed('cloud-stalled-notice', 3600,
                md5($stalled->pluck('id')->sort()->implode(',')))) {
                return;
            }

            $reasons = [];

            foreach ($stalled as $service) {
                $why = CloudDeliveryWatch::reasonFor($service) ?? '—';
                $reasons[$why] = ($reasons[$why] ?? 0) + 1;
            }

            $detail = [];
            foreach ($reasons as $why => $n) {
                $detail[] = $n.'× '.$why;
            }

            \App\Support\ErrorTracker::note(
                'provision',
                $stalled->count().' سرویسِ ابریِ پرداخت‌شده تحویل نشده — '.implode(' · ', $detail),
                ['services' => implode(',', $stalled->pluck('id')->take(10)->all())]
            );

            // ثبت و پیام با **یک** گلوگاه می‌روند: هر دو یک خبرند و جداکردنشان
            // فقط دو حالتِ ناهماهنگ می‌ساخت (ردی که هست و پیامی که نیست).
            app(\App\Services\Notify\AdminNotifier::class)->event(
                // ⚠️ عبارتِ «گیر کرده» عمداً در عنوان مانده: تستِ
                // `CloudDeliveryReadinessTest` همین را می‌جوید و آن گارد باید
                // زنده بماند.
                '🔴 سرورِ پول‌داده تحویل نشده — تحویل گیر کرده',
                [
                    'تعداد'  => (string) $stalled->count(),
                    'سرویس'  => implode('، ', $stalled->pluck('id')->take(5)->map(fn ($i) => '#'.$i)->all()),
                    'علت'    => implode(' · ', $detail),
                ],
                url('/admin/cloud/inventory'),
                '🔴'
            );
        } catch (\Throwable $e) {
            // ⚠️ هیچ‌چیزِ این متد نباید به `schedule:run` برسد: یک استثنا کلِ آن
            // دقیقه را می‌کشد و با آن تحویلِ سرور و ثبتِ دامنه هم می‌ایستد.
            Log::warning('cloud.stalled-notice', ['err' => $e->getMessage()]);
        }
    }

    /**
     * گلوگاهِ زمانیِ **فایل‌محور** — عمداً نه `Cache`.
     *
     * ⚠️ کش در این پروژه روی دیتابیس است و همان دیتابیس گاهی قطع می‌شود. یک
     * گلوگاهی که با مرگِ دیتابیس بیفتد یعنی هشدار دقیقاً در بدترین لحظه ساکت
     * می‌شود. اگر نوشتن/خواندنِ فایل هم شکست بخورد، پیش‌فرض **اجازه دادن** است:
     * پیامِ تکراری آزاردهنده است، پیامِ نرفته گران.
     */
    private function shoutAllowed(string $key, int $seconds, string $signature = ''): bool
    {
        return \App\Support\ErrorTracker::throttlePassed($key, $seconds, $signature);
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
            // ⚠️ نامِ قطعی این‌جا هم داده می‌شود و نه فقط در کرون: این همان مسیری
            // است که دکمهٔ «تلاشِ دوباره»ی مدیر می‌رود، یعنی اولین کاری که
            // کارفرما روی یک سفارشِ گیرکرده انجام می‌دهد. بی‌آن، دکمه هر بار
            // «هنوز آماده نیست» می‌گفت و هیچ‌وقت جلو نمی‌رفت.
            $real = $driver->resolveOrder($ref, $instance->hostname);

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
    /**
     * پیشوندِ یادداشتی که قرنطینهٔ **خودکار** می‌گذارد.
     *
     * 🔴 چرا یک ثابتِ مشترک و نه یک رشته در دو جا: فرمانِ بازکردن
     * (`cloud:reopen`) باید بتواند پلنی را که **این متد** بست از پلنی که
     * **مدیر آگاهانه** بست تشخیص دهد. اگر این دو رشته روزی از هم بیفتند،
     * فرمان یا هیچ‌چیز باز نمی‌کند یا — بدتر — پکیجی را باز می‌کند که مدیر
     * عمداً بسته بود.
     */
    public const QUARANTINE_PREFIX = 'خودکار بسته شد:';

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

        /*
        | ═══ 🔴 شکلِ **بافاصله**ی همان خطا، که هرگز نمی‌گرفت ═══
        |
        | فهرستِ بالا فقط `proxy_internal_server_error` (اسلاگِ زیرِ خطی) را
        | می‌شناخت. ولی چیزی که درایور واقعاً برمی‌گرداند پیامِ خامِ گیت‌وی است:
        | «Proxy internal server error (see traceId)» — بی‌هیچ اسلاگی. پس
        | سرویس‌های ۶ و ۱۳ با دقیقاً همین متن شکست خوردند و قرنطینه **نگرفت**؛
        | پلن‌ها در فروشگاه ماندند و مشتریِ بعدی همان تجربه را می‌گرفت.
        |
        | تستِ موجود هم نمی‌توانست بگیردش: خودش اسلاگ را به ورودی می‌چسباند و
        | روی همان اسلاگی که خودش ساخته بود سبز می‌شد.
        |
        | ⚠️ ولی این شکل **مبهم** است: همین متن هم «شکلِ درخواستت را نمی‌شناسم»
        | است هم «سرورِ من خراب است». پس بر خلافِ بقیهٔ فهرست، **بارِ اول
        | قرنطینه نمی‌کند** — یک قطعیِ دو دقیقه‌ای نباید ۲۲۱ پلن را تا دخالتِ
        | دستی ببندد. بارِ دوم در نیم‌ساعت یعنی «گذرا نبود» و بسته می‌شود.
        |
        | (این متد فقط از شاخهٔ شکستِ `createServer()` صدا زده می‌شود، پس
        | استعلامِ کاتالوگ هرگز به این‌جا نمی‌رسد.)
        */
        if ($hit === null && str_contains($needle, 'proxy internal server error')) {
            $key = 'cloud:gateway500:'.$plan->provider;

            if (! \Illuminate\Support\Facades\Cache::get($key)) {
                \Illuminate\Support\Facades\Cache::put($key, 1, now()->addMinutes(30));

                \App\Support\ErrorTracker::note('cloud',
                    'زیرساخت سرِ سفارش «Proxy internal server error» داد. اگر در نیم‌ساعت تکرار شود، '
                    .'فروشِ پلن‌هایش خودکار بسته می‌شود.', ['provider' => $plan->provider]);

                return;
            }

            $hit = 'proxy internal server error';
        }

        if ($hit === null) {
            return;
        }

        $note = self::QUARANTINE_PREFIX.' زیرساخت سفارش را نپذیرفت ('.mb_substr($message, 0, 120).')';

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
                // ⚠️ بی‌این جمله، مدیر باید ۲۲۱ ردیف را تک‌تک در پنل باز کند —
                // که یک‌بار واقعاً پیش آمد و عملاً یعنی راهی نبود.
                'اقدام'     => 'اعتبار و دسترسیِ توکن را در پنلِ آن زیرساخت بررسی کنید. '
                    .'پس از یک سفارشِ واقعیِ موفق، همه را با «php artisan cloud:reopen» باز کنید '
                    .'(یا ردیف‌به‌ردیف از /admin/cloud).',
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

        /*
        | 🔴 تنها جایی که مدیر دنبالِ **علت** می‌گردد، تا امروز خالی بود.
        |
        | `fail()` پایین‌تر `ActivityLog::forService` می‌نویسد، ولی این متد
        | نمی‌نوشت. یعنی `/admin/services/{id}/history` برای یک سفارشِ پارک‌شده
        | هیچ‌چیز نداشت و تنها ردِ ماجرا در ردیابِ خطا بود — همان‌جایی که
        | کارفرما گفت «فقط چون خودم بازش کردم فهمیدم».
        */
        try {
            \App\Models\ActivityLog::forService($service, 'provision',
                'سفارش به صفِ بازبینیِ دستی رفت: '.$reason, 'system');
        } catch (\Throwable) {
        }

        \App\Support\ErrorTracker::note('fraud-guard',
            'سفارشِ سرور به بازبینیِ دستی رفت: '.$reason, ['service' => $service->id]);

        /*
        | ═══ 🔴 شکستنِ سکوت ═══
        |
        | تا امروز فقط مدیر خبردار می‌شد (آن هم بی‌لینک و بی‌ایموجی، پس بینِ
        | بقیهٔ 🔔ها گم می‌شد) و **مشتری هیچ‌چیز نمی‌شنید**: پولش رفته بود،
        | سروری نمی‌آمد، و پنل هم‌زمان وعده می‌داد «کمتر از دو دقیقه».
        |
        | حالا یک رویدادِ کاتالوگ (`service_hold`، مخاطب: هر دو) شلیک می‌شود:
        | مشتری متنِ صادق و بی‌عدد می‌گیرد، مدیر همان لحظه سطرِ «علت» و لینکِ
        | مستقیمِ سرویس را.
        |
        | ⚠️ علتِ فنی **فقط** به مدیر می‌رود. متنِ زندهٔ محافظ «بیش از ۵ سرور در
        | ۲۴ ساعت» است؛ نشان‌دادنش به مشتری یعنی به مهاجم یاد بدهیم یکی زیرِ
        | سقف بماند. متغیرهای الگوی مشتری هم به همین دلیل فقط `service` است.
        |
        | ⚠️ **دقیقاً یک بار**: `provision:run` و «تلاشِ دوباره»ی مدیر هر دو
        | می‌توانند بارها این‌جا برسند. بی‌قفل، مشتری به‌ازای هر تلاش یک پیام
        | می‌گرفت — همان «هرگز دو بار»ی که برای ایمیلِ تحویل هم نوشته شده.
        */
        if (! $this->claimHoldNotice($service)) {
            return;
        }

        try {
            app(\App\Services\Notify\Notifier::class)->fire(
                'service_hold',
                $service->customer,
                ['service' => (string) $service->name],
                'سفارشِ «'.$service->name.'» برای بررسیِ کوتاهِ امنیتی نگه داشته شد. '
                .'تیمِ ما آن را بازبینی می‌کند و نتیجه را به شما اطلاع می‌دهیم؛ مبلغی از دست نمی‌رود.',
                ['سرویس' => '#'.$service->id.' — '.$service->name, 'علت' => $reason],
                url('/admin/services/'.$service->id.'/history'),
                '🛑',
            );
        } catch (\Throwable $e) {
            \App\Support\ErrorTracker::note('notify', $e, ['area' => 'cloud-provision-hold']);
        }
    }

    /**
     * قفلِ «یک بار به مشتری خبر بده» برای یک سفارشِ پارک‌شده.
     *
     * ⚠️ روی خودِ ردیف قفل می‌شود، نه روی کش: کشِ پروداکشن روی همان دیتابیسی
     * می‌نشیند که بارها لرزیده، و از دست رفتنِ کلید یعنی سیلِ پیامِ تکراری.
     *
     * علامت در `provision_meta` می‌نشیند. `finalize()` این ستون را یکجا
     * بازنویسی می‌کند — که این‌جا دقیقاً رفتارِ درست است: تحویلِ موفق پروندهٔ
     * «نگه‌داشته شده» را می‌بندد، و اگر روزی همان سرویس دوباره پارک شود، باید
     * دوباره خبر برود.
     *
     * @return bool آیا این فراخوان حقِ فرستادن را گرفت
     */
    private function claimHoldNotice(Service $service): bool
    {
        try {
            return (bool) DB::transaction(function () use ($service) {
                $fresh = Service::whereKey($service->id)->lockForUpdate()->first();

                if ($fresh === null) {
                    return false;
                }

                $meta = (array) ($fresh->provision_meta ?? []);

                if (filled($meta['hold_notified_at'] ?? null)) {
                    return false;
                }

                $meta['hold_notified_at'] = now()->toIso8601String();
                $fresh->forceFill(['provision_meta' => $meta])->save();
                $service->provision_meta = $meta;

                return true;
            });
        } catch (\Throwable $e) {
            \App\Support\ErrorTracker::note('provision', $e, ['area' => 'hold-notice-claim', 'service' => $service->id]);

            return false;   // مطمئن‌تر: پیامِ نرفته از پیامِ تکراری بهتر است
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 🔴 رهاسازیِ دستیِ یک سفارشِ پارک‌شده — «می‌دانم، بساز»
    |--------------------------------------------------------------------------
    |
    | مسئله‌ای که حل می‌کند، اندازه‌گیری‌شده روی پروداکشن: محافظ سفارش را نگه
    | می‌داشت و **تنها درِ خروج** («تلاشِ دوباره»ی مدیر) از خودِ همان محافظ رد
    | می‌شد. پس سفارش هرگز بیرون نمی‌آمد و پیامِ خطا به مدیر می‌گفت «نیازمندِ
    | تأییدِ دستی» در حالی که هیچ افورد‌نسِ تأییدی در محصول وجود نداشت.
    |
    | طراحی، و چراییِ هر قید:
    |
    |  • **تک‌سرویس و یک‌بارمصرف.** علامتی در `provision_meta` می‌نشیند و در
    |    همان شاخهٔ محافظ **مصرف** می‌شود. کلیدِ سراسریِ «محافظ خاموش» وجود
    |    ندارد، چون خاموشیِ سراسری همان چیزی است که این کلاس برای نداشتنش
    |    نوشته شد.
    |  • **فقط شاخهٔ محافظ.** هرچه زیرِ آن است — درایورِ پیکربندی‌شده، موجودی،
    |    در دسترس بودنِ سیستم‌عامل — دست‌نخورده می‌مانَد. رهاسازی‌ای که از روی
    |    آنها بپرد، بن‌بست را با «پولِ چیزی که تحویل‌شدنی نیست» عوض می‌کند.
    |  • **نمی‌تواند دو بار بخرد.** این متد چیزی نمی‌خرد؛ فقط ردیف را به
    |    `pending` برمی‌گرداند. خریدْ همان مسیرِ همیشگی است و هر سه لایهٔ
    |    ضدِ خریدِ دوباره سرِ جایشان‌اند: قفلِ اتمیِ `provision()` (دو رهاسازیِ
    |    هم‌زمان، یک برنده)، شاخهٔ `adoptExisting()` وقتی `provider_ref` پر
    |    باشد، و نامِ قطعیِ `sn-svc-{id}`.
    |  • **از سمتِ مشتری غیرقابلِ دسترس.** روتش پشتِ `auth:web` + `admin` است،
    |    در فهرستِ سفیدِ نویسنده نیست، کنترلر دوباره `isAdmin()` می‌سنجد، و
    |    مشتری اصلاً روی گاردِ `web` نیست.
    */

    /**
     * ثبتِ درخواستِ رهاسازی روی یک سرویس (فقط علامت‌گذاری؛ چیزی تحویل نمی‌دهد).
     *
     * @param  string  $by  نامِ مدیرِ تصمیم‌گیرنده — برای ردِ حسابرسی
     */
    public static function requestOverride(Service $service, string $by): void
    {
        $meta = (array) ($service->provision_meta ?? []);

        $meta['fraud_override'] = [
            'pending' => true,
            'by'      => $by,
            'at'      => now()->toIso8601String(),
            'flagged' => mb_substr((string) $service->provision_error, 0, 200),
        ];

        $service->forceFill(['provision_meta' => $meta])->save();
    }

    /** آیا روی این سرویس رهاسازیِ مصرف‌نشده هست؟ (فقط برای نمایش) */
    public static function overrideRequested(Service $service): bool
    {
        return (($service->provision_meta['fraud_override']['pending'] ?? false) === true);
    }

    /**
     * مصرفِ یک‌بارهٔ علامتِ رهاسازی — اتمی، زیرِ قفلِ ردیف.
     *
     * ⚠️ سندِ حسابرسی در `ActivityLog` و ردیابِ خطا نوشته می‌شود نه فقط در
     * `provision_meta`: `finalize()` آن ستون را در لحظهٔ تحویلِ موفق یکجا
     * بازنویسی می‌کند، یعنی دقیقاً وقتی «چه کسی اجازه داد» بیشترین ارزش را
     * دارد، از بین می‌رفت.
     */
    private function consumeOverride(Service $service, string $reason): bool
    {
        try {
            $mark = DB::transaction(function () use ($service) {
                $fresh = Service::whereKey($service->id)->lockForUpdate()->first();

                if ($fresh === null) {
                    return null;
                }

                $meta = (array) ($fresh->provision_meta ?? []);

                if ((($meta['fraud_override']['pending'] ?? false) !== true)) {
                    return null;
                }

                $mark = (array) $meta['fraud_override'];

                unset($meta['fraud_override']);
                $meta['fraud_override_used'] = [
                    'by' => $mark['by'] ?? '—',
                    'at' => now()->toIso8601String(),
                ];

                $fresh->forceFill(['provision_meta' => $meta])->save();
                $service->provision_meta = $meta;

                return $mark;
            });
        } catch (\Throwable $e) {
            \App\Support\ErrorTracker::note('provision', $e, ['area' => 'override-consume', 'service' => $service->id]);

            return false;
        }

        if ($mark === null) {
            return false;
        }

        $who = (string) ($mark['by'] ?? '—');

        try {
            \App\Models\ActivityLog::forService($service, 'provision',
                'محافظِ سوءاستفاده با تأییدِ صریحِ مدیر ('.$who.') کنار گذاشته شد — نشانهٔ محافظ: '.$reason,
                'staff');
        } catch (\Throwable) {
        }

        \App\Support\ErrorTracker::note('fraud-guard',
            'رهاسازیِ دستیِ سفارش توسط مدیر ('.$who.') — نشانهٔ محافظ: '.$reason,
            ['service' => $service->id, 'by' => $who]);

        return true;
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

    /**
     * اعلانِ «سرورت آماده شد» — دقیقاً یک بار در عمرِ هر سرویس.
     *
     * ═══ چرا «ثبتِ فرستادن» **پیش** از فرستادن است ═══
     *
     * قفلِ اتمی (`whereNull → update`) اول برداشته می‌شود و بعد ایمیل می‌رود.
     * ترتیبش عمدی است: `cloud:sync-instances` و `provision:run` هر دو هر دقیقه
     * می‌دوند و می‌توانند هم‌زمان روی یک ردیف بیفتند. اگر اول بفرستیم و بعد
     * علامت بزنیم، مشتری دو (یا در حالتِ گیرکردن، ده) ایمیلِ یکسان می‌گیرد —
     * و کارفرما صریح گفت «هرگز دو بار».
     *
     * بهایش این است که یک خرابیِ گذرای SMTP ایمیل را می‌سوزاند. آن هزینه
     * پذیرفته است چون مشتری هر چیزی که در ایمیل بود را در پنل هم دارد، و ردِ
     * ماجرا در `ActivityLog` می‌مانَد؛ در حالی که سیلِ ایمیلِ تکراری هم اعتماد
     * را می‌برد هم دامنهٔ فرستنده را.
     *
     * @return bool آیا این فراخوان اعلان را فرستاد (false = قبلاً فرستاده شده)
     */
    private function notify(Service $service, CloudInstance $instance): bool
    {
        // ── قفلِ اتمیِ «یک بار» ──
        $claimed = CloudInstance::whereKey($instance->id)
            ->whereNull('ready_notified_at')
            ->update(['ready_notified_at' => now()]);

        if ($claimed === 0) {
            return false;
        }

        $instance->ready_notified_at = now();

        try {
            // ⚠️ متغیرها باید پاس داده شوند وگرنه الگوی /admin/templates بی‌اثر
            // است: `NotificationTemplate::body()` اگر بعد از جایگزینی هنوز
            // `{service}` ببیند عمداً متنِ کد را می‌فرستد. یعنی مدیر متن را
            // ویرایش می‌کرد و هیچ اتفاقی نمی‌افتاد.
            app(\App\Services\Notify\CustomerNotifier::class)->event(
                $service->customer, 'service_ready',
                ['service' => $service->name, 'ip' => (string) $instance->ipv4],
                'سرورِ «'.$service->name.'» شما آماده شد. IP: '.$instance->ipv4
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
                        $service->panel_url ?: url('/account/cloud/'.$service->id),
                        'root',
                        /*
                        | 🔴 رمزِ root عمداً در ایمیل **نیست**.
                        |
                        | قاعدهٔ این حوزه از قبل این بود که رمز فقط یک بار در پنل
                        | دیده شود (`password_seen`)، ولی ایمیل نسخهٔ دومی از همان
                        | رمز را برای همیشه در اینباکس می‌گذاشت — یعنی همان قاعده
                        | از درِ پشتی شکسته می‌شد.
                        |
                        | ⚠️ ولی سکوت هم جواب نبود: کارفرما گزارش داد مشتری فکر
                        | می‌کند «چیزی جا افتاده». پس `passwordInPanel` صریح
                        | می‌گوید رمز کجاست و چرا یک بار است.
                        */
                        null,
                        $customer->locale ?: 'fa',
                        passwordInPanel: true,
                        withSshGuide: true,
                    )
                );
            }
        } catch (\Throwable) {
        }

        try {
            \App\Models\ActivityLog::forService($service, 'provision',
                'ایمیلِ تحویلِ سرور فرستاده شد (IP: '.$instance->ipv4.').', 'system');
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

        return true;
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

        /*
        | ═══ 🔴 «چیزی برای حذف نیست» با «نمی‌دانم چه چیزی را حذف کنم» یکی نیست ═══
        |
        | این شاخه بی‌قید `true` می‌داد و فراخوان آن را «موفق» ثبت می‌کرد:
        | `provision_status='none'` و صفِ آزادسازی بسته. برای سرویسی که هرگز
        | سفارشی نداده درست است — ولی برای سرویسی که ردیفِ نمونه دارد و شناسه‌اش
        | هنوز حل نشده (`order:…`، یا تماسِ ساخت که پاسخش به ما نرسید)، یعنی یک
        | ماشینِ احتمالاً **زندهٔ** یتیم که اجاره‌اش پای ماست و هیچ‌جا ردی ندارد.
        | این دقیقاً همان دکمهٔ حذفی است که «بی‌صدا هیچ کاری نمی‌کرد».
        |
        | ⚠️ ولی جهتِ مخالف هم باگ است: اگر نبودِ واقعیِ ماشین را «شکست» بخوانیم،
        | صفِ `releasing` و چکِ سلامت پر از کاری می‌شود که وجود ندارد — زنگِ
        | همیشه‌قرمزی که زنگِ بعدی را خفه می‌کند. پس فقط دو حالت جدا شده‌اند:
        | «هیچ سفارشی ثبت نشده» ⇒ موفق · «سفارش هست ولی شناسه‌اش را نداریم» ⇒ ناموفق.
        */
        if ($instance === null) {
            return true;                              // هرگز سفارشی ثبت نشد
        }

        if (blank($instance->provider_ref)) {
            $this->shoutAboutLingeringMachine(
                'حذفِ سرور ممکن نشد: شناسهٔ ماشین نزدِ زیرساخت در دست نیست',
                $service, $instance,
                'ردیفِ نمونه ساخته شده ولی شناسه‌ای ثبت نشده — ممکن است ماشین واقعاً '
                .'ساخته شده باشد. نامِ قطعیِ «'.$this->serverName($service).'» را در پنلِ '
                .'زیرساخت جستجو کنید.'
            );

            return false;
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

        /*
        | 🔴 حذفِ ناموفق **گران‌ترین سکوتِ این حوزه** است.
        |
        | تنها ردی که می‌گذاشت یک ستونِ `last_error` بود؛ هیچ‌کس آن ستون را
        | نمی‌خوانَد. و فراخوان (`CloudMeterHourly::creditOut()`) مقدارِ برگشتی را
        | دور می‌ریزد و سرویس را «خاتمه‌یافته» می‌نویسد. نتیجه: در پنلِ ما سرویس
        | مرده است، نزدِ زیرساخت ماشین **زنده** است و اجاره‌اش هر ماه از حسابِ ما
        | می‌رود — بی‌مشتری، بی‌درآمد، بی‌هیچ هشداری. تا رسیدنِ صورت‌حساب هیچ‌کس
        | نمی‌فهمد، و صورت‌حساب هم فقط جمعِ کل را می‌گوید.
        */
        $this->shoutAboutLingeringMachine(
            'حذفِ سرور نزدِ زیرساخت انجام نشد',
            $service, $instance, (string) $r['message']
        );

        return false;
    }

    /**
     * 🔴 حذفی که زیرساخت نپذیرفت — شمارنده‌ها را بنویس و بلند بگو.
     *
     * فراخوانش `ProvisioningService::releaseAndTrack()` است، برای **هر دو** نوعِ
     * سرویس (ابری و WHM/DA)؛ ردیفِ غیرابری نمونه ندارد و شمارنده‌ها فقط برای
     * ابری نوشته می‌شوند، ولی فریادش یکی است.
     *
     * شمارنده‌ها در `cloud_instances.meta` می‌نشینند (ستونِ JSONِ موجود، در
     * `$fillable` و cast‌شده) — بی‌هیچ مهاجرتی. سؤالی که به آنها پاسخ می‌دهند:
     * «این نشتی از کی شروع شده و چند بار تلاش کرده‌ایم؟»
     */
    public function recordReleaseFailure(Service $service, string $why): void
    {
        $instance = CloudInstance::where('service_id', $service->id)->first();
        $attempt = 1;

        if ($instance !== null) {
            $meta = (array) ($instance->meta ?? []);
            $attempt = (int) ($meta['release_attempts'] ?? 0) + 1;

            $meta['release_attempts'] = $attempt;
            $meta['release_first_failed_at'] ??= now()->toIso8601String();
            $meta['release_last_failed_at'] = now()->toIso8601String();

            $instance->update(['meta' => $meta]);
        }

        $this->shoutAboutLingeringMachine(
            'حذفِ سرور نزدِ زیرساخت انجام نشد', $service, $instance, $why, $attempt
        );
    }

    /**
     * تعلیقی که زیرساخت نپذیرفت — ماشینی که ما «خاموش» می‌دانیم و روشن است.
     *
     * ارزان‌تر از حذفِ ناموفق ولی هر ساعت تکرارشونده: مشتری چیزی نمی‌پردازد
     * (اعتبارش تمام شده) و اجاره را ما می‌دهیم. کرانش `SUSPEND_GRACE_HOURS` است،
     * چون پس از آن مسیرِ حذف می‌دود و اگر آن هم شکست بخورد ردیف در صفِ
     * `releasing` می‌نشیند.
     */
    public function recordSuspendFailure(Service $service): void
    {
        $instance = CloudInstance::where('service_id', $service->id)->first();

        if ($instance !== null) {
            $meta = (array) ($instance->meta ?? []);
            $meta['suspend_failed_at'] = now()->toIso8601String();
            $instance->update(['meta' => $meta]);
        }

        $this->shoutAboutLingeringMachine(
            'خاموش‌کردنِ سرور نزدِ زیرساخت انجام نشد', $service, $instance,
            (string) ($instance?->last_error ?: 'دلیلِ نامعلوم')
        );
    }

    /**
     * ماشینی که وضعیتِ محلی‌اش با واقعیت نمی‌خوانَد — بلند بگو.
     *
     * ⚠️ گلوگاه با امضای «سرویس + پیام» است، نه زمانِ خالی: خطای **تازه** روی
     * همان سرویس فوراً دیده می‌شود، ولی تلاشِ ساعتیِ تکراری ردیاب را پر نمی‌کند.
     *
     * ⚠️ شمارهٔ تلاش در امضا **سطلی** است (۱، ۲، ۳، ۴، ۸، ۱۶، ۳۲…): تلاشِ
     * ساعتیِ `cloud:release-retry` نباید روزی ۲۴ خط بنویسد و پنجرهٔ ۴۰۰ خطیِ
     * ردیاب را خالی کند (همان خرابیِ «سیلِ ۴۰۴»)، ولی نباید هم بعد از اولین
     * ساعت برای همیشه ساکت شود — نشتی که دو هفته ادامه دارد باید دوباره فریاد
     * بزند. `SystemHealth` مستقل از این گلوگاه، وضعیت را دائمی قرمز نگه می‌دارد.
     */
    private function shoutAboutLingeringMachine(string $what, Service $service, ?CloudInstance $instance, string $why, ?int $attempt = null): void
    {
        try {
            $bucket = $attempt === null ? 0
                : ($attempt <= 3 ? $attempt : (1 << (int) floor(log($attempt, 2))));

            // ⚠️ ثبت هم زیرِ همین گلوگاه است: مدیری که ده بار «تلاشِ دوباره» را
            // می‌زند نباید پنجرهٔ ۴۰۰ خطیِ ردیاب را با یک خبر پر کند. امضا شاملِ
            // علت است، پس خطای **متفاوتِ** بعدی فوراً می‌نشیند.
            if (! $this->shoutAllowed('cloud-lingering-'.$service->id, 3600, md5($what.'|'.$why.'|'.$bucket))) {
                return;
            }

            \App\Support\ErrorTracker::note('provision',
                $what.' — ماشین احتمالاً هنوز زنده است و اجاره‌اش از حسابِ ما می‌رود. علت: '
                .mb_substr($why, 0, 200),
                ['service' => $service->id, 'instance' => $instance?->id, 'attempt' => $attempt]
            );

            app(\App\Services\Notify\AdminNotifier::class)->event(
                '🔴 '.$what,
                array_filter([
                    'سرویس' => $service->name.' (#'.$service->id.')',
                    'علت'   => mb_substr($why, 0, 200),
                    'تلاش'  => $attempt === null ? null : fa_num($attempt).'اُم',
                    'خطر'   => 'ماشین ممکن است زنده مانده باشد و هزینه‌اش را ما بدهیم.',
                ]),
                url('/admin/cloud/inventory'),
                '🔴'
            );
        } catch (\Throwable $e) {
            Log::warning('cloud.lingering-notice', ['err' => $e->getMessage()]);
        }
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

        // تعلیقِ ناموفق = ما فکر می‌کنیم خاموش است و مشتری چیزی نمی‌پردازد، ولی
        // ماشین روشن است و اجاره‌اش می‌رود. همان سکوتِ `terminate()`، ارزان‌تر
        // ولی هر ماه تکرارشونده.
        $this->shoutAboutLingeringMachine(
            'تغییرِ وضعیتِ سرور نزدِ زیرساخت انجام نشد',
            $service, $instance, (string) $r['message']
        );

        return false;
    }
}

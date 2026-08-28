<?php

namespace App\Services\Provisioning;

use App\Models\Server;
use App\Models\Service;
use Illuminate\Support\Facades\DB;

/**
 * هماهنگ‌کنندهٔ فراهم‌سازی — بینِ سرویس و درایور.
 *
 * روی زیرساختِ فعلی (SQLite/cPanel، بدونِ worker اختصاصی) ساخته شده: به‌جای
 * صفِ Redis/تسکِ idempotent، از یک «قفلِ وضعیتی» ساده استفاده می‌کند
 * (pending→running با یک UPDATE اتمی) که برای حجمِ سفارشِ یک شرکتِ هاست کافی
 * است، و idempotency واقعی از سمتِ درایور می‌آید (WHM قبلِ ساخت، بودنِ حساب را
 * چک می‌کند). تماسِ شبکه‌ای هرگز داخلِ تراکنشِ پرداخت اجرا نمی‌شود؛ این‌جا
 * جدا و بعد از commit صدا زده می‌شود (کرونِ provision:run یا دکمهٔ ادمین).
 */
class ProvisioningService
{
    public function driverFor(Server $server): Provisioner
    {
        if (! $server->isAutoProvisioned()) {
            return new ManualProvisioner();
        }

        return match ($server->type) {
            'directadmin' => new DirectAdminProvisioner(),
            'plesk'       => new PleskProvisioner(),
            default       => new WhmProvisioner(),
        };
    }

    /**
     * تلاش برای تحویلِ یک سرویس. idempotent و بی‌خطر: هرگز استثنا پرت نمی‌کند.
     *
     * @return bool موفقیت (done)
     */
    public function provision(Service $service): bool
    {
        // سرورِ ابری مسیرِ خودش را دارد: پیش از خرید وجود ندارد، پس نه
        // `server_id` دارد و نه ظرفیتی که بشود از قبل سنجید.
        if (\App\Services\Cloud\CloudProvisioner::handles($service)) {
            return app(\App\Services\Cloud\CloudProvisioner::class)->provision($service);
        }

        if ($service->server_id === null || $service->provision_status === 'done') {
            return $service->provision_status === 'done';
        }

        // قفلِ وضعیتی اتمی، محدود به همین سرویس: فقط اگر در حال اجرا/تمام‌شده
        // نیست، آن را «running» بگیر. اگر ۰ ردیف گرفت یعنی کسِ دیگری گرفتش.
        $claimed = Service::whereKey($service->id)
            ->where(function ($q) {
                $q->whereIn('provision_status', ['pending', 'failed', 'manual'])
                    ->orWhereNull('provision_status')
                    // قفلِ کهنه (پروسهٔ قبلی وسطِ کار مرده) — وگرنه سرویس تا ابد
                    // در 'running' می‌مانَد بی‌آنکه خطایی تولید شود.
                    ->orWhere(fn ($s) => $s->where('provision_status', 'running')
                        ->where('updated_at', '<', now()->subMinutes(15)));
            })
            ->update(['provision_status' => 'running']);

        if ($claimed === 0) {
            return $service->fresh()?->provision_status === 'done';
        }

        $service->refresh();
        $server = $service->server;
        if (! $server) {
            $this->markFailed($service, 'سرور حذف شده است.');

            return false;
        }

        // ظرفیت/وضعیت را همین‌جا (نه فقط سرِ سفارش) دوباره بسنج: بین ثبتِ سفارش و
        // پرداخت ممکن است سرور به «تعمیر» رفته یا پر شده باشد. اگر بسازیم، حسابِ
        // مشتری روی سروری می‌نشیند که نباید. وضعیت را pending نگه می‌داریم تا
        // کرونِ بعدی دوباره تلاش کند و مدیر در پنل ببیند.
        if (! $server->canAcceptNew()) {
            $service->forceFill([
                'provision_status' => 'pending',
                'status'           => 'awaiting_provision',
                'provision_error'  => 'سرورِ «'.$server->name.'» فعلاً ظرفیت/دسترسِ پذیرشِ حسابِ تازه ندارد ('.$server->status.').',
            ])->save();

            return false;
        }

        // اطلاعاتِ لازم را آماده کن (نام‌کاربری/رمز) و پیش از تماس ذخیره کن تا
        // اجرای دوباره همان‌ها را دوباره استفاده کند (idempotency).
        $this->ensureCredentials($service, $server);

        try {
            $result = $this->driverFor($server)->create($service);
        } catch (\Throwable $e) {
            \App\Support\ErrorTracker::note('provision', $e, [
                'service' => $service->id, 'server' => $server->id,
            ]);
            $this->markFailed($service, 'خطای غیرمنتظره: '.mb_substr($e->getMessage(), 0, 160));

            return false;
        }

        if ($result->manual) {
            $service->forceFill([
                'provision_status' => 'manual',
                'status'           => 'awaiting_provision',
                'provision_error'  => $result->error,
            ])->save();

            return false;
        }

        if (! $result->ok) {
            $this->markFailed($service, $result->error ?? 'تحویل ناموفق بود.');

            return false;
        }

        // موفق
        DB::transaction(function () use ($service, $server, $result) {
            // ⚠️ **پیش از** نوشتن خوانده می‌شود: بعد از `save()` لاراول مقدارِ
            // «اصلی» را با مقدارِ تازه هم‌گام می‌کند، پس خواندنش بعدش همیشه
            // `true` می‌داد و شمارش هرگز انجام نمی‌شد.
            $alreadyCounted = (bool) ($service->provision_meta['counted'] ?? false);

            /*
            | 🔴 مرجعِ سایت‌ساز باید از بازنویسیِ metaِ پایین جان به در ببرد.
            |
            | forceFill چند خط پایین‌تر provision_meta را با metaی **درایور**
            | جایگزین می‌کند؛ builder_ref در لحظهٔ سفارش نوشته شده و اگر همین‌جا
            | حفظ نشود، BuilderSitePublisher هرگز نمی‌فهمد این سرویس سایتِ
            | آماده دارد — سکوتِ کامل، هاستِ خالی، مشتریِ منتظر.
            */
            $builderKeys = array_intersect_key(
                (array) $service->provision_meta,
                array_flip(['builder_ref', 'builder_published_at', 'builder_publish_error'])
            );

            $service->forceFill([
                'username'         => $result->username ?: $service->username,
                'password'         => $result->password ?: $service->password,
                'panel_url'        => $result->panelUrl ?: $service->panel_url,
                'provision_status' => 'done',
                'provision_error'  => null,
                'provisioned_at'   => now(),
                /*
                | 🔴 مهرِ `counted` هم‌زمان با خودِ شمارش نوشته می‌شود.
                |
                | تا امروز شرطِ افزایش `! reused` بود و شرطِ کاهش هم `! reused` —
                | ولی حسابی که **پس از یک شکست پذیرفته می‌شود** هم `reused` است.
                | یعنی ظرفیتش هرگز شمرده نمی‌شد در حالی که واقعاً یک حساب روی
                | سرور است: سرور به‌ازای هر رخدادِ zhina.shop یک‌واحد بیشتر از
                | واقعیت «جا» نشان می‌داد و بیش‌فروش می‌شد.
                |
                | ⚠️ رفعِ ساده‌لوحانه (فقط عوض‌کردنِ شرطِ افزایش) خرابیِ بدتری
                | می‌سازد: کاهش هنوز `! reused` را می‌خوانَد، پس این ردیف‌ها
                | افزایش می‌گرفتند و هرگز کاهش نمی‌گرفتند ⇒ شمارنده بی‌سقف بالا
                | می‌رفت و سرور بی‌هیچ خطایی از صفحهٔ خرید غیب می‌شد. پس **هر دو
                | طرف** به همین یک مهر نگاه می‌کنند.
                */
                'provision_meta'   => array_merge($builderKeys, $result->meta, ['counted' => true]),
                'status'           => 'active',
                'activated_at'     => $service->activated_at ?? now(),
            ])->save();

            // شمارندهٔ ظرفیتِ سرور (اتمی) — یک حسابِ واقعی روی سرور، چه تازه
            // ساخته باشیمش چه پس از شکست پذیرفته باشیمش، ظرفیت اشغال می‌کند.
            if (! $alreadyCounted) {
                Server::whereKey($server->id)->increment('active_accounts');
            }
        });

        // زیردامنهٔ رایگان تا وقتی رکوردِ DNS نداشته باشد بالا نمی‌آید (nameserverها
        // روی Cloudflare است و zoneِ محلیِ WHM را دنیا نمی‌بیند). بیرونِ تراکنش و
        // «بی‌صدا» است: اگر DNS نشد، سرویس نباید شکست‌خورده اعلام شود.
        $this->pointFreeSubdomain($service, $server);

        // سفارشِ سایت‌ساز: کدِ HTML آماده همان لحظه روی اکانت نوشته می‌شود.
        // مثلِ DNS بیرونِ تراکنش و بی‌صداست — شکستش تحویل را شکست نمی‌دهد،
        // فقط فریادِ ماشین‌خوان می‌گذارد (جزئیات در BuilderSitePublisher).
        try {
            app(BuilderSitePublisher::class)->publish($service->refresh(), $server);
        } catch (\Throwable $e) {
            \App\Support\ErrorTracker::noteOnce('provision',
                'سایت‌ساز: خطای غیرمنتظره در انتشارِ سایتِ سرویسِ #'.$service->id.' — '.mb_substr($e->getMessage(), 0, 160));
        }

        $this->notify($service, 'سرویسِ «'.$service->name.'» شما آماده شد و در پنل قابل مشاهده است.');

        return true;
    }

    /**
     * اگر دامنهٔ سرویس زیردامنهٔ خودمان است، رکوردِ A را روی Cloudflare بنشان.
     *
     * دامنهٔ اختصاصیِ مشتری دست‌کاری نمی‌شود — DNSِ آن مالِ خودش است.
     */
    private function pointFreeSubdomain(Service $service, Server $server): void
    {
        $zone = (string) config('servernet.subdomain_zone', 'servernet.cloud');
        $fqdn = strtolower((string) $service->domain);

        if ($fqdn === '' || ! str_ends_with($fqdn, '.'.$zone)) {
            return;                                   // دامنهٔ خودِ مشتری
        }

        $dns = app(\App\Services\Dns\CloudflareDns::class);

        if (! $dns->isConfigured()) {
            $this->noteDns($service, 'توکنِ Cloudflare تنظیم نشده؛ رکوردِ DNS دستی لازم است.');

            return;
        }

        // IPِ حسابِ ساخته‌شده: اول IPِ ثبت‌شدهٔ سرور، وگرنه از میزبان resolve کن
        $ip = filled($server->server_ip)
            ? (string) $server->server_ip
            : (string) @gethostbyname((string) $server->hostname);

        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            $this->noteDns($service, 'IPِ سرور مشخص نشد؛ رکوردِ DNS دستی لازم است.');

            return;
        }

        try {
            $res = $dns->pointSubdomain($fqdn, $ip);
        } catch (\Throwable $e) {
            $res = ['ok' => false, 'reason' => mb_substr($e->getMessage(), 0, 140)];
        }

        $this->noteDns($service, $res['ok']
            ? 'رکوردِ DNS زیردامنه روی '.$ip.' تنظیم شد.'
            : 'تنظیمِ DNS زیردامنه ناموفق: '.($res['reason'] ?? '—'));
    }

    /** نتیجهٔ DNS در provision_meta و لاگِ فعالیت می‌نشیند تا مدیر ببیند */
    private function noteDns(Service $service, string $message): void
    {
        $meta = (array) ($service->provision_meta ?? []);
        $meta['dns'] = $message;
        $service->forceFill(['provision_meta' => $meta])->save();

        try {
            \App\Models\ActivityLog::record($service->customer_id, 'service',
                'DNS زیردامنه — '.$message, null, 'system');
        } catch (\Throwable) {
        }
    }

    public function suspend(Service $service): ProvisionResult
    {
        // سرورِ ابری: «تعلیق» = خاموش کردن. داده می‌ماند، هزینه‌اش هم برای ما
        // می‌ماند — ولی حذفِ خودکارِ دادهٔ مشتریِ بدهکار را عمداً نمی‌کنیم.
        if (\App\Services\Cloud\CloudProvisioner::handles($service)) {
            $ok = app(\App\Services\Cloud\CloudProvisioner::class)->suspend($service);
            $service->update(['status' => 'suspended']);

            return $ok
                ? ProvisionResult::success(null, null, null)
                : ProvisionResult::fail('سرور خاموش نشد؛ وضعیتِ سرویس معلق ثبت شد.');
        }

        if (! $service->server) {
            $service->update(['status' => 'suspended']);

            return ProvisionResult::success(null, null, null);
        }
        $r = $this->driverFor($service->server)->suspend($service);
        if ($r->ok || $r->manual) {
            $service->update(['status' => 'suspended']);
        }

        return $r;
    }

    public function unsuspend(Service $service): ProvisionResult
    {
        if (\App\Services\Cloud\CloudProvisioner::handles($service)) {
            $ok = app(\App\Services\Cloud\CloudProvisioner::class)->unsuspend($service);
            $service->update($this->resumeColumns($service));

            return $ok
                ? ProvisionResult::success(null, null, null)
                : ProvisionResult::fail('سرور روشن نشد؛ از پنل دستی روشنش کنید.');
        }

        if (! $service->server) {
            $service->update($this->resumeColumns($service));

            return ProvisionResult::success(null, null, null);
        }
        $r = $this->driverFor($service->server)->unsuspend($service);
        if ($r->ok || $r->manual) {
            $service->update($this->resumeColumns($service));
        }

        return $r;
    }

    /**
     * 🔴 روشن‌کردنِ سرویسِ ساعتی باید **لنگرِ متر** را هم جلو ببرد.
     *
     * `suspend()`ِ مدیر فقط `status` را می‌نوشت و `last_metered_at` کهنه می‌مانْد؛
     * پس تیکِ بعدی بعد از یک تعلیقِ ده‌ساعته، هر ده ساعتِ **خاموشی** را از مشتری
     * می‌گرفت (تا سقفِ ۴۸ ساعت). مشتری بابتِ ماشینی که ما خاموشش کرده بودیم پول
     * می‌داد. مسیرِ خودِ متر (`CloudMeterHourly`) این کار را از قبل می‌کرد؛
     * مسیرِ مدیر نه — همان نیمهٔ فراموش‌شده.
     *
     * @return array<string,mixed>
     */
    private function resumeColumns(Service $service): array
    {
        return $service->billing_mode === 'hourly'
            ? ['status' => 'active', 'last_metered_at' => now()]
            : ['status' => 'active'];
    }

    /**
     * فقط **آزادسازیِ منبع** نزدِ سرور/زیرساخت — بی‌آنکه وضعیتِ سرویس را بنویسد.
     *
     * چرا از `terminate()` جدا شد: دو فراخوان با دو وضعیتِ نهاییِ متفاوت داریم.
     * مدیر که سرویس را می‌بندد نتیجه‌اش `cancelled` است؛ مشتری که خودش با کدِ
     * یک‌بارمصرف حذف می‌کند نتیجه‌اش `terminated` است و در پنلش برچسبِ «حذف شده»
     * می‌گیرد. اگر مسیرِ مشتری همان `terminate()` را صدا بزند، وضعیت `cancelled`
     * می‌شود و برچسب عوض می‌شود. پس نیمهٔ «کارِ واقعی» را جدا می‌کنیم تا هر
     * فراخوان وضعیتِ خودش را بنویسد.
     */
    public function releaseServer(Service $service): ProvisionResult
    {
        // خاتمهٔ سرورِ ابری = حذفِ واقعی نزدِ زیرساخت. اگر نکنیم، اجارهٔ سروری را
        // می‌دهیم که هیچ‌کس پولش را نمی‌دهد.
        if (\App\Services\Cloud\CloudProvisioner::handles($service)) {
            if (! app(\App\Services\Cloud\CloudProvisioner::class)->terminate($service)) {
                return ProvisionResult::fail('حذفِ سرور ناموفق بود؛ دوباره تلاش کنید.');
            }

            /*
            | 🔴 شاخهٔ ابری هم باید `none` بنویسد.
            |
            | پیش از این این‌جا بی‌درنگ `return` می‌شد و یک سرویسِ ابریِ
            | خاتمه‌یافته تا ابد `provision_status='done'` می‌مانْد. یعنی دو شاخهٔ
            | همین متد دو حرفِ متفاوت می‌زدند، و «آزادشده» هیچ نشانهٔ قابلِ
            | پرس‌وجویی نداشت — همان چیزی که صفِ تلاشِ دوبارهٔ `cloud:release-retry`
            | و چکِ سلامتِ `cloud_release` به آن نیاز دارند.
            */
            $service->update(['provision_status' => 'none']);

            return ProvisionResult::success(null, null, null);
        }

        /*
        | ⚠️ «حسابی وجود ندارد» با «حذف شکست خورد» یکی نیست.
        |
        | سفارشِ تحویل‌نشدهٔ هاست `server_id` دارد ولی `username` ندارد؛ درایور
        | برایش `fail('سرور یا نام‌کاربری مشخص نیست')` می‌دهد. اگر آن را شکست
        | بشماریم، هر لغوِ سفارشِ تحویل‌نشده یک ردیفِ **دروغینِ** `releasing`
        | می‌سازد و صفِ تلاشِ دوباره و چکِ سلامت را با کارِ نکرده پر می‌کند —
        | همان «هشدارِ همیشه‌قرمز» که هشدارِ بعدی را خفه می‌کند.
        */
        $r = ($service->server && filled($service->username))
            ? $this->driverFor($service->server)->terminate($service)
            : ProvisionResult::success(null, null, null);

        if ($r->ok || $r->manual) {
            // ظرفیتِ آزادشده را برگردان — وگرنه شمارنده فقط بالا می‌رفت و
            // سرور برای همیشه «پر» می‌ماند؛ آن‌وقت هیچ مکانی در صفحهٔ خرید
            // نمایش داده نمی‌شد. فقط برای حسابی که واقعاً شمرده شده بود، و
            // با کرانِ صفر تا منفی نشود.
            // ⚠️ `released_from_done` مهرِ `releaseAndTrack()` است: تلاشِ دومِ
            // یک آزادسازیِ ناموفق، `provision_status` را دیگر `done` نمی‌بیند و
            // بی‌این مهر ظرفیت هرگز برنمی‌گشت.
            // ⚠️ همان مهری که موقعِ افزایش نوشته شد، نه `! reused`. جدا شدنِ این
            // دو یعنی شمارنده یا هرگز بالا نمی‌رود یا هرگز پایین نمی‌آید.
            $counted = ($service->provision_status === 'done'
                    || ($service->provision_meta['released_from_done'] ?? false))
                && ($service->provision_meta['counted'] ?? ! ($service->provision_meta['reused'] ?? false));

            if ($counted && $service->server_id) {
                Server::whereKey($service->server_id)
                    ->where('active_accounts', '>', 0)
                    ->decrement('active_accounts');
            }

            // دوباره شمرده نشود — این‌جا نوشته می‌شود نه در فراخوان، چون
            // جفتش با decrement است و جداکردنشان یعنی تلاشِ دوم دوباره کم می‌کند.
            $service->update(['provision_status' => 'none']);
        }

        return $r;
    }

    /**
     * 🔴 **گامِ ۲ و ۳ِ هر مسیرِ خاتمه** — آزادسازی + دفترداریِ نتیجه.
     *
     * قاعدهٔ کارفرما: «نه ما ضرر کنیم نه مشتری.» ترجمه‌اش به کد:
     *
     *   موفق (یا `manual`)  ⇒ `provision_status='none'` — پرونده بسته است.
     *   ناموفق              ⇒ `provision_status='releasing'` — «مشتری تمام شده،
     *                          ماشین هنوز تأییدنشده پاک نشده». صف می‌مانَد،
     *                          `cloud:release-retry` هر ساعت دوباره تلاش می‌کند،
     *                          و `SystemHealth::cloudRelease()` قرمز می‌مانَد تا
     *                          آدمی ببندَدش.
     *
     * ⚠️ **گامِ ۱ (بستنِ وضعیتِ صورت‌حسابی) این‌جا نیست و نباید باشد.** فراخوان
     * باید `status` را **پیش** از صدازدنِ این متد مرده کرده باشد؛ همان یک نوشتن
     * است که هم‌زمان مترِ ساعتی، `provision:run`، `PaymentService::applyPaid` و
     * دکمهٔ «تلاشِ دوباره»ی مدیر را می‌بندد.
     *
     * ⚠️ `releasing` عمداً روی `provision_status` می‌نشیند نه `services.status`:
     * `PaymentService::applyPaid` فقط `status` را می‌سنجد و شرطِ خامِ داخلِ
     * `catch`ش (`provision_status != 'done'`) با `releasing` **تطبیق می‌کند** —
     * پس اگر این نشانه روی `status` بود، پرداختِ یک فاکتورِ کهنه سرویس را دوباره
     * به صفِ خرید می‌فرستاد و **سرورِ دوم** خریده می‌شد.
     *
     * ⚠️ در شکستِ غیرابری (WHM/DA) `active_accounts` عمداً کم **نمی‌شود** —
     * حسابِ cPanel واقعاً هنوز هست و ظرفیتِ آن سرور واقعاً مصرف است.
     * `releaseServer()` آن decrement را با نوشتنِ `none` جفت کرده؛ جدا نکن.
     */
    public function releaseAndTrack(Service $service): ProvisionResult
    {
        /*
        | ⚠️ «این حساب در ظرفیتِ سرور شمرده شده بود» را **پیش از** اولین تلاش
        | مهر می‌زنیم.
        |
        | `releaseServer()` آن را از `provision_status === 'done'` می‌فهمد؛ ولی
        | به‌محضِ اینکه یک تلاشِ ناموفق ردیف را به `releasing` ببرد، آن نشانه از
        | بین می‌رود و تلاشِ دوم — حتی وقتی موفق شود — `active_accounts` را
        | برنمی‌گرداند. نتیجه‌اش سروری بود که برای همیشه «پر» می‌مانْد و از صفحهٔ
        | خرید غیب می‌شد. مهرِ ماندگار همان واقعیت را حمل می‌کند.
        */
        $meta = (array) ($service->provision_meta ?? []);

        if ($service->provision_status === 'done' && ! ($meta['released_from_done'] ?? false)) {
            $meta['released_from_done'] = true;
            $service->forceFill(['provision_meta' => $meta])->save();
        }

        try {
            $r = $this->releaseServer($service);
        } catch (\Throwable $e) {
            \App\Support\ErrorTracker::note('provision', $e, ['area' => 'release', 'service' => $service->id]);
            $r = ProvisionResult::fail(mb_substr($e->getMessage(), 0, 200));
        }

        if ($r->ok || $r->manual) {
            // شاخهٔ غیرابریِ `releaseServer()` از قبل نوشته؛ نوشتنِ دوباره بی‌ضرر
            // است و شاخهٔ `manual` (که چیزی ننوشته) را هم می‌پوشانَد.
            $service->forceFill(['provision_status' => Service::PROVISION_NONE])->save();

            return $r;
        }

        $service->forceFill(['provision_status' => Service::PROVISION_RELEASING])->save();

        app(\App\Services\Cloud\CloudProvisioner::class)
            ->recordReleaseFailure($service, (string) ($r->error ?: 'دلیلِ نامعلوم'));

        return $r;
    }

    /**
     * «خودم دستی پاکش کردم — دیگر تلاش نکن.»
     *
     * ═══ 🔴 چرا این وجود دارد ═══
     *
     * صفِ آزادسازی فرض می‌کند تنها راهِ بسته‌شدنش تأییدِ **زیرساخت** است. ولی
     * یک راهِ دیگر هم هست که کد نمی‌بیندش: کارفرما می‌رود در پنلِ دیتاسنتر و
     * ماشین را با دست پاک می‌کند. از آن لحظه، API دیگر آن سرور را نمی‌شناسد و
     * هر تلاشِ خودکار برای همیشه شکست می‌خورد — یعنی صف هرگز خالی نمی‌شود و
     * هشدارِ «ماشین شاید زنده است» هر ساعت می‌آید برای ماشینی که وجود ندارد.
     *
     * ۴۶ تلاشِ ناموفق روی سرویسِ #۳۰ دقیقاً همین بود.
     *
     * ⚠️ عمداً **همان** وضعیتی نوشته می‌شود که مسیرِ موفق می‌نویسد
     * (`PROVISION_NONE`)، نه یک وضعیتِ تازه. وضعیتِ تازه یعنی هر پرس‌وجویی که
     * امروز روی این دو مقدار حساب می‌کند، فردا یک حالتِ نادیده داشته باشد.
     *
     * ⚠️ ولی در `provision_meta` رد می‌گذارد: تفاوتِ «زیرساخت تأیید کرد» و
     * «آدمی گفت تأیید شده» باید بعداً قابلِ تشخیص باشد — به‌خصوص اگر روزی
     * صورت‌حسابِ ارائه‌دهنده بگوید ماشین هنوز زنده بوده.
     *
     * @param  string  $by  چه کسی این را اعلام کرد (برای لاگِ فعالیت)
     */
    public function markReleasedManually(Service $service, string $by = 'admin'): bool
    {
        if ($service->provision_status !== Service::PROVISION_RELEASING) {
            return false;   // در صفِ آزادسازی نیست — کاری برای انجام نیست
        }

        $meta = (array) ($service->provision_meta ?? []);
        $meta['released_manually_at'] = now()->toIso8601String();
        $meta['released_manually_by'] = $by;

        $service->forceFill([
            'provision_status' => Service::PROVISION_NONE,
            'provision_meta' => $meta,
        ])->save();

        try {
            $instance = \App\Models\CloudInstance::where('service_id', $service->id)->first();

            if ($instance !== null) {
                $imeta = (array) ($instance->meta ?? []);
                $imeta['released_manually_at'] = $meta['released_manually_at'];

                // ⚠️ وضعیتِ نمونه هم بسته می‌شود، وگرنه در فهرستِ موجودی «زنده» می‌مانَد
                $instance->update(['status' => 'deleted', 'last_error' => null, 'meta' => $imeta]);
            }
        } catch (\Throwable $e) {
            \App\Support\ErrorTracker::note('provision', $e, ['area' => 'release-manual', 'service' => $service->id]);
        }

        try {
            \App\Models\ActivityLog::forService($service, 'terminate',
                'آزادسازیِ سرور دستی اعلام شد — تلاشِ خودکار متوقف شد', $by);
        } catch (\Throwable $e) {
            /*
            | 🔴 این یکی عمداً `catch` خالی **نیست**.
            |
            | این تنها ردِ ماندگارِ «آدمی گفت پاک شده» است. اگر روزی صورت‌حسابِ
            | ارائه‌دهنده بگوید ماشین زنده بوده، سؤال دقیقاً همین است: چه کسی و
            | کِی گفت تمام شد. لاگی که بی‌صدا نیفتد، همان لحظه‌ای که لازمش داریم
            | نیست و ما هم نمی‌دانیم که نیست.
            */
            \App\Support\ErrorTracker::note('provision', $e, [
                'area' => 'release-manual-log', 'service' => $service->id, 'by' => $by,
            ]);
        }

        return true;
    }

    public function terminate(Service $service): ProvisionResult
    {
        /*
        | 🔴 گامِ ۱ — تصمیمِ صورت‌حسابی **پیش** از تماس با زیرساخت، و بی‌قیدوشرط.
        |
        | تا مرداد ۱۴۰۵ وضعیت فقط روی موفقیتِ زیرساخت نوشته می‌شد، پس یک حذفِ
        | ناموفق سرویس را `active` نگه می‌داشت و مترِ ساعتی همان ساعت دوباره از
        | مشتری کسر می‌کرد — بابتِ سروری که خواسته بود پاک شود.
        */
        $alreadyDead = $service->isDead();

        if (! $alreadyDead) {
            $service->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        }

        $r = $this->releaseAndTrack($service);

        if (! $alreadyDead) {
            /*
            | آخرین پیامِ این سرویس. پس از این، داده برنمی‌گردد.
            |
            | ⚠️ مدیر هم خبردار می‌شود چون خاتمه تصمیمِ آگاهانهٔ آدم است و باید
            |    ردِ مکتوب داشته باشد — «چه کسی، چه چیزی، کی».
            */
            try {
                app(\App\Services\Notify\Notifier::class)->fire(
                    'terminated',
                    $service->customer,
                    ['service' => $service->name],
                    'سرویسِ «'.$service->name.'» خاتمه یافت و داده‌هایش حذف شد. '
                    .'اگر این کار اشتباه بوده، فوراً با پشتیبانی تماس بگیرید.',
                    [],
                    $service->customer ? url('/admin/customers/'.$service->customer->id) : null,
                    '🗑',
                );
            } catch (\Throwable $e) {
                \App\Support\ErrorTracker::note('notify', $e, ['event' => 'terminated', 'service' => $service->id]);
            }
        }

        return $r;
    }

    // ───────────────────────────── کمکی‌ها ─────────────────────────────

    private function markFailed(Service $service, string $error): void
    {
        $service->forceFill([
            'provision_status' => 'failed',
            'provision_error'  => mb_substr($error, 0, 290),
            'status'           => 'provision_failed',
        ])->save();

        /*
        | 🔴 مشتری هم باید بداند، نه فقط لاگِ فعالیت.
        |
        | `notifyStaff()` فقط یک `ActivityLog::record` می‌نویسد و به هیچ کانالی
        | push نمی‌کند. یعنی جمله‌ای که در پنل به مشتریِ **پول‌داده** نشان
        | می‌دادیم («پشتیبانی در حالِ بررسی است») تا وقتی مدیر اتفاقی لاگ را باز
        | کند درست نبود — همان «اطمینانِ دروغ» که در پروندهٔ تمدیدِ دامنه هم
        | گرفتارمان کرد.
        */
        $this->notifyStaff('تحویلِ سرویس #'.$service->id.' («'.$service->name.'») ناموفق بود: '.$error);

        try {
            app(\App\Services\Notify\Notifier::class)->fire(
                'service_failed',
                $service->customer,
                /*
                | 🔴 `reason` عمداً **متنِ خامِ سرور نیست**.
                |
                | این رویداد الگوی پیامکِ زنده دارد، یعنی هر مقداری که این‌جا
                | بگذاریم به اپراتورِ پیامک هم می‌رود. متنِ خامِ WHM شاملِ
                | hostname و پورتِ سرورِ ماست («ارتباط با سرور … :2087 …») —
                | یعنی نامِ زیرساخت از یک پیامکِ پشتیبانی بیرون می‌رفت.
                |
                | ⚠️ کلید حذف نشد و فقط مقدارش امن شد: اگر از `NotifyEvent::vars`
                | برداشته می‌شد، هر الگویی که مدیر قبلاً با `{reason}` ذخیره
                | کرده، بی‌صدا از کار می‌افتاد (هر دو خوانندهٔ الگو، متنی که
                | جای‌نگهدارِ پرنشده دارد را دور می‌ریزند).
                |
                | متنِ خام همان‌جایی می‌مانَد که باید: `provision_error` برای
                | مدیر، و سطرِ «خطا» در اعلانِ مدیر پایین‌تر.
                */
                ['service' => $service->name, 'reason' => 'در حالِ بررسی توسطِ تیمِ فنی'],
                '⚠️ در آماده‌سازیِ سرویسِ «'.$service->name.'» مشکلی پیش آمد و تیمِ ما در حالِ بررسی است. '
                .'اگر ترجیح می‌دهید منتظر نمانید، می‌توانید از پنل سفارش را لغو کنید و مبلغ کامل به '
                .'اعتبارتان برمی‌گردد: '.console_lroute('account.services'),
                [
                    'سرویس'  => '#'.$service->id.' — '.$service->name,
                    'پلن'    => (string) $service->plan,
                    'سرور'   => (string) ($service->server?->name ?? '—'),
                    'خطا'    => mb_substr($error, 0, 160),
                ],
                null,
                '⚠️',
                [
                    [['text' => '🔁 تلاشِ دوبارهٔ تحویل', 'data' => \App\Services\Bale\Admin\AdminBaleRouter::CB_PREFIX.'spa:'.$service->id]],
                    $service->customer
                        ? [['text' => '👤 پروفایلِ مشتری', 'data' => \App\Services\Bale\Admin\AdminBaleRouter::CB_PREFIX.'c:'.$service->customer->id]]
                        : [],
                ],
            );
        } catch (\Throwable $e) {
            \App\Support\ErrorTracker::note('notify', $e, ['event' => 'service_failed', 'service' => $service->id]);
        }
    }

    private function ensureCredentials(Service $service, Server $server): void
    {
        $dirty = false;

        if (blank($service->username)) {
            $service->username = $this->makeUsername($service);
            $dirty = true;
        }
        if (blank($service->password)) {
            $service->password = $this->makePassword();
            $dirty = true;
        }
        // دامنهٔ پیش‌فرض اگر ادمین وارد نکرده باشد (WHM دامنه می‌خواهد)
        if (blank($service->domain)) {
            $service->domain = $service->username.'.'.($server->hostname ?: 'servernet.cloud');
            $dirty = true;
        }

        if ($dirty) {
            $service->save();
        }
    }

    /** نام‌کاربریِ معتبرِ cPanel: با حرف شروع، حداکثر ۱۶ نویسه، حروف کوچک و رقم */
    private function makeUsername(Service $service): string
    {
        $seed = $service->domain ?: $service->name;
        $base = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $seed));
        if ($base === '' || ! ctype_alpha($base[0])) {
            $base = 'sn'.$base;
        }
        $base = substr($base, 0, 8);

        return $base.str_pad((string) $service->id, 4, '0', STR_PAD_LEFT);   // یکتا و پایدار برای این سرویس
    }

    /** رمزِ قویِ سازگار با الزاماتِ cPanel (حروف بزرگ/کوچک/رقم/نماد) */
    private function makePassword(): string
    {
        $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnpqrstuvwxyz', '23456789', '!@#$%^*-_=+'];
        $pw = '';
        foreach ($sets as $s) {
            $pw .= $s[random_int(0, strlen($s) - 1)];
        }
        $all = implode('', $sets);
        for ($i = 0; $i < 12; $i++) {
            $pw .= $all[random_int(0, strlen($all) - 1)];
        }

        return str_shuffle($pw);
    }

    private function notify(Service $service, string $text): void
    {
        try {
            /*
            | 🔴 از `Notifier::fire()` می‌رود، نه مستقیم `CustomerNotifier`.
            |
            | کاتالوگ `service_ready` را `both` اعلام کرده، ولی این مسیر فقط به
            | مشتری خبر می‌داد: هر تحویلِ موفقِ cPanel/DirectAdmin بی‌اطلاعِ مدیر
            | انجام می‌شد، در حالی که برای **همان رویداد** روی سرورِ ابری اعلان
            | می‌رفت.
            |
            | ⚠️ و تستِ پوشش نمی‌گرفتش، چون فراخوانِ مسیرِ ابری را پیدا می‌کرد و
            | راضی می‌شد. درسِ تکراری: «وصل بودن» به‌ازای **هر مسیر** معنا دارد،
            | نه یک بار برای کلِ رویداد.
            |
            | ⚠️ متغیرها لازم‌اند وگرنه الگوی /admin/templates بی‌اثر می‌مانَد.
            */
            app(\App\Services\Notify\Notifier::class)->fire(
                'service_ready', $service->customer,
                ['service' => $service->name, 'ip' => (string) ($service->server?->hostname ?? '—')],
                $text,
                ['سرویس' => $service->name, 'سرور' => (string) ($service->server?->hostname ?? '—')],
                url('/admin/services/'.$service->id), '✅',
            );
        } catch (\Throwable $e) {
            // اعلان نباید تحویل را بشکند — ولی بی‌صدا هم نباید بمیرد
            \App\Support\ErrorTracker::note('notify', $e, ['area' => 'provision-ready']);
        }

        // ایمیلِ اطلاعاتِ سرویس (نام‌کاربری/رمز/آدرسِ ورود) — best-effort
        try {
            $customer = $service->customer;
            if ($customer && filled($customer->email)) {
                \Illuminate\Support\Facades\Mail::mailer('smtp')->to($customer->email)->send(
                    new \App\Mail\ServiceReadyMail(
                        $service->name,
                        $service->domain,
                        $service->panel_url,
                        $service->username,
                        (string) $service->password ?: null,
                        $customer->locale ?: 'fa',
                    )
                );
            }
        } catch (\Throwable) {
            // ایمیل نباید تحویل را بشکند
        }

        \App\Models\ActivityLog::record($service->customer_id, 'service', $text, null, 'system');
    }

    private function notifyStaff(string $text): void
    {
        \App\Models\ActivityLog::record(null, 'service', $text, null, 'system');
    }
}

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
                    ->orWhereNull('provision_status');
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
            $service->forceFill([
                'username'         => $result->username ?: $service->username,
                'password'         => $result->password ?: $service->password,
                'panel_url'        => $result->panelUrl ?: $service->panel_url,
                'provision_status' => 'done',
                'provision_error'  => null,
                'provisioned_at'   => now(),
                'provision_meta'   => $result->meta,
                'status'           => 'active',
                'activated_at'     => $service->activated_at ?? now(),
            ])->save();

            // شمارندهٔ ظرفیتِ سرور (اتمی) فقط اگر حسابِ تازه ساخته شده
            if (! ($result->meta['reused'] ?? false)) {
                Server::whereKey($server->id)->increment('active_accounts');
            }
        });

        // زیردامنهٔ رایگان تا وقتی رکوردِ DNS نداشته باشد بالا نمی‌آید (nameserverها
        // روی Cloudflare است و zoneِ محلیِ WHM را دنیا نمی‌بیند). بیرونِ تراکنش و
        // «بی‌صدا» است: اگر DNS نشد، سرویس نباید شکست‌خورده اعلام شود.
        $this->pointFreeSubdomain($service, $server);

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
            $service->update(['status' => 'active']);

            return $ok
                ? ProvisionResult::success(null, null, null)
                : ProvisionResult::fail('سرور روشن نشد؛ از پنل دستی روشنش کنید.');
        }

        if (! $service->server) {
            $service->update(['status' => 'active']);

            return ProvisionResult::success(null, null, null);
        }
        $r = $this->driverFor($service->server)->unsuspend($service);
        if ($r->ok || $r->manual) {
            $service->update(['status' => 'active']);
        }

        return $r;
    }

    public function terminate(Service $service): ProvisionResult
    {
        // خاتمهٔ سرورِ ابری = حذفِ واقعی نزدِ زیرساخت. اگر نکنیم، اجارهٔ سروری را
        // می‌دهیم که هیچ‌کس پولش را نمی‌دهد.
        if (\App\Services\Cloud\CloudProvisioner::handles($service)) {
            $ok = app(\App\Services\Cloud\CloudProvisioner::class)->terminate($service);

            if ($ok) {
                $service->update(['status' => 'cancelled', 'cancelled_at' => now()]);

                return ProvisionResult::success(null, null, null);
            }

            return ProvisionResult::fail('حذفِ سرور ناموفق بود؛ دوباره تلاش کنید.');
        }

        $r = $service->server
            ? $this->driverFor($service->server)->terminate($service)
            : ProvisionResult::success(null, null, null);

        if ($r->ok || $r->manual) {
            // ظرفیتِ آزادشده را برگردان — وگرنه شمارنده فقط بالا می‌رفت و
            // سرور برای همیشه «پر» می‌ماند؛ آن‌وقت هیچ مکانی در صفحهٔ خرید
            // نمایش داده نمی‌شد. فقط برای حسابی که واقعاً شمرده شده بود، و
            // با کرانِ صفر تا منفی نشود.
            $counted = $service->provision_status === 'done'
                && ! ($service->provision_meta['reused'] ?? false);

            if ($counted && $service->server_id) {
                Server::whereKey($service->server_id)
                    ->where('active_accounts', '>', 0)
                    ->decrement('active_accounts');
            }

            $service->update([
                'status'           => 'cancelled',
                'cancelled_at'     => now(),
                'provision_status' => 'none',      // دوباره شمرده نشود
            ]);
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

        // ادمین باید بداند تحویلی گیر کرده
        $this->notifyStaff('تحویلِ سرویس #'.$service->id.' («'.$service->name.'») ناموفق بود: '.$error);
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
            app(\App\Services\Notify\CustomerNotifier::class)->event($service->customer, 'service_ready', [], $text);
        } catch (\Throwable) {
            // اعلان نباید تحویل را بشکند
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

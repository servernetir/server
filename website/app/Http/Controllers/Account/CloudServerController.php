<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\CloudImage;
use App\Models\CloudInstance;
use App\Models\Service;
use App\Models\TunnelAgent;
use App\Models\TunnelJob;
use App\Services\Cloud\CloudManager;
use App\Support\ExitCountries;
use App\Support\TunnelProfile;
use App\Support\WireGuardKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * مدیریتِ سرورِ ابری در پنلِ مشتری.
 *
 * ⚠️ سه قاعدهٔ امنیتیِ این کنترلر:
 *
 * ۱) **مالکیت در هر متد.** `abort_unless($service->customer_id === …, 404)` —
 *    ۴۰۴ نه ۴۰۳، تا وجود/نبودِ سرویسِ دیگران هم لو نرود.
 *
 * ۲) **عملیاتِ پاک‌کنندهٔ داده تأییدِ صریح می‌خواهد.** نصبِ دوبارهٔ سیستم‌عامل
 *    کلِ دیسک را پاک می‌کند؛ فرم باید `confirm=DELETE` بفرستد. یک کلیکِ اشتباه
 *    نباید دادهٔ مشتری را ببرد.
 *
 * ۳) **محدودیتِ نرخ روی هر عمل.** کسی نباید بتواند با نگه‌داشتنِ کلیدِ Enter صد
 *    درخواستِ ریبوت به زیرساخت بفرستد (هم سرورش را می‌کشد، هم سهمیهٔ API ما).
 *
 * ⚠️ قاعدهٔ سفیدبرچسبی: هیچ پیامی در این کنترلر نامِ زیرساخت را نمی‌گوید. اگر
 * قابلیتی پشتیبانی نشود، پیامِ خنثای «برای این سرور در دسترس نیست» می‌آید.
 */
class CloudServerController extends Controller
{
    public function __construct(
        private CloudManager $manager,
        private \App\Services\Cloud\CloudOperations $ops,
    ) {}

    private function ownedService(Service $service): Service
    {
        $customer = Auth::guard('customer')->user();
        abort_unless($customer && $service->customer_id === $customer->id, 404);

        return $service;
    }

    /**
     * گیتِ عملیاتِ **نوشتنی** — سرویسِ تعلیق‌شده نباید دستکاری شود.
     *
     * 🔴 چرا حیاتی است: «تعلیقِ سرورِ ابری» یعنی **خاموش کردن** (نه بستنِ
     * دسترسی مثلِ cPanel). بی‌این گیت، مشتریِ بدهکاری که سرورش به‌خاطر
     * پرداخت‌نشدن خاموش شده، فقط با زدنِ دکمهٔ «روشن کردن» تعلیق را خودش لغو
     * می‌کند و تا ابد سرورِ ما را می‌چرخانَد؛ یعنی کلِ سازوکارِ تعلیق بی‌اثر
     * می‌شود و اجارهٔ سرور را ما می‌دهیم.
     *
     * خواندن (show/status/metrics) باز می‌ماند تا مشتری بتواند وضعیت و فاکتورِ
     * بازش را ببیند — بستنِ آن فقط سردرگمی می‌سازد.
     */
    private function denyIfNotWritable(Service $service): ?RedirectResponse
    {
        if (in_array($service->status, ['suspended', 'cancelled', 'expired', 'terminated', 'pending'], true)) {
            return back()->withErrors(
                'این سرور در حالِ حاضر تعلیق است. برای استفاده، فاکتورِ بازمانده را پرداخت کنید.'
            );
        }

        return null;
    }

    /**
     * پیامِ خطای زیرساخت را پیش از نمایش به مشتری پاک‌سازی کن.
     *
     * ⚠️ پیامِ خامِ ارائه‌دهنده معمولاً شناسه‌های بومی دارد؛ مثلاً
     * «Server 55443322 (cx22 in fsn1) is locked» — یعنی شناسهٔ سرور، نامِ بومیِ
     * پلن و کدِ دیتاسنتر، هر سه در صفحهٔ مشتری. همین سه ستون در `$hidden` مدل‌ها
     * پنهان شده‌اند، پس چاپشان در پیامِ خطا همان قاعده را از درِ پشتی می‌شکند.
     * متنِ خام فقط در `last_error` و لاگ می‌مانَد که مدیر می‌بیند.
     */
    private function safeMessage(string $raw, ?CloudInstance $instance): string
    {
        return $this->ops->scrub($raw, $instance);
    }

    private function instanceOf(Service $service): ?CloudInstance
    {
        return CloudInstance::where('service_id', $service->id)->first();
    }

    // ───────────────────────── نمایش ─────────────────────────

    public function show(Service $service): View
    {
        $this->ownedService($service);

        $instance = $this->instanceOf($service);
        $caps = $instance ? $this->manager->capabilitiesFor($instance) : [];

        // رمز فقط **یک بار** نشان داده می‌شود. دلیل: صفحهٔ همیشه‌بازِ پنل روی یک
        // لپ‌تاپِ مشترک، رمزِ root را به هر رهگذری می‌دهد. بعد از اولین دیدن،
        // مشتری باید «رمزِ تازه بساز» بزند.
        /*
        |----------------------------------------------------------------------
        | 🔴 رمز با **دیدنِ صفحه** سوخته نمی‌شود — فقط با کلیکِ صریح
        |----------------------------------------------------------------------
        |
        | رخدادِ واقعی: مشتری سرور خرید و در پنل هیچ رمزی ندید، پس اصلاً
        | نمی‌توانست وصل شود.
        |
        | علت: این‌جا یک **GET** پرچمِ `password_seen` را می‌زد. یعنی هر
        | بارگذاریِ صفحه رمز را می‌سوزاند — یک رفرش، یک prefetchِ مرورگر، یا
        | ورودِ مدیر به پنلِ مشتری برای عیب‌یابی. کاربر هیچ‌وقت چیزی ندید و
        | پرچم روشن شد.
        |
        | ⚠️ قاعدهٔ عمومی: **GET نباید حالت را عوض کند.** مرورگرها GET را آزادانه
        | تکرار و پیش‌بارگذاری می‌کنند؛ هر چیزی که یک‌بارمصرف است باید پشتِ یک
        | کنشِ صریح باشد.
        |
        | خودِ قاعدهٔ «یک بار» درست است و می‌مانَد (صفحهٔ همیشه‌بازِ پنل روی
        | لپ‌تاپِ مشترک)، ولی حالا لحظه‌اش را **کاربر** انتخاب می‌کند:
        | `revealPassword()` با POST.
        */
        $password = session('revealed_root_password');
        $canReveal = $instance && ! $instance->password_seen && $instance->hasPassword();

        // فازِ A: سوییچِ کشورِ خروج — فقط برای سرورهای میزبانِ ایران (Proxmox) که
        // تحویل شده‌اند. نامِ زیرساخت هیچ‌جا بیرون نمی‌رود (قاعدهٔ سفیدبرچسبی).
        $exitCapable = $instance && $instance->provider === 'proxmox' && $instance->isDelivered();

        // اکانت‌های تونلِ TCP — فقط اگر برای این سرور پروفایل تعریف شده باشد.
        $tunnel = TunnelProfile::fromInstance($instance);

        // پوستهٔ پنل (منو، هویتِ کاربر) از همان منبعِ بقیهٔ صفحات می‌آید؛ بی‌آن،
        // layout به متغیرِ نبود می‌خورد و کلِ صفحه ۵۰۰ می‌شود.
        return view('account.cloud-server', AccountController::shell('servers') + [
            'service'  => $service,
            'instance' => $instance,
            'caps'      => $caps,
            'password'  => $password,
            'canReveal' => $canReveal,
            'osList'   => $instance ? CloudImage::catalog('os', $instance->provider) : collect(),
            'appList'  => $instance ? CloudImage::catalog('app', $instance->provider) : collect(),
            // فازِ A
            'exitCapable' => $exitCapable,
            'exitOptions' => $exitCapable ? ExitCountries::options('fa') : [],
            'exitCurrent' => $exitCapable ? ($instance->exitCountryCode() ?: ExitCountries::NONE) : null,
            // اکانت‌های تونلِ TCP
            'tunnel' => $tunnel,
            'tunnelPeers' => $tunnel?->peers() ?? [],
            'tunnelNextIp' => $tunnel?->nextIp(),
            'tunnelNextName' => $tunnel?->suggestedName() ?? '',
            'tunnelIssued' => session('tunnel_issued'),
            // ایجنتِ روتر: نصب‌شده؟ زنده؟ چند کار در صف؟
            // ⚠️ «نصب‌شده» و «زنده» عمداً دو چیزند — ایجنتی که سه روز است
            //    خبری ازش نیست از نظرِ مشتری کار نمی‌کند، و اگر یک برچسب
            //    نشانشان دهیم تا اولین اکانتِ ساخته‌نشده کسی خبردار نمی‌شود.
            'tunnelAgent' => $tunnel === null ? null : TunnelAgent::where('service_id', $service->id)->first(),
            'tunnelPending' => $tunnel === null ? 0 : TunnelJob::query()->forService((int) $service->id)->pending()->count(),
            // نام → وضعیتِ تحویل، فقط برای اکانت‌هایی که کارِ بازی دارند.
            'tunnelStates' => $tunnel === null ? [] : TunnelJob::query()
                ->forService((int) $service->id)
                ->where('status', '!=', 'done')
                ->orderBy('id')
                ->pluck('status', 'name')
                ->map(fn (string $st): string => $st === 'pending' ? 'pending' : 'failed')
                ->mapWithKeys(fn (string $st, string $n): array => [mb_strtolower($n) => $st])
                ->all(),
        ]);
    }

    /**
     * سوییچِ کشورِ خروج توسطِ خودِ مشتری (فازِ A).
     *
     * فقط «حالتِ مطلوب» را در `meta['exit_country']` می‌نویسد؛ اعمالِ واقعی با
     * ایجنتِ ایران در پیمایشِ بعدی است. مثلِ بقیهٔ کنش‌ها: مالکیت + گیتِ
     * نوشتنی + محدودیتِ نرخ. پیام‌ها نامِ زیرساخت را نمی‌گویند.
     */
    public function setExitCountry(Request $request, Service $service): RedirectResponse
    {
        $this->ownedService($service);

        if ($resp = $this->denyIfNotWritable($service)) {
            return $resp;
        }

        $instance = $this->instanceOf($service);

        if ($instance === null || $instance->provider !== 'proxmox' || ! $instance->isDelivered()) {
            return back()->withErrors(__('ui.cx_egress_na'));
        }

        // محدودیتِ نرخ — مثلِ الگوی throttleِ بقیهٔ عملیاتِ این کنترلر
        $key = 'cloud-exit:'.$service->id;

        if (RateLimiter::tooManyAttempts($key, 6)) {
            $seconds = RateLimiter::availableIn($key);

            return back()->withErrors(__('ui.cx_throttle', ['sec' => fa_num($seconds)]));
        }

        RateLimiter::hit($key, 60);

        $cc = strtolower(trim((string) $request->input('country')));

        if (! ExitCountries::accepts($cc)) {
            return back()->withErrors(__('ui.cx_country_na'));
        }

        $disable = ExitCountries::isNone($cc);

        $meta = $instance->meta ?? [];
        $meta['exit_country']    = $disable ? ExitCountries::NONE : $cc;
        $meta['exit_country_at'] = now()->toIso8601String();
        $meta['exit_country_by'] = 'customer';
        $instance->meta = $meta;
        $instance->save();

        $label = $disable ? 'ایران (بدونِ اکسیت)' : mb_strtoupper($cc);

        $this->log($service, __('ui.act_cloud_exit', ['label' => $label]));

        return back()->with('ok', __('ui.cx_egress_set', ['label' => $label]));
    }

    /**
     * وضعیتِ زندهٔ سرور (AJAX) — برای نشانگرِ روشن/خاموش بی‌بارگذاریِ صفحه.
     *
     * خطای زیرساخت این‌جا هم ۲۰۰ برمی‌گردد با `ok=false`، چون یک نشانگرِ
     * ناموفق نباید کنسولِ مرورگر را پر از خطای ۵۰۰ کند.
     */
    public function status(Service $service): JsonResponse
    {
        $this->ownedService($service);
        $instance = $this->instanceOf($service);

        // ⚠️ هنوز شناسهٔ سرور نداریم (سفارشِ دومرحله‌ای تازه ثبت شده). صفحه باید
        // «سفارش ثبت شد» ببیند، نه «نامشخص» — و مطلقاً نه «آماده».
        if ($instance === null || blank($instance->provider_ref)) {
            return response()->json([
                'ok' => false, 'status' => 'building', 'ready' => false,
                'stage' => $instance?->stage() ?? 'ordered',
                'stage_index' => $instance?->stageIndex() ?? 0,
            ]);
        }

        $driver = $this->manager->forInstance($instance);

        if ($driver === null) {
            return response()->json($this->statePayload($instance, false));
        }

        // ⚠️ کش لازم است، نه تجملی: این متد را صفحه هر ۳۰ ثانیه می‌پرسد و هر
        // تماس یک درخواستِ واقعی روی **توکنِ مشترکِ کلِ پروژه** است. سهمیهٔ
        // ساعتیِ زیرساخت مشترک است، پس یک تبِ رهاشده (یا یک حلقهٔ ساده با
        // کوکیِ نشست) می‌تواند سهمیه را بسوزاند و از آن لحظه **تحویلِ سرورِ
        // همهٔ مشتریانِ دیگر** شکست بخورد. ۲۰ ثانیه برای نشانگرِ روشن/خاموش
        // کافی است و بار را ~۹۰٪ کم می‌کند.
        $r = \Illuminate\Support\Facades\Cache::remember(
            'cloud-st:'.$instance->id,
            now()->addSeconds(20),
            fn () => $driver->serverStatus((string) $instance->provider_ref)
        );

        if ($r['ok']) {
            $instance->update([
                'status'    => $r['status'],
                'ipv4'      => $r['ipv4'] ?: $instance->ipv4,
                'ipv6'      => $r['ipv6'] ?: $instance->ipv6,
                'synced_at' => now(),
            ]);
        }

        return response()->json(
            $this->statePayload($instance->fresh(), (bool) $r['ok'])
            + ['traffic' => $r['traffic_used_gb'] ?? null]
        );
    }

    /**
     * تنها شکلِ پاسخِ وضعیت — تا صفحه و کرون یک تعریف از «آماده» داشته باشند.
     *
     * 🔴 `ready` عمداً از `CloudInstance::isDelivered()` می‌آید و نه از رشتهٔ
     * وضعیت. باگِ گزارش‌شده همین بود: پنل «ساخته شد» می‌گفت در حالی که زیرساخت
     * `activating` می‌گفت. هر وضعیتِ ناشناخته (یا بی‌IP) ⇒ آماده **نیست**.
     *
     * @return array<string,mixed>
     */
    private function statePayload(CloudInstance $instance, bool $ok): array
    {
        return [
            'ok'          => $ok,
            'status'      => $instance->status,
            /*
            | برچسبِ نشانگر هم از همان تعریف می‌آید. تا پیش از تحویل، **مرحله**
            | را می‌گوید نه رشتهٔ خامِ زیرساخت — وگرنه مشتری روی سرورِ در حالِ
            | ساخت کلمهٔ «نامشخص» می‌دید، که هم بی‌معنی است هم نگران‌کننده.
            */
            'label'       => $instance->isDelivered()
                ? $instance->statusLabel()
                : __('ui.cs_stage_'.$instance->stage()),
            'color'       => $instance->statusColor(),
            'ipv4'        => $instance->ipv4,
            'ready'       => $instance->isDelivered(),
            'stage'       => $instance->stage(),
            'stage_index' => $instance->stageIndex(),
        ];
    }

    /** نمودارِ مصرف (AJAX) */
    public function metrics(Request $request, Service $service): JsonResponse
    {
        $this->ownedService($service);
        $instance = $this->instanceOf($service);

        if ($instance === null || blank($instance->provider_ref)) {
            return response()->json(['ok' => false, 'series' => []]);
        }

        $driver = $this->manager->forInstance($instance);
        $window = in_array($request->query('window'), ['1h', '24h', '7d', '30d'], true)
            ? (string) $request->query('window') : '24h';

        // نمودار گران‌ترین تماسِ این حوزه است (بازهٔ زمانی + گامِ نمونه‌برداری)
        // و دادهٔ ۲۴ ساعت با دو دقیقه تأخیر هیچ تفاوتی برای کاربر ندارد.
        $r = \Illuminate\Support\Facades\Cache::remember(
            'cloud-mx:'.$instance->id.':'.$window,
            now()->addMinutes(2),
            fn () => $driver?->metrics((string) $instance->provider_ref, $window)
                ?? ['ok' => false, 'series' => [], 'message' => '']
        );

        return response()->json(['ok' => (bool) $r['ok'], 'series' => $r['series'] ?? []]);
    }

    // ───────────────────────── عملیات ─────────────────────────

    /**
     * روشن/خاموش/راه‌اندازیِ دوباره.
     *
     * توجه: «خاموش» در درایور به `shutdown` نرم (ACPI) نگاشت می‌شود نه کشیدنِ
     * برق — روی سرورِ دیتابیس‌دار، قطعِ ناگهانی داده را خراب می‌کند.
     */
    /**
     * نمایشِ یک‌بارهٔ رمزِ root — با کنشِ صریحِ کاربر.
     *
     * ⚠️ POST و نه GET: مرورگر GET را آزادانه تکرار و پیش‌بارگذاری می‌کند، و
     * تا امروز همان باعث می‌شد رمز پیش از دیده‌شدن بسوزد و مشتری هیچ راهی به
     * سرورش نداشته باشد.
     *
     * ⚠️ رمز در **session flash** برمی‌گردد نه در URL: هرچه در آدرس باشد در
     * لاگِ سرور، لاگِ کلادفلر و تاریخچهٔ مرورگر می‌نشیند — همان قاعده‌ای که
     * برای `DEPLOY_TOKEN` هم داریم.
     */
    public function revealPassword(Request $request, Service $service): RedirectResponse
    {
        $this->ownedService($service);

        $instance = $service->cloudInstance;

        if ($instance === null || ! $instance->hasPassword()) {
            return back()->withErrors(__('ui.cx_no_pass'));
        }

        if ($instance->password_seen) {
            return back()->withErrors(__('ui.cx_pass_seen'));
        }

        $password = $instance->password();
        $instance->update(['password_seen' => true]);

        return back()->with('revealed_root_password', $password);
    }

    public function power(Request $request, Service $service): RedirectResponse
    {
        $this->ownedService($service);

        $action = (string) $request->input('action');

        if (! in_array($action, ['on', 'off', 'reboot'], true)) {
            return back()->withErrors(__('ui.cx_bad_op'));
        }

        if ($denied = $this->denyIfNotWritable($service)) {
            return $denied;
        }

        if ($limited = $this->rateLimit($service, 'power', 12)) {
            return $limited;
        }

        return $this->run($service, function ($driver, $ref) use ($action) {
            return $driver->power($ref, $action);
        }, match ($action) {
            'on'     => 'سرور روشن شد.',
            'off'    => 'فرمانِ خاموش‌شدن فرستاده شد.',
            default  => 'سرور در حالِ راه‌اندازیِ دوباره است.',
        }, 'power:'.$action);
    }

    /**
     * نصبِ دوبارهٔ سیستم‌عامل یا نرم‌افزارِ آماده.
     *
     * ⚠️ **کلِ دیسک پاک می‌شود.** پس هم تأییدِ متنیِ صریح لازم است هم محدودیتِ
     * نرخِ سخت‌تر. رمزِ تازه هم برمی‌گردد و پرچمِ «دیده‌نشده» ری‌ست می‌شود تا
     * مشتری یک‌بار ببیندش.
     */
    public function rebuild(Request $request, Service $service): RedirectResponse
    {
        $this->ownedService($service);

        if ($denied = $this->denyIfNotWritable($service)) {
            return $denied;
        }

        $data = $request->validate([
            'image'   => ['required', 'string', 'max:64'],
            'confirm' => ['required', 'string'],
        ]);

        if (strtoupper(trim($data['confirm'])) !== 'DELETE') {
            return back()->withErrors(__('ui.cx_type_delete'));
        }

        $instance = $this->instanceOf($service);

        if ($instance === null) {
            return back()->withErrors(__('ui.cx_not_ready'));
        }

        // ایمیج باید واقعاً روی همین زیرساخت **و همین معماری** باشد؛ ورودیِ
        // دلخواهِ کاربر را مستقیم به API نمی‌فرستیم. معماری از پلنِ همین سرویس
        // می‌آید — نصبِ دوبارهٔ یک سرورِ arm با ایمیجِ x86 رد می‌شود.
        $ref = CloudImage::refFor($instance->provider, $data['image'], $service->cloudPlan?->arch);

        if ($ref === null) {
            return back()->withErrors(__('ui.cx_os_na'));
        }

        if ($limited = $this->rateLimit($service, 'rebuild', 3)) {
            return $limited;
        }

        $driver = $this->manager->forInstance($instance);

        if ($driver === null) {
            return back()->withErrors(__('ui.cx_op_na'));
        }

        $r = $driver->rebuild((string) $instance->provider_ref, $ref);

        if (! ($r['ok'] ?? false)) {
            $instance->update(['last_error' => mb_substr((string) $r['message'], 0, 500)]);

            return back()->withErrors(__('ui.cx_reinstall_fail', ['msg' => $this->safeMessage((string) $r['message'], $instance)]));
        }

        $instance->fill(['status' => 'building', 'image_key' => $data['image'], 'last_error' => null]);

        if (filled($r['root_password'] ?? null)) {
            $instance->setPassword($r['root_password']);      // password_seen → false
        }

        $instance->save();

        $this->log($service, __('ui.act_cloud_reinstall', ['image' => $data['image']]));

        return back()->with('ok', __('ui.cx_reinstall_ok'));
    }

    /** رمزِ تازهٔ root */
    public function resetPassword(Service $service): RedirectResponse
    {
        $this->ownedService($service);

        if ($denied = $this->denyIfNotWritable($service)) {
            return $denied;
        }

        if ($limited = $this->rateLimit($service, 'password', 5)) {
            return $limited;
        }

        $instance = $this->instanceOf($service);
        $driver = $instance ? $this->manager->forInstance($instance) : null;

        if ($instance === null || $driver === null || blank($instance->provider_ref)) {
            return back()->withErrors(__('ui.cx_op_na'));
        }

        $r = $driver->resetPassword((string) $instance->provider_ref);

        if (! ($r['ok'] ?? false) || blank($r['root_password'] ?? null)) {
            return back()->withErrors(__('ui.cx_newpass_fail', ['msg' => $this->safeMessage((string) ($r['message'] ?: '—'), $instance)]));
        }

        $instance->setPassword($r['root_password']);
        $instance->save();

        // رمز را در سرویس هم تازه می‌کنیم تا صفحهٔ فهرستِ سرویس‌ها همان را بگوید
        $service->forceFill(['password' => $r['root_password']])->save();

        $this->log($service, __('ui.act_cloud_rootpw'));

        return back()->with('ok', __('ui.cx_newpass_ok'));
    }

    /**
     * کنسولِ تحتِ وب — سه‌مرحله‌ای، تا آدرسِ زیرساخت هرگز در HTML نباشد.
     *
     * ۱) `console()`  : از زیرساخت آدرس می‌گیرد، در کشِ کوتاه‌عمر می‌گذارد و یک
     *                   **بلیتِ یک‌بارمصرف** می‌سازد، بعد به صفحهٔ نمایش می‌رود.
     * ۲) `consoleView()`: صفحهٔ کنسول روی **دامنهٔ خودمان** با noVNC خودمیزبان.
     * ۳) `consoleTicket()`: تنها جایی که آدرسِ واقعی برمی‌گردد — پاسخِ JSON
     *                   same-origin که بعد از یک بار خواندن **پاک می‌شود**.
     *
     * چرا این‌طور و نه یک `href` ساده: آدرسِ خامِ زیرساخت هم نامِ برند را لو
     * می‌داد هم شناسهٔ داخلیِ سرور، و در تاریخچهٔ مرورگر و لاگِ Cloudflare
     * می‌نشست. با بلیت، آدرس فقط یک بار و فقط به جاوااسکریپتِ همان صفحه می‌رسد.
     *
     * ⚠️ صداقتِ لازم: مرورگر در نهایت **مستقیم** به ماشینِ مجازیِ مشتری وصل
     * می‌شود، پس کسی که کنسولِ توسعه‌دهندهٔ مرورگر را باز کند می‌تواند میزبان را
     * ببیند. پنهان‌سازیِ کامل به یک رله‌ی WebSocket روی دامنهٔ خودمان نیاز دارد
     * (یک پروسهٔ همیشه‌روشن که cPanel نمی‌تواند نگه دارد). در HTML، هدرها،
     * تاریخچه و لینک‌ها هیچ نشانی نیست.
     */
    public function console(Service $service): RedirectResponse
    {
        $this->ownedService($service);

        if ($denied = $this->denyIfNotWritable($service)) {
            return $denied;
        }

        if ($limited = $this->rateLimit($service, 'console', 10)) {
            return $limited;
        }

        $instance = $this->instanceOf($service);
        $driver = $instance ? $this->manager->forInstance($instance) : null;

        if ($instance === null || $driver === null || blank($instance->provider_ref)) {
            return back()->withErrors(__('ui.cx_console_na'));
        }

        $r = $driver->console((string) $instance->provider_ref);

        if (! ($r['ok'] ?? false) || blank($r['url'] ?? null)) {
            return back()->withErrors($this->safeMessage(
                (string) ($r['message'] ?: 'کنسول برای این سرور در دسترس نیست.'), $instance
            ));
        }

        // بلیت: تصادفی، کوتاه‌عمر، و گره‌خورده به همین سرویس تا با بلیتِ سرویسِ
        // دیگری قابلِ استفاده نباشد.
        $ticket = bin2hex(random_bytes(16));

        \Illuminate\Support\Facades\Cache::put(
            $this->ticketKey($service, $ticket),
            ['url' => $r['url'], 'password' => $r['password'] ?? null],
            now()->addSeconds(90)
        );

        $this->log($service, __('ui.act_cloud_console'));

        return redirect()->route('account.cloud.console.view', [$service, 't' => $ticket]);
    }

    /** صفحهٔ کنسول — روی دامنهٔ خودمان، با noVNC خودمیزبان (CSP اجازهٔ CDN نمی‌دهد) */
    public function consoleView(Request $request, Service $service): View|RedirectResponse
    {
        $this->ownedService($service);

        $ticket = (string) $request->query('t', '');

        if ($ticket === '' || ! \Illuminate\Support\Facades\Cache::has($this->ticketKey($service, $ticket))) {
            return redirect()->route('account.cloud.show', $service)
                ->withErrors(__('ui.cx_console_expired'));
        }

        return view('account.cloud-console', AccountController::shell('servers') + [
            'service'  => $service,
            'instance' => $this->instanceOf($service),
            'ticket'   => $ticket,
        ]);
    }

    /**
     * بلیت را **یک بار** به آدرسِ واقعی تبدیل می‌کند و بعد پاکش می‌کند.
     *
     * `pull` نه `get`: اگر آدرس در کش بماند، بازکردنِ دوبارهٔ تبِ کنسول با همان
     * بلیت کار می‌کند و «یک‌بارمصرف» فقط یک ادعا می‌شود.
     */
    public function consoleTicket(Request $request, Service $service): JsonResponse
    {
        $this->ownedService($service);

        $data = \Illuminate\Support\Facades\Cache::pull(
            $this->ticketKey($service, (string) $request->query('t', ''))
        );

        if (! is_array($data) || blank($data['url'] ?? null)) {
            return response()->json(['ok' => false, 'message' => 'نشستِ کنسول منقضی شده است.'], 410);
        }

        return response()->json([
            'ok'       => true,
            'url'      => $data['url'],
            'password' => $data['password'],
        ])->header('Cache-Control', 'no-store');
    }

    private function ticketKey(Service $service, string $ticket): string
    {
        return 'cloud-console:'.$service->id.':'.$ticket;
    }

    // ───────────────────────── کمکی ─────────────────────────

    private function run(Service $service, callable $fn, string $okMessage, string $logAction): RedirectResponse
    {
        $instance = $this->instanceOf($service);
        $driver = $instance ? $this->manager->forInstance($instance) : null;

        if ($instance === null || $driver === null || blank($instance->provider_ref)) {
            return back()->withErrors(__('ui.cx_not_ready'));
        }

        $r = $fn($driver, (string) $instance->provider_ref);

        if (! ($r['ok'] ?? false)) {
            $instance->update(['last_error' => mb_substr((string) $r['message'], 0, 500)]);

            return back()->withErrors(__('ui.cx_action_fail', ['msg' => $this->safeMessage((string) $r['message'], $instance)]));
        }

        $instance->update(['last_error' => null, 'synced_at' => now()]);
        $this->log($service, $logAction);

        return back()->with('ok', $okMessage);
    }

    // ────────────────── اکانت‌های تونلِ TCP ──────────────────

    /**
     * صدورِ یک اکانتِ تازهٔ «WireGuard روی TCP» برای این سرور.
     *
     * چرا این‌جا و نه روی روتر: پنل به روترِ مشتری راه ندارد (سرویسِ SSHـش با
     * `available-from` بسته است و API خاموش). پس پنل کلید را می‌سازد، کانفیگِ
     * کاربر را تحویل می‌دهد و **یک خط دستور** می‌دهد که مشتری در ترمینالِ روترِ
     * خودش اجرا می‌کند. وقتی دسترسیِ API فراهم شد، فقط همان یک خط خودکار
     * می‌شود و بقیهٔ این جریان دست‌نخورده می‌ماند.
     *
     * 🔴 کلیدِ خصوصی ذخیره نمی‌شود. یک‌بار در پاسخِ همین درخواست دیده می‌شود و
     * می‌رود — مثلِ «نمایشِ یک‌بارهٔ رمزِ روت». در `meta` فقط نام، آدرس و کلیدِ
     * عمومی می‌ماند، که هیچ‌کدام رازِ قابلِ‌سوءاستفاده نیستند.
     */
    public function issueTunnelAccount(Request $request, Service $service): RedirectResponse
    {
        $this->ownedService($service);

        if ($resp = $this->denyIfNotWritable($service)) {
            return $resp;
        }

        $instance = $this->instanceOf($service);
        $tunnel = TunnelProfile::fromInstance($instance);

        if ($tunnel === null) {
            return back()->withErrors(__('ui.cx_tunnel_na'));
        }

        if ($resp = $this->rateLimit($service, 'tunnel', 10)) {
            return $resp;
        }

        if (count($tunnel->peers()) >= TunnelProfile::MAX_PEERS) {
            return back()->withErrors(
                'به سقفِ '.fa_num(TunnelProfile::MAX_PEERS).' اکانت رسیده‌اید. یکی را حذف کنید و دوباره تلاش کنید.'
            );
        }

        $name = strtolower(trim((string) $request->input('name')));

        if (! preg_match('~^[a-z0-9][a-z0-9_-]{1,23}$~', $name)) {
            return back()->withErrors(__('ui.cx_tun_name_bad'));
        }

        if (! $tunnel->nameIsFree($name)) {
            return back()->withErrors(__('ui.cx_tun_dup'));
        }

        $ip = trim((string) $request->input('ip')) ?: (string) $tunnel->nextIp();

        if (! $tunnel->ipInSubnet($ip)) {
            return back()->withErrors(__('ui.cx_tun_ip_range'));
        }

        if (! $tunnel->ipIsFree($ip)) {
            return back()->withErrors(__('ui.cx_tun_ip_used'));
        }

        $keys = WireGuardKey::generate();

        $tunnel->addPeer($name, $ip, $keys['public']);

        $agented = $this->dispatchTunnelJob($service, TunnelJob::OP_ADD, $name, $ip, $keys['public']);

        $this->log($service, __('ui.act_cloud_tun_new', ['name' => $name, 'ip' => $ip]));

        // یک‌بارمصرف: کلیدِ خصوصی و کانفیگ فقط در همین flash می‌مانَد.
        return back()->with('tunnel_issued', [
            'name' => $name,
            'ip' => $ip,
            // ⚠️ دستور حتی در حالتِ ایجنت هم می‌ماند: ایجنت ممکن است خاموش باشد
            //    و این تنها راهِ نجاتِ مشتری در همان لحظه است.
            'command' => $tunnel->routerAddCommand($name, $ip, $keys['public']),
            'agented' => $agented,
            'config' => $tunnel->configJson($ip, $keys['private']),
            'legacy' => $tunnel->configJson($ip, $keys['private'], 'legacy'),
            'file' => 'tunnel-'.$name.'.json',
        ])->with('ok', $agented
            ? __('ui.cx_tun_created_agent', ['name' => $name])
            : __('ui.cx_tun_created', ['name' => $name]));
    }

    /**
     * حذفِ یک اکانت از فهرست + دستورِ حذفِ متناظر برای روتر.
     *
     * حذف از فهرستِ پنل به‌تنهایی دسترسی را قطع نمی‌کند؛ تا وقتی peer روی روتر
     * هست کار می‌کند. برای همین پیام، دستورِ حذف را هم برمی‌گرداند و صریح
     * می‌گوید که اجرای آن لازم است.
     */
    public function removeTunnelAccount(Request $request, Service $service): RedirectResponse
    {
        $this->ownedService($service);

        if ($resp = $this->denyIfNotWritable($service)) {
            return $resp;
        }

        $instance = $this->instanceOf($service);
        $tunnel = TunnelProfile::fromInstance($instance);

        if ($tunnel === null) {
            return back()->withErrors(__('ui.cx_tunnel_na'));
        }

        if ($resp = $this->rateLimit($service, 'tunnel-del', 20)) {
            return $resp;
        }

        $name = strtolower(trim((string) $request->input('name')));

        if ($name === '' || ! $tunnel->removePeer($name)) {
            return back()->withErrors(__('ui.cx_tun_missing'));
        }

        $agented = $this->dispatchTunnelJob($service, TunnelJob::OP_REMOVE, $name);

        $this->log($service, __('ui.act_cloud_tun_del', ['name' => $name]));

        return back()
            ->with('tunnel_removed', $tunnel->routerRemoveCommand($name))
            ->with('tunnel_removed_agented', $agented)
            ->with('ok', $agented
                ? __('ui.cx_tun_deleted_agent', ['name' => $name])
                : __('ui.cx_tun_deleted', ['name' => $name]));
    }

    /**
     * ثبتِ ایجنتِ روتر — «از این به بعد خودش انجام بده».
     *
     * 🔴 توکن فقط همین یک بار دیده می‌شود و در flash می‌نشیند. ذخیره‌اش در
     * دیتابیس به‌شکلِ خوانا وسوسه‌کننده است («اگر مشتری گمش کرد») ولی همان
     * توکن روی روترِ مشتری زندگی می‌کند — جایی که نه وصله‌اش می‌کنیم نه لاگش
     * را می‌بینیم. صدورِ دوباره ارزان است؛ نگه‌داشتنِ نسخهٔ خوانا نیست.
     *
     * ⚠️ صدورِ دوباره توکنِ قبلی را می‌کُشد و متنِ صفحه صریح همین را می‌گوید.
     * وگرنه مشتری روی دکمه می‌زند تا «دوباره ببیندش» و روترِ زنده‌اش را قطع
     * می‌کند، بی‌آنکه بفهمد چرا از فردا اکانت‌ها ساخته نمی‌شوند.
     */
    public function enrollTunnelAgent(Request $request, Service $service): RedirectResponse
    {
        $this->ownedService($service);

        if ($resp = $this->denyIfNotWritable($service)) {
            return $resp;
        }

        $tunnel = TunnelProfile::fromInstance($this->instanceOf($service));

        if ($tunnel === null) {
            return back()->withErrors(__('ui.cx_tunnel_na'));
        }

        if ($resp = $this->rateLimit($service, 'tunnel-agent', 5)) {
            return $resp;
        }

        $existed = TunnelAgent::where('service_id', $service->id)->exists();

        [, $plain] = TunnelAgent::issueFor((int) $service->id);

        $this->log($service, $existed ? __('ui.act_cloud_agent_re') : __('ui.act_cloud_agent_new'));

        $base = rtrim(url('/agent/tunnel'), '/');

        return back()->with('tunnel_agent', [
            'replaced' => $existed,
            'lines' => [
                '/tool fetch url="'.$base.'/install" http-header-field="X-Agent-Token: '.$plain.'" dst-path=snet-agent.rsc',
                '/import file-name=snet-agent.rsc',
            ],
        ])->with('ok', $existed
            ? __('ui.cx_agent_reissued')
            : __('ui.cx_agent_enrolled'));
    }

    /**
     * اگر ایجنتی هست، کار را در صفش بگذار. `true` یعنی گذاشته شد.
     *
     * 🔴 بی‌ایجنت هیچ ردیفی ساخته نمی‌شود. صفی که هیچ‌کس از آن برنمی‌دارد فقط
     * انبوهی کارِ «منتظر» می‌سازد که ۲۴ ساعت بعد `failed` می‌شوند — یعنی به
     * مشتریِ بی‌ایجنت خبرِ خرابی می‌دادیم برای کاری که او از اول قرار بود دستی
     * انجام دهد.
     */
    private function dispatchTunnelJob(Service $service, string $op, string $name, ?string $ip = null, ?string $pub = null): bool
    {
        if (! TunnelAgent::where('service_id', $service->id)->exists()) {
            return false;
        }

        TunnelJob::enqueue((int) $service->id, $op, $name, $ip, $pub);

        return true;
    }

    /**
     * محدودیتِ نرخ بر پایهٔ سرویس، نه IP.
     *
     * چرا سرویس: مشتری با موبایل و لپ‌تاپ دو IP دارد ولی سرور یکی است. سقف روی
     * IP، یک کاربر با شبکهٔ متغیر را بی‌دلیل می‌بندد و در عوض جلوی سیلِ
     * درخواست به یک سرور را نمی‌گیرد.
     */
    private function rateLimit(Service $service, string $action, int $perMinute): ?RedirectResponse
    {
        $key = 'cloud:'.$action.':'.$service->id;

        if (RateLimiter::tooManyAttempts($key, $perMinute)) {
            $seconds = RateLimiter::availableIn($key);

            return back()->withErrors(__('ui.cx_throttle', ['sec' => fa_num($seconds)]));
        }

        RateLimiter::hit($key, 60);

        return null;
    }

    private function log(Service $service, string $text): void
    {
        try {
            ActivityLog::record($service->customer_id, 'service',
                __('ui.act_cloud_evt', ['id' => $service->id, 'text' => $text]), null, 'customer');
        } catch (\Throwable) {
            // لاگ نباید عملیات را بشکند
        }
    }
}

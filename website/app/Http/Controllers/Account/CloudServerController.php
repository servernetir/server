<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\CloudImage;
use App\Models\CloudInstance;
use App\Models\Service;
use App\Services\Cloud\CloudManager;
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
        $password = null;

        if ($instance && ! $instance->password_seen && $instance->hasPassword()) {
            $password = $instance->password();
            $instance->update(['password_seen' => true]);
        }

        // پوستهٔ پنل (منو، هویتِ کاربر) از همان منبعِ بقیهٔ صفحات می‌آید؛ بی‌آن،
        // layout به متغیرِ نبود می‌خورد و کلِ صفحه ۵۰۰ می‌شود.
        return view('account.cloud-server', AccountController::shell('services') + [
            'service'  => $service,
            'instance' => $instance,
            'caps'     => $caps,
            'password' => $password,
            'osList'   => $instance ? CloudImage::catalog('os', $instance->provider) : collect(),
            'appList'  => $instance ? CloudImage::catalog('app', $instance->provider) : collect(),
        ]);
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

        if ($instance === null || blank($instance->provider_ref)) {
            return response()->json(['ok' => false, 'status' => 'building']);
        }

        $driver = $this->manager->forInstance($instance);

        if ($driver === null) {
            return response()->json(['ok' => false, 'status' => $instance->status]);
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

        return response()->json([
            'ok'      => (bool) $r['ok'],
            'status'  => $r['ok'] ? $r['status'] : $instance->status,
            'label'   => $instance->fresh()->statusLabel(),
            'color'   => $instance->fresh()->statusColor(),
            'ipv4'    => $instance->fresh()->ipv4,
            'traffic' => $r['traffic_used_gb'] ?? null,
        ]);
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
    public function power(Request $request, Service $service): RedirectResponse
    {
        $this->ownedService($service);

        $action = (string) $request->input('action');

        if (! in_array($action, ['on', 'off', 'reboot'], true)) {
            return back()->withErrors('عملیاتِ نامعتبر.');
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
            return back()->withErrors('برای نصبِ دوباره باید عبارتِ DELETE را تایپ کنید — همهٔ داده‌های سرور پاک می‌شود.');
        }

        $instance = $this->instanceOf($service);

        if ($instance === null) {
            return back()->withErrors('سرور هنوز آماده نیست.');
        }

        // ایمیج باید واقعاً روی همین زیرساخت **و همین معماری** باشد؛ ورودیِ
        // دلخواهِ کاربر را مستقیم به API نمی‌فرستیم. معماری از پلنِ همین سرویس
        // می‌آید — نصبِ دوبارهٔ یک سرورِ arm با ایمیجِ x86 رد می‌شود.
        $ref = CloudImage::refFor($instance->provider, $data['image'], $service->cloudPlan?->arch);

        if ($ref === null) {
            return back()->withErrors('این سیستم‌عامل برای این سرور در دسترس نیست.');
        }

        if ($limited = $this->rateLimit($service, 'rebuild', 3)) {
            return $limited;
        }

        $driver = $this->manager->forInstance($instance);

        if ($driver === null) {
            return back()->withErrors('این عملیات برای این سرور در دسترس نیست.');
        }

        $r = $driver->rebuild((string) $instance->provider_ref, $ref);

        if (! ($r['ok'] ?? false)) {
            $instance->update(['last_error' => mb_substr((string) $r['message'], 0, 500)]);

            return back()->withErrors('نصبِ دوباره انجام نشد: '.$this->safeMessage((string) $r['message'], $instance));
        }

        $instance->fill(['status' => 'building', 'image_key' => $data['image'], 'last_error' => null]);

        if (filled($r['root_password'] ?? null)) {
            $instance->setPassword($r['root_password']);      // password_seen → false
        }

        $instance->save();

        $this->log($service, 'نصبِ دوبارهٔ سیستم‌عامل: '.$data['image']);

        return back()->with('ok', 'نصبِ دوباره آغاز شد. چند دقیقه بعد سرور با سیستم‌عاملِ تازه بالا می‌آید.');
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
            return back()->withErrors('این عملیات برای این سرور در دسترس نیست.');
        }

        $r = $driver->resetPassword((string) $instance->provider_ref);

        if (! ($r['ok'] ?? false) || blank($r['root_password'] ?? null)) {
            return back()->withErrors('رمزِ تازه ساخته نشد: '.$this->safeMessage((string) ($r['message'] ?: '—'), $instance));
        }

        $instance->setPassword($r['root_password']);
        $instance->save();

        // رمز را در سرویس هم تازه می‌کنیم تا صفحهٔ فهرستِ سرویس‌ها همان را بگوید
        $service->forceFill(['password' => $r['root_password']])->save();

        $this->log($service, 'رمزِ root تازه شد.');

        return back()->with('ok', 'رمزِ تازه ساخته شد و پایینِ صفحه نمایش داده می‌شود.');
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
            return back()->withErrors('کنسول برای این سرور در دسترس نیست.');
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

        $this->log($service, 'کنسولِ تحتِ وب باز شد.');

        return redirect()->route('account.cloud.console.view', [$service, 't' => $ticket]);
    }

    /** صفحهٔ کنسول — روی دامنهٔ خودمان، با noVNC خودمیزبان (CSP اجازهٔ CDN نمی‌دهد) */
    public function consoleView(Request $request, Service $service): View|RedirectResponse
    {
        $this->ownedService($service);

        $ticket = (string) $request->query('t', '');

        if ($ticket === '' || ! \Illuminate\Support\Facades\Cache::has($this->ticketKey($service, $ticket))) {
            return redirect()->route('account.cloud.show', $service)
                ->withErrors('نشستِ کنسول منقضی شده است. دوباره «کنسولِ تحتِ وب» را بزنید.');
        }

        return view('account.cloud-console', AccountController::shell('services') + [
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
            return back()->withErrors('سرور هنوز آماده نیست.');
        }

        $r = $fn($driver, (string) $instance->provider_ref);

        if (! ($r['ok'] ?? false)) {
            $instance->update(['last_error' => mb_substr((string) $r['message'], 0, 500)]);

            return back()->withErrors('انجام نشد: '.$this->safeMessage((string) $r['message'], $instance));
        }

        $instance->update(['last_error' => null, 'synced_at' => now()]);
        $this->log($service, $logAction);

        return back()->with('ok', $okMessage);
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

            return back()->withErrors('درخواست‌های زیاد. '.fa_num($seconds).' ثانیه دیگر تلاش کنید.');
        }

        RateLimiter::hit($key, 60);

        return null;
    }

    private function log(Service $service, string $text): void
    {
        try {
            ActivityLog::record($service->customer_id, 'service',
                'سرورِ ابری #'.$service->id.' — '.$text, null, 'customer');
        } catch (\Throwable) {
            // لاگ نباید عملیات را بشکند
        }
    }
}

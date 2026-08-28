<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\CloudInstance;
use App\Models\Customer;
use App\Models\Service;
use App\Models\TunnelAgent;
use App\Models\TunnelJob;
use App\Support\TunnelProfile;
use App\Support\WireGuardKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * APIِ «اکانت‌های تونل» — همان کاری که مشتری در پنل با دکمه می‌کند، با توکن.
 *
 * چرا لازم شد: مشتریِ سرورِ اکسیت می‌خواهد ساختِ اکانتِ WireGuard-روی-TCP را
 * از سامانهٔ خودش خودکار کند (پنلِ فروشِ خودش، ربات، اسکریپت). تا امروز تنها
 * راهش باز کردن پنل و کلیک بود.
 *
 * ═══ منطق در یک جا می‌مانَد ═══
 *
 * این کنترلر **هیچ منطقِ تازه‌ای ندارد**: همان `TunnelProfile` و همان
 * `WireGuardKey`ی را صدا می‌زند که `Account\CloudServerController` صدا می‌زند.
 * اگر روزی قاعده‌ای عوض شود (سقفِ اکانت، الگوی نام، رنجِ آدرس)، هر دو در
 * همان لحظه عوض می‌شوند. کپیِ منطق این‌جا یعنی روزی پنل و API دو حرفِ متفاوت
 * بزنند و هیچ خطایی هم تولید نشود.
 *
 * ═══ 🔴 «۲۰۱ Created» یعنی چه — و چرا این سؤال مهم است ═══
 *
 * روترِ مشتری از سمتِ ما قابلِ دسترسی نیست (`/ip service ssh` روی CHR
 * `available-from` دارد و APIاش خاموش است). پس دو حالت وجود دارد و پاسخ
 * صریح می‌گوید کدام است:
 *
 *  • `delivery.mode = "agent"`  — ایجنتِ روتر نصب است. کار در صف نشست و روتر
 *    ظرفِ ثانیه‌ها خودش peer را می‌سازد. `delivery.status` می‌گوید کجای کار است.
 *  • `delivery.mode = "manual"` — ایجنتی نصب نیست. مثلِ گذشته `router_command`
 *    برمی‌گردد و اجرایش کارِ خودِ مشتری است.
 *
 * ⚠️ در **هر دو** حالت `router_command` برمی‌گردد. حذفش وسوسه‌کننده بود ولی
 * غلط است: ایجنت ممکن است خاموش باشد و آن خط تنها راهِ نجاتِ مشتری در آن
 * لحظه است. یک راهِ دستیِ همیشه‌حاضر، هزینه‌اش چند بایت است.
 *
 * ⚠️ و «۲۰۱» هرگز به‌تنهایی یعنی «کاربر وصل می‌شود». تا ایجنت گزارش ندهد،
 * `delivery.status` برابرِ `pending` است. همان درسِ `provision_status='done'`:
 * برچسبِ داخلی نباید جای واقعیتِ مشتری بنشیند.
 *
 * ═══ 🔴 کلیدِ خصوصی فقط یک بار ═══
 *
 * `private_key` هرگز ذخیره نمی‌شود؛ فقط در همین یک پاسخ هست. تماس‌گیرنده اگر
 * ذخیره‌اش نکند، راهی برای بازیابی نیست و باید اکانت را حذف و دوباره بسازد.
 * (همان قاعدهٔ `password_seen`ِ سرورِ ابری، به همان دلیل.)
 */
class TunnelApiController extends Controller
{
    private function customer(Request $request): Customer
    {
        return $request->attributes->get('api_customer');
    }

    /**
     * سرویسِ متعلق به همین توکن.
     *
     * 🔴 عمداً از route-model-binding استفاده نمی‌شود: آن سرویس را با شناسه
     * پیدا می‌کند و بعد باید مالکیت را جدا سنجید. یک فراموشیِ ساده یعنی هر
     * توکنی سرورِ هر مشتریِ دیگری را می‌بیند. این‌جا دامنهٔ مالکیت **در خودِ
     * پرس‌وجو** است، پس فراموش‌شدنی نیست.
     */
    private function serviceOf(Request $request, int|string $id): ?Service
    {
        return Service::where('customer_id', $this->customer($request)->id)
            ->whereKey((int) $id)
            ->first();
    }

    private function profileOf(?Service $service): ?TunnelProfile
    {
        if ($service === null) {
            return null;
        }

        return TunnelProfile::fromInstance(
            CloudInstance::where('service_id', $service->id)->first()
        );
    }

    private function fail(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['ok' => false, 'error' => $code, 'message' => $message], $status);
    }

    /**
     * سرویسِ تعلیق‌شده نباید اکانتِ تازه بگیرد — همان گیتِ `denyIfNotWritable`
     * پنل. بی‌آن، مشتریِ بدهکار از راهِ API همان کاری را می‌کند که پنل جلویش
     * را گرفته؛ یعنی گیت فقط ظاهراً وجود دارد.
     */
    private function notWritable(Service $service): ?JsonResponse
    {
        if (in_array($service->status, ['suspended', 'cancelled', 'expired', 'terminated', 'pending'], true)) {
            return $this->fail('service_not_active',
                'این سرویس فعال نیست؛ اکانتِ تازه صادر نمی‌شود.', 409);
        }

        return null;
    }

    // ───────────────────────── ایجنتِ روتر ─────────────────────────

    /**
     * وضعیتِ ایجنت — «آیا روتر خودش کارها را انجام می‌دهد؟»
     *
     * ⚠️ `alive` از `installed` جداست و این تفکیک کلِ ارزشِ این مسیر است:
     * ایجنتی که نصب شده ولی سه روز است خبری ازش نیست، از نظرِ مشتری **کار
     * نمی‌کند** — و اگر هر دو را یک بولین نشان دهیم، او تا وقتی اکانتِ تازه‌ای
     * نسازد نمی‌فهمد. پایشگری که فقط «نصب شده» بگوید، توهمِ پایش می‌سازد.
     */
    public function agentStatus(Request $request, string $service): JsonResponse
    {
        $svc = $this->serviceOf($request, $service);
        $tunnel = $this->profileOf($svc);

        if ($svc === null || $tunnel === null) {
            return $this->fail('not_found', 'چنین سرورِ تونل‌داری در حسابِ شما نیست.', 404);
        }

        return response()->json(['ok' => true, 'data' => $this->agentBlock($svc)]);
    }

    /**
     * صدور (یا صدورِ دوبارهٔ) توکنِ ایجنت + دو خطِ نصب.
     *
     * 🔴 صدورِ دوباره توکنِ قبلی را همان لحظه می‌کُشد. این عمدی است و تنها راهِ
     * ابطالی است که مشتری بدونِ ما دارد؛ اگر توکنِ قدیمی زنده می‌ماند، روترِ
     * فروخته‌شده یا بکاپِ کهنه تا ابد به صفِ او دسترسی داشت. پاسخ صریح
     * هشدارش را می‌دهد تا کسی تصادفی روترِ زنده‌اش را قطع نکند.
     */
    public function agentEnroll(Request $request, string $service): JsonResponse
    {
        $svc = $this->serviceOf($request, $service);
        $tunnel = $this->profileOf($svc);

        if ($svc === null || $tunnel === null) {
            return $this->fail('not_found', 'چنین سرورِ تونل‌داری در حسابِ شما نیست.', 404);
        }

        if ($resp = $this->notWritable($svc)) {
            return $resp;
        }

        $existed = TunnelAgent::where('service_id', $svc->id)->exists();

        [, $plain] = TunnelAgent::issueFor((int) $svc->id);

        $this->log($svc, $existed ? 'توکنِ ایجنتِ روتر دوباره صادر شد' : 'ایجنتِ روتر ثبت شد');

        $base = rtrim(url('/agent/tunnel'), '/');

        return response()->json(['ok' => true, 'data' => [
            // 🔴 تنها جایی از تمامِ عمرِ این ایجنت که توکنِ خام دیده می‌شود.
            'token'      => $plain,
            'replaced'   => $existed,
            'install'    => [
                '/tool fetch url="'.$base.'/install" http-header-field="X-Agent-Token: '.$plain.'" dst-path=snet-agent.rsc',
                '/import file-name=snet-agent.rsc',
            ],
            'note' => $existed
                ? 'توکنِ قبلی از همین لحظه باطل است. تا وقتی این دو خط روی روتر اجرا نشود، ایجنتِ فعلی دیگر کار نمی‌کند.'
                : 'این دو خط را در ترمینالِ روتر اجرا کنید. توکن دیگر نمایش داده نمی‌شود.',
        ]], 201);
    }

    // ───────────────────────── خواندن ─────────────────────────

    /**
     * فهرستِ سرورهایی که تونلِ TCP دارند.
     *
     * سروری که پروفایل ندارد اصلاً در این فهرست نمی‌آید — همان رفتارِ پنل که
     * بخش را رندر نمی‌کند. یعنی تماس‌گیرنده لازم نیست بداند کدام سرور «قابل»
     * است؛ فهرست خودش جواب است.
     */
    public function servers(Request $request): JsonResponse
    {
        $rows = [];

        foreach ($this->customer($request)->services()->orderBy('id')->get() as $service) {
            $tunnel = $this->profileOf($service);

            if ($tunnel === null) {
                continue;
            }

            $rows[] = [
                'service_id' => $service->id,
                'name'       => $service->name,
                'status'     => $service->status,
                'writable'   => $this->notWritable($service) === null,
                'host'       => $tunnel->str('host'),
                'port'       => $tunnel->int('port'),
                'subnet'     => $tunnel->str('subnet'),
                'accounts'   => count($tunnel->peers()),
                'max'        => TunnelProfile::MAX_PEERS,
                'next_ip'    => $tunnel->nextIp(),
                'agent'      => $this->agentBlock($service),
            ];
        }

        return response()->json(['ok' => true, 'data' => $rows]);
    }

    /**
     * فهرستِ اکانت‌های یک سرور — نام، آدرس، کلیدِ عمومی، زمانِ صدور، وضعیت.
     *
     * ⚠️ کلیدِ عمومی برگردانده می‌شود چون تماس‌گیرنده برای ساختنِ دستورِ روتر
     * لازمش دارد؛ کلیدِ خصوصی اصلاً وجود ندارد که برگردد.
     */
    public function accounts(Request $request, string $service): JsonResponse
    {
        $svc = $this->serviceOf($request, $service);
        $tunnel = $this->profileOf($svc);

        if ($svc === null || $tunnel === null) {
            return $this->fail('not_found', 'چنین سرورِ تونل‌داری در حسابِ شما نیست.', 404);
        }

        $state = $this->stateMap($svc);

        return response()->json(['ok' => true, 'data' => [
            'service_id' => $svc->id,
            'host'       => $tunnel->str('host'),
            'port'       => $tunnel->int('port'),
            'subnet'     => $tunnel->str('subnet'),
            'next_ip'    => $tunnel->nextIp(),
            'max'        => TunnelProfile::MAX_PEERS,
            'agent'      => $this->agentBlock($svc),
            'accounts'   => array_map(fn (array $p): array => [
                'name'       => $p['name'],
                'ip'         => $p['ip'],
                'public_key' => $p['pub'] ?? null,
                'issued_at'  => $p['at'] ?? null,
                'state'      => $state[strtolower($p['name'])] ?? 'active',
            ], $tunnel->peers()),
        ]]);
    }

    // ───────────────────────── نوشتن ─────────────────────────

    /**
     * ساختِ اکانتِ تازه.
     *
     * ورودی: `name` (اجباری)، `ip` (اختیاری — نبودنش یعنی آدرسِ آزادِ بعدی)،
     * `format` (اختیاری: `singbox` پیش‌فرض یا `legacy`).
     */
    public function issue(Request $request, string $service): JsonResponse
    {
        $svc = $this->serviceOf($request, $service);
        $tunnel = $this->profileOf($svc);

        if ($svc === null || $tunnel === null) {
            return $this->fail('not_found', 'چنین سرورِ تونل‌داری در حسابِ شما نیست.', 404);
        }

        if ($resp = $this->notWritable($svc)) {
            return $resp;
        }

        if (count($tunnel->peers()) >= TunnelProfile::MAX_PEERS) {
            return $this->fail('limit_reached',
                'به سقفِ '.TunnelProfile::MAX_PEERS.' اکانت رسیده‌اید.', 409);
        }

        $name = strtolower(trim((string) $request->input('name')));

        if (! preg_match('~^[a-z0-9][a-z0-9_-]{1,23}$~', $name)) {
            return $this->fail('bad_name',
                'نامِ اکانت باید ۲ تا ۲۴ نویسهٔ لاتین، رقم، خط‌تیره یا زیرخط باشد.', 422);
        }

        if (! $tunnel->nameIsFree($name)) {
            return $this->fail('name_taken', 'اکانتی با این نام از قبل وجود دارد.', 409);
        }

        $ip = trim((string) $request->input('ip')) ?: (string) $tunnel->nextIp();

        if ($ip === '' || ! $tunnel->ipInSubnet($ip)) {
            return $this->fail('bad_ip', 'آدرس باید از رنجِ داخلیِ همین سرور باشد.', 422);
        }

        if (! $tunnel->ipIsFree($ip)) {
            return $this->fail('ip_taken', 'این آدرس قبلاً استفاده شده است.', 409);
        }

        $format = $request->input('format') === 'legacy' ? 'legacy' : 'singbox';
        $keys = WireGuardKey::generate();

        $tunnel->addPeer($name, $ip, $keys['public']);

        $delivery = $this->dispatch($svc, TunnelJob::OP_ADD, $name, $ip, $keys['public']);

        $this->log($svc, 'اکانتِ تونل «'.$name.'» ('.$ip.') از API صادر شد');

        return response()->json(['ok' => true, 'data' => [
            'name'           => $name,
            'ip'             => $ip,
            'public_key'     => $keys['public'],
            // 🔴 تنها جایی از تمامِ عمرِ این اکانت که کلیدِ خصوصی دیده می‌شود.
            'private_key'    => $keys['private'],
            'delivery'       => $delivery,
            // ⚠️ در حالتِ ایجنت هم برمی‌گردد: راهِ نجات وقتی روتر خاموش است.
            'router_command' => $tunnel->routerAddCommand($name, $ip, $keys['public']),
            'config'         => json_decode($tunnel->configJson($ip, $keys['private'], $format), true),
            'note'           => $delivery['mode'] === 'agent'
                ? 'اکانت ثبت شد و در صفِ روتر نشست. ایجنت ظرفِ چند ثانیه peer را می‌سازد؛ تا آن لحظه وضعیت pending است.'
                : 'اکانت در فهرست ثبت شد. تا وقتی router_command روی روترِ خودتان اجرا نشود، این کانفیگ وصل نمی‌شود.',
        ]], 201);
    }

    /**
     * حذفِ اکانت از فهرست.
     *
     * ⚠️ همان هشدارِ پنل: حذف از فهرست به‌تنهایی دسترسی را قطع **نمی‌کند**؛ تا
     * peer روی روتر هست کار می‌کند. با ایجنت این کار خودکار می‌شود، ولی
     * `router_command`ِ حذف همچنان برمی‌گردد چون ایجنت ممکن است خاموش باشد —
     * و «دسترسی را قطع کردم» ادعایی است که نباید به روترِ خاموش وابسته باشد.
     */
    public function remove(Request $request, string $service, string $name): JsonResponse
    {
        $svc = $this->serviceOf($request, $service);
        $tunnel = $this->profileOf($svc);

        if ($svc === null || $tunnel === null) {
            return $this->fail('not_found', 'چنین سرورِ تونل‌داری در حسابِ شما نیست.', 404);
        }

        if ($resp = $this->notWritable($svc)) {
            return $resp;
        }

        $name = strtolower(trim($name));
        $command = $tunnel->routerRemoveCommand($name);

        if (! $tunnel->removePeer($name)) {
            return $this->fail('not_found', 'چنین اکانتی در فهرست نیست.', 404);
        }

        $delivery = $this->dispatch($svc, TunnelJob::OP_REMOVE, $name);

        $this->log($svc, 'اکانتِ تونل «'.$name.'» از API حذف شد');

        return response()->json(['ok' => true, 'data' => [
            'name'           => $name,
            'delivery'       => $delivery,
            'router_command' => $command,
            'note'           => $delivery['mode'] === 'agent'
                ? 'حذف در صفِ روتر نشست. تا اجرای ایجنت، این اکانت هنوز وصل می‌شود.'
                : 'برای قطعِ واقعیِ دسترسی، این دستور را روی روتر اجرا کنید.',
        ]]);
    }

    // ───────────────────────── مشترک ─────────────────────────

    /**
     * کار را در صف می‌گذارد **اگر** ایجنتی هست، و می‌گوید نتیجه چه شد.
     *
     * 🔴 بی‌ایجنت هیچ ردیفی ساخته نمی‌شود. صفی که هیچ‌کس از آن برنمی‌دارد، فقط
     * انبوهی از کارهای «منتظر» می‌سازد که ۲۴ ساعت بعد `failed` می‌شوند — یعنی
     * پنل به مشتریِ بی‌ایجنت خبرِ خرابی می‌داد برای کاری که او از اول قرار بود
     * دستی انجام دهد.
     *
     * @return array<string,mixed>
     */
    private function dispatch(Service $service, string $op, string $name, ?string $ip = null, ?string $pub = null): array
    {
        $agent = TunnelAgent::where('service_id', $service->id)->first();

        if ($agent === null) {
            return ['mode' => 'manual', 'status' => 'manual'];
        }

        $job = TunnelJob::enqueue((int) $service->id, $op, $name, $ip, $pub);

        return [
            'mode'           => 'agent',
            'status'         => 'pending',
            'job_id'         => $job->id,
            'agent_alive'    => $agent->isAlive(),
            'agent_seen_at'  => $agent->last_seen_at?->toIso8601String(),
        ];
    }

    /**
     * وضعیتِ ایجنتِ یک سرویس، به همان شکلی که همهٔ مسیرها نشانش می‌دهند.
     *
     * @return array<string,mixed>
     */
    private function agentBlock(Service $service): array
    {
        $agent = TunnelAgent::where('service_id', $service->id)->first();

        if ($agent === null) {
            return ['installed' => false, 'alive' => false, 'last_seen_at' => null, 'pending_jobs' => 0];
        }

        return [
            'installed'    => true,
            'alive'        => $agent->isAlive(),
            'last_seen_at' => $agent->last_seen_at?->toIso8601String(),
            'pending_jobs' => TunnelJob::query()->forService((int) $service->id)->pending()->count(),
        ];
    }

    /**
     * نام → وضعیتِ تحویل، برای اکانت‌هایی که هنوز کارِ بازی دارند.
     *
     * ⚠️ اکانتی که هیچ کارِ بازی ندارد `active` است، نه «نامعلوم». اکانت‌های
     * پیش از ساخته‌شدنِ این صف هیچ ردیفی ندارند و اگر «نامعلوم» نشانشان
     * می‌دادیم، مشتری فکر می‌کرد چیزی خراب شده — در حالی که همه‌شان سالم روی
     * روتر نشسته‌اند.
     *
     * @return array<string,string>
     */
    private function stateMap(Service $service): array
    {
        $rows = TunnelJob::query()
            ->forService((int) $service->id)
            ->where('status', '!=', 'done')
            ->orderBy('id')
            ->get(['name', 'status']);

        $out = [];

        foreach ($rows as $row) {
            $out[strtolower((string) $row->name)] = $row->status === 'pending' ? 'pending' : 'failed';
        }

        return $out;
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

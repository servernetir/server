<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\CloudInstance;
use App\Models\Service;
use App\Models\TunnelAgent;
use App\Models\TunnelJob;
use App\Support\TunnelAgentScript;
use App\Support\TunnelProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * مسیرهای «کششیِ» ایجنتِ روترِ مشتری.
 *
 * ═══ پروتکل ═══
 *
 * پاسخ **متنِ ساده** است، نه JSON — و این یک انتخابِ اجباری است نه سلیقه:
 * RouterOS پارسرِ JSON ندارد و تنها راهِ عملیِ خواندنِ JSON در اسکریپتِ روتر
 * `[:parse …]` است، یعنی اجرای متنی که سرور فرستاده. آن یک خط، کنترلِ کاملِ
 * روتر را به هر کسی می‌دهد که بتواند پاسخِ ما را جعل کند.
 *
 *   SNET|1|<تعداد>
 *   ADD|<شناسه>|<نام>|<آدرس>|<کلیدِ عمومی>
 *   DEL|<شناسه>|<نام>
 *
 * خطِ اول امضای پروتکل است و اسکریپت هر پاسخی را که با آن شروع نشود دور
 * می‌ریزد — صفحهٔ خطای Cloudflare و صفحهٔ نگه‌داری هم با کدِ ۲۰۰ می‌آیند و
 * بی‌این امضا، خطوطشان «کار» خوانده می‌شدند.
 *
 * ═══ 🔴 مالکیت از توکن می‌آید، نه از پارامتر ═══
 *
 * هیچ‌کدام از این مسیرها شناسهٔ سرویس نمی‌گیرند. اگر می‌گرفتند، اولین
 * فراموشیِ تطبیق یعنی روترِ یک مشتری کارهای مشتریِ دیگر را بردارد — و آن peer
 * روی روترِ اشتباه می‌نشیند، یعنی دسترسیِ یک غریبه به شبکهٔ داخلیِ کسِ دیگری.
 * `TunnelAgent::findByPlain()` سرویس را از خودِ توکن درمی‌آورد.
 */
class TunnelAgentController extends Controller
{
    /** سقفِ کارهای هر پیمایش — پاسخ کوتاه بمانَد و روتر در یک نوبت غرق نشود. */
    private const BATCH = 25;

    /**
     * صفِ کارهای این روتر.
     *
     * ⚠️ عمداً `no-store`: پاسخ شاملِ کلیدِ عمومی و آدرسِ داخلیِ مشتری است و
     * هیچ واسطه‌ای — نه Cloudflare، نه پروکسیِ مسیر — نباید نگهش دارد.
     */
    public function pending(Request $request): Response
    {
        $agent = $this->agent($request);
        $agent->seen($request->ip());

        $serviceId = (int) $agent->service_id;

        // کارهای کهنه همین‌جا بسته می‌شوند، نه در یک کرونِ جدا: این مسیر تنها
        // جایی است که مرتب اجرا می‌شود و از قبل صاحبِ همین ردیف‌هاست.
        TunnelJob::expireStale($serviceId);

        $jobs = TunnelJob::query()
            ->forService($serviceId)
            ->pending()
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get();

        $lines = ['SNET|1|'.$jobs->count()];

        foreach ($jobs as $job) {
            $lines[] = $job->op === TunnelJob::OP_REMOVE
                ? 'DEL|'.$job->id.'|'.$job->name
                : 'ADD|'.$job->id.'|'.$job->name.'|'.$job->ip.'|'.$job->public_key;
        }

        /*
        | شمارندهٔ **تحویل** بالا می‌رود، نه شمارندهٔ شکست. کاری که روتر
        | برمی‌دارد ولی نمی‌تواند اجرا کند، در `fail`ِ ack خودش را نشان می‌دهد؛
        | این عدد فقط می‌گوید «چند بار به دستش رسید» — و همان است که «روتر
        | خاموش است» را از «روتر می‌گیرد و هر بار می‌افتد» تفکیک می‌کند.
        */
        if ($jobs->isNotEmpty()) {
            TunnelJob::whereIn('id', $jobs->pluck('id'))
                ->update(['attempts' => DB::raw('attempts + 1'), 'delivered_at' => now()]);
        }

        return $this->text(implode("\n", $lines)."\n");
    }

    /**
     * گزارشِ نتیجه: `ok=۱,۲` و `fail=۳`.
     *
     * 🔴 هر دو فهرست با `service_id` محدود می‌شوند. بی‌آن، یک توکنِ معتبر
     * می‌توانست کارهای **هر** سرویسِ دیگری را «انجام‌شده» علامت بزند — یعنی
     * اکانتی که هرگز روی روتر ننشسته در پنلِ مشتریِ دیگر «فعال» دیده شود.
     */
    public function ack(Request $request): Response
    {
        $agent = $this->agent($request);
        $agent->seen($request->ip());

        $serviceId = (int) $agent->service_id;

        $ok = $this->ids($request->input('ok'));
        $bad = $this->ids($request->input('fail'));

        $done = 0;
        $failed = 0;

        if ($ok !== []) {
            $done = TunnelJob::query()->forService($serviceId)->pending()
                ->whereIn('id', $ok)
                ->update(['status' => 'done', 'done_at' => now(), 'updated_at' => now()]);
        }

        if ($bad !== []) {
            $failed = TunnelJob::query()->forService($serviceId)->pending()
                ->whereIn('id', $bad)
                ->update(['status' => 'failed', 'last_error' => 'router_rejected', 'updated_at' => now()]);
        }

        return $this->text('SNET|1|OK|'.$done.'|'.$failed."\n");
    }

    /**
     * فایلِ `.rsc`ی که روتر با `/import` اجرا می‌کند.
     *
     * 🔴 توکن از **هدرِ همین درخواست** برداشته و در اسکریپت کار گذاشته می‌شود؛
     * ما نسخهٔ خامش را نگه نداشته‌ایم (فقط هش). یعنی کسی که این فایل را
     * می‌گیرد از قبل توکن را داشته و هیچ رازِ تازه‌ای این‌جا لو نمی‌رود.
     *
     * ⚠️ و توکن در **مسیرِ URL** نیست: قاعدهٔ ثبت‌شدهٔ پروژه است که مسیر در
     * لاگِ سرور و Cloudflare و تاریخچهٔ مرورگر می‌نشیند. یک بار کلیدِ API
     * دقیقاً همین‌طور لو رفت.
     */
    public function install(Request $request): Response
    {
        $agent = $this->agent($request);

        $tunnel = $this->profileOf((int) $agent->service_id);

        if ($tunnel === null) {
            return $this->text("# this service has no TCP tunnel profile\n", 409);
        }

        $base = $tunnel->subnetBase();

        if ($base === null) {
            return $this->text("# tunnel profile has no usable /24 subnet\n", 409);
        }

        $script = TunnelAgentScript::build(
            (string) $request->header('X-Agent-Token'),
            rtrim(url('/agent/tunnel'), '/'),
            $tunnel->str('iface'),
            $base.'.',
        );

        return $this->text($script);
    }

    // ───────────────────────── کمکی ─────────────────────────

    /**
     * ایجنتِ معتبر، وگرنه ۴۰۳.
     *
     * ⚠️ سرویسِ تعلیق‌شده هم رد می‌شود. بی‌آن، مشتریِ بدهکار همچنان اکانتِ تازه
     * روی روترش می‌نشاند — یعنی گیتی که API دارد از راهِ ایجنت دور زده می‌شود
     * و فقط ظاهراً وجود دارد.
     */
    private function agent(Request $request): TunnelAgent
    {
        $agent = TunnelAgent::findByPlain($request->header('X-Agent-Token'));

        if ($agent === null) {
            abort(403);
        }

        $service = Service::find($agent->service_id);

        if ($service === null
            || in_array($service->status, ['cancelled', 'expired', 'terminated'], true)) {
            abort(403);
        }

        return $agent;
    }

    private function profileOf(int $serviceId): ?TunnelProfile
    {
        return TunnelProfile::fromInstance(
            CloudInstance::where('service_id', $serviceId)->first()
        );
    }

    /**
     * «۱,۲,۳» → [1,2,3].
     *
     * ⚠️ سقفِ ۵۰۰ عمدی است: بدنهٔ ورودی از روترِ مشتری می‌آید و یک فهرستِ
     * ده‌هزارتایی یعنی یک `whereIn` که پرس‌وجوی دیتابیس را می‌خواباند.
     *
     * @return list<int>
     */
    private function ids(mixed $raw): array
    {
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $out = [];

        foreach (explode(',', $raw) as $part) {
            $part = trim($part);

            if ($part !== '' && ctype_digit($part)) {
                $out[] = (int) $part;
            }

            if (count($out) >= 500) {
                break;
            }
        }

        return array_values(array_unique($out));
    }

    private function text(string $body, int $status = 200): Response
    {
        return response($body, $status)
            ->header('Content-Type', 'text/plain; charset=utf-8')
            ->header('Cache-Control', 'no-store');
    }
}

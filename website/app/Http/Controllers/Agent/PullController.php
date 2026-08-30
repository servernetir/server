<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\CloudInstance;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * مسیرهای «کششیِ» موتورِ هاستِ ایران (pull-agent).
 *
 * ═══ چه می‌کند ═══
 *
 * سرورِ اصلی در آلمان است و نمی‌تواند به میزبانِ ایران push کند. پس موتورِ ایران
 * **می‌پرسد**: هر چند دقیقه این دو مسیر را می‌خوانَد تا «حالتِ مطلوب» را یاد
 * بگیرد و مسیریابیِ خروجِ کشوری و port-forwardهای ورودی را تنظیم کند.
 *
 *  • `countryroutes` → برای هر Exit VPS: `{ip, cc}` تا خروجِ آن ماشین از همان
 *    کشور برود.
 *  • `portforwards`  → برای هر سرور: یک پورتِ عمومیِ **پایدار** که به SSH/RDPِ
 *    داخلی نگاشت می‌شود، تا از بیرون در دسترس باشد.
 *
 * ═══ امنیت ═══
 *
 * هدرِ `X-Agent-Token` با `Setting::getSecret('agent_pull_token')` مقایسه می‌شود
 * (مثلِ الگوی BaleWebhookController). توکنِ خالی یا ناهم‌خوان → ۴۰۳. مسیرها
 * بی‌نشست و فقط‌خواندنی‌اند و برای پیمایشِ کرونیِ ایجنت طراحی شده‌اند.
 */
class PullController extends Controller
{
    /** پورتِ داخلیِ مقصد بر اساسِ سیستم‌عامل: ویندوز RDP، بقیه SSH */
    private const RDP_PORT = 3389;

    private const SSH_PORT = 22;

    /**
     * مسیرِ کشوریِ خروج برای هر Exit VPSِ زنده.
     *
     * فقط نمونه‌های Proxmox که مکانشان `exit-<cc>` است، بالا/در حالِ ساخت‌اند و
     * IP دارند. `cc` از خودِ کدِ مکان (`exit-de` → `de`) درمی‌آید.
     */
    public function countryRoutes(Request $request): JsonResponse
    {
        $this->authorizeAgent($request);

        // ضربانِ ایجنت: آخرین باری که ایجنتِ ایران این مسیر را خواند — صفحهٔ
        // «زیرساختِ اکسیت» از رویش می‌فهمد ایجنت زنده است یا خوابیده.
        Setting::put('agent_seen_countryroutes', now()->toIso8601String());

        // نامزدها: هم اکسیت‌های کاتالوگی (`location_code = exit-*`)، هم هر ماشینی
        // که override دستیِ `meta['exit_country']` خورده باشد (سوییچِ کشور از پنل).
        // کدِ کشور را در PHP با exitCountryCode() حساب می‌کنیم تا override بر
        // location_code مقدم باشد و مقدارِ «ir/none» یعنی «مسیرِ کشوری نده».
        $rows = CloudInstance::query()
            ->where('provider', 'proxmox')
            ->whereIn('status', ['building', 'running'])
            ->whereNotNull('ipv4')
            ->where('ipv4', '!=', '')
            ->where(function ($q) {
                $q->where('location_code', 'like', 'exit-%')
                    ->orWhereNotNull('meta->exit_country');
            })
            ->get();

        $out = [];

        foreach ($rows as $inst) {
            $cc = $inst->exitCountryCode();     // override بر location_code مقدم

            if ($cc === null) {
                continue;                        // خروجِ عادیِ ایران — مسیرِ کشوری لازم نیست
            }

            $out[] = ['ip' => (string) $inst->ipv4, 'cc' => $cc];
        }

        return response()->json($out);
    }

    /**
     * port-forwardهای ورودی برای هر سرورِ Proxmoxِ زنده.
     *
     * هر سرور یک پورتِ عمومیِ **پایدار** می‌گیرد: اگر در `meta['public_port']`
     * باشد همان می‌مانَد، وگرنه پایین‌ترین پورتِ آزادِ محدوده تخصیص و در `meta`
     * ذخیره می‌شود — پس پیمایشِ بعدی همان پورت را می‌بیند. `dest_port` بر اساسِ
     * سیستم‌عامل است (ویندوز ۳۳۸۹، بقیه ۲۲).
     *
     * تخصیص برای یک پیمایشگرِ کرونی «به‌قدرِ کافی» ایمن است: پورتهای مصرف‌شده را
     * یک‌جا می‌خوانیم و پایین‌ترین آزاد را برمی‌داریم.
     */
    public function portForwards(Request $request): JsonResponse
    {
        $this->authorizeAgent($request);

        // ضربانِ ایجنت (مسیرِ port-forward) — دوقلوی countryRoutes برای پایشِ زنده‌بودن.
        Setting::put('agent_seen_portforwards', now()->toIso8601String());

        $portMin = (int) config('servernet.exit.sale_port_min', 20000);
        $portMax = (int) config('servernet.exit.sale_port_max', 20999);
        $publicIp = (string) (Setting::get('public_ip') ?: config('servernet.exit.public_ip', ''));

        // پورتهای مصرف‌شده روی **هر** نمونه (نه فقط زنده‌ها) تا تکراری ندهیم
        $used = [];

        foreach (CloudInstance::query()->whereNotNull('meta')->get(['meta']) as $row) {
            $p = (int) ($row->meta['public_port'] ?? 0);

            if ($p > 0) {
                $used[$p] = true;
            }
        }

        $instances = CloudInstance::query()
            ->where('provider', 'proxmox')
            ->whereIn('status', ['building', 'running'])
            ->whereNotNull('ipv4')
            ->where('ipv4', '!=', '')
            ->get();

        $out = [];

        foreach ($instances as $inst) {
            $port = (int) ($inst->meta['public_port'] ?? 0);

            if ($port <= 0) {
                $port = $this->lowestFreePort($used, $portMin, $portMax);

                if ($port === null) {
                    continue;               // محدوده پر است؛ پیمایشگر را نمی‌شکنیم
                }

                $inst->meta = array_merge($inst->meta ?? [], ['public_port' => $port]);
                $inst->save();
            }

            $used[$port] = true;

            $out[] = [
                'ip'          => (string) $inst->ipv4,
                'dest_port'   => str_contains((string) $inst->image_key, 'windows') ? self::RDP_PORT : self::SSH_PORT,
                'public_port' => $port,
                'public_ip'   => $publicIp,
            ];
        }

        return response()->json($out);
    }

    /**
     * پایین‌ترین پورتِ آزادِ محدوده، یا null اگر همه پر باشند.
     *
     * @param  array<int,bool>  $used
     */
    private function lowestFreePort(array $used, int $min, int $max): ?int
    {
        for ($p = $min; $p <= $max; $p++) {
            if (! isset($used[$p])) {
                return $p;
            }
        }

        return null;
    }

    /**
     * احرازِ توکنِ ایجنت — مثلِ الگوی BaleWebhookController با `hash_equals`.
     * توکنِ خالی (تنظیم‌نشده) یا ناهم‌خوان → ۴۰۳.
     */
    private function authorizeAgent(Request $request): void
    {
        $expected = (string) (Setting::getSecret('agent_pull_token') ?? '');
        // هر دو هدر پذیرفته می‌شود: X-Agent-Token (نو) یا X-PF-Token (عامل‌های
        // موجودِ هاستِ ایران) — تا repoint فقط تغییرِ URL/توکن باشد، نه بازنویسیِ
        // اسکریپتِ هاست. توکنِ خالی/ناهم‌خوان → ۴۰۳ (fail-closed).
        $provided = (string) ($request->header('X-Agent-Token') ?: $request->header('X-PF-Token') ?: '');

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            abort(403);
        }
    }
}

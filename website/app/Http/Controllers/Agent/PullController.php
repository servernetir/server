<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\CloudInstance;
use App\Models\ExitUpstream;
use App\Models\Setting;
use App\Services\Cloud\PublicPortAllocator;
use App\Support\GuestPolicySnapshot;
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
     * «حالتِ مطلوبِ» آپ‌استریم‌ها برای میزبانِ ایران — رله‌ها و اکسیت‌های کشوری.
     *
     * 🔴 این تنها مسیری است که مقدارِ **خامِ** اعتبارنامه را بیرون می‌دهد، چون
     * میزبان برای dial واقعاً لازمش دارد. پس: فقط GET، فقط با توکن، و
     * `Cache-Control: no-store` تا هیچ واسطه‌ای کشش نکند.
     *
     * شکل:
     *   { "relays": [ {...} ], "exits": { "de": [ {...} ] } }
     *
     * `id` در هر ردیف همان چیزی است که `countryroutes` با `via: "u<id>"` به آن
     * اشاره می‌کند — پس هاست می‌تواند یک ماشین را به یک آپ‌استریمِ **مشخص**
     * سنجاق کند، نه فقط به «کشور».
     *
     * ⚠️ `exits` عمداً آبجکت است نه آرایه: بی‌کشورِ خروج، `json_encode` یک
     * آرایهٔ خالیِ `[]` می‌داد و پارسرِ سمتِ هاست که dict انتظار دارد می‌ترکید.
     */
    public function exitUpstreams(Request $request): JsonResponse
    {
        $this->authorizeAgent($request);

        Setting::put('agent_seen_exitupstreams', now()->toIso8601String());

        $rows = ExitUpstream::query()
            ->enabled()
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        $relays = [];
        $exits = [];

        foreach ($rows as $u) {
            if ($u->isRelay()) {
                $relays[] = $u->toAgentArray();

                continue;
            }

            $cc = $u->cc();

            if ($cc === null || $cc === '') {
                continue;               // اکسیتِ بی‌کشور معنی ندارد؛ رد شود
            }

            $exits[$cc][] = $u->toAgentArray();
        }

        return response()
            ->json(['relays' => $relays, 'exits' => (object) $exits])
            ->header('Cache-Control', 'no-store');
    }

    /**
     * port-forwardهای ورودی — **فقط خواندنی**.
     *
     * 🔴 پیش از این، همین متد پورت را همین‌جا تخصیص می‌داد و
     * `$inst->save()` می‌زد: یک GET از عامل، دیتابیس را عوض می‌کرد. حالا
     * تخصیص کارِ `PublicPortAllocator` است و فقط از مسیرهای نوشتنی صدا زده
     * می‌شود (ثبت، اتصال به مشتری، فرمان یا دکمهٔ مدیر).
     *
     * ⚠️ پیامدِ عمدی: ماشینی که هنوز پورت نگرفته در این خروجی **نیست**. برای
     * اینکه این سکوت دیده شود، `missing()` در صفحهٔ «زیرساختِ اکسیت» و در
     * فرمانِ `exit:ports-sync` گزارش می‌شود. سکوتِ دیده‌نشده بدترین حالت است.
     */
    public function portForwards(Request $request, PublicPortAllocator $ports): JsonResponse
    {
        $this->authorizeAgent($request);

        // ضربانِ ایجنت (مسیرِ port-forward) — دوقلوی countryRoutes برای پایشِ زنده‌بودن.
        Setting::put('agent_seen_portforwards', now()->toIso8601String());

        $publicIp = $ports->publicIp();
        $out = [];

        foreach ($ports->eligible() as $inst) {
            $port = $inst->publicPort();

            if ($port <= 0) {
                continue;                   // تخصیص‌نیافته — این‌جا ساخته نمی‌شود
            }

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
     * سیاستِ شبکهٔ داخلی برای هر مهمان — «این ماشین اجازهٔ دیدنِ شبکهٔ داخلی
     * را دارد یا نه» — به‌همراهِ **نسخه**.
     *
     * 🔴 چرا مسیرِ جداست و به `countryroutes` اضافه نشد: آن مسیر فقط ماشین‌هایی
     * را دارد که کشورِ خروج دارند. اگر برای این سیاست بازترش می‌کردیم، عاملِ
     * موجود ردیف‌هایی با `cc` تهی می‌دید که هرگز انتظارشان را نداشت — همان
     * «تغییرِ شکل» که پروژه یک‌بار با `via` عمداً از آن پرهیز کرد.
     *
     * 🔴 و چرا پاسخ `schema` دارد: صفحهٔ خطای Cloudflare و صفحهٔ نگه‌داری با
     * کدِ ۲۰۰ می‌آیند. بی‌یک نشانهٔ صریح، عامل آن HTML را «پاسخِ معتبرِ خالی»
     * می‌خواند و همهٔ قواعد را پاک می‌کند.
     */
    public function guestPolicy(Request $request, GuestPolicySnapshot $snap): JsonResponse
    {
        $this->authorizeAgent($request);

        Setting::put('agent_seen_guestpolicy', now()->toIso8601String());

        return response()->json($snap->payload())->header('Cache-Control', 'no-store');
    }

    /**
     * تأییدِ اعمال — عامل بعد از اجرا می‌گوید «نسخهٔ X را اعمال کردم».
     *
     * 🔴 چرا لازم است: ضربان فقط می‌گوید عامل زنده است، نه اینکه کارش را کرده.
     * تا امروز هیچ‌جای این سامانه این دو را از هم جدا نمی‌کرد — و پنل «فرستاده
     * شد» را به مدیر مثلِ «اعمال شد» نشان می‌داد.
     *
     * ⚠️ نسخهٔ ناشناخته پذیرفته می‌شود ولی ثبت هم می‌شود: اگر عامل نسخه‌ای عقب
     * تأیید کند، صفحه «در انتظار» می‌مانَد — که درست است، نه خطا.
     */
    public function guestPolicyAck(Request $request): JsonResponse
    {
        $this->authorizeAgent($request);

        $data = $request->validate([
            'revision' => ['required', 'string', 'max:64'],
            'ok'       => ['required', 'boolean'],
            'error'    => ['nullable', 'string', 'max:500'],
        ]);

        Setting::put(GuestPolicySnapshot::ACK_AT, now()->toIso8601String());

        if ($data['ok']) {
            Setting::put(GuestPolicySnapshot::ACK_REVISION, $data['revision']);
            Setting::put(GuestPolicySnapshot::ACK_ERROR, '');
        } else {
            // ⚠️ نسخهٔ تأییدشده را روی شکست **جلو نمی‌بریم**؛ وگرنه یک اعمالِ
            // ناموفق در پنل «اعمال شد» دیده می‌شود.
            Setting::put(GuestPolicySnapshot::ACK_ERROR, (string) ($data['error'] ?: 'اعمال ناموفق بود'));
        }

        return response()->json(['ok' => true]);
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

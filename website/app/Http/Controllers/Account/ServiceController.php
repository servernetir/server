<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\CreditEntry;
use App\Models\Service;
use App\Services\Provisioning\WhmClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * سرویس‌های مشتری — سمت خودِ مشتری (پنل کاربری).
 * فقط سرویس‌های خودش را می‌بیند.
 */
class ServiceController extends Controller
{
    // Deletion consent is local to this customer session and this service.
    // No SMS/email destination or OTP delivery is needed.
    public function terminateStart(Request $request, Service $service): RedirectResponse
    {
        $this->ownedOr404($service);

        if (! $this->terminable($service)) {
            return back()->withErrors(__('ui.svf_state'));
        }

        $request->session()->put('svc_terminate_ctx', [
            'service_id' => (int) $service->id,
            'phrase' => __('ui.svc_terminate_phrase'),
            'token' => bin2hex(random_bytes(32)),
            'expires_at' => now()->addMinutes(15)->timestamp,
        ]);

        return back()->with('svc_terminate_open', (int) $service->id);
    }

    public function terminate(Request $request, Service $service): RedirectResponse
    {
        $this->ownedOr404($service);
        $ctx = $request->session()->get('svc_terminate_ctx');

        // Reject legacy OTP contexts, expired dialogs and confirmation of a different service.
        if (! is_array($ctx)
            || (int) ($ctx['service_id'] ?? 0) !== (int) $service->id
            || ! is_string($ctx['phrase'] ?? null)
            || ! is_string($ctx['token'] ?? null)
            || (int) ($ctx['expires_at'] ?? 0) <= now()->timestamp) {
            return back()->withErrors(__('ui.svc_terminate_restart'));
        }

        if (! $this->terminable($service)) {
            $request->session()->forget('svc_terminate_ctx');

            return back()->withErrors(__('ui.svf_state'));
        }

        $data = $request->validate([
            'confirmation_phrase' => ['required', 'string', 'max:400', Rule::in([$ctx['phrase']])],
            'confirmation_token' => ['required', 'string', Rule::in([$ctx['token']])],
            'reason' => ['nullable', 'string', Rule::in(Service::terminateReasonCodes())],
            'reason_note' => ['nullable', 'string', 'max:500'],
        ], [
            'confirmation_phrase.required' => __('ui.svc_terminate_phrase_error'),
            'confirmation_phrase.in' => __('ui.svc_terminate_phrase_error'),
            'confirmation_token.*' => __('ui.svc_terminate_restart'),
        ], [
            'confirmation_phrase' => __('ui.svc_terminate_phrase_label'),
            'reason' => __('panel.field_reason'),
            'reason_note' => __('panel.field_note'),
        ]);

        $request->session()->forget('svc_terminate_ctx');

        /*
        | ⚠️ **پیش از نوشتن، وجودِ ستون را بپرس.**
        |
        | مهاجرت را کارفرما دستی اجرا می‌کند (`/system/migrate`)، پس بینِ دپلویِ
        | کد و اجرای مهاجرت پنجره‌ای هست که این ستون‌ها نیستند. یک خطای SQL
        | این‌جا یعنی سرویسی که هنوز «فعال» است و مشتری فاکتورِ تمدیدش را می‌گیرد.
        | یک ستونِ آمارِ بازاریابی هرگز نباید چنین ریسکی بسازد.
        */
        $reasonCols = Schema::hasColumn('services', 'terminate_reason')
            ? [
                'terminate_reason'      => $data['reason'] ?? null,
                'terminate_reason_note' => filled($data['reason_note'] ?? null)
                    ? mb_substr(trim((string) $data['reason_note']), 0, 500)
                    : null,
            ]
            : [];

        /*
        | 🔴 گامِ ۱ — **صورت‌حساب پیش از زیرساخت.** (کارفرما: «اگر سرور رو پاک کرد
        | دیگر نیازی نیست پولشو بدهد.»)
        |
        | تا مرداد ۱۴۰۵ ترتیب برعکس بود: اول حذف نزدِ زیرساخت، و اگر شکست می‌خورد
        | `back()->withErrors()` و سرویس `active` می‌مانْد. یعنی مشتری‌ای که کدِ
        | یک‌بارمصرفش را سوزانده و گفته «پاکش کن»، همان ساعت دوباره از کیفِ پولش
        | کسر می‌شد — بابتِ خرابیِ **ما**. حالا `status` مرده می‌شود و همان یک
        | نوشتن، مترِ ساعتی و هر درِ تحویلِ دوباره را با هم می‌بندد.
        |
        | ⚠️ ساعتِ جاری که پیش‌تر پرداخت شده برنمی‌گردد — خُرد نمی‌کنیم، نه در این
        | جهت و نه در آن. متنِ تأیید همین را می‌گوید.
        */
        $transitioned = DB::transaction(function () use ($service, $reasonCols): bool {
            $fresh = Service::whereKey($service->id)->lockForUpdate()->first();

            if ($fresh === null || ! $this->terminable($fresh)) {
                return false; // Another request already closed or changed this service.
            }

            app(\App\Services\Billing\UndeliveredRefund::class)->maybeRefund($fresh, 'customer');

            $fresh->update($reasonCols + [
                'status'       => 'terminated',
                'cancelled_at' => now(),
            ]);

            return true;
        });

        // Only the request that changed the locked row may release capacity or log deletion.
        if (! $transitioned) {
            return redirect()->to(lroute('account.services'))->withErrors(__('ui.svf_state'));
        }

        /*
        | گامِ ۲ و ۳ — آزادسازی نزدِ زیرساخت، و ثبتِ نتیجه.
        |
        | 🔴 مشتری هرگز پیامِ شکستِ زیرساخت را نمی‌بیند و هرگز از او خواسته
        | نمی‌شود دوباره تلاش کند؛ تلاشِ دوباره **کارِ ماست** (`cloud:release-retry`).
        | شکست ردیف را در `provision_status='releasing'` می‌گذارد، به مدیر خبر
        | می‌دهد و چکِ سلامت را قرمز می‌کند.
        |
        | 🔴 `releaseAndTrack()` هر دو نوع را می‌پوشانَد. قبلاً فقط `isCloud()`
        | شاخه می‌خورد و حذفِ هاستِ اشتراکی هیچ‌وقت به WHM نمی‌رسید: حسابِ cPanel
        | زنده می‌مانْد، دیسک مصرف می‌شد، و `active_accounts` کم نمی‌شد.
        */
        try {
            app(\App\Services\Provisioning\ProvisioningService::class)
                ->releaseAndTrack($service->fresh() ?? $service);
        } catch (\Throwable $e) {
            \App\Support\ErrorTracker::note('provision', $e, ['area' => 'customer-terminate', 'service' => $service->id]);
        }

        try {
            // دلیل در لاگِ سرویس هم می‌نشیند: گزارشِ مدیر عدد می‌دهد، ولی وقتی
            // پشتیبانی پروندهٔ **یک** سرویس را باز می‌کند، همان‌جا باید ببیند.
            $why = Service::terminateReasonLabel($data['reason'] ?? null);

            ActivityLog::forService($service, 'terminate',
                __('ui.act_svc_delete')
                .($why !== null ? __('ui.act_reason', ['why' => $why]) : ''), 'customer', $request);
        } catch (\Throwable) {
        }

        return redirect()->to(lroute('account.services'))
            ->with('ok', __('ui.svf_deleted'));
    }

    /**
     * فقط سرویسی که واقعاً تحویل شده و هنوز باز است.
     *
     * ⚠️ `cloudUndelivered()` جفتِ دقیقِ همان شرط در
     * `account/partials/svc-actions.blade.php` است. اگر یکی عوض شود و دیگری نه،
     * یا دکمه‌ای رندر می‌شود که سرور ردش می‌کند، یا سرویسی حذف می‌شود که هنوز
     * ماشینی ندارد و حذفش بی‌صدا هیچ‌کاری نمی‌کند.
     */
    private function terminable(Service $service): bool
    {
        return in_array($service->status, ['active', 'suspended', 'expired'], true)
            && $service->provision_status !== 'failed'
            && ! $service->cloudUndelivered();
    }

    /**
     * سفارشی که هنوز تحویل نشده و مشتری حق دارد لغوش کند.
     *
     * جفتِ `$cancellable` در همان ویو. شاخهٔ سوم تازه است: سرویسِ ابری‌ای که
     * `active`+`done` نوشته شده ولی ماشینش نیامده. پیش از این نه لغو می‌شد نه
     * (واقعاً) حذف — یعنی مشتریِ پول‌داده هیچ راهی نداشت.
     */
    private function cancellable(Service $service): bool
    {
        return in_array($service->status, ['awaiting_provision', 'provision_failed'], true)
            || ($service->status === 'active' && $service->provision_status === 'failed')
            || ($service->cloudUndelivered()
                && in_array($service->status, ['active', 'suspended', 'expired'], true));
    }

    private function ownedOr404(Service $service)
    {
        $customer = Auth::guard('customer')->user();
        abort_if($customer === null || (int) $service->customer_id !== (int) $customer->id, 404);

        return $customer;
    }

    /**
     * لغوِ سفارشی که تحویل نشده — با بازگشتِ پول به کیفِ پول.
     *
     * چرا لازم است: اگر تحویلِ خودکار شکست بخورد (مثلاً موجودیِ حسابِ زیرساخت
     * تمام باشد)، سرویس روی «در حالِ آماده‌سازی» می‌مانَد و مشتری **هیچ کاری**
     * نمی‌توانست بکند: نه سرور داشت، نه پولش را. حالا خودش لغو می‌کند و مبلغ
     * فوری به کیفِ پولش برمی‌گردد.
     *
     * ایمنیِ پول:
     *  • فقط مالکِ سرویس (Route Model Binding + بررسیِ صریحِ customer_id)
     *  • فقط وضعیت‌هایی که **تحویل نشده‌اند** — سرورِ تحویل‌شده از این‌جا لغو نمی‌شود
     *  • بازگشتِ پول **یک‌بار** (کلیدِ منبعِ یکتا در دفترِ اعتبار بررسی می‌شود)
     *  • اگر نمونه‌ای نیمه‌ساخته باشد، همان‌جا حذف می‌شود تا هزینه‌اش پای ما نماند
     */
    public function cancel(Request $request, Service $service): RedirectResponse
    {
        $customer = Auth::guard('customer')->user();

        abort_if($customer === null || (int) $service->customer_id !== (int) $customer->id, 404);

        // فقط سفارشِ تحویل‌نشده. سرویسِ فعالِ تحویل‌شده مسیرِ خودش را دارد.
        if (! $this->cancellable($service)) {
            return back()->withErrors(__('ui.svf_cancel_state'));
        }

        $refund = 0;

        DB::transaction(function () use ($service, $customer, &$refund) {
            $fresh = Service::whereKey($service->id)->lockForUpdate()->first();

            if ($fresh === null || $fresh->status === 'cancelled') {
                return;                          // قبلاً لغو شده — دوباره پول برنگردان
            }

            // مبلغِ پرداخت‌شدهٔ همین سرویس (فاکتورهای تسویه‌شده + کسرهای ساعتی)
            $paidInvoices = (int) $fresh->invoices()->where('status', 'paid')->sum('paid');

            $hourlySpent = Schema::hasTable('credit_ledger')
                ? (int) abs((int) CreditEntry::where('source_type', Service::class)
                    ->where('source_id', $fresh->id)
                    ->whereIn('reason', ['cloud_hourly', 'cloud_hourly_convert'])
                    ->sum('amount'))
                : 0;

            $refund = $paidInvoices + $hourlySpent;

            // ⚠️ محافظِ پرداختِ دوباره: اگر قبلاً برای همین سرویس بازگشت خورده،
            // دوباره نده. (دکمه دوبار زده شود یا دو تب باز باشد.)
            $already = Schema::hasTable('credit_ledger')
                && CreditEntry::where('source_type', Service::class)
                    ->where('source_id', $fresh->id)
                    ->where('reason', 'refund')->exists();

            if ($refund > 0 && ! $already) {
                $balance = $customer->creditBalance('IRT');

                CreditEntry::create([
                    'customer_id'   => $customer->id,
                    'currency_code' => 'IRT',
                    'amount'        => $refund,
                    'balance_after' => $balance + $refund,
                    'reason'        => 'refund',
                    'source_type'   => Service::class,
                    'source_id'     => $fresh->id,
                    'note'          => 'بازگشتِ وجه — لغوِ سفارشِ تحویل‌نشدهٔ «'.mb_substr((string) $fresh->name, 0, 60).'»',
                ]);
            } else {
                $refund = 0;
            }

            /*
            | 🔴 `provision_status` هم باید پاک شود، وگرنه پول برمی‌گردد **و**
            |    سرور خریده می‌شود.
            |
            | نه `RunProvisioning`، نه `ProvisioningService::provision()` و نه
            | `CloudProvisioner::provision()` هیچ‌کدام `status` را نمی‌سنجند —
            | همه فقط `provision_status` را می‌بینند. پس سرویسِ لغوشده‌ای که
            | روی `pending` مانده، دقیقهٔ بعد توسطِ کرون برداشته می‌شود، سرور
            | **واقعاً** خریداری می‌شود، و `finalize()` سرویس را دوباره
            | `active` می‌کند. سه‌گانهٔ ضرر: وجهِ برگشته، سرورِ خریده‌شده،
            | سرویسِ زنده‌شده.
            |
            | ⚠️ `none` همان مقداری است که `releaseServer()` می‌نویسد — یعنی
            | «هیچ صفی این را نمی‌خواهد».
            */
            $fresh->update([
                'status'           => 'cancelled',
                'cancelled_at'     => now(),
                'provision_status' => 'none',
            ]);
        });

        /*
        | اگر نیمه‌ساخته چیزی نزدِ زیرساخت مانده، پاکش کن (هزینه‌اش پای ماست).
        |
        | 🔴 مقدارِ برگشتی دیگر دور ریخته نمی‌شود. پیش از این این‌جا بدترین ترکیب
        | ساخته می‌شد: پول برگشته + ماشینِ احتمالاً زنده + وضعیتِ **پایانیِ**
        | `none` که تضمین می‌کرد هیچ صف و هیچ ناظری دیگر به آن ردیف نگاه نکند.
        | حالا شکست ردیف را به `releasing` می‌برد و صفِ `cloud:release-retry`
        | تا تأییدِ واقعیِ حذف رهایش نمی‌کند.
        */
        try {
            app(\App\Services\Provisioning\ProvisioningService::class)
                ->releaseAndTrack($service->fresh() ?? $service);
        } catch (\Throwable $e) {
            \App\Support\ErrorTracker::note('provision', $e, ['area' => 'order-cancel', 'service' => $service->id]);
        }

        try {
            ActivityLog::forService($service, 'terminate',
                __('ui.act_svc_cancel')
                .($refund > 0 ? __('ui.act_refund_suffix', ['amount' => invoice_money($refund)]) : ''),
                'customer', $request);
        } catch (\Throwable) {
        }

        return back()->with('ok', $refund > 0
            ? __('panel.cancel_refund', ['amount' => invoice_money($refund)])
            : __('panel.cancel_order'));
    }

    public function index(): View
    {
        $customer = Auth::guard('customer')->user();

        // سرویسی که فاکتورش پرداخت نشده هنوز مالِ مشتری نیست و نباید در فهرستِ
        // «سرویس‌های من» بیاید — وگرنه کاربر فکر می‌کند خریدش انجام شده. تا
        // پرداخت، همان پیش‌فاکتور در بخشِ فاکتورها منتظرِ اوست.
        //
        // ⚠️ فهرستِ **سفید** است نه سیاه. با فهرستِ سیاه، هر وضعیتِ تازه‌ای که
        // روزی اضافه شود خودبه‌خود به مشتری نشان داده می‌شود؛ این‌جا برعکس:
        // چیزی که صریحاً مجاز نباشد دیده نمی‌شود.
        //
        // فقط سرویسِ زنده: «فعال»، و «معلق» که تعلیقش موقتی است تا فاکتورِ
        // تمدید پرداخت شود. حذف‌شده و لغوشده و منقضی بیرون می‌مانند — ردِ
        // مالی‌شان در فاکتورها و گردشِ اعتبار محفوظ است، پس چیزی گم نمی‌شود.
        //
        // 🔴 سفارشِ در حالِ تحویل هم می‌مانَد. اگر بیرونش بگذاریم، مشتری‌ای که
        // همین حالا پول داده تا لحظهٔ تحویل **هیچ‌چیز** نمی‌بیند، و مهم‌تر:
        // دکمهٔ «لغو سفارش» روی همین ردیف‌هاست — همان راهِ فراری که برای
        // سفارشِ گیرکرده ساختیم از بین می‌رفت.
        $services = Schema::hasTable('services')
            ? $customer->services()
                ->whereIn('status', ['active', 'suspended', 'awaiting_provision', 'provision_failed'])
                ->with(['invoices' => fn ($q) => $q->latest('id'), 'server', 'cloudInstance'])
                ->latest('id')->get()
            : collect();

        return view('account.services', AccountController::shell('services') + [
            'services' => $services,
        ]);
    }

    /**
     * ورودِ یک‌کلیکِ مشتری به cPanelِ خودش — WHM یک نشستِ ازپیش‌احرازشده می‌سازد
     * و مشتری مستقیم واردِ کنترل‌پنل می‌شود (بدونِ نیاز به نام‌کاربری/رمز).
     */
    public function cpanel(Request $request, Service $service): RedirectResponse
    {
        $customer = Auth::guard('customer')->user();
        abort_unless($service->customer_id === $customer->id, 404);

        if ($service->provision_status !== 'done' || ! $service->server || blank($service->username)) {
            return back()->withErrors(__('ui.svf_sso_na'));
        }

        // فقط WHM نشستِ ورود دارد؛ بقیه به آدرسِ کنترل‌پنل هدایت می‌شوند
        if ($service->server->type !== 'whm') {
            return $service->panel_url ? redirect()->away($service->panel_url)
                : back()->withErrors(__('ui.svf_panel_url'));
        }

        /*
        | «وب‌میل» نشستِ جداگانهٔ خودش را دارد؛ `createUserSession` از قبل
        | پارامترش را می‌گرفت و هیچ‌وقت پاس داده نمی‌شد.
        |
        | 🔴 نماینده باید به **WHM** برود (`whostmgrd`), نه cPanel. با `cpaneld`
        | نشست ساخته می‌شود و ورود هم موفق است — ولی مشتری داخلِ cPanelِ حسابِ
        | خودش می‌افتد و هیچ‌جا اکانت‌های مشتریانش را نمی‌بیند. یعنی خرابی کدِ
        | ۳۰۲ می‌دهد و «کار می‌کند»، فقط محصولی که خریده را تحویل نمی‌دهد.
        */
        $svc = match (true) {
            $request->query('app') === 'webmail' => 'webmaild',
            (bool) $service->is_reseller         => 'whostmgrd',
            default                              => 'cpaneld',
        };

        $res = (new WhmClient($service->server))->createUserSession($service->username, $svc);
        $url = $res['data']['url'] ?? ($res['raw']['data']['url'] ?? null);

        if (! $res['ok'] || ! $url) {
            return back()->withErrors(__('ui.svf_cpanel_fail', ['reason' => $res['reason'] ?? '—']));
        }

        // ورودِ عمیق به یک ابزارِ خاصِ cPanel (قالبِ Jupiter؛ اگر مسیر نخورد،
        // روی خانهٔ cPanel می‌نشیند — بی‌خطر)
        //
        // ⚠️ برای نماینده اصلاً اعمال نمی‌شود: این مسیرها زیرِ `frontend/jupiter`
        // یعنی داخلِ cPanel، و در نشستِ WHM بی‌معنی‌اند.
        $goto = $service->is_reseller ? null : match ($request->query('app')) {
            'files' => '/frontend/jupiter/filemanager/index.html',
            'db'    => '/frontend/jupiter/sql/index.html',
            'email' => '/frontend/jupiter/mail/index.html',
            'php'   => '/frontend/jupiter/software/phpini.html',
            // ویرایشگرِ DNS عمداً لینکِ عمیق است نه رابطِ خودمان: رکوردهای
            // زیردامنهٔ رایگان روی Cloudflare است نه در zoneِ WHM، پس یک
            // ویرایشگرِ داخلی بی‌صدا روی جای اشتباه می‌نوشت.
            'dns'   => '/frontend/jupiter/zoneeditor/index.html',
            'back'  => '/frontend/jupiter/backup/index.html',
            default => null,
        };
        if ($goto) {
            $url .= (str_contains($url, '?') ? '&' : '?').'goto_uri='.rawurlencode($goto);
        }

        return redirect()->away($url);
    }

    /** آمارِ زندهٔ سرویس (فضا/وضعیت) — JSON، با کشِ کوتاه تا WHM را نکوبد */
    public function stats(Request $request, Service $service): JsonResponse
    {
        $customer = Auth::guard('customer')->user();
        abort_unless($service->customer_id === $customer->id, 404);

        if ($service->provision_status !== 'done' || ! $service->server || $service->server->type !== 'whm' || blank($service->username)) {
            return response()->json(['ok' => false]);
        }

        $data = Cache::remember('svc-stats:'.$service->id, now()->addMinutes(3), function () use ($service) {
            $sum = (new WhmClient($service->server))->accountSummary($service->username);
            $acct = $sum['data']['acct'][0] ?? ($sum['raw']['data']['acct'][0] ?? []);

            if (! $sum['ok'] || ! $acct) {
                return ['ok' => false];
            }

            $limit = $acct['disklimit'] ?? 'unlimited';
            $num = fn ($v) => is_numeric($v) ? (int) $v : null;

            // پهنای‌باند تماسِ جداگانه می‌خواهد (`accountsummary` ندارَدش) و
            // پرتکرارترین پرسشِ پشتیبانی است. اگر توکن دسترسی نداشت،
            // بی‌سروصدا null می‌مانَد و بقیهٔ کارت سالم نشان داده می‌شود.
            $bwUsed = $bwLimit = null;

            try {
                $bw = (new WhmClient($service->server))->bandwidth($service->username);
                $row = $bw['data']['acct'][0] ?? ($bw['raw']['data']['acct'][0] ?? []);

                // ⚠️ ردیفِ برگشتی **باید** همین کاربر باشد. الگو مهار شده،
                // ولی اتکا به `[0]`ِ کور همان اشتباهی است که مصرفِ مشتریِ
                // دیگری را نشان می‌داد — پس صریح می‌سنجیم.
                if ($bw['ok'] && $row
                    && strcasecmp((string) ($row['user'] ?? ''), (string) $service->username) === 0) {
                    $bwUsed = $num($row['totalbytes'] ?? null);
                    $bwLimit = $num($row['limit'] ?? null);
                }
            } catch (\Throwable) {
            }

            return [
                'ok'         => true,
                'disk_used'  => (int) ($acct['diskused'] ?? 0),                          // MB
                'disk_limit' => is_numeric($limit) ? (int) $limit : null,               // MB، null=نامحدود
                'suspended'  => (int) ($acct['suspended'] ?? 0) === 1,
                'ip'         => $acct['ip'] ?? null,
                'plan'       => $acct['plan'] ?? null,
                'bw_used'    => $bwUsed,                                                 // بایت
                'bw_limit'   => $bwLimit,                                                // بایت، null=نامحدود
                // این چهار عدد از همان پاسخِ ازقبل‌گرفته‌شده می‌آیند و تا امروز
                // دور ریخته می‌شدند — هیچ تماسِ اضافه‌ای ندارند.
                'max_email'  => $num($acct['maxpop'] ?? null),
                'max_db'     => $num($acct['maxsql'] ?? null),
                'max_sub'    => $num($acct['maxsub'] ?? null),
                'max_addon'  => $num($acct['maxaddon'] ?? null),
            ];
        });

        return response()->json($data);
    }
}

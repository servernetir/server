<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\CreditEntry;
use App\Models\Service;
use App\Services\Otp\OtpService;
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
    public function __construct(private OtpService $otp) {}

    // ═════════════ حذفِ سرویسِ تحویل‌شده — دومرحله‌ای با کدِ یک‌بارمصرف ═════════════

    /**
     * چرا OTP: این کار **سرور را واقعاً نزدِ زیرساخت حذف می‌کند** و داده‌ها
     * برنمی‌گردند. یک نشستِ دزدیده‌شده یا لپ‌تاپِ بازمانده نباید بتواند با یک
     * کلیک کلِ سرورِ مشتری را پاک کند. کد به موبایل/ایمیلِ خودِ صاحبِ حساب
     * می‌رود، پس مهاجم علاوه بر نشست باید به آن هم دسترسی داشته باشد.
     *
     * ⚠️ کد به **همین سرویس** گره می‌خورد. اگر فقط «کدِ حذف» صادر می‌کردیم،
     * مشتری می‌توانست کد را برای سرویسِ ارزان بگیرد و با همان، سرویسِ دیگری را
     * حذف کند — و مهم‌تر، یک باگِ ساده در فرم همین کار را ناخواسته می‌کرد.
     */
    public function terminateStart(Request $request, Service $service): RedirectResponse
    {
        $customer = $this->ownedOr404($service);

        if (! $this->terminable($service)) {
            return back()->withErrors('این سرویس در وضعیتی نیست که بتوان حذفش کرد.');
        }

        $channel = $customer->phone ? 'sms' : 'email';
        $destination = $channel === 'sms' ? $customer->phone : $customer->email;

        if (! $destination) {
            return back()->withErrors('راهی برای ارسالِ کد نداریم؛ ابتدا ایمیل یا موبایل ثبت کنید.');
        }

        $issue = $this->otp->issue($channel, $destination, 'service_terminate', $request->ip());

        if (! $issue->ok && $issue->retryAfter === null) {
            return back()->withErrors($issue->error);
        }

        $request->session()->put('svc_terminate_ctx', [
            'service_id'  => (int) $service->id,
            'channel'     => $channel,
            'destination' => $destination,
        ]);

        return back()->with('ok', 'کدِ تأیید فرستاده شد. برای حذفِ سرویس آن را وارد کنید.');
    }

    /** مرحلهٔ دوم: بررسیِ کد و حذفِ واقعیِ سرور نزدِ زیرساخت */
    public function terminate(Request $request, Service $service): RedirectResponse
    {
        $customer = $this->ownedOr404($service);
        $ctx = $request->session()->get('svc_terminate_ctx');

        if (! is_array($ctx) || (int) ($ctx['service_id'] ?? 0) !== (int) $service->id) {
            return back()->withErrors('ابتدا برای همین سرویس کدِ تأیید بگیرید.');
        }

        if (! $this->terminable($service)) {
            $request->session()->forget('svc_terminate_ctx');

            return back()->withErrors('این سرویس در وضعیتی نیست که بتوان حذفش کرد.');
        }

        /*
        | 🔴 دلیلِ حذف **اختیاری** است و باید بمانَد.
        |
        | مشتری در این لحظه از ما ناراضی است؛ فیلدِ اجباری یک دیوار است و
        | نتیجه‌اش دادهٔ بهتر نیست، تیکتِ عصبانی است. پس `nullable`، و اگر خالی
        | باشد حذف بی‌هیچ مانعی انجام می‌شود.
        |
        | ⚠️ کد باید از فهرستِ بسته بیاید. با `string`ِ آزاد، هر مقداری از
        | مرورگر به ستون می‌رسید و گزارشِ مدیر پر از کدهای بی‌معنی می‌شد — یعنی
        | همان «دادهٔ غیرقابلِ شمارش» که این ستون برای رفعش ساخته شد.
        */
        $data = $request->validate([
            'code'        => ['required', 'string', 'max:12'],
            'reason'      => ['nullable', 'string', Rule::in(Service::terminateReasonCodes())],
            'reason_note' => ['nullable', 'string', 'max:500'],
        ], [], ['code' => 'کد', 'reason' => 'دلیل حذف', 'reason_note' => 'توضیح']);

        $check = $this->otp->verify($ctx['channel'], $ctx['destination'], 'service_terminate', $data['code']);

        if (! $check->ok) {
            return back()->withErrors(['code' => $check->error]);
        }

        $request->session()->forget('svc_terminate_ctx');

        // حذفِ واقعی نزدِ زیرساخت **پیش از** بستنِ وضعیت: اگر این‌جا شکست بخورد،
        // سرویس «فعال» می‌ماند و مشتری دوباره تلاش می‌کند. برعکسش یعنی سرویسِ
        // بسته‌شده ولی سروری که هنوز اجاره‌اش را ما می‌دهیم.
        //
        // 🔴 قبلاً فقط `isCloud()` بود. یعنی حذفِ هاستِ اشتراکی هیچ‌وقت به WHM
        // نمی‌رسید: متنِ تأییدی که مشتری می‌پذیرد صریح می‌گوید «سرور و همهٔ
        // داده‌ها برای همیشه پاک می‌شود»، ولی حسابِ cPanel زنده می‌مانْد، سایت
        // بالا می‌مانْد، دیسک مصرف می‌کرد، و با همان رمزی که در پنل نشان داده
        // شده در دسترس بود. `active_accounts` هم کم نمی‌شد، پس ظرفیتِ آن سرور
        // برای همیشه اشغال می‌مانْد. `releaseServer()` هر دو نوع را می‌پوشانَد.
        try {
            $r = app(\App\Services\Provisioning\ProvisioningService::class)->releaseServer($service);
            $ok = $r->ok || $r->manual;
        } catch (\Throwable) {
            $ok = false;
        }

        if (! $ok) {
            return back()->withErrors('حذفِ سرور نزدِ زیرساخت انجام نشد. چند دقیقهٔ دیگر دوباره تلاش کنید یا به پشتیبانی بگویید.');
        }

        /*
        | ⚠️ **پیش از نوشتن، وجودِ ستون را بپرس.**
        |
        | مهاجرت را کارفرما دستی اجرا می‌کند (`/system/migrate`)، پس بینِ دپلویِ
        | کد و اجرای مهاجرت پنجره‌ای هست که این ستون‌ها نیستند. و این نوشتن
        | **بعد از** حذفِ واقعیِ سرور نزدِ زیرساخت اتفاق می‌افتد: یک خطای SQL
        | این‌جا یعنی سرورِ پاک‌شده و سرویسی که هنوز «فعال» است و مشتری فاکتورِ
        | تمدیدش را می‌گیرد. یک ستونِ آمارِ بازاریابی هرگز نباید چنین ریسکی
        | بسازد.
        */
        $reasonCols = Schema::hasColumn('services', 'terminate_reason')
            ? [
                'terminate_reason'      => $data['reason'] ?? null,
                'terminate_reason_note' => filled($data['reason_note'] ?? null)
                    ? mb_substr(trim((string) $data['reason_note']), 0, 500)
                    : null,
            ]
            : [];

        DB::transaction(function () use ($service, $reasonCols) {
            $fresh = Service::whereKey($service->id)->lockForUpdate()->first();

            if ($fresh === null || in_array($fresh->status, ['terminated', 'cancelled'], true)) {
                return;                          // قبلاً بسته شده
            }

            $fresh->update($reasonCols + [
                'status'       => 'terminated',
                'cancelled_at' => now(),
                'billing_mode' => $fresh->billing_mode,   // متر ساعتی دیگر نمی‌شمارد چون وضعیت فعال نیست
            ]);
        });

        try {
            // دلیل در لاگِ سرویس هم می‌نشیند: گزارشِ مدیر عدد می‌دهد، ولی وقتی
            // پشتیبانی پروندهٔ **یک** سرویس را باز می‌کند، همان‌جا باید ببیند.
            $why = Service::terminateReasonLabel($data['reason'] ?? null);

            ActivityLog::forService($service, 'terminate',
                'حذفِ سرویس به‌خواستِ مشتری با تأییدِ کدِ یک‌بارمصرف'
                .($why !== null ? ' — دلیل: '.$why : ''), 'customer', $request);
        } catch (\Throwable) {
        }

        return redirect()->to(lroute('account.services'))
            ->with('ok', 'سرویس حذف شد. اگر اعتبارِ استفاده‌نشده‌ای داشتید، در کیفِ پولتان می‌مانَد.');
    }

    /** فقط سرویسی که واقعاً تحویل شده و هنوز باز است */
    private function terminable(Service $service): bool
    {
        return in_array($service->status, ['active', 'suspended', 'expired'], true)
            && $service->provision_status !== 'failed';
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
        $cancellable = in_array($service->status, ['awaiting_provision', 'provision_failed'], true)
            || ($service->status === 'active' && $service->provision_status === 'failed');

        if (! $cancellable) {
            return back()->withErrors('این سرویس در وضعیتی نیست که بتوان از این‌جا لغوش کرد. با پشتیبانی تماس بگیرید.');
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

        // اگر نیمه‌ساخته چیزی نزدِ زیرساخت مانده، پاکش کن (هزینه‌اش پای ماست)
        try {
            app(\App\Services\Cloud\CloudProvisioner::class)->terminate($service->fresh());
        } catch (\Throwable) {
        }

        try {
            ActivityLog::forService($service, 'terminate',
                'لغوِ سفارشِ تحویل‌نشده توسط مشتری'.($refund > 0 ? ' — بازگشتِ '.fa_num(number_format($refund)).' تومان به کیفِ پول' : ''),
                'customer', $request);
        } catch (\Throwable) {
        }

        return back()->with('ok', $refund > 0
            ? 'سفارش لغو شد و '.fa_num(number_format($refund)).' تومان به کیفِ پولِ شما بازگشت.'
            : 'سفارش لغو شد.');
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
            return back()->withErrors('ورودِ یک‌کلیک برای این سرویس هنوز در دسترس نیست.');
        }

        // فقط WHM نشستِ ورود دارد؛ بقیه به آدرسِ کنترل‌پنل هدایت می‌شوند
        if ($service->server->type !== 'whm') {
            return $service->panel_url ? redirect()->away($service->panel_url)
                : back()->withErrors('آدرسِ کنترل‌پنل تعیین نشده است.');
        }

        // «وب‌میل» نشستِ جداگانهٔ خودش را دارد؛ `createUserSession` از قبل
        // پارامترش را می‌گرفت و هیچ‌وقت پاس داده نمی‌شد.
        $svc = $request->query('app') === 'webmail' ? 'webmaild' : 'cpaneld';

        $res = (new WhmClient($service->server))->createUserSession($service->username, $svc);
        $url = $res['data']['url'] ?? ($res['raw']['data']['url'] ?? null);

        if (! $res['ok'] || ! $url) {
            return back()->withErrors('ورود به cPanel ناموفق بود: '.($res['reason'] ?? 'نامشخص'));
        }

        // ورودِ عمیق به یک ابزارِ خاصِ cPanel (قالبِ Jupiter؛ اگر مسیر نخورد،
        // روی خانهٔ cPanel می‌نشیند — بی‌خطر)
        $goto = match ($request->query('app')) {
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

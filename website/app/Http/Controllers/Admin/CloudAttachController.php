<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\CloudInstance;
use App\Models\CloudPlan;
use App\Models\Customer;
use App\Models\Service;
use App\Services\Cloud\CloudManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * اتصالِ سرورِ **ازقبل‌ساخته‌شده** نزدِ زیرساخت به یک مشتری.
 *
 * چرا لازم شد: گاهی سرور را دستی نزدِ زیرساخت می‌سازیم (مشتری تلفنی سفارش
 * می‌دهد، پول را کارت‌به‌کارت می‌گیریم، سرور را همان‌جا بالا می‌آوریم). آن سرور
 * تا وقتی در سامانه ثبت نشود:
 *   • در پنلِ مشتری دیده نمی‌شود و خودش هیچ کاری نمی‌تواند بکند
 *   • سررسیدِ تمدید ندارد، پس کرونِ صورت‌حساب هرگز برایش فاکتور نمی‌سازد
 *     و ماهِ بعد بی‌آنکه کسی بفهمد رایگان می‌شود
 *
 * ⚠️ این مسیر عمداً **سرور نمی‌سازد**. فقط چیزی را که هست ثبت می‌کند. برای همین
 * پیش از ثبت، وجودِ سرور را از خودِ زیرساخت می‌پرسد؛ اگر شناسه را نشناسد ثبت
 * نمی‌شود — وگرنه یک غلطِ تایپی، سرویسی می‌ساخت که به هیچ سروری وصل نیست و
 * مشتری با دکمه‌هایی روبه‌رو می‌شد که همه خطا می‌دهند.
 */
class CloudAttachController extends Controller
{
    /**
     * افقِ صدورِ فاکتورِ تمدید — همان `--days` پیش‌فرضِ `services:renew-due`.
     *
     * اگر روزی آن گزینه عوض شد، این عدد هم باید عوض شود؛ وگرنه فرم دوباره
     * اجازهٔ ثبتِ سررسیدی را می‌دهد که کرون فوراً فاکتورش می‌کند.
     */
    private const RENEW_LEAD_DAYS = 5;

    public function __construct(
        private CloudManager $manager,
        private \App\Services\Cloud\CloudInventory $inventory,
    ) {}

    /**
     * نزدیک‌ترین سررسیدِ بی‌خطر: امروز + افقِ فاکتور.
     *
     * ⚠️ از `startOfDay()` استفاده می‌شود چون منطقهٔ زمانیِ برنامه UTC است ولی
     * مدیر در تهران (UTC+3:30) می‌نشیند؛ مقایسهٔ ساعتی نزدیکِ نیمه‌شب نتیجهٔ
     * غافلگیرکننده می‌داد.
     */
    private static function safeDueFloor(): \Illuminate\Support\Carbon
    {
        return now()->startOfDay()->addDays(self::RENEW_LEAD_DAYS);
    }

    public function form(Request $request): View
    {
        // اسکن عمداً **دستی** است نه خودکار روی هر بازکردنِ صفحه: هر بار چند
        // تماسِ زندهٔ صفحه‌بندی‌شده با همهٔ زیرساخت‌هاست و صفحه را کُند می‌کند.
        $scan = $request->boolean('scan')
            ? $this->inventory->reconcile()
            : null;

        return view('admin.cloud-attach', [
            // برچسبِ سفیدبرچسبِ زیرساخت‌ها — نامِ واقعی نزدِ مدیر مشکلی ندارد،
            // ولی همان چیزی را نشان می‌دهیم که بقیهٔ پنل نشان می‌دهد.
            'labelOf'   => fn (?string $p) => $this->manager->label($p),
            'plans'     => CloudPlan::query()
                ->orderBy('provider')->orderBy('sort')
                ->get(['id', 'provider', 'public_name', 'slug', 'location_code', 'vcpu', 'ram_mb', 'disk_gb', 'price_irt']),
            'customers' => Customer::query()->orderBy('id', 'desc')->limit(500)
                ->get(['id', 'code', 'email', 'phone']),
            // بدونِ 'once' — کرونِ تمدید نمی‌بیندش و سرویس هرگز فاکتور نمی‌شود
            'cycles'    => array_keys(config('billing.cycles')),
            'prefill'   => $request->query('ref', ''),
            'prefillName' => $request->query('sname', ''),
            'scan'      => $scan,
        ]);
    }

    /**
     * گزارشِ تطبیقِ موجودی — یتیم‌ها و شبح‌ها.
     *
     * جای این گزارش در پنلِ مدیریت است نه در کرون: هر ردیفش تصمیمِ آدم می‌خواهد
     * (این سرور را حذف کنم؟ آن سرویس را ببندم؟) و خودکارسازیِ چنین تصمیمی یعنی
     * روزی یک اسکریپت سرورِ زندهٔ یک مشتری را پاک می‌کند.
     */
    public function inventory(): View
    {
        return view('admin.cloud-inventory', [
            'report'  => $this->inventory->reconcile(),
            'labelOf' => fn (?string $p) => $this->manager->label($p),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'customer_id'  => ['required', 'integer', 'exists:customers,id'],
            'cloud_plan_id' => ['required', 'integer', 'exists:cloud_plans,id'],
            'provider_ref' => ['required', 'string', 'max:120'],
            'name'         => ['required', 'string', 'max:150'],
            'price'        => ['required', 'integer', 'min:0', 'max:100000000000'],
            // 'once' عمداً بیرون است: کرونِ صورت‌حساب فقط دوره‌های
            // `config/billing.cycles` را می‌بیند و 'once' جزوشان نیست. سرویسی که
            // با آن ثبت شود هرگز فاکتور نمی‌شود — یعنی دقیقاً همان «ماهِ بعد
            // بی‌آنکه کسی بفهمد رایگان می‌شود» که این صفحه برای جلوگیری از آن ساخته شد.
            'cycle'        => ['required', \Illuminate\Validation\Rule::in(array_keys(config('billing.cycles')))],
            'activated_at' => ['required', 'date'],
            // 🔴 سررسید **صریح** گرفته می‌شود و باید در آینده باشد.
            //
            // قبلاً از «تاریخ شروع + یک دوره» حساب می‌شد. کاربردِ اصلیِ همین صفحه
            // ثبتِ سروری است که هفته‌ها پیش ساخته شده — پس آن فرمول سررسیدِ
            // گذشته می‌ساخت، و زنجیرهٔ کرون بی‌رحم است: ۰۷:۰۰ `services:renew-due`
            // برای هر سرویسِ فعالِ سررسیدگذشته فاکتور صادر می‌کند و ۰۷:۳۰ همان
            // صبح `services:lifecycle` همان فاکتورِ پرداخت‌نشده را می‌بیند و سرور
            // را واقعاً **خاموش** می‌کند. یعنی مدیر سرورِ زندهٔ مشتری را وصل
            // می‌کرد و نیم‌ساعت بعد از کار می‌افتاد، با پیامکِ «سرویس شما غیرفعال شد».
            //
            // جلو بردنِ خودکارِ سررسید تا آینده هم جوابِ درستی نبود: به مشتریِ
            // بدهکار ماه‌ها سرویسِ رایگان می‌داد. پس تصمیم را به مدیر می‌دهیم و
            // فقط حالتِ فاجعه‌بار را غیرممکن می‌کنیم.
            //
            // 🔴 «فردا» کافی **نیست**. `services:renew-due` با `--days=5` می‌دود و
            // هر سرویسی را که تا ۵ روزِ آینده سررسید دارد همان اجرا فاکتور
            // می‌کند؛ بعد `services:lifecycle` همان فاکتورِ پرداخت‌نشده را می‌بیند
            // و سرور را خاموش می‌کند. پس مرزِ درست «پس از افقِ صدور فاکتور» است،
            // نه «پس از امروز» — وگرنه صفحه، مدیر را دقیقاً به بازهٔ خطرناک
            // هدایت می‌کرد («تاریخی چند روز جلوتر بگذارید»).
            'next_due_at'  => ['required', 'date', 'after:'.self::safeDueFloor()->toDateString()],
        ], [
            'next_due_at.after' => 'سررسید تمدید باید دستِ‌کم '.fa_num(self::RENEW_LEAD_DAYS + 1)
                .' روز دیگر باشد (پس از '.sdate(self::safeDueFloor()).'). '
                .'کرونِ صورت‌حساب هر سرویسی را که تا '.fa_num(self::RENEW_LEAD_DAYS)
                .' روزِ آینده سررسید دارد فاکتور می‌کند و بعد بابتِ همان فاکتورِ '
                .'پرداخت‌نشده سرور را خاموش می‌کند. اگر مشتری همین حالا بدهکار است، '
                .'سرویس را با سررسیدی دورتر ثبت کنید و فاکتورش را دستی صادر کنید.',
            'cycle.in' => 'دورهٔ «یک‌بار» برای این کار مناسب نیست: کرونِ تمدید آن را '
                .'نمی‌بیند و سرویس هرگز فاکتور نمی‌شود.',
        ], [
            'customer_id' => 'مشتری', 'cloud_plan_id' => 'پلن', 'provider_ref' => 'شناسهٔ سرور',
            'name' => 'نام سرویس', 'price' => 'مبلغ', 'cycle' => 'دوره', 'activated_at' => 'تاریخ شروع',
            'next_due_at' => 'سررسید تمدید بعدی',
        ]);

        $plan = CloudPlan::findOrFail($data['cloud_plan_id']);
        $ref = trim($data['provider_ref']);

        // ── همان سرور واقعاً نزدِ زیرساخت هست؟ ──
        $driver = $this->manager->driver($plan->provider);

        if ($driver === null || ! $driver->isConfigured()) {
            return back()->withInput()->withErrors('توکنِ این زیرساخت تنظیم نشده؛ بی‌آن نمی‌توان وجودِ سرور را تأیید کرد.');
        }

        $status = $driver->serverStatus($ref);

        if (! ($status['ok'] ?? false)) {
            return back()->withInput()->withErrors(
                'سروری با این شناسه نزدِ زیرساخت پیدا نشد: '.($status['message'] ?? 'پاسخی نیامد').
                ' — شناسه را از پنلِ خودِ زیرساخت بردارید.'
            );
        }

        // ⚠️ یک سرور نباید هم‌زمان مالِ دو سرویس باشد؛ وگرنه دو مشتری روی یک
        // ماشین دکمهٔ «حذف» دارند.
        $taken = CloudInstance::where('provider', $plan->provider)->where('provider_ref', $ref)->first();

        if ($taken !== null) {
            return back()->withInput()->withErrors(
                'این سرور از قبل به سرویسِ شمارهٔ '.$taken->service_id.' وصل است.'
            );
        }

        $customer = Customer::findOrFail($data['customer_id']);
        $start = \Illuminate\Support\Carbon::parse($data['activated_at']);

        $service = DB::transaction(function () use ($customer, $data, $plan, $ref, $status, $start, $request) {
            $service = Service::create([
                'customer_id'      => $customer->id,
                'name'             => $data['name'],
                'currency_code'    => 'IRT',
                'price'            => (int) $data['price'],
                'tax_percent'      => 0,
                'cycle'            => $data['cycle'],
                // پول از قبل گرفته شده، پس سرویس **فعال** ثبت می‌شود نه «منتظر پرداخت».
                'status'           => 'active',
                'provision_status' => 'done',
                'activated_at'     => $start,
                'next_due_at'      => \Illuminate\Support\Carbon::parse($data['next_due_at']),
                'cloud_plan_id'    => $plan->id,
                'created_by'       => $request->user()?->id,
            ]);

            CloudInstance::create([
                'service_id'    => $service->id,
                'provider'      => $plan->provider,
                'provider_ref'  => $ref,
                'location_code' => $plan->location_code,
                'hostname'      => 'sn-svc-'.$service->id,
                'ipv4'          => $status['ipv4'] ?? null,
                'ipv6'          => $status['ipv6'] ?? null,
                'status'        => $status['status'] ?? 'running',
                // رمزِ root را نداریم (سرور را دستی ساخته‌ایم). «دیده‌شده» علامت
                // می‌خورد تا پنل رمزی را وعده ندهد که ندارد؛ مشتری از دکمهٔ
                // «رمزِ تازه» خودش یکی می‌سازد.
                'password_seen' => true,
                'synced_at'     => now(),
            ]);

            return $service;
        });

        ActivityLog::forService($service, 'purchase',
            'سرورِ ازقبل‌ساخته‌شدهٔ زیرساخت («'.$ref.'») توسط مدیر به این مشتری متصل شد؛ '
            .'سررسیدِ تمدید: '.sdate($service->next_due_at),
            'staff', $request);

        return redirect('/admin/customers/'.$customer->id)
            ->with('ok', 'سرور به مشتری وصل شد. از این پس در پنلِ خودش دیده می‌شود و '
                .'سررسیدِ تمدیدش '.sdate($service->next_due_at).' است.');
    }
}

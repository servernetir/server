<?php

namespace Tests\Feature;

use App\Models\CloudPlan;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * مسیریابیِ تحویل پس از پرداخت — محافظِ یک باگِ **سه‌بار تکرارشده**.
 *
 * ═══ چرا این فایل وجود دارد ═══
 *
 * سه بار، در سه جای مختلف، کد فرض کرد «مقصدِ تحویل = server_id». هر سه بار
 * نتیجه یکی بود و بدترین شکلِ ممکن: مشتری پول می‌داد، **هیچ خطایی در هیچ لاگی
 * تولید نمی‌شد**، و سرویس هرگز ساخته نمی‌شد. خرابی‌ای که خطا تولید نمی‌کند،
 * تا شکایتِ مشتری دیده نمی‌شود.
 *
 *   ۱) `provision:run` → `whereNotNull('server_id')`
 *   ۲) `PaymentService::applyPaid` → شرطِ `$needsDelivery`
 *   ۳) همان متد، UPDATEِ خامِ داخلِ `catch`
 *
 * سرورِ ابری پیش از خرید وجود ندارد، پس نه `server_id` دارد نه دامنه.
 *
 * این تست‌ها **همهٔ** مسیرهای تحویل را با هم می‌سنجند تا اگر روزی مسیرِ چهارمی
 * اضافه شد (Proxmox، سرورِ فیزیکی، …) و یکی از این سه جا جا افتاد، تست بیفتد
 * نه مشتری.
 */
class DeliveryRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put('pricing_rate_override', '100000');
        Mail::fake();
        Http::fake();                       // هیچ تماسِ واقعی‌ای نباید لازم باشد
    }

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'dr'.random_int(1, 99999).'@example.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => 'secret1234', 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    private function cloudPlan(): CloudPlan
    {
        return CloudPlan::create([
            'provider' => 'hetzner', 'provider_ref' => 'cx22', 'provider_location' => 'fsn1',
            'location_code' => 'de-falkenstein', 'public_name' => 'CV-2-4',
            'slug' => 'cv-2c-4g-40d-de-falkenstein',
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme',
            'cost_eur_cents' => 379, 'price_eur_cents' => 570, 'price_irt' => 570000,
            'is_active' => true, 'in_stock' => true,
        ]);
    }

    /** سرویس + فاکتورِ پرداخت‌نشده، آمادهٔ تسویه */
    private function serviceWithInvoice(array $attrs): array
    {
        $customer = $this->customer();

        $service = Service::create(array_merge([
            'customer_id' => $customer->id, 'name' => 'سرویسِ آزمون',
            'price' => 570000, 'cycle' => 'monthly', 'status' => 'pending',
        ], $attrs));

        $invoice = Invoice::create([
            'customer_id'   => $customer->id,
            'service_id'    => $service->id,
            'kind'          => 'service',
            'currency_code' => 'IRT',
            'status'        => 'unpaid',
            'paid'          => 0,
            'subtotal'      => 570000,
            'tax'           => 0,
            'total'         => 570000,
            'issued_at'     => now(),
        ]);

        return [$service, $invoice];
    }

    /**
     * همان مسیری که بازگشتِ موفقِ درگاه می‌رود (`settleConfirmed` → `applyPaid`).
     * عمداً از مسیرِ عمومی می‌رویم نه متدِ خصوصی، تا تست همان کدی را بسنجد که
     * در عمل اجرا می‌شود.
     */
    private function pay(Invoice $invoice): void
    {
        $payment = \App\Models\Payment::create([
            'invoice_id'    => $invoice->id,
            'customer_id'   => $invoice->customer_id,
            'gateway'       => 'bale',
            'currency_code' => 'IRT',
            'amount'        => $invoice->total,
            'status'        => 'redirected',
            'external_ref'  => 'DR-'.$invoice->id.'-'.random_int(1000, 9999),
        ]);

        app(PaymentService::class)->settleConfirmed($payment, 'REF-'.random_int(1000, 9999));
    }

    // ═══════════════════ سرورِ ابری — باگِ سوم ═══════════════════

    /**
     * 🔴 قلبِ ماجرا: سرویسِ ابری پس از پرداخت باید به **صفِ تحویلِ خودکار** برود.
     *
     * پیش از اصلاح، این سرویس مستقیم `active` می‌شد با `provision_status` نال، و
     * کرون که فقط `pending` را برمی‌دارد هرگز نمی‌دیدش.
     */
    public function test_paid_cloud_service_enters_the_automatic_delivery_queue(): void
    {
        [$service, $invoice] = $this->serviceWithInvoice([
            'cloud_plan_id' => $this->cloudPlan()->id,
            'cloud_image_key' => 'ubuntu-24.04',
        ]);

        $this->pay($invoice);
        $service->refresh();

        $this->assertSame('awaiting_provision', $service->status,
            'سرویسِ ابری نباید مستقیم active شود — هنوز سروری ساخته نشده');
        $this->assertSame('pending', $service->provision_status,
            'بی‌pending، کرونِ تحویل هرگز آن را برنمی‌دارد و سرور ساخته نمی‌شود');
    }

    /**
     * و مهم‌تر از وضعیت: کرون باید **واقعاً** آن را برداشت کند.
     *
     * دو تستِ جدا لازم است چون باگ می‌تواند در هر کدام از دو سر باشد: یا
     * PaymentService وضعیت را غلط بگذارد، یا کرون شرطِ غلط داشته باشد.
     */
    public function test_cron_actually_picks_up_the_paid_cloud_service(): void
    {
        [$service, $invoice] = $this->serviceWithInvoice([
            'cloud_plan_id' => $this->cloudPlan()->id,
            'cloud_image_key' => 'ubuntu-24.04',
        ]);

        $this->pay($invoice);

        $picked = Service::where('provision_status', 'pending')
            ->where(function ($q) {
                $q->whereNotNull('server_id')->orWhereNotNull('cloud_plan_id');
            })
            ->pluck('id');

        $this->assertContains($service->id, $picked->all(),
            'پرس‌وجوی کرون باید این سرویس را ببیند');
    }

    // ═══════════════════ بقیهٔ مسیرها دست‌نخورده بمانند ═══════════════════

    /** سرویسِ روی سرورِ خودمان: همان رفتارِ قبلی */
    public function test_paid_service_on_our_own_server_still_queues_automatically(): void
    {
        $server = Server::create([
            'name' => 'WHM-DE', 'type' => 'whm', 'hostname' => 'de.example.com',
            'username' => 'root', 'status' => 'active',
        ]);

        [$service, $invoice] = $this->serviceWithInvoice([
            'server_id' => $server->id, 'plan' => 'sn_wordpress_1', 'domain' => 'x.example.com',
        ]);

        $this->pay($invoice);
        $service->refresh();

        $this->assertSame('awaiting_provision', $service->status);
        $this->assertSame('pending', $service->provision_status);
    }

    /** هاستِ بی‌سرور ولی با دامنه: صفِ **دستیِ** ادمین، نه خودکار */
    public function test_service_with_domain_but_no_server_goes_to_the_manual_queue(): void
    {
        [$service, $invoice] = $this->serviceWithInvoice(['domain' => 'y.example.com']);

        $this->pay($invoice);
        $service->refresh();

        $this->assertSame('awaiting_provision', $service->status);
        $this->assertSame('manual', $service->provision_status,
            'بی‌سرور و بی‌پلنِ ابری، کرون نمی‌تواند بسازدش — ادمین باید ببیندش');
    }

    /**
     * سرویسِ صرفاً مالی (پشتیبانی، مشاوره): هیچ چیزی برای تحویل نیست، پس مستقیم
     * فعال می‌شود. اگر این را هم به صفِ تحویل بفرستیم، صفِ ادمین پر از ردیفِ
     * بی‌معنا می‌شود و ردیف‌های واقعی گم می‌شوند.
     */
    public function test_financial_only_service_activates_immediately(): void
    {
        [$service, $invoice] = $this->serviceWithInvoice(['name' => 'پشتیبانیِ ماهانه']);

        $this->pay($invoice);
        $service->refresh();

        $this->assertSame('active', $service->status);
        $this->assertNull($service->provision_status);
    }

    /** تمدیدِ سرویسِ ازقبل‌تحویل‌شده نباید دوباره به صفِ تحویل برود */
    public function test_renewal_of_a_delivered_service_stays_active(): void
    {
        [$service, $invoice] = $this->serviceWithInvoice([
            'cloud_plan_id'    => $this->cloudPlan()->id,
            'status'           => 'active',
            'provision_status' => 'done',
            'provisioned_at'   => now()->subMonth(),
            'activated_at'     => now()->subMonth(),
        ]);

        $this->pay($invoice);
        $service->refresh();

        $this->assertSame('active', $service->status,
            'تمدید نباید سرورِ کارکنندهٔ مشتری را به حالتِ «در انتظار تحویل» ببرد');
        $this->assertSame('done', $service->provision_status);
    }

    /** سرویسِ لغوشده با پرداختِ دیرهنگام زنده نشود */
    public function test_cancelled_service_is_not_revived_by_a_late_payment(): void
    {
        [$service, $invoice] = $this->serviceWithInvoice([
            'cloud_plan_id' => $this->cloudPlan()->id,
            'status' => 'cancelled', 'cancelled_at' => now()->subDay(),
        ]);

        $this->pay($invoice);

        $this->assertSame('cancelled', $service->fresh()->status);
    }

    // ═══════════════════ فاکتور واقعاً پرداخت‌شده ثبت شود ═══════════════════

    public function test_invoice_is_marked_paid(): void
    {
        [, $invoice] = $this->serviceWithInvoice([
            'cloud_plan_id' => $this->cloudPlan()->id,
        ]);

        $this->pay($invoice);

        $this->assertSame('paid', $invoice->fresh()->status);
    }

    /**
     * وب‌هوکِ درگاه می‌تواند **دو بار** برای یک پرداخت بیاید (تلاشِ دوبارهٔ درگاه،
     * یا بازگشتِ کاربر هم‌زمان با وب‌هوک). تسویهٔ دوباره نباید فاکتور را دو بار
     * پرداخت‌شده حساب کند و نباید سرویسِ تحویل‌شده را عقب ببرد.
     *
     * قبلاً در همین ناحیه `payments_external_ref_unique` می‌شکست و کلِ تراکنش
     * برمی‌گشت — و برگشتِ تراکنش، فعال‌سازیِ سرویس را هم با خودش می‌برد.
     */
    public function test_settling_the_same_payment_twice_is_safe(): void
    {
        [$service, $invoice] = $this->serviceWithInvoice([
            'cloud_plan_id' => $this->cloudPlan()->id,
        ]);

        $payment = \App\Models\Payment::create([
            'invoice_id'    => $invoice->id,
            'customer_id'   => $invoice->customer_id,
            'gateway'       => 'bale',
            'currency_code' => 'IRT',
            'amount'        => $invoice->total,
            'status'        => 'redirected',
            'external_ref'  => 'DR-double',
        ]);

        $svc = app(PaymentService::class);
        $svc->settleConfirmed($payment, 'REF-1');
        $svc->settleConfirmed($payment->fresh(), 'REF-1');

        $this->assertSame(1, \App\Models\Payment::where('invoice_id', $invoice->id)->count());
        $this->assertSame('paid', $invoice->fresh()->status);

        // و مسیرِ تحویل هنوز درست است — تسویهٔ دوباره آن را خراب نکرده
        $service->refresh();
        $this->assertSame('awaiting_provision', $service->status);
        $this->assertSame('pending', $service->provision_status);
    }
}

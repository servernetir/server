<?php

namespace Tests\Feature;

use App\Models\CloudImage;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Cloud\CloudProvisioner;
use App\Support\ErrorTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * 🔴 سرویسِ ابریِ `provision_status='failed'` — آیا اصلاً راهِ بازگشتی داشت؟
 *
 * سرویس‌های ۶ و ۱۳ روی پروداکشن با «تحویلِ سرور ناموفق: Proxy internal server
 * error (see traceId)» نشسته‌اند. سه چیز هم‌زمان درست بود:
 *
 *   • کرون عمداً `failed` را برنمی‌دارد (نقطهٔ شکست می‌تواند **بعد** از خریدِ
 *     واقعیِ ماشین باشد؛ تلاشِ کورِ خودکار یعنی خریدِ دوم).
 *   • روتِ «تلاشِ دوباره»ی مدیر `failed` را می‌پذیرد و درست کار می‌کند.
 *   • ولی **هیچ دکمه‌ای در هیچ صفحه‌ای آن روت را صدا نمی‌زد** — گیتِ ویو
 *     `server_id || domain` بود و سرورِ ابری هیچ‌کدام را ندارد.
 *
 * یعنی راهِ بازیابی از نظرِ کد وجود داشت و از نظرِ محصول وجود نداشت. این فایل
 * هر سه ادعا را قفل می‌کند، از جمله اینکه قاعدهٔ «کرون هرگز failed را برندارد»
 * **عمدی** است و باید بمانَد.
 */
class CloudFailedRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private int $orders = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put('pricing_rate_override', '100000');
        Setting::putSecret('aeza_api_token', 'aeza-key');

        Sleep::fake();
        Mail::fake();
        ErrorTracker::clear();
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'مدیر', 'email' => 'r'.random_int(1, 999999).'@x.com',
            'password' => bcrypt('secret1234'), 'role' => 'admin',
        ]);
    }

    private function plan(array $over = []): CloudPlan
    {
        CloudLocation::firstOrCreate(['code' => 'de-falkenstein'],
            ['country' => 'DE', 'city' => 'Falkenstein', 'is_active' => true]);

        CloudImage::firstOrCreate(
            ['provider' => 'aeza', 'key' => 'ubuntu-24.04', 'arch' => 'x86'],
            ['provider_ref' => 'ubuntu_2404', 'kind' => 'os', 'family' => 'ubuntu',
                'version' => '24.04', 'label' => 'Ubuntu 24.04', 'is_active' => true]
        );

        return CloudPlan::create(array_merge([
            'provider' => 'aeza', 'provider_ref' => '153',
            'provider_location' => 'fsn1', 'location_code' => 'de-falkenstein',
            'public_name' => 'CV-2-4', 'slug' => 'cv-2c-4g-40d-de-falkenstein-'.random_int(1, 99999),
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme',
            'traffic_gb' => 20480, 'cpu_kind' => 'shared', 'arch' => 'x86',
            'cost_eur_cents' => 379, 'price_eur_cents' => 570, 'price_irt' => 570000,
            'is_active' => true, 'in_stock' => true, 'admin_disabled' => false,
        ], $over));
    }

    /** سرویسی دقیقاً در وضعیتِ سرویس‌های ۶ و ۱۳ */
    private function failedService(CloudPlan $plan): Service
    {
        $c = Customer::create([
            'email' => 'f'.random_int(1, 999999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);

        // ⚠️ حسابِ قدیمی و کم‌سفارش، وگرنه محافظِ سوءاستفاده جلوی تحویل را
        //    می‌گیرد و این تست دربارهٔ بازیابی هیچ‌چیز ثابت نمی‌کند.
        $c->forceFill(['created_at' => now()->subYear()])->save();

        return Service::create([
            'customer_id' => $c->id, 'name' => 'سرورِ ابری CV-2-4', 'currency_code' => 'IRT',
            'price' => 570000, 'tax_percent' => 0, 'cycle' => 'monthly',
            'status' => 'awaiting_provision', 'provision_status' => 'failed',
            'provision_error' => 'تحویلِ سرور ناموفق: Proxy internal server error (see traceId)',
            'cloud_plan_id' => $plan->id, 'cloud_image_key' => 'ubuntu-24.04',
        ]);
    }

    private function fakeProvider(): void
    {
        $this->orders = 0;

        Http::swap(new Factory);
        Http::fake(function ($request) {
            $url = $request->url();

            if ($request->method() === 'POST' && str_contains($url, 'services/orders')) {
                $this->orders++;

                return Http::response(['data' => ['id' => 90210, 'items' => [['id' => 90210]]]], 200);
            }

            if (str_contains($url, 'password')) {
                return Http::response(['data' => ['password' => 'RootPw42!!']], 200);
            }

            if (str_contains($url, '/services')) {
                return Http::response(['data' => ['total' => 0, 'items' => []]], 200);
            }

            return Http::response(['data' => [
                'id' => 90210, 'currentStatus' => 'active', 'ip' => ['203.0.113.44'],
            ]], 200);
        });
    }

    // ═══════════════ ۱) دکمه حالا وجود دارد ═══════════════

    /**
     * ✅ رفعِ خودِ بن‌بست: صفحهٔ مشتری در پنلِ مدیریت حالا برای یک سرویسِ ابریِ
     * شکست‌خورده فرمِ «تلاش دوباره» رندر می‌کند.
     */
    public function test_a_failed_cloud_service_now_has_a_retry_button_in_the_admin_panel(): void
    {
        $s = $this->failedService($this->plan());

        $html = $this->actingAs($this->admin(), 'web')
            ->get('/admin/customers/'.$s->customer_id)
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('/admin/services/'.$s->id.'/provision"', $html);
        $this->assertStringContainsString('تلاش دوباره', $html);
    }

    /** و آن دکمه واقعاً تحویل می‌دهد */
    public function test_the_admin_retry_recovers_a_failed_cloud_service(): void
    {
        $s = $this->failedService($this->plan());

        $this->fakeProvider();

        $this->actingAs($this->admin(), 'web')
            ->post('/admin/services/'.$s->id.'/provision')
            ->assertRedirect();

        $this->assertSame('done', $s->fresh()->provision_status);
        $this->assertSame(1, $this->orders);
    }

    /**
     * 🔴 و آن دکمه **نمی‌تواند** ردیفِ ابری را با ستون‌های WHM آلوده کند.
     *
     * حالا که فرم برای ردیفِ ابری هم رندر می‌شود، یک POSTِ دست‌ساز می‌توانست
     * `server_id` و نامِ پکیجِ WHM را روی سرویسِ ابری بنشاند — و از آن لحظه
     * `ProvisioningService` دیگر آن را ابری نمی‌دید و تعلیق/حذفش سراغِ WHM
     * می‌رفت. قید سمتِ **سرور** است، نه فقط در ویو.
     */
    public function test_the_retry_route_refuses_to_stamp_whm_columns_onto_a_cloud_service(): void
    {
        $s = $this->failedService($this->plan());

        $server = \App\Models\Server::create([
            'name' => 'whm-1', 'hostname' => 'whm1.example.com', 'type' => 'whm',
            'username' => 'root', 'is_active' => true,
        ]);

        $this->fakeProvider();

        $this->actingAs($this->admin(), 'web')->post('/admin/services/'.$s->id.'/provision', [
            'server_id' => $server->id,
            'plan' => 'sn_evil',
            'domain' => 'evil.example.com',
        ]);

        $fresh = $s->fresh();

        $this->assertNull($fresh->server_id, 'سرورِ WHM روی ردیفِ ابری نشست');
        $this->assertNotSame('sn_evil', $fresh->plan);
        $this->assertTrue(CloudProvisioner::handles($fresh),
            'سرویس دیگر ابری شناخته نمی‌شود — یعنی مسیرِ تحویل/حذفش عوض شده');
    }

    // ═══════════════ ۲) قاعدهٔ عمدی: کرون هرگز failed را برنمی‌دارد ═══════════════

    /**
     * ⚠️ این تست **نبودِ** یک قابلیت را قفل می‌کند، و عمدی است.
     *
     * نقطهٔ شکست می‌تواند بعد از خریدِ واقعیِ ماشین باشد (تماس موفق شد، پاسخ به
     * ما نرسید). تلاشِ کورِ هر-دقیقه‌ایِ کرون روی چنین ردیفی یعنی سرورِ دوم و
     * پولِ دوم. بازیابی باید **تصمیمِ آدم** بمانَد.
     */
    public function test_the_cron_still_refuses_to_touch_a_failed_service(): void
    {
        $s = $this->failedService($this->plan());

        $this->fakeProvider();
        $this->artisan('provision:run');

        $this->assertSame('failed', $s->fresh()->provision_status,
            'کرون سرویسِ شکست‌خورده را برداشت — این همان مسیری است که دو بار می‌خرد');
        $this->assertSame(0, $this->orders);
    }

    // ═══════════════ ۳) از زیرساختِ قرنطینه‌شده دوباره سفارش نمی‌دهد ═══════════════

    /**
     * 🔴 شاخهٔ `?? $ordered` قرنطینه را دور می‌زد.
     *
     * هر دو انتخاب‌کنندهٔ پلن `sellable()` می‌زنند و ردیفِ `admin_disabled` را
     * کنار می‌گذارند، ولی وقتی هیچ‌کدام چیزی پیدا نکنند همان ردیفِ سفارش‌شده
     * برمی‌گشت — یعنی «تلاشِ دوباره» دقیقاً از زیرساختی سفارش می‌داد که به‌خاطرِ
     * همین سفارش بسته شده بود.
     */
    public function test_a_retry_never_reorders_from_an_auto_quarantined_provider(): void
    {
        $plan = $this->plan([
            'admin_disabled' => true,
            'admin_note' => CloudProvisioner::QUARANTINE_PREFIX.' زیرساخت سفارش را نپذیرفت',
        ]);

        $s = $this->failedService($plan);

        $this->fakeProvider();

        $this->actingAs($this->admin(), 'web')->post('/admin/services/'.$s->id.'/provision');

        $this->assertSame(0, $this->orders,
            'از زیرساختِ قرنطینه‌شده دوباره سفارش رفت — همان خرابی که قرنطینه برای جلوگیری‌اش هست');
        $this->assertSame('failed', $s->fresh()->provision_status);
        $this->assertStringContainsString('خودکار بسته شده', (string) $s->fresh()->provision_error,
            'مدیر باید علتِ ردشدن را ببیند، نه یک شکستِ مبهم');
    }

    /**
     * ⚠️ ولی پلنی که **مدیر آگاهانه** بسته، همچنان تحویل می‌شود.
     *
     * «بستنِ فروش» یعنی «تازه نفروش»، نه «سفارشِ پرداخت‌شده را تحویل نده». اگر
     * این دو یکی گرفته شوند، هر بستنِ دستی، سرویس‌های فروخته‌شدهٔ آن زیرساخت را
     * هم بی‌صدا زمین می‌گذارد.
     */
    public function test_a_manually_closed_plan_still_delivers_an_already_paid_order(): void
    {
        $plan = $this->plan([
            'admin_disabled' => true,
            'admin_note' => 'موقتاً بستم تا قیمت را بازبینی کنم',
        ]);

        $s = $this->failedService($plan);

        $this->fakeProvider();

        $this->actingAs($this->admin(), 'web')->post('/admin/services/'.$s->id.'/provision');

        $this->assertSame('done', $s->fresh()->provision_status);
        $this->assertSame(1, $this->orders);
    }
}

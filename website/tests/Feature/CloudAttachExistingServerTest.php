<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use App\Services\Cloud\CloudProvider;
use App\Services\Cloud\CloudManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * اتصالِ سرورِ **ازقبل‌ساخته‌شده** نزدِ زیرساخت به یک مشتری.
 *
 * چرا لازم است: سرور را گاهی دستی می‌سازیم (سفارشِ تلفنی، پولِ کارت‌به‌کارت).
 * تا وقتی در سامانه ثبت نشود، مشتری در پنلش هیچ نمی‌بیند و — مهم‌تر —
 * **سررسیدِ تمدید ندارد**، پس کرونِ صورت‌حساب هرگز فاکتورش نمی‌کند و ماهِ بعد
 * بی‌آنکه کسی بفهمد رایگان می‌شود.
 */
class CloudAttachExistingServerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    /** زیرساختِ ساختگی: شناسهٔ ۹۹۹ را می‌شناسد، بقیه را نه */
    private function fakeProvider(): void
    {
        $driver = new class implements CloudProvider {
            public function slug(): string { return 'aeza'; }
            public function isConfigured(): bool { return true; }
            public function capabilities(): array { return []; }
            public function testConnection(): array { return ['ok' => true, 'message' => '']; }
            public function fetchCatalog(): array { return ['ok' => true, 'message' => '', 'locations' => [], 'plans' => [], 'images' => []]; }
            public function createServer(array $spec): array { return ['ok' => false, 'message' => 'نباید صدا زده شود']; }
            public function power(string $r, string $a): array { return ['ok' => true, 'message' => '']; }
            public function rebuild(string $r, string $i, ?string $p = null): array { return ['ok' => true, 'message' => '']; }
            public function resetPassword(string $r): array { return ['ok' => true, 'message' => '']; }
            public function console(string $r): array { return ['ok' => true, 'message' => '']; }
            public function metrics(string $r, string $w = '24h'): array { return ['ok' => true, 'message' => '']; }
            public function resize(string $r, string $p, bool $u = true): array { return ['ok' => true, 'message' => '']; }
            public function deleteServer(string $r): array { return ['ok' => true, 'message' => '']; }
            public function uploadSshKey(string $n, string $k): array { return ['ok' => true, 'message' => '']; }
            public function addExtraIps(string $r, int $c): array { return ['ok' => true, 'message' => '']; }
            public function listServers(): array { return ['ok' => true, 'message' => '', 'servers' => []]; }

            public function serverStatus(string $ref): array
            {
                return $ref === '999'
                    ? ['ok' => true, 'message' => '', 'status' => 'running', 'ipv4' => '198.51.100.9', 'ipv6' => null]
                    : ['ok' => false, 'message' => 'not found', 'status' => 'unknown', 'ipv4' => null, 'ipv6' => null];
            }
        };

        $this->app->instance(CloudManager::class, new class($driver) extends CloudManager {
            public function __construct(private $d) {}
            public function driver(string $provider): ?CloudProvider { return $this->d; }
            public function label(?string $p): string { return 'زیرساخت ۲'; }
        });
    }

    private function admin(): User
    {
        return User::create(['name' => 'مدیر', 'email' => 'a'.random_int(1, 99999).'@x.com',
            'password' => bcrypt('x'), 'role' => 'admin']);
    }

    private function customer(): Customer
    {
        return Customer::create(['email' => 'c'.random_int(1, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa']);
    }

    private function plan(): CloudPlan
    {
        CloudLocation::firstOrCreate(['code' => 'de-frankfurt'],
            ['country' => 'DE', 'city' => 'Frankfurt', 'is_active' => true]);

        return CloudPlan::create([
            'provider' => 'aeza', 'provider_ref' => 'eps-1',
            'provider_location' => 'fra', 'location_code' => 'de-frankfurt',
            'public_name' => 'CV-2-4', 'slug' => 'cv-2c-4g-40d-de-frankfurt',
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme',
            'traffic_gb' => 20480, 'cpu_kind' => 'shared', 'arch' => 'x86',
            'cost_eur_cents' => 400, 'price_eur_cents' => 600, 'price_irt' => 600000,
            'is_active' => true, 'in_stock' => true,
        ]);
    }

    private function payload(Customer $c, CloudPlan $p, array $over = []): array
    {
        return array_merge([
            'customer_id' => $c->id, 'cloud_plan_id' => $p->id, 'provider_ref' => '999',
            'name' => 'سرور مجازی امیر', 'price' => 900000, 'cycle' => 'monthly',
            'activated_at' => now()->subMonth()->toDateString(),
            'next_due_at'  => now()->addMonth()->toDateString(),
        ], $over);
    }

    /** ✅ مسیرِ اصلی: سرویسِ فعال + نمونهٔ ابری + سررسیدِ درست */
    public function test_attaching_creates_an_active_service_with_a_due_date(): void
    {
        $this->fakeProvider();
        $c = $this->customer();
        $p = $this->plan();

        $this->actingAs($this->admin())
            ->post('/admin/cloud/attach', $this->payload($c, $p))
            ->assertRedirect();

        $s = Service::where('customer_id', $c->id)->firstOrFail();

        $this->assertSame('active', $s->status, 'پول از قبل گرفته شده، پس فعال است نه منتظرِ پرداخت');
        $this->assertSame('done', $s->provision_status);
        $this->assertSame($p->id, $s->cloud_plan_id, 'بی‌این، پنلِ مشتری آن را سرورِ ابری نمی‌شناسد');
        $this->assertSame(900000, (int) $s->price);

        // 🔴 سررسید: دقیقاً همان چیزی که مدیر وارد کرده — بی‌این، کرونِ تمدید
        // هرگز فاکتور نمی‌سازد و ماهِ بعد سرویس بی‌سروصدا رایگان می‌شود.
        $this->assertNotNull($s->next_due_at);
        $this->assertSame(now()->addMonth()->toDateString(), $s->next_due_at->toDateString());
    }

    public function test_it_records_the_real_server_and_its_ip(): void
    {
        $this->fakeProvider();
        $c = $this->customer();
        $p = $this->plan();

        $this->actingAs($this->admin())->post('/admin/cloud/attach', $this->payload($c, $p));

        $inst = CloudInstance::firstOrFail();
        $this->assertSame('999', $inst->provider_ref);
        $this->assertSame('aeza', $inst->provider);
        $this->assertSame('198.51.100.9', $inst->ipv4, 'IP باید از خودِ زیرساخت خوانده شود');
        $this->assertTrue((bool) $inst->password_seen, 'رمزِ root را نداریم؛ نباید وعده داده شود');
    }

    /**
     * 🔴 سررسیدِ گذشته باید رد شود.
     *
     * کاربردِ اصلیِ این صفحه ثبتِ سروری است که هفته‌ها پیش تحویل شده. اگر سررسید
     * در گذشته بیفتد، ۰۷:۰۰ کرونِ `services:renew-due` فاکتور صادر می‌کند و
     * ۰۷:۳۰ همان صبح `services:lifecycle` همان فاکتورِ پرداخت‌نشده را می‌بیند و
     * سرورِ زندهٔ مشتری را واقعاً خاموش می‌کند — نیم‌ساعت بعد از اتصال.
     */
    public function test_a_past_due_date_is_refused(): void
    {
        $this->fakeProvider();
        $c = $this->customer();
        $p = $this->plan();

        $this->actingAs($this->admin())
            ->post('/admin/cloud/attach', $this->payload($c, $p, [
                'next_due_at' => now()->subDay()->toDateString(),
            ]))
            ->assertSessionHasErrors('next_due_at');

        $this->assertSame(0, Service::count(), 'سرویسِ سررسیدگذشته اصلاً نباید ساخته شود');
        $this->assertSame(0, CloudInstance::count());
    }

    public function test_today_is_also_refused(): void
    {
        $this->fakeProvider();

        $this->actingAs($this->admin())
            ->post('/admin/cloud/attach', $this->payload($this->customer(), $this->plan(), [
                'next_due_at' => now()->toDateString(),
            ]))
            ->assertSessionHasErrors('next_due_at');

        $this->assertSame(0, Service::count());
    }

    /**
     * 🔴 سرویسِ تازه‌متصل نباید فردا صبح فاکتور و تعلیق بخورد.
     *
     * این تست دو کرونِ واقعی را پشتِ سر هم می‌دواند — همان ترتیبی که روی سرور
     * اجرا می‌شوند. تستِ صرفاً «سررسید درست ذخیره شد» این را نمی‌گرفت.
     */
    public function test_an_attached_service_survives_both_billing_crons(): void
    {
        $this->fakeProvider();
        $c = $this->customer();
        $p = $this->plan();

        $this->actingAs($this->admin())->post('/admin/cloud/attach', $this->payload($c, $p));

        $s = Service::firstOrFail();
        $this->assertSame('active', $s->status);

        $this->artisan('services:renew-due');
        $this->artisan('services:lifecycle');

        $this->assertSame('active', $s->fresh()->status, 'کرون نباید سرویسِ تازه‌متصل را معلق کند');
        $this->assertSame(0, \App\Models\Invoice::where('customer_id', $c->id)->count(),
            'سررسید یک ماه دیگر است؛ هنوز نباید فاکتوری صادر شده باشد');
    }
    /**
     * 🔴 «فردا» هنوز داخلِ بازهٔ خطر است.
     *
     * `services:renew-due` با `--days=5` می‌دود، پس هر سررسیدی تا ۵ روزِ آینده
     * همان اجرا فاکتور می‌شود و بعد `services:lifecycle` بابتِ همان فاکتورِ
     * پرداخت‌نشده سرور را خاموش می‌کند. مرزِ `after:today` این را نمی‌گرفت و
     * متنِ راهنمای فرم مدیر را دقیقاً به همین بازه هدایت می‌کرد.
     */
    public function test_a_due_date_inside_the_invoicing_window_is_refused(): void
    {
        $this->fakeProvider();
        $plan = $this->plan();          // یک بار — پلن روی (provider, ref, location) یکتاست

        foreach ([1, 3, 5] as $days) {
            $this->actingAs($this->admin())
                ->post('/admin/cloud/attach', $this->payload($this->customer(), $plan, [
                    'next_due_at' => now()->addDays($days)->toDateString(),
                ]))
                ->assertSessionHasErrors('next_due_at');
        }

        $this->assertSame(0, Service::count());
    }

    public function test_a_due_date_past_the_window_is_accepted(): void
    {
        $this->fakeProvider();

        $this->actingAs($this->admin())
            ->post('/admin/cloud/attach', $this->payload($this->customer(), $this->plan(), [
                'next_due_at' => now()->addDays(6)->toDateString(),
            ]))
            ->assertRedirect();

        $this->assertSame(1, Service::count());
    }

    /** 🔴 پیامِ خطای سفارشی باید واقعاً دیده شود، نه پیامِ عمومیِ لاراول */
    public function test_the_custom_error_message_is_shown(): void
    {
        $this->fakeProvider();

        $res = $this->actingAs($this->admin())
            ->post('/admin/cloud/attach', $this->payload($this->customer(), $this->plan(), [
                'next_due_at' => now()->addDay()->toDateString(),
            ]))
            ->assertSessionHasErrors('next_due_at');

        $msg = (string) session('errors')->first('next_due_at');

        // پیامِ عمومیِ لاراول («باید تاریخی پس از … باشد») چیزی به مدیر
        // نمی‌گوید؛ او باید بداند **چرا** و چه بلایی سرِ سرور می‌آید.
        $this->assertStringContainsString('خاموش', $msg);
        $this->assertStringContainsString('فاکتور', $msg);
    }

    /**
     * 🔴 دورهٔ «یک‌بار» باید رد شود.
     *
     * هر دو کرونِ صورت‌حساب روی `config/billing.cycles` فیلتر می‌کنند و 'once'
     * جزوشان نیست، پس سرویسی که با آن ثبت شود هرگز فاکتور نمی‌شود — همان
     * «رایگان‌شدنِ بی‌صدا» که این صفحه برای جلوگیری از آن ساخته شد.
     */
    public function test_the_once_cycle_is_refused(): void
    {
        $this->fakeProvider();

        $this->actingAs($this->admin())
            ->post('/admin/cloud/attach', $this->payload($this->customer(), $this->plan(), [
                'cycle' => 'once',
            ]))
            ->assertSessionHasErrors('cycle');

        $this->assertSame(0, Service::count());
    }
    /** 🔴 شناسهٔ اشتباه نباید سرویسِ بی‌سرور بسازد */
    public function test_unknown_server_ref_is_rejected(): void
    {
        $this->fakeProvider();
        $c = $this->customer();
        $p = $this->plan();

        $this->actingAs($this->admin())
            ->post('/admin/cloud/attach', $this->payload($c, $p, ['provider_ref' => 'typo']))
            ->assertSessionHasErrors();

        $this->assertSame(0, Service::count());
        $this->assertSame(0, CloudInstance::count());
    }

    /** 🔴 یک سرور نباید هم‌زمان مالِ دو مشتری شود */
    public function test_a_server_cannot_be_attached_twice(): void
    {
        $this->fakeProvider();
        $p = $this->plan();

        $this->actingAs($this->admin())->post('/admin/cloud/attach', $this->payload($this->customer(), $p));
        $this->assertSame(1, CloudInstance::count());

        $this->actingAs($this->admin())
            ->post('/admin/cloud/attach', $this->payload($this->customer(), $p))
            ->assertSessionHasErrors();

        $this->assertSame(1, CloudInstance::count(), 'نمونهٔ دوم نباید ساخته شود');
        $this->assertSame(1, Service::count());
    }

    /** سرویسِ متصل‌شده باید در پنلِ خودِ مشتری دیده شود */
    public function test_the_customer_sees_it_in_their_panel(): void
    {
        $this->fakeProvider();
        $c = $this->customer();
        $p = $this->plan();

        $this->actingAs($this->admin())->post('/admin/cloud/attach', $this->payload($c, $p));

        $html = $this->actingAs($c, 'customer')
            ->get(route('account.services', [], false))->assertOk()->getContent();

        $this->assertStringContainsString('سرور مجازی امیر', $html);
        $this->assertStringContainsString('198.51.100.9', $html, 'IP باید به مشتری نشان داده شود');
    }

    /** فرم باید بالا بیاید و کلیدِ خام چاپ نکند */
    public function test_form_renders(): void
    {
        $this->fakeProvider();
        $this->plan();

        $html = $this->actingAs($this->admin())->get('/admin/cloud/attach')->assertOk()->getContent();

        $this->assertStringContainsString('اتصال سرور موجود به مشتری', $html);
        $this->assertStringContainsString('ad-input', $html, 'کلاسِ واقعیِ admin.css باید استفاده شود');
    }
}

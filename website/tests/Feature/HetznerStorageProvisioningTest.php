<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Server;
use App\Models\Service;
use App\Services\Provisioning\HetznerStorageProvisioner;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * تحویلِ فضای بکاپ/دانلود روی Storage Boxِ هتزنر.
 *
 * ادعاها روی **بدنه و آدرسِ درخواستی که واقعاً به هتزنر می‌رود** هستند، نه روی
 * «تحویل موفق بود». درسِ ثبت‌شدهٔ §۱۰٫۶: در هر دو حالت تحویل موفق است و تفاوت
 * دقیقاً همان‌جاست که تستِ سطحی نمی‌بیندش.
 *
 * ⚠️ هر تست فقط **یک بار** `Http::fake()` می‌زند — استابِ `'*'` همه‌گیر، هر
 * fakeِ بعدی را بی‌صدا بی‌اثر می‌کند (§۸).
 */
class HetznerStorageProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private function server(): Server
    {
        return Server::create([
            'name' => 'فضای بکاپ هتزنر', 'type' => 'hetzner_storage',
            'hostname' => 'api.hetzner.com', 'username' => '', 'api_token' => 'hz-token',
            'status' => 'active', 'verify_tls' => true,
        ]);
    }

    private function service(Server $server, array $over = []): Service
    {
        $c = Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'b'.random_int(1000, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);

        return Service::create(array_merge([
            'customer_id' => $c->id, 'server_id' => $server->id,
            'name' => 'هاست بکاپ — BK-500', 'plan' => 'sn_backup_2',
            'currency_code' => 'IRT', 'price' => 530000, 'cycle' => 'monthly',
            'status' => 'active', 'provision_status' => 'pending',
        ], $over));
    }

    private function mapPlan(string $plan = 'sn_backup_2', string $type = 'bx11'): void
    {
        config(['provisioning.hetzner_storage.plans' => [$plan => $type]]);
    }

    private function box(array $over = []): array
    {
        return array_merge([
            'id' => 4711, 'name' => 'sn-svc-1', 'status' => 'active',
            'username' => 'u123456', 'server' => 'u123456.your-storagebox.de',
            'location' => ['name' => 'fsn1'],
            'storage_box_type' => ['name' => 'bx11'],
        ], $over);
    }

    /* ────────────── توکن: همان توکنِ سرورِ ابری کار می‌کند ────────────── */

    /**
     * اسپکِ رسمی می‌گوید توکن از «Console → Project → Security → API Tokens»
     * ساخته می‌شود — همان جایی که `hetzner_api_token` از آن آمده. پس ردیفِ
     * سرور لازم نیست نسخهٔ دومِ همان راز را نگه دارد.
     */
    public function test_it_falls_back_to_the_cloud_token_in_settings(): void
    {
        \App\Models\Setting::putSecret('hetzner_api_token', 'cloud-token');

        $server = Server::create([
            'name' => 'فضای بکاپ', 'type' => 'hetzner_storage',
            'hostname' => 'api.hetzner.com', 'username' => '', 'api_token' => null,
            'status' => 'active', 'verify_tls' => true,
        ]);

        Http::fake(['api.hetzner.com/*' => Http::response(['storage_box_types' => []], 200)]);

        (new \App\Services\Provisioning\HetznerStorageClient($server))->types();

        Http::assertSent(fn ($req) => $req->hasHeader('Authorization', 'Bearer cloud-token'));
    }

    public function test_without_any_token_it_says_so_instead_of_calling_and_getting_a_401(): void
    {
        $server = Server::create([
            'name' => 'فضای بکاپ', 'type' => 'hetzner_storage',
            'hostname' => 'api.hetzner.com', 'username' => '', 'api_token' => null,
            'status' => 'active', 'verify_tls' => true,
        ]);

        Http::fake();

        $r = (new \App\Services\Provisioning\HetznerStorageClient($server))->types();

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('ثبت نشده', $r['reason'],
            '۴۰۱ِ هتزنر آدم را دنبالِ توکنِ باطل می‌فرستد، نه دنبالِ توکنِ نبود.');
        Http::assertNothingSent();
    }

    /* ─────────────── اقتصادِ نگاشت: حذف‌های عمدی ─────────────── */

    /**
     * 🔴 کوچک‌ترین باکسِ هتزنر ۱ ترابایت است (bx11, 3.20 €).
     *
     * BK-100 و BK-500 ارزان‌تر از بهای همان باکس فروخته می‌شوند، پس نگاشتشان
     * یعنی ضرر روی **هر** مشتری و **هر** ماه — و چون تحویل موفق است، هیچ خطایی
     * هیچ‌جا ثبت نمی‌شود. نبودشان در نگاشت یک تصمیم است، نه فراموشی.
     *
     * و پلن‌های دانلود اصلاً قابلِ تحویل نیستند: Storage Box دانلودِ عمومیِ
     * HTTP ندارد (همه‌چیزش پشتِ احراز هویت است) در حالی که صفحهٔ محصول «لینک
     * مستقیم دانلود» وعده می‌دهد — عیناً همان خرابیِ S3.
     */
    public function test_the_plans_that_cannot_be_delivered_profitably_stay_unmapped(): void
    {
        $map = (array) config("provisioning.hetzner_storage.plans");

        foreach (["sn_backup_1", "sn_backup_2"] as $plan) {
            $this->assertArrayNotHasKey($plan, $map,
                "«{$plan}» زیرِ بهای کوچک‌ترین باکسِ هتزنر فروخته می‌شود — نگاشتش یعنی ضررِ ماهانه روی هر مشتری.");
        }

        foreach (array_keys($map) as $plan) {
            $this->assertStringStartsNotWith("sn_download", $plan,
                "Storage Box دانلودِ عمومیِ HTTP ندارد؛ نگاشتِ پلنِ دانلود همان وعدهٔ بی‌پشتوانهٔ S3 را تکرار می‌کند.");
        }
    }

    /* ───────────────────────── ساختِ درست ───────────────────────── */

    public function test_it_creates_a_box_with_the_deterministic_name_on_the_right_host(): void
    {
        $this->mapPlan();
        $server = $this->server();
        $service = $this->service($server);

        Http::fake([
            'api.hetzner.com/v1/storage_boxes?*' => Http::response(['storage_boxes' => []], 200),
            'api.hetzner.com/v1/storage_boxes'   => Http::response(['storage_box' => $this->box(['name' => 'sn-svc-'.$service->id])], 201),
        ]);

        $r = (new HetznerStorageProvisioner())->create($service);

        $this->assertTrue($r->ok, 'تحویل باید موفق باشد: '.$r->error);
        $this->assertSame('u123456', $r->username);
        $this->assertNotEmpty($r->password);

        Http::assertSent(function ($req) use ($service) {
            if ($req->method() !== 'POST') {
                return false;
            }

            $d = $req->data();

            return str_starts_with($req->url(), 'https://api.hetzner.com/v1/storage_boxes')
                && $req->hasHeader('Authorization', 'Bearer hz-token')
                // نامِ قطعی — تنها چیزی که تلاشِ دوباره را از خریدِ دوباره جدا می‌کند
                && ($d['name'] ?? null) === 'sn-svc-'.$service->id
                && ($d['storage_box_type'] ?? null) === 'bx11'
                && ($d['location'] ?? null) === 'fsn1'
                && filled($d['password'] ?? null)
                // بی‌این، باکس فقط از داخلِ شبکهٔ هتزنر دیده می‌شود
                && ($d['access_settings']['reachable_externally'] ?? null) === true;
        });
    }

    /* ─────────────── «دو بار نخر» — سه محافظ، هرکدام جدا ─────────────── */

    public function test_an_existing_box_with_the_same_name_is_adopted_instead_of_bought_again(): void
    {
        $this->mapPlan();
        $server = $this->server();
        $service = $this->service($server);

        Http::fake(function ($req) use ($service) {
            if ($req->method() === 'GET') {
                return Http::response(['storage_boxes' => [$this->box(['name' => 'sn-svc-'.$service->id])]], 200);
            }

            return Http::response(['action' => ['status' => 'success']], 200);
        });

        $r = (new HetznerStorageProvisioner())->create($service);

        $this->assertTrue($r->ok);
        $this->assertTrue($r->meta['reused'] ?? false);

        /*
        | ادعا دقیقاً «باکسِ دوم خریده نشد» است، نه «هیچ POSTی نرفت».
        | پذیرشِ باکسی که رمزش دستِ ما نیست، عمداً `reset_password` می‌زند —
        | آن POST لازم است، وگرنه مشتری باکسی دارد که هیچ‌کس رمزش را ندارد.
        */
        Http::assertNotSent(fn ($req) => $req->method() === 'POST'
            && rtrim(parse_url($req->url(), PHP_URL_PATH) ?: '', '/') === '/v1/storage_boxes');

        $this->assertNotEmpty($r->password, 'رمزِ بازیابی‌شده باید به مشتری برسد.');
    }

    /**
     * 🔴 «نپرسیدیم» هرگز «نیست» خوانده نمی‌شود.
     *
     * برخلافِ WHM که نامِ تکراری را خودش رد می‌کند، هتزنر باکسِ دوم را
     * می‌سازد و پولش را می‌گیرد. پس اگر نتوانستیم بپرسیم، **نمی‌سازیم**.
     */
    public function test_it_refuses_to_create_when_the_existence_check_could_not_be_answered(): void
    {
        $this->mapPlan();
        $server = $this->server();
        $service = $this->service($server);

        Http::fake(fn () => throw new ConnectionException('timeout'));

        $r = (new HetznerStorageProvisioner())->create($service);

        $this->assertFalse($r->ok);
        Http::assertNotSent(fn ($req) => $req->method() === 'POST');
    }

    /**
     * 🔴 تایم‌اوتِ وسطِ ساخت = ممکن است باکس ساخته شده باشد.
     *
     * همان رخدادِ zhina.shop: «نشنیدیم» را «نه گفت» خواندن یعنی به مشتری
     * می‌گوییم تحویل نشد، در حالی که اجاره‌اش از حساب ما می‌رود.
     */
    public function test_a_timeout_during_create_is_resolved_by_asking_again(): void
    {
        $this->mapPlan();
        $server = $this->server();
        $service = $this->service($server);
        $name = 'sn-svc-'.$service->id;

        $listCall = 0;

        Http::fake(function ($req) use ($name, &$listCall) {
            if ($req->method() === 'POST') {
                throw new ConnectionException('timeout');
            }

            $listCall++;

            // اولین پرسش: نیست. بعد از تایم‌اوت: هست.
            return Http::response([
                'storage_boxes' => $listCall === 1 ? [] : [$this->box(['name' => $name])],
            ], 200);
        });

        $r = (new HetznerStorageProvisioner())->create($service);

        $this->assertTrue($r->ok, 'باکسی که واقعاً ساخته شده نباید «ناموفق» گزارش شود.');
        $this->assertSame('u123456', $r->username);
        $this->assertNotEmpty($r->password, 'رمزِ همان تلاش باید نگه داشته شود، وگرنه مشتری راهی به باکسش ندارد.');
    }

    /* ───────────────────── نگاشتِ نبود ⇒ دستی، نه پیش‌فرض ───────────────────── */

    public function test_an_unmapped_plan_goes_to_the_manual_queue_and_never_guesses_a_type(): void
    {
        config(['provisioning.hetzner_storage.plans' => []]);
        $server = $this->server();
        $service = $this->service($server, ['plan' => 'sn_backup_9']);

        Http::fake();

        $r = (new HetznerStorageProvisioner())->create($service);

        $this->assertFalse($r->ok);
        $this->assertTrue($r->manual, 'نگاشتِ نبود باید به صفِ دستی برود، نه به یک نوعِ حدسی.');
        Http::assertNothingSent();
    }

    /* ───────────────────────── چرخهٔ عمر ───────────────────────── */

    public function test_suspend_closes_external_access_instead_of_deleting_data(): void
    {
        $server = $this->server();
        $service = $this->service($server, ['provision_meta' => ['hetzner_box_id' => 4711]]);

        Http::fake(['api.hetzner.com/*' => Http::response(['action' => ['status' => 'success']], 200)]);

        $r = (new HetznerStorageProvisioner())->suspend($service);

        $this->assertTrue($r->ok);

        Http::assertSent(function ($req) {
            return $req->method() === 'POST'
                && str_contains($req->url(), '/storage_boxes/4711/actions/update_access_settings')
                && ($req->data()['reachable_externally'] ?? null) === false;
        });

        // تعلیق نباید هیچ‌وقت DELETE بزند — دادهٔ مشتریِ بدهکار پاک نمی‌شود
        Http::assertNotSent(fn ($req) => $req->method() === 'DELETE');
    }

    public function test_deleting_a_box_that_is_already_gone_counts_as_success(): void
    {
        $server = $this->server();
        $service = $this->service($server, ['provision_meta' => ['hetzner_box_id' => 4711]]);

        Http::fake(['api.hetzner.com/*' => Http::response(['error' => ['code' => 'not_found', 'message' => 'nope']], 404)]);

        $r = (new HetznerStorageProvisioner())->terminate($service);

        $this->assertTrue($r->ok, 'حذفِ چیزی که نیست باید موفق باشد، وگرنه تا ابد در صفِ تلاشِ دوباره می‌مانَد.');
    }

    /* ─────────── تلهٔ `default => WhmProvisioner` در رجیستری ─────────── */

    public function test_the_registry_routes_this_server_type_to_its_own_driver(): void
    {
        $server = $this->server();

        $driver = app(ProvisioningService::class)->driverFor($server);

        $this->assertInstanceOf(HetznerStorageProvisioner::class, $driver,
            'نوعِ تازه بدونِ case در driverFor() بی‌صدا به WHM می‌رود و تحویلش شکست می‌خورد.');
        $this->assertSame('hetzner_storage', $driver->slug());
    }

    /**
     * فهرستِ نوع‌ها در فرمِ `/admin/servers` دستی است و با `Server::TYPES`
     * همگام نمی‌مانَد مگر کسی حواسش باشد. نوعی که در فرم نباشد قابلِ انتخاب
     * نیست — یعنی قابلیتی که ساخته شده ولی از پنل به آن نمی‌شود رسید.
     */
    public function test_every_server_type_is_selectable_in_the_admin_form(): void
    {
        $blade = file_get_contents(resource_path('views/admin/partials/server-form.blade.php'));

        foreach (Server::TYPES as $type) {
            $this->assertStringContainsString("'".$type."'=>", str_replace(' ', '', $blade),
                "نوعِ «{$type}» در فهرستِ فرمِ سرور نیست و در پنل انتخاب نمی‌شود.");
        }
    }
}

<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Services\Provisioning\ProvisioningService;
use App\Services\Provisioning\ResellerLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * تحویلِ **نمایندگی** — آنچه پیش از این اصلاً وجود نداشت.
 *
 * ═══ خرابی‌ای که این تست‌ها می‌بندند ═══
 *
 * صفحاتِ `/hosting/reseller-*` ماه‌ها پکیجِ نمایندگی می‌فروختند، ولی
 * `WhmProvisioner` همان `createacct`ِ حسابِ عادی را می‌فرستاد — بی‌`reseller=1`،
 * بی‌ACL، بی‌سقف. یعنی مشتری پولِ «پنل نمایندگی» می‌داد و یک cPanelِ ساده
 * می‌گرفت. **هیچ خطایی هیچ‌جا ثبت نمی‌شد**؛ تحویل «موفق» بود و رمز هم ایمیل
 * می‌شد. تنها راهِ فهمیدنش شکایتِ خودِ مشتری بود.
 *
 * پس این تست‌ها روی **بدنهٔ درخواستی که به WHM می‌رود** ادعا دارند، نه روی
 * «تحویل موفق بود». تحویل در هر دو حالت موفق است — تفاوت دقیقاً همان‌جاست که
 * تستِ سطحی نمی‌بیندش.
 */
class ResellerProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private function server(string $type = 'whm'): Server
    {
        return Server::create([
            'name' => 'نودِ آلمان', 'type' => $type, 'hostname' => 'node.example.com',
            'username' => 'root', 'api_token' => 'tok', 'status' => 'active',
            'verify_tls' => false, 'max_accounts' => 100, 'active_accounts' => 0,
        ]);
    }

    private function customer(): Customer
    {
        return Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'r'.random_int(1000, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    private function service(Server $server, bool $reseller, array $over = []): Service
    {
        return Service::create(array_merge([
            'customer_id' => $this->customer()->id, 'server_id' => $server->id,
            'name' => 'نمایندگی سی‌پنل', 'domain' => 'agency.ir',
            'username' => 'agencyir', 'password' => 'Secret123!',
            'plan' => 'sn_reseller_linux_1', 'is_reseller' => $reseller,
            'cycle' => 'monthly', 'price' => 990000,
            'status' => 'awaiting_provision', 'provision_status' => 'pending',
        ], $over));
    }

    /** پکیجِ نمایندگیِ RCP-25 با همان مشخصاتِ واقعیِ کاتالوگ */
    private function resellerProduct(): Product
    {
        return Product::create([
            'name' => 'نمایندگی هاست لینوکس — RCP-25', 'slug' => 'reseller-linux-1',
            'category' => 'reseller', 'group' => 'reseller-linux',
            'plan' => 'sn_reseller_linux_1', 'currency_code' => 'IRT',
            'price' => 990000, 'cycle' => 'monthly', 'tax_percent' => 10, 'is_active' => true,
            'specs' => [
                ['label' => '25 GB NVMe'],
                ['label' => '۲۵ اکانت هاست'],
                ['label' => 'پنل صورتحساب وایت‌لیبل'],
                ['label' => 'ترافیک نامحدود'],
            ],
        ]);
    }

    /** پاسخِ «حساب نیست» */
    private function noAcct(): array
    {
        return ['metadata' => ['result' => 0, 'reason' => 'Account does not exist']];
    }

    private function okResp(): array
    {
        return ['metadata' => ['result' => 1, 'reason' => 'Ok'], 'data' => []];
    }

    /**
     * فِیکی که هر درخواست را ضبط می‌کند و همه‌چیز را موفق می‌گوید — جز
     * `accountsummary`ِ پیش‌پرواز که باید «نیست» بدهد.
     *
     * ⚠️ فقط **یک** `Http::fake` در هر تست (اولین تطبیق برنده است).
     *
     * @param  array<int,\Illuminate\Http\Client\Request>  $seen
     */
    private function fakeWhm(array &$seen, bool $aclOk = true, bool $limitsOk = true): void
    {
        Http::swap(new Factory);
        Http::fake(function ($request) use (&$seen, $aclOk, $limitsOk) {
            $seen[] = $request;
            $url = $request->url();

            if (str_contains($url, 'accountsummary')) {
                return Http::response($this->noAcct());
            }
            if (str_contains($url, 'setacls')) {
                return Http::response($aclOk ? $this->okResp() : ['metadata' => ['result' => 0, 'reason' => 'ACL not found']]);
            }
            if (str_contains($url, 'setresellerlimits')) {
                return Http::response($limitsOk ? $this->okResp() : ['metadata' => ['result' => 0, 'reason' => 'bad limit']]);
            }

            return Http::response($this->okResp());
        });
    }

    /** @param array<int,\Illuminate\Http\Client\Request> $seen */
    private function urls(array $seen): string
    {
        return implode("\n", array_map(fn ($r) => $r->url(), $seen));
    }

    // ═══════════════ ۱) هستهٔ ماجرا: reseller=1 ═══════════════

    /**
     * 🔴 خودِ باگ. بی‌این پرچم، WHM یک حسابِ **کاملاً معمولی** می‌سازد و تحویل
     * هم موفق است — پس هیچ ادعای دیگری این خرابی را نمی‌گیرد.
     */
    public function test_a_reseller_package_is_created_with_the_reseller_flag(): void
    {
        config(['provisioning.reseller_acl' => 'servernet_reseller']);
        $this->resellerProduct();
        $service = $this->service($this->server(), reseller: true);

        $seen = [];
        $this->fakeWhm($seen);

        $this->assertTrue(app(ProvisioningService::class)->provision($service));

        $create = collect($seen)->first(fn ($r) => str_contains($r->url(), 'createacct'));
        $this->assertNotNull($create, 'createacct اصلاً صدا زده نشد');
        $this->assertStringContainsString('reseller=1', $create->url(),
            'پکیجِ نمایندگی بدونِ پرچمِ reseller ساخته شد — مشتری یک هاستِ ساده می‌گیرد');
    }

    /** و نیمهٔ دیگر: هاستِ معمولی **نباید** نماینده شود. */
    public function test_an_ordinary_package_never_gets_the_reseller_flag(): void
    {
        $service = $this->service($this->server(), reseller: false, over: ['plan' => 'sn_linux_1']);

        $seen = [];
        $this->fakeWhm($seen);

        $this->assertTrue(app(ProvisioningService::class)->provision($service));

        $create = collect($seen)->first(fn ($r) => str_contains($r->url(), 'createacct'));
        $this->assertStringNotContainsString('reseller=1', $create->url());

        // و هیچ‌کدام از گام‌های نمایندگی نباید اجرا شده باشد
        $this->assertStringNotContainsString('setresellerlimits', $this->urls($seen));
        $this->assertStringNotContainsString('setacls', $this->urls($seen));
    }

    // ═══════════════ ۲) ACL و سقف — دو گامِ فراموش‌شدنی ═══════════════

    /**
     * نمایندهٔ بی‌ACL وارد WHM می‌شود و **هیچ دکمه‌ای** ندارد؛ نمایندهٔ بی‌سقف
     * می‌تواند کلِ نود را پر کند. هر دو بی‌صدا هستند، پس صریح سنجیده می‌شوند.
     */
    public function test_acl_and_resource_limits_are_applied_after_the_account_is_created(): void
    {
        config(['provisioning.reseller_acl' => 'servernet_reseller']);
        $this->resellerProduct();
        $service = $this->service($this->server(), reseller: true);

        $seen = [];
        $this->fakeWhm($seen);

        app(ProvisioningService::class)->provision($service);

        $acl = collect($seen)->first(fn ($r) => str_contains($r->url(), 'setacls'));
        $this->assertNotNull($acl, 'ACL نماینده اصلاً ست نشد');
        $this->assertStringContainsString('acllist=servernet_reseller', $acl->url());

        $lim = collect($seen)->first(fn ($r) => str_contains($r->url(), 'setresellerlimits'));
        $this->assertNotNull($lim, 'سقفِ منابعِ نماینده اصلاً ست نشد');
        // ۲۵ اکانت و ۲۵ گیگ از مشخصاتِ فارسیِ همان پکیج درآمده‌اند
        $this->assertStringContainsString('account_limit=25', $lim->url());
        $this->assertStringContainsString('enable_account_limit=1', $lim->url());
        $this->assertStringContainsString('diskspace_limit=25600', $lim->url());
    }

    /**
     * 🔴 شکستِ ACL نباید تحویل را بخواباند.
     *
     * در آن لحظه حساب روی سرور **ساخته شده** و رمزش دستِ ماست. اگر «ناموفق»
     * بگوییم، مشتری لغو می‌کند و پولش را پس می‌گیرد در حالی که حسابش زنده
     * است — همان الگوی zhina.shop یک گام جلوتر.
     */
    public function test_a_failed_acl_step_does_not_fail_a_delivery_that_already_created_the_account(): void
    {
        config(['provisioning.reseller_acl' => 'servernet_reseller']);
        $this->resellerProduct();
        $service = $this->service($this->server(), reseller: true);

        $seen = [];
        $this->fakeWhm($seen, aclOk: false);

        $this->assertTrue(app(ProvisioningService::class)->provision($service),
            'شکستِ ACL کلِ تحویل را ناموفق کرد، در حالی که حساب ساخته شده بود');

        $service->refresh();
        $this->assertSame('done', $service->provision_status);

        // ...ولی ساکت هم نمانده
        $this->assertStringContainsString('failed', (string) json_encode($service->provision_meta),
            'شکستِ ACL هیچ ردی در provision_meta نگذاشت');
    }

    /** ACLِ تنظیم‌نشده هم باید رد بگذارد، نه اینکه بی‌صدا از کنارش رد شویم. */
    public function test_an_unconfigured_acl_is_recorded_rather_than_silently_skipped(): void
    {
        config(['provisioning.reseller_acl' => '']);
        $this->resellerProduct();
        $service = $this->service($this->server(), reseller: true);

        $seen = [];
        $this->fakeWhm($seen);

        app(ProvisioningService::class)->provision($service);

        $this->assertStringNotContainsString('setacls', $this->urls($seen));
        $this->assertSame('not-configured', $service->refresh()->provision_meta['reseller_acl'] ?? null);
    }

    /**
     * 🔴 «پکیج پیدا نشد» هرگز نباید به «نامحدود» ترجمه شود.
     *
     * اگر سقف را ندانیم و بی‌سقف بسازیم، نمایندهٔ ۱۰ اکانتی می‌تواند نود را پر
     * کند و قربانی‌اش مشتریانِ **دیگر** هستند — کسی که شکایت کند وجود ندارد.
     */
    public function test_unknown_limits_never_become_unlimited_limits(): void
    {
        config(['provisioning.reseller_acl' => 'servernet_reseller']);
        // عمداً هیچ Productای ساخته نمی‌شود
        $service = $this->service($this->server(), reseller: true);

        $seen = [];
        $this->fakeWhm($seen);

        app(ProvisioningService::class)->provision($service);

        $this->assertStringNotContainsString('setresellerlimits', $this->urls($seen),
            'بی‌آنکه سقف را بدانیم، سقف ست شد');
        $this->assertSame('unknown', $service->refresh()->provision_meta['reseller_limits'] ?? null);
    }

    // ═══════════════ ۳) آدرسِ پنل ═══════════════

    /**
     * نماینده به WHM (۲۰۸۷) می‌رود، هاستِ ساده به cPanel (۲۰۸۳). آدرسِ غلط
     * کدِ خطا نمی‌دهد؛ فقط مشتری محصولی که خریده را نمی‌بیند.
     */
    public function test_the_panel_url_points_at_whm_for_resellers_and_cpanel_for_everyone_else(): void
    {
        config(['provisioning.reseller_acl' => 'acl']);
        $this->resellerProduct();

        $seen = [];
        $this->fakeWhm($seen);

        $r = $this->service($this->server(), reseller: true);
        app(ProvisioningService::class)->provision($r);
        $this->assertStringEndsWith(':2087', (string) $r->refresh()->panel_url);

        $n = $this->service($this->server(), reseller: false,
            over: ['username' => 'plainacc', 'domain' => 'plain.ir', 'plan' => 'sn_linux_1']);
        app(ProvisioningService::class)->provision($n);
        $this->assertStringEndsWith(':2083', (string) $n->refresh()->panel_url);
    }

    // ═══════════════ ۴) استخراجِ سقف از مشخصاتِ فارسی ═══════════════

    public function test_limits_are_read_from_persian_specs(): void
    {
        $out = ResellerLimits::fromSpecs([
            ['label' => '۵۰ گیگابایت NVMe'],
            ['label' => '۵۰ اکانت هاست'],
            ['label' => 'ترافیک نامحدود'],
        ]);

        $this->assertSame(50, $out['accounts']);
        $this->assertSame(51200, $out['disk_mb']);
        // «نامحدود» تصمیمِ صریحِ فروش است ⇒ 0 یعنی سقف نگذار
        $this->assertSame(0, $out['bw_mb']);
    }

    /**
     * ⚠️ «۲۵ اکانت» نباید با «۲۵ گیگ» قاطی شود و برعکس. یک تطبیقِ شل، سقفِ
     * دیسکِ نماینده را روی ۲۵ **مگابایت** می‌نشاند.
     */
    public function test_account_count_is_never_mistaken_for_disk_size(): void
    {
        $out = ResellerLimits::fromSpecs([
            ['label' => '۲۵ اکانت هاست'],
            ['label' => '100 GB NVMe'],
        ]);

        $this->assertSame(25, $out['accounts']);
        $this->assertSame(102400, $out['disk_mb']);
    }

    // ═══════════════ ۵) DirectAdmin: endpointِ جدا ═══════════════

    /**
     * 🔴 در DirectAdmin نماینده و کاربرِ عادی **دو دستورِ متفاوت**اند، نه یک
     * پرچم. `CMD_API_ACCOUNT_USER` موفق برمی‌گردد و یک کاربرِ ساده می‌سازد.
     */
    public function test_directadmin_uses_the_reseller_endpoint_not_the_user_endpoint(): void
    {
        $service = $this->service($this->server('directadmin'), reseller: true);

        $seen = [];
        Http::swap(new Factory);
        Http::fake(function ($request) use (&$seen) {
            $seen[] = $request;

            // SHOW_USER_CONFIG پیش‌پرواز: کاربر نیست
            if (str_contains($request->url(), 'CMD_API_SHOW_USER_CONFIG')) {
                return Http::response('error=1&text=No such user');
            }

            return Http::response('error=0&text=ok');
        });

        $this->assertTrue(app(ProvisioningService::class)->provision($service));

        $urls = $this->urls($seen);
        $this->assertStringContainsString('CMD_API_ACCOUNT_RESELLER', $urls,
            'نمایندگیِ DirectAdmin از مسیرِ کاربرِ عادی ساخته شد');
        $this->assertStringNotContainsString('CMD_API_ACCOUNT_USER?', $urls);
    }

    // ═══════════════ ۶) Plesk: خاموش تا آزمایش نشود ═══════════════

    /**
     * درایورِ Plesk نوشته شده ولی روی سرورِ واقعی آزمایش نشده. تا آن لحظه
     * نباید خودکار اجرا شود — «تحویلِ موفقِ دروغین» از تحویلِ کند بدتر است.
     */
    public function test_plesk_stays_manual_until_it_is_explicitly_switched_on(): void
    {
        config(['provisioning.plesk_auto' => false]);
        $this->assertFalse($this->server('plesk')->isAutoProvisioned());

        config(['provisioning.plesk_auto' => true]);
        $this->assertTrue($this->server('plesk')->isAutoProvisioned());
    }

    /**
     * ⚠️ تستِ سیم‌کشی: بلوکِ config واقعاً همان‌جایی است که کد نگاه می‌کند.
     * (همان درسِ `bale_relay` — `.env` درست، `env()` درست، `config()` خالی.)
     * این تست عمداً چیزی را `config([...])` نمی‌کند.
     */
    public function test_the_provisioning_config_file_actually_exists_where_the_code_reads_it(): void
    {
        $file = config_path('provisioning.php');
        $this->assertFileExists($file);

        $raw = require $file;
        $this->assertArrayHasKey('reseller_acl', $raw);
        $this->assertArrayHasKey('plesk_auto', $raw);
    }

    /** درایورِ هر نوع سرور واقعاً همانی است که فکر می‌کنیم. */
    public function test_each_server_type_resolves_to_its_own_driver(): void
    {
        config(['provisioning.plesk_auto' => true]);
        $svc = app(ProvisioningService::class);

        $this->assertSame('whm', $svc->driverFor($this->server('whm'))->slug());
        $this->assertSame('directadmin', $svc->driverFor($this->server('directadmin'))->slug());
        $this->assertSame('plesk', $svc->driverFor($this->server('plesk'))->slug());
    }

    // ═══════════════ ۷) نیت از لحظهٔ سفارش تا لحظهٔ تحویل ═══════════════

    /**
     * 🔴 حلقهٔ گم‌شده: اگر `is_reseller` در لحظهٔ **سفارش** نوشته نشود، همهٔ
     * ادعاهای بالا بی‌اثرند — درایور هیچ‌وقت خبردار نمی‌شود.
     *
     * تست عمداً از خودِ روتِ خرید می‌رود، نه با `Service::create` دستی.
     */
    public function test_ordering_a_reseller_package_marks_the_service_as_reseller(): void
    {
        $server = $this->server();
        $product = Product::create([
            'name' => 'نمایندگی سی‌پنل RCP-25', 'category' => 'reseller',
            'server_id' => $server->id, 'plan' => 'sn_reseller_linux_1', 'requires_domain' => true,
            'price' => 990000, 'setup_fee' => 0, 'cycle' => 'monthly',
            'tax_percent' => 10, 'is_active' => true,
        ]);
        $customer = $this->customer();

        $this->actingAs($customer, 'customer')
            ->post("/account/order/{$product->slug}",
                ['cycle' => 'monthly', 'domain_mode' => 'have', 'domain' => 'agency.ir'])
            ->assertRedirect();

        $service = Service::where('customer_id', $customer->id)->firstOrFail();
        $this->assertTrue((bool) $service->is_reseller,
            'پکیجِ نمایندگی خریده شد ولی سرویس نمایندگی علامت نخورد — تحویل یک هاستِ ساده می‌سازد');
    }

    /** و پکیجِ عادی نباید علامت بخورد. */
    public function test_ordering_an_ordinary_package_leaves_the_reseller_flag_off(): void
    {
        $server = $this->server();
        $product = Product::create([
            'name' => 'هاست لینوکس LX-5', 'category' => 'shared',
            'server_id' => $server->id, 'plan' => 'sn_linux_2', 'requires_domain' => true,
            'price' => 249000, 'setup_fee' => 0, 'cycle' => 'monthly',
            'tax_percent' => 10, 'is_active' => true,
        ]);
        $customer = $this->customer();

        $this->actingAs($customer, 'customer')
            ->post("/account/order/{$product->slug}",
                ['cycle' => 'monthly', 'domain_mode' => 'have', 'domain' => 'shop.ir'])
            ->assertRedirect();

        $this->assertFalse((bool) Service::where('customer_id', $customer->id)->firstOrFail()->is_reseller);
    }

    // ═══════════════ ۸) ورودِ یک‌کلیکی ═══════════════

    /**
     * 🔴 نماینده باید به **WHM** برود. با `cpaneld` نشست ساخته می‌شود، ورود
     * موفق است و کدِ ۳۰۲ برمی‌گردد — ولی مشتری داخلِ cPanelِ حسابِ خودش
     * می‌افتد و اکانت‌های مشتریانش را هیچ‌جا نمی‌بیند. یعنی «کار می‌کند» و
     * محصول را تحویل نمی‌دهد؛ تستِ وضعیتِ HTTP این را نمی‌گیرد.
     */
    public function test_one_click_login_opens_whm_for_a_reseller_and_cpanel_for_a_plain_host(): void
    {
        $server = $this->server();

        $seen = [];
        Http::swap(new Factory);
        Http::fake(function ($request) use (&$seen) {
            $seen[] = $request;

            return Http::response(['metadata' => ['result' => 1, 'reason' => 'Ok'],
                'data' => ['url' => 'https://node.example.com:2087/session/xyz']]);
        });

        $reseller = $this->service($server, reseller: true,
            over: ['provision_status' => 'done', 'status' => 'active']);
        $this->actingAs($reseller->customer, 'customer')
            ->get("/account/services/{$reseller->id}/cpanel")
            ->assertRedirect();

        $plain = $this->service($server, reseller: false, over: [
            'provision_status' => 'done', 'status' => 'active',
            'username' => 'plainacc', 'domain' => 'plain.ir',
        ]);
        $this->actingAs($plain->customer, 'customer')
            ->get("/account/services/{$plain->id}/cpanel")
            ->assertRedirect();

        $this->assertStringContainsString('service=whostmgrd', $seen[0]->url(),
            'نماینده به‌جای WHM به cPanel فرستاده شد');
        $this->assertStringContainsString('service=cpaneld', $seen[1]->url());
    }
}

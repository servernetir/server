<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * «چهار اتاق» — هاست / سرور / دامنه / خدمات.
 *
 * ═══ باگی که این فایل برای تکرارنشدنش نوشته شد ═══
 *
 * کارفرما: «این صفحه از لحاظ ui ux قشنگ نیست، همه چی توهم هست و ساختار درستی
 * نداره.» علت یک جدولِ شش‌ستونی بود که چهار محصولِ بی‌هم‌پوشانی را با یک شکلِ
 * ردیف نشان می‌داد: دامنه با تاریخ انقضا، هاست با مصرفِ دیسک، سرور با IP و
 * وضعیتِ روشن/خاموش، و خدماتِ صرفاً مالی که هیچ‌کدامِ اینها را ندارد. جدول
 * ناچار بود اشتراکشان را نشان دهد، و اشتراکشان تقریباً هیچ بود.
 *
 * ⚠️ این تست‌ها عمداً **مقدارِ دیداری** را می‌سنجند نه کدِ ۲۰۰. درسِ ثبت‌شدهٔ
 * پروژه: صفحه بارها ۲۰۰ داده و محتوایش مرده بوده.
 */
class AccountSectionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    // ───────────────────────── فیکسچرها ─────────────────────────

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'c'.random_int(1, 999999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    private function server(string $type): Server
    {
        return Server::create([
            'name' => strtoupper($type).'-'.random_int(1, 9999), 'type' => $type,
            'hostname' => $type.'.test', 'username' => 'root', 'api_token' => 't',
            'verify_tls' => false, 'status' => 'active',
        ]);
    }

    private function plan(): CloudPlan
    {
        CloudLocation::firstOrCreate(['code' => 'de-falkenstein'],
            ['country' => 'DE', 'city' => 'Falkenstein', 'is_active' => true]);

        return CloudPlan::create([
            'provider' => 'hetzner', 'provider_ref' => 'cx22',
            'provider_location' => 'fsn1', 'location_code' => 'de-falkenstein',
            'public_name' => 'CV-2-4', 'slug' => 'cv-2c-4g-40d-de-falkenstein-'.random_int(1, 9999),
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme',
            'traffic_gb' => 20480, 'cpu_kind' => 'shared', 'arch' => 'x86',
            'cost_eur_cents' => 379, 'price_eur_cents' => 570, 'price_irt' => 570000,
            'is_active' => true, 'in_stock' => true,
        ]);
    }

    private function service(Customer $c, array $over = []): Service
    {
        return Service::create(array_merge([
            'customer_id' => $c->id, 'currency_code' => 'IRT', 'price' => 100000,
            'tax_percent' => 0, 'cycle' => 'monthly', 'status' => 'active',
            'provision_status' => 'done', 'activated_at' => now(),
            'next_due_at' => now()->addMonth(),
        ], $over));
    }

    /** هاستِ اشتراکیِ تحویل‌شده روی WHM */
    private function hosting(Customer $c, array $over = []): Service
    {
        return $this->service($c, array_merge([
            'name' => 'هاست اشتراکی من', 'server_id' => $this->server('whm')->id,
            'username' => 'clientusr', 'domain' => 'hostbox.test',
            'panel_url' => 'https://whm.test:2083',
        ], $over));
    }

    /** سرورِ ابریِ تحویل‌شده */
    private function cloud(Customer $c, array $over = [], ?string $ip = '203.0.113.45'): Service
    {
        $s = $this->service($c, array_merge([
            'name' => 'سرور ابری من', 'cloud_plan_id' => $this->plan()->id,
        ], $over));

        if ($ip !== null) {
            CloudInstance::create([
                'service_id' => $s->id, 'provider' => 'hetzner', 'provider_ref' => '42',
                'location_code' => 'de-falkenstein', 'image_key' => 'ubuntu-24.04',
                'hostname' => 'sn-svc-'.$s->id, 'ipv4' => $ip, 'ipv6' => '2a01:4f8::1',
                'status' => 'running', 'password_seen' => true,
                'specs' => ['vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme'],
            ]);
        }

        return $s;
    }

    /** خدمتِ صرفاً مالی — نه سرور دارد، نه پلنِ ابری */
    private function misc(Customer $c, array $over = []): Service
    {
        return $this->service($c, array_merge([
            'name' => 'پشتیبانی ویژه من', 'description' => 'پشتیبانی اختصاصی ماهانه',
        ], $over));
    }

    private function domain(Customer $c, array $over = []): Domain
    {
        return Domain::create(array_merge([
            'customer_id' => $c->id, 'domain' => 'mydomain'.random_int(1, 9999).'.com',
            'sld' => 'mydomain', 'tld' => 'com', 'status' => 'active',
            'provision_status' => 'done', 'expires_at' => now()->addDays(200),
            'auto_renew' => true, 'is_locked' => true, 'period_years' => 1,
        ], $over));
    }

    private function html(Customer $c, string $route): string
    {
        return $this->actingAs($c, 'customer')
            ->get(route($route, [], false))->assertOk()->getContent();
    }

    // ═══════════════ ۱) هر اتاق فقط نوعِ خودش ═══════════════

    public function test_each_section_renders_only_its_own_kind(): void
    {
        $c = $this->customer();
        $this->hosting($c);
        $this->cloud($c);
        $this->misc($c);

        $hosting = $this->html($c, 'account.hosting');
        $this->assertStringContainsString('هاست اشتراکی من', $hosting);
        $this->assertStringNotContainsString('سرور ابری من', $hosting);
        $this->assertStringNotContainsString('پشتیبانی ویژه من', $hosting);

        $servers = $this->html($c, 'account.servers');
        $this->assertStringContainsString('سرور ابری من', $servers);
        $this->assertStringNotContainsString('هاست اشتراکی من', $servers);
        $this->assertStringNotContainsString('پشتیبانی ویژه من', $servers);

        $other = $this->html($c, 'account.other');
        $this->assertStringContainsString('پشتیبانی ویژه من', $other);
        $this->assertStringNotContainsString('هاست اشتراکی من', $other);
        $this->assertStringNotContainsString('سرور ابری من', $other);
    }

    /**
     * 🔴 جمعِ اتاق‌ها باید دقیقاً برابرِ کلِ فهرست باشد.
     *
     * بدترین شکستِ ممکنِ این طراحی «سرویسِ ناپدید» است: ردیفی که نوعش تشخیص
     * داده نمی‌شود و در هیچ اتاقی نمی‌افتد. مشتری پول داده و هیچ‌جا نمی‌بیندش.
     * پس «خدمات» سطلِ **پیش‌فرض** است، نه یک نوعِ سوم در کنارِ بقیه.
     */
    public function test_every_service_lands_in_exactly_one_room(): void
    {
        $c = $this->customer();

        $rows = [
            $this->hosting($c),
            $this->service($c, ['name' => 'A', 'server_id' => $this->server('plesk')->id]),
            $this->service($c, ['name' => 'B', 'server_id' => $this->server('directadmin')->id]),
            $this->cloud($c),
            $this->service($c, ['name' => 'C', 'server_id' => $this->server('vps')->id]),
            $this->service($c, ['name' => 'D', 'server_id' => $this->server('dedicated')->id]),
            $this->service($c, ['name' => 'E', 'server_id' => $this->server('generic')->id]),
            $this->misc($c),
        ];

        $seen = [];

        foreach ($rows as $s) {
            $kind = $s->fresh()->load('server')->kind();
            $this->assertContains($kind, ['hosting', 'server', 'other'],
                "نوعِ ناشناخته «{$kind}» یعنی ردیف در هیچ اتاقی نمی‌افتد");
            $seen[$kind] = ($seen[$kind] ?? 0) + 1;
        }

        $this->assertSame(count($rows), array_sum($seen), 'جمعِ اتاق‌ها با کلِ فهرست نمی‌خوانَد');
        $this->assertSame(3, $seen['hosting'], 'whm + plesk + directadmin');
        $this->assertSame(3, $seen['server'], 'ابری + vps + dedicated');
        $this->assertSame(2, $seen['other'], 'generic + بی‌سرور — سطلِ پیش‌فرض');
    }

    // ═══════════════ ۲) حالتِ خالیِ به‌دردبخور ═══════════════

    /**
     * اتاقِ خالی باید بگوید **چه چیزی** آن‌جا می‌نشیند و **از کجا** تهیه‌اش کنند.
     * قابِ خالی یا یک سرستونِ تنها، هیچ‌کدام جواب نیست.
     */
    public function test_a_customer_with_none_of_a_kind_sees_the_useful_empty_state(): void
    {
        $c = $this->customer();
        $this->hosting($c);                       // فقط هاست دارد

        $servers = $this->html($c, 'account.servers');
        $this->assertStringContainsString(__('ui.sec_empty_servers_h'), $servers);
        $this->assertStringContainsString(__('ui.sec_empty_servers_p'), $servers);
        $this->assertStringContainsString(route('account.cloud.store', [], false), $servers,
            'حالتِ خالی باید راهِ خرید بدهد، نه فقط خبرِ نداشتن');
        $this->assertStringContainsString('pnl-empty', $servers);

        $other = $this->html($c, 'account.other');
        $this->assertStringContainsString(__('ui.sec_empty_other_h'), $other);
        // ⚠️ این خدمات را مشتری خودش نمی‌خرد؛ لینک به فروشگاه او را به صفحه‌ای
        //    می‌فرستد که چیزی برای این بخش ندارد.
        $this->assertStringContainsString(route('account.tickets', [], false), $other);

        // و اتاقی که پر است حالتِ خالی نمی‌گیرد
        $hosting = $this->html($c, 'account.hosting');
        $this->assertStringNotContainsString(__('ui.sec_empty_hosting_h'), $hosting);
    }

    /** حسابِ کاملاً خالی: یک پیامِ روشن، نه چهار قابِ تهی روی هم */
    public function test_an_empty_account_gets_one_message_not_four_empty_frames(): void
    {
        $c = $this->customer();

        $html = $this->html($c, 'account.services');

        $this->assertStringContainsString(__('ui.sec_empty_all_h'), $html);
        $this->assertStringContainsString(route('account.store', [], false), $html);
        $this->assertSame(1, substr_count($html, 'pnl-empty'),
            'چهار جعبهٔ خالیِ روی‌هم برای مشتریِ تازه یک دیوارِ هیچ است');
    }

    // ═══════════════ ۳) دفترِ دامنه — وضعیتِ صادق ═══════════════

    /**
     * 🔴 دامنهٔ منقضیِ در مهلتِ بازیابی نباید همان سبزِ دامنهٔ سالم را بگیرد.
     *
     * `Domain::isActive()` فقط `status === 'active'` را می‌سنجد و چرخهٔ عمر،
     * دامنه را در کلِ ۳۰ روزِ مهلت روی همان `active` نگه می‌دارد — عمداً، تا از
     * پنل غیب نشود. ولی نمایشش تا امروز از دامنهٔ سالم قابلِ تشخیص نبود.
     */
    public function test_the_domain_ledger_marks_a_domain_inside_its_grace_window(): void
    {
        $c = $this->customer();
        $this->domain($c, ['domain' => 'healthy.com', 'sld' => 'healthy']);
        $dying = $this->domain($c, [
            'domain' => 'dying.com', 'sld' => 'dying',
            // ⚠️ `startOfDay` عمدی است: `daysLeft()` از ابتدای امروز فاصله
            //    می‌گیرد و کسرِ ساعت را با cast به int به سمتِ صفر می‌بُرد، پس
            //    `now()->subDays(3)`ِ خام بسته به ساعتِ اجرا ‎-۲ یا ‎-۳ می‌داد.
            'expires_at' => now()->startOfDay()->subDays(3),
        ]);
        $this->domain($c, ['domain' => 'soon.com', 'sld' => 'soon', 'expires_at' => now()->addDays(9)]);

        $this->assertSame(-3, $dying->fresh()->daysLeft(), 'فیکسچر باید واقعاً منقضی باشد');

        $html = $this->html($c, 'account.domains');

        $this->assertStringContainsString(__('ui.dmn_state_grace'), $html,
            'دامنهٔ منقضی در مهلتِ بازیابی باید صریح گفته شود');
        $this->assertStringContainsString(__('ui.dmn_state_soon'), $html);
        $this->assertStringContainsString(__('ui.dmn_state_active'), $html);

        // «۲۷ روز» — مهلتِ ۳۰ روزه منهای ۳ روزِ گذشته
        $this->assertStringContainsString(fa_num(27), $html);
        $this->assertStringContainsString(__('ui.dmn_days_to_delete'), $html);

        // و همان دفتر در نمای «همه» هم هست
        $this->assertStringContainsString(__('ui.dmn_state_grace'), $this->html($c, 'account.services'));
    }

    /** دامنه‌ای که هیچ سرویسی رویش سوار نیست، پیشنهادِ میزبانی می‌گیرد */
    public function test_a_bare_domain_is_offered_hosting(): void
    {
        $c = $this->customer();
        $this->domain($c, ['domain' => 'bare.com', 'sld' => 'bare']);
        $this->domain($c, ['domain' => 'hostbox.test', 'sld' => 'hostbox', 'tld' => 'test']);
        $this->hosting($c);                        // domain = hostbox.test

        $html = $this->html($c, 'account.domains');

        $this->assertSame(1, substr_count($html, __('ui.dmn_nothing_attached')),
            'فقط دامنهٔ بی‌سرویس باید این پیشنهاد را بگیرد');
    }

    // ═══════════════ ۴) مترِ مصرف فقط جایی که کار می‌کند ═══════════════

    /**
     * 🔴 ویجتِ آمار برای هر کنترل‌پنلی جز WHM یک کارتِ همیشه-خالی می‌سازد **و**
     * یکی از ۶۰ درخواستِ سقف‌دارِ دقیقه را می‌سوزاند: `ServiceController::stats()`
     * برای غیرِ whm فوراً `ok:false` برمی‌گرداند.
     */
    public function test_the_usage_widget_is_not_rendered_for_a_non_whm_host(): void
    {
        $c = $this->customer();
        $whm = $this->hosting($c);
        $da  = $this->hosting($c, [
            'name' => 'هاست دایرکت‌ادمین', 'server_id' => $this->server('directadmin')->id,
        ]);

        $html = $this->html($c, 'account.hosting');

        $this->assertStringContainsString(route('account.services.stats', $whm, false), $html);
        $this->assertStringNotContainsString(route('account.services.stats', $da, false), $html,
            'ردیفِ غیرِ WHM نباید درخواستِ آمار بزند');
        $this->assertSame(1, substr_count($html, 'class="svc-usage"'));
        $this->assertStringContainsString(__('ui.hst_stats_na'), $html,
            'به‌جای کارتِ مرده باید بگوید چرا آماری نیست');
    }

    /** سرویسِ معلق نباید دکمهٔ ورودی بگیرد که تضمین‌شده شکست می‌خورد */
    public function test_a_suspended_host_is_not_offered_a_login_that_will_fail(): void
    {
        $c = $this->customer();
        $s = $this->hosting($c, ['status' => 'suspended']);

        $html = $this->html($c, 'account.hosting');

        $this->assertStringContainsString(__('ui.hst_suspended_h'), $html);
        $this->assertStringNotContainsString(route('account.services.cpanel', $s, false), $html);
    }

    // ═══════════════ ۵) نمای «همه» هنوز همه‌چیز است ═══════════════

    /**
     * 🔴 این نشانی حق ندارد به یک صفحهٔ فهرستِ لینک تبدیل شود.
     *
     * `ProvisioningService` همین لینک را داخلِ اعلانِ بازگشتِ وجه می‌گذارد —
     * پیامی که واقعاً برای مشتری فرستاده می‌شود. اگر صفحه فقط منو باشد، مشتری
     * روی آن کلیک می‌کند و سفارشِ شکست‌خورده‌اش را **نمی‌بیند**.
     */
    public function test_account_services_still_shows_every_kind_on_one_page(): void
    {
        $c = $this->customer();
        $this->hosting($c);
        $this->cloud($c);
        $this->misc($c);
        $this->domain($c, ['domain' => 'ledger.com', 'sld' => 'ledger']);

        $html = $this->html($c, 'account.services');

        foreach (['هاست اشتراکی من', 'سرور ابری من', 'پشتیبانی ویژه من', 'ledger.com'] as $needle) {
            $this->assertStringContainsString($needle, $html, "«{$needle}» باید در نمای «همه» باشد");
        }

        // و خطِ SSH با مقدارِ واقعی — تلهٔ ‎@{{ که بی‌هیچ خطایی IP را می‌خورد
        $this->assertStringContainsString('ssh root@203.0.113.45', $html);
    }

    public function test_every_section_resolves_in_all_three_locales(): void
    {
        $c = $this->customer();
        $this->hosting($c);
        $this->cloud($c);
        $this->misc($c);
        $this->domain($c);

        foreach (['', 'en/', 'tr/'] as $prefix) {
            foreach (['services', 'hosting', 'servers', 'other', 'domains'] as $room) {
                $url = '/'.$prefix.'account/'.($room === 'services' ? 'services' : $room);

                $this->actingAs($c, 'customer')->get($url)
                    ->assertOk()
                    ->assertDontSee('ui.sec_', false)
                    ->assertDontSee('ui.dmn_', false)
                    ->assertDontSee('ui.svc_', false);
            }
        }
    }

    /** سوییچر باید در هر پنج صفحه به هر پنج در راه بدهد */
    public function test_the_lens_switcher_links_to_every_room(): void
    {
        $c = $this->customer();
        $this->hosting($c);

        $targets = [
            route('account.services', [], false),
            route('account.hosting', [], false),
            route('account.servers', [], false),
            route('account.domains', [], false),
            route('account.other', [], false),
        ];

        foreach (['account.services', 'account.hosting', 'account.servers', 'account.other', 'account.domains'] as $page) {
            $html = $this->html($c, $page);

            $this->assertStringContainsString('svc-lens', $html, "سوییچر روی {$page} نیست");

            foreach ($targets as $t) {
                // ⚠️ `lroute()` نشانیِ **مطلق** می‌سازد، پس مسیر انتهای href است
                //    نه کلِ آن. علامتِ نقل‌قولِ پایانی، `/account/servers` را از
                //    `/account/servers/…` جدا نگه می‌دارد.
                $this->assertStringContainsString($t.'"', $html,
                    "لینکِ {$t} روی {$page} نیست — کاربر داخلِ یک اتاق گیر می‌افتد");
            }
        }
    }

    // ═══════════════ ۶) سفیدبرچسبی و قاعده‌های ساختاری ═══════════════

    /**
     * 🔴 کارفرما: «نمیخوام مشخص بشه از aeza or hetzner میخرم من.»
     * `$hidden` فقط JSON را می‌بندد، نه Blade را — پس صریح می‌سنجیم.
     */
    public function test_no_provider_name_reaches_any_section_page(): void
    {
        $c = $this->customer();
        $this->hosting($c);
        $this->cloud($c);
        $this->misc($c);
        $this->domain($c);

        $leaks = ['hetzner', 'aeza', 'ovh', 'arvan', 'cx22', 'cx33', 'EPs-', 'fsn1', 'hel1', 'gra7'];

        foreach (['account.services', 'account.hosting', 'account.servers', 'account.other', 'account.domains'] as $page) {
            $html = $this->html($c, $page);

            foreach ($leaks as $needle) {
                $this->assertStringNotContainsStringIgnoringCase($needle, $html,
                    "نامِ زیرساخت «{$needle}» در {$page} نشت کرده");
            }
        }
    }

    /**
     * ⚠️ استایل باید در panel.css باشد، نه در Blade.
     *
     * ۲۷ قاعدهٔ `.svc-*` سال‌ها داخلِ یک بلوکِ style در services.blade.php
     * زندگی می‌کردند — یعنی بیرون از دیدِ CssVariablesDefinedTest، که دقیقاً
     * برای همین نوع خطا نوشته شده بود.
     */
    public function test_no_section_blade_contains_a_style_block(): void
    {
        $files = array_merge(
            [
                resource_path('views/account/services.blade.php'),
                resource_path('views/account/hosting.blade.php'),
                resource_path('views/account/servers.blade.php'),
                resource_path('views/account/other.blade.php'),
                resource_path('views/account/domains.blade.php'),
            ],
            glob(resource_path('views/account/partials/*.blade.php')) ?: [],
        );

        foreach ($files as $f) {
            $this->assertStringNotContainsString('<style', file_get_contents($f),
                basename($f).' استایلِ درون‌خطی دارد — جایش انتهای panel.css است');
        }
    }

    /**
     * 🔴 کلاسِ CSSِ نبود، بی‌هیچ خطایی بی‌استایل رندر می‌شود.
     *
     * هر کلاسی که این بخش‌ها اختراع کرده‌اند باید واقعاً در panel.css باشد.
     */
    public function test_every_new_class_actually_exists_in_panel_css(): void
    {
        $css = file_get_contents(public_path('assets/css/panel.css'));

        $invented = [
            'svc-lens', 'svc-lens-i', 'svc-sec-ic', 'svc-grid', 'svc-card', 'svc-card-h',
            'svc-card-t', 'svc-chips', 'svc-facts', 'svc-fact', 'svc-net', 'svc-net-r',
            'svc-list', 'svc-row', 'svc-row-main', 'svc-row-t', 'svc-row-m', 'svc-row-a',
            'svc-none', 'svc-acts', 'svc-otp-box', 'svc-otp-why', 'svc-otp-why-h',
            'svc-otp-why-p', 'svc-otp-go', 'svc-input', 'svc-ta', 'svc-code',
            'dmn-table', 'dmn-days', 'dmn-bare', 'dmn-acts', 'svc-empty-acts',
            // منتقل‌شده‌ها از بلوکِ style
            'svc-quick', 'svc-qbtn', 'svc-usage-load', 'svc-usage-grid', 'svc-stat',
            'svc-bar', 'svc-cred', 'svc-pw', 'pw-eye', 'svc-note', 'copyable',
        ];

        foreach ($invented as $cls) {
            $this->assertMatchesRegularExpression('~\.'.preg_quote($cls, '~').'[\s,{:.>]~', $css,
                "کلاسِ .{$cls} در panel.css تعریف نشده — بی‌هیچ خطایی بی‌استایل رندر می‌شود");
        }
    }

    /**
     * ردیفِ سرور نباید هرگز رمزِ کنترل‌پنل را چاپ کند.
     *
     * `CloudProvisioner` رمزِ rootِ سرور را روی همان ستونِ `services.password`
     * می‌نویسد، پس یک قالبِ مشترکِ «اطلاعات ورود» آن را در هر بار بارگذاری چاپ
     * می‌کرد و قاعدهٔ «فقط یک بار» را بی‌صدا دور می‌زد.
     */
    public function test_a_cloud_row_never_prints_the_root_password(): void
    {
        $c = $this->customer();
        $this->cloud($c, ['password' => 'r00t-Secret-9x']);

        foreach (['account.services', 'account.servers'] as $page) {
            $this->assertStringNotContainsString('r00t-Secret-9x', $this->html($c, $page),
                "رمزِ root در {$page} چاپ شده — قاعدهٔ یک‌بارنمایش دور زده شد");
        }
    }
}

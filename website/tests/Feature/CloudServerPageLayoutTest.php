<?php

namespace Tests\Feature;

use App\Models\CloudImage;
use App\Models\CloudInstance;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Customer;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * چیدمانِ صفحهٔ سرورِ ابریِ مشتری — `/account/cloud/{service}`.
 *
 * ═══ چرا این فایل هست ═══
 *
 * کارفرما: «کاملاً بهم خورده… هرچیزی یه وری هستند.» سایدبارِ پنل به **پایینِ**
 * صفحه افتاده بود، بالای صفحه یک نوارِ خالی بود، و کارت‌ها خودشان سالم بودند.
 * یعنی مشکل در **پوستهٔ چیدمان** بود نه در استایلِ کارت‌ها.
 *
 * ⚠️ «کدِ ۲۰۰ یعنی هیچ.» صفحه تمامِ مدت ۲۰۰ می‌داد. پس این تست‌ها **ساختار** را
 * می‌سنجند: اینکه `.pnl-side` واقعاً فرزندِ `.pnl-layout` باشد و نه اسیرِ
 * `.pnl-main`، و اینکه تگ‌های محتوای صفحه تراز باشند.
 */
class CloudServerPageLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    // ───────────────────────── فیکسچر ─────────────────────────

    private function service(): Service
    {
        $customer = Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'cs'.random_int(1, 99999).'@example.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('secret-pass-123'), 'status' => 'active', 'locale' => 'fa',
        ]);

        CloudLocation::firstOrCreate(['code' => 'de-falkenstein'],
            ['country' => 'DE', 'city' => 'Falkenstein', 'is_active' => true]);

        CloudImage::firstOrCreate(
            ['provider' => 'hetzner', 'provider_ref' => '161547269'],
            ['key' => 'ubuntu-24.04', 'kind' => 'os', 'family' => 'ubuntu', 'version' => '24.04',
                'label' => 'Ubuntu 24.04', 'arch' => 'x86', 'is_active' => true],
        );

        // ⚠️ (provider, provider_ref, location_code) یکتاست و این فیکسچر در یک
        //    تست چند بار صدا زده می‌شود — پس ref باید یکتا باشد.
        $plan = CloudPlan::create([
            'provider' => 'hetzner', 'provider_ref' => 'cx22-'.random_int(1, 999999),
            'provider_location' => 'fsn1',
            'location_code' => 'de-falkenstein', 'public_name' => 'CV-2-4',
            'slug' => 'cv-2c-4g-40d-de-falkenstein',
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme',
            'traffic_gb' => 20480, 'cpu_kind' => 'shared', 'arch' => 'x86',
            'cost_eur_cents' => 379, 'price_eur_cents' => 570, 'price_irt' => 570000,
            'is_active' => true, 'in_stock' => true,
        ]);

        return Service::create([
            'customer_id' => $customer->id, 'name' => 'سرورِ ابری CV-2-4',
            'currency_code' => 'IRT', 'price' => 570000, 'tax_percent' => 0,
            'cycle' => 'monthly', 'status' => 'active', 'provision_status' => 'done',
            'cloud_plan_id' => $plan->id, 'cloud_image_key' => 'ubuntu-24.04',
            'activated_at' => now(), 'next_due_at' => now()->addMonth(),
        ]);
    }

    /** سرورِ تحویل‌شده — همان حالتی که کارفرما دید (server 17) */
    private function delivered(Service $service): CloudInstance
    {
        return CloudInstance::create([
            'service_id' => $service->id, 'provider' => 'hetzner', 'provider_ref' => '4711',
            'location_code' => 'de-falkenstein', 'image_key' => 'ubuntu-24.04',
            'hostname' => 'srv-17', 'ipv4' => '185.51.200.9', 'status' => 'running',
            'specs' => ['vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme', 'traffic_gb' => 20480],
            'synced_at' => now(),
        ]);
    }

    /** سرورِ در حالِ ساخت — هنوز IP ندارد */
    private function building(Service $service): CloudInstance
    {
        return CloudInstance::create([
            'service_id' => $service->id, 'provider' => 'aeza', 'provider_ref' => 'order:9',
            'location_code' => 'de-falkenstein', 'image_key' => 'ubuntu-24.04',
            'status' => 'building',
            'specs' => ['vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme', 'traffic_gb' => 20480],
        ]);
    }

    private function render(Service $service): string
    {
        return $this->actingAs($service->customer, 'customer')
            ->get('/account/cloud/'.$service->id)
            ->assertOk()->getContent();
    }

    // ───────────────────────── ادعاها ─────────────────────────

    /**
     * 🔴 ادعای اصلی: سایدبار باید **فرزندِ مستقیمِ** `.pnl-layout` بماند.
     *
     * اگر محتوای صفحه یک `</div>` کم داشته باشد، `</div>`ِ بستنِ `.pnl-main`
     * مصرفِ آن تگِ بازِ داخلی می‌شود و `<aside class="pnl-side">` **داخلِ**
     * محتوا می‌افتد — دیگر آیتمِ گریدِ چیدمان نیست، پس به انتهای صفحه سُر
     * می‌خورد. دقیقاً همان چیزی که کارفرما دید.
     */
    public function test_the_sidebar_is_a_direct_child_of_the_panel_layout_when_delivered(): void
    {
        $s = $this->service();
        $this->delivered($s);

        $this->assertSidebarIsSibling($this->render($s), 'تحویل‌شده');
    }

    /** همین ادعا برای حالتِ در حالِ ساخت */
    public function test_the_sidebar_is_a_direct_child_of_the_panel_layout_while_building(): void
    {
        $s = $this->service();
        $this->building($s);

        $this->assertSidebarIsSibling($this->render($s), 'در حالِ ساخت');
    }

    /**
     * 🔴 سربرگِ صفحه — نوارِ خالیِ بالای صفحه دقیقاً همین بود.
     *
     * کلِ بلوکِ `pnl-head` (خرده‌نان، عنوان، IP، نشانگرِ وضعیت) از HTML حذف شده
     * بود و فقط متنِ نشانگر («روشن») و یک `</div>`ِ یتیم مانده بود.
     */
    public function test_the_page_head_renders_with_breadcrumb_title_and_status(): void
    {
        foreach (['delivered', 'building'] as $state) {
            $s = $this->service();
            $this->{$state}($s);

            $html = $this->render($s);

            $this->assertStringContainsString('class="pnl-head"', $html,
                "حالتِ {$state}: سربرگِ صفحه اصلاً رندر نشده");
            $this->assertStringContainsString('class="blog-crumbs"', $html,
                "حالتِ {$state}: خرده‌نان نیست");
            $this->assertStringContainsString('<h1>'.e($s->name).'</h1>', $html,
                "حالتِ {$state}: نامِ سرویس در عنوان نیست");
            $this->assertStringContainsString('id="st-pill"', $html,
                "حالتِ {$state}: نشانگرِ وضعیت نیست");
            $this->assertStringContainsString('window.T =', $html,
                "حالتِ {$state}: رشته‌های سه‌زبانهٔ JS تزریق نشده‌اند");
        }
    }

    /**
     * 🔴 نگهبانِ عمومی — این تله در هر ویویی می‌تواند تکرار شود.
     *
     * Blade **پیش از هر کاری** بلوک‌های خام را با `@`+`php … @`+`endphp` بیرون
     * می‌کشد، و آن جستجو داخلِ کامنت‌های `{{--  --}}` را هم می‌بیند. پس نامِ آن
     * دستورها در متنِ یک توضیح، یک «شروعِ بلوک»ِ واقعی شمرده می‌شود و به
     * `@`+`endphp`ِ چند خط پایین‌تر جفت می‌خورد — و هرچه بینشان است بی‌صدا حذف
     * می‌شود. نه خطایی، نه هشداری؛ فقط بخشی از صفحه نیست.
     *
     * ⚠️ خودِ این تست نباید قربانیِ همان تله شود، پس نامِ دستورها را می‌چسباند.
     */
    public function test_no_blade_comment_anywhere_contains_a_raw_block_directive(): void
    {
        $marks = ['@'.'php', '@'.'endphp', '@'.'verbatim', '@'.'endverbatim'];
        $offenders = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $src = file_get_contents($file->getPathname());

            if (! preg_match_all('/\{\{--(.*?)--\}\}/s', $src, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($m[1] as $i => $body) {
                foreach ($marks as $mark) {
                    if (preg_match('/(?<!@)'.preg_quote($mark, '/').'\b/', $body[0])) {
                        $line = substr_count(substr($src, 0, $m[0][$i][1]), "\n") + 1;
                        $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname())
                            .':'.$line.' → '.$mark;
                    }
                }
            }
        }

        $this->assertSame([], $offenders,
            'کامنتِ Blade که نامِ بلوکِ خام را در متنش دارد، بخشی از صفحه را بی‌صدا می‌خورد: '
            .implode(' · ', $offenders));
    }

    /** خطِ SSH باید IP واقعی داشته باشد، نه آکولادِ کامپایل‌نشده */
    public function test_the_ssh_line_carries_the_real_ip(): void
    {
        $s = $this->service();
        $inst = $this->delivered($s);

        $html = $this->render($s);

        $this->assertStringContainsString('ssh root'.'@'.$inst->ipv4, $html);
        $this->assertStringNotContainsString('{{', $html, 'هیچ آکولادِ کامپایل‌نشده نباید بماند');
    }

    /**
     * تگ‌های `div` و `section` داخلِ `.pnl-main` باید تراز باشند — در **هر دو**
     * حالت. یک تگِ بازِ جامانده هیچ خطایی تولید نمی‌کند و صفحه ۲۰۰ می‌ماند.
     */
    public function test_the_panel_content_has_balanced_tags_in_both_states(): void
    {
        foreach (['delivered', 'building'] as $state) {
            $s = $this->service();
            $this->{$state}($s);

            $main = $this->panelMain($this->render($s));

            foreach (['div', 'section', 'form', 'details'] as $tag) {
                $open = preg_match_all('~<'.$tag.'\b[^>]*(?<!/)>~i', $main);
                $close = preg_match_all('~</'.$tag.'>~i', $main);

                $this->assertSame($close, $open,
                    "حالتِ {$state}: تگِ <{$tag}> تراز نیست ({$open} باز / {$close} بسته)");
            }
        }
    }

    // ───────────────────────── کمکی‌ها ─────────────────────────

    /**
     * پوستهٔ دو ستونه باید سالم باشد.
     *
     * ⚠️ ادعای «سایدبار داخلِ .pnl-main نیست» به‌تنهایی **کافی نیست** و باگِ
     * واقعی از زیرش رد شد: آن‌جا `.pnl-main` با یک `</div>`ِ یتیم **زودتر**
     * بسته می‌شد، پس سایدبار طبیعتاً داخلش نبود — ولی کارت‌ها هم نبودند و
     * `</div>`ِ بعدیِ layout به‌جای main، خودِ `.pnl-layout` را می‌بست و
     * سایدبار از گرید بیرون می‌افتاد.
     *
     * پس ادعای درست این است: **هر کارتِ صفحه باید داخلِ `.pnl-main` باشد.**
     */
    private function assertSidebarIsSibling(string $html, string $state): void
    {
        $main = $this->panelMain($html);

        $this->assertStringContainsString('class="pnl-side"', $html,
            "حالتِ {$state}: سایدبار اصلاً رندر نشده");

        $this->assertStringNotContainsString('class="pnl-side"', $main,
            "حالتِ {$state}: سایدبار داخلِ .pnl-main افتاده");

        $this->assertStringContainsString('class="pnl-head"', $main,
            "حالتِ {$state}: سربرگِ صفحه بیرونِ .pnl-main افتاده یا اصلاً رندر نشده");

        $this->assertSame(
            substr_count($html, 'class="pnl-sec'),
            substr_count($main, 'class="pnl-sec'),
            "حالتِ {$state}: بعضی کارت‌ها بیرونِ .pnl-main افتاده‌اند — یعنی پوسته "
            .'زودتر بسته شده و سایدبار از گرید بیرون می‌افتد'
        );
    }

    /** فقط محتوای داخلِ `<div class="pnl-main"> … </div>` (با شمارشِ تودرتو) */
    private function panelMain(string $html): string
    {
        $start = strpos($html, '<div class="pnl-main">');
        $this->assertNotFalse($start, 'پوستهٔ پنل (.pnl-main) اصلاً رندر نشده');

        $i = $start + strlen('<div class="pnl-main">');
        $depth = 1;

        preg_match_all('~<div\b[^>]*>|</div>~i', $html, $m, PREG_OFFSET_CAPTURE, $i);

        foreach ($m[0] as [$tag, $at]) {
            $depth += str_starts_with($tag, '</') ? -1 : 1;

            if ($depth === 0) {
                return substr($html, $i, $at - $i);
            }
        }

        // تراز نشد ⇒ همان باگ. تا انتهای صفحه را برمی‌گردانیم تا ادعا بیفتد.
        return substr($html, $i);
    }
}

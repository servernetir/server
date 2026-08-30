<?php

namespace Tests\Feature;

use App\Http\Controllers\Account\CloudStoreController;
use App\Models\CloudImage;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Setting;
use App\Services\Cloud\HetznerClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * نامِ ایمیج نزدِ هتزنر **یکتا نیست**. از ۲۰۲۳ هر سیستم‌عامل دو ایمیج دارد —
 * یکی x86 و یکی arm — با نامِ کاملاً یکسان و شناسهٔ متفاوت. یکتا «نام + معماری»
 * است. (تغییرنامهٔ رسمیِ هتزنر: «the image is now uniquely identified through
 * the combination of name & architecture».)
 *
 * ما نام را شناسه گرفته بودیم، پس دو باگ هم‌زمان داشتیم:
 *
 *  ۱) **دیده‌نشدن** — ردیفِ arm روی ردیفِ x86 می‌افتاد و هر ۲۹ ایمیج «arm»
 *     ذخیره می‌شد. پلن‌ها x86 بودند، پس فیلترِ معماری همه را رد می‌کرد و
 *     مشتری در صفحهٔ خرید هیچ سیستم‌عامل و هیچ نرم‌افزاری نمی‌دید.
 *
 *  ۲) **تحویلِ ناممکن (نهفته)** — نامِ خالی روی پلنِ arm به x86 می‌افتد و
 *     زیرساخت سفارش را با «معماریِ ناسازگار» رد می‌کند. چون معماری در اسلاگِ
 *     پلن نیست، یک اسلاگ می‌تواند هم پلنِ x86 داشته باشد هم arm — یعنی این
 *     مسیر واقعاً قابلِ رخ‌دادن بود: پولِ گرفته‌شده و سرورِ تحویل‌نشده.
 *
 * هر دو باگ بی‌صدا بودند. و از چشمِ تست‌ها گریختند چون فیکسچرِ قبلی هر نام را
 * فقط **یک بار** برمی‌گرداند و بنابراین اصلاً تصادمی نمی‌ساخت.
 */
class CloudHetznerArchTest extends TestCase
{
    use RefreshDatabase;

    /** پاسخِ هتزنر با الگوی واقعی: هر نام دو بار، دو معماری، دو شناسه */
    private function fakeHetzner(): void
    {
        Setting::putSecret('hetzner_api_token', 'test-token');

        $page = ['pagination' => ['last_page' => 1]];

        Http::fake(function ($request) use ($page) {
            $url = $request->url();

            if (str_contains($url, '/locations')) {
                return Http::response(['locations' => [
                    ['id' => 1, 'name' => 'fsn1', 'country' => 'DE', 'city' => 'Falkenstein',
                        'latitude' => 50.47, 'longitude' => 12.37],
                ], 'meta' => $page]);
            }

            if (str_contains($url, '/datacenters')) {
                return Http::response(['datacenters' => [
                    ['id' => 1, 'location' => ['name' => 'fsn1'], 'server_types' => ['available' => [11, 12]]],
                ], 'meta' => $page]);
            }

            if (str_contains($url, '/server_types')) {
                return Http::response(['server_types' => [
                    // x86 و arm با مشخصاتِ یکسان → اسلاگِ یکسان. عمداً.
                    ['id' => 11, 'name' => 'cx22', 'cores' => 2, 'memory' => 4, 'disk' => 40,
                        'cpu_type' => 'shared', 'architecture' => 'x86', 'storage_type' => 'local',
                        'deprecated' => false, 'included_traffic' => 21474836480,
                        'prices' => [['location' => 'fsn1',
                            'price_monthly' => ['net' => '3.2900000', 'gross' => '3.91']]]],
                    ['id' => 12, 'name' => 'cax11', 'cores' => 2, 'memory' => 4, 'disk' => 40,
                        'cpu_type' => 'shared', 'architecture' => 'arm', 'storage_type' => 'local',
                        'deprecated' => false, 'included_traffic' => 21474836480,
                        'prices' => [['location' => 'fsn1',
                            'price_monthly' => ['net' => '3.0000000', 'gross' => '3.57']]]],
                ], 'meta' => $page]);
            }

            if (str_contains($url, '/pricing')) {
                return Http::response(['pricing' => ['primary_ips' => [
                    ['type' => 'ipv4', 'prices' => [['location' => 'fsn1',
                        'price_monthly' => ['net' => '0.50']]]],
                ]]]);
            }

            if (str_contains($url, 'type=app')) {
                return Http::response(['images' => [
                    ['id' => 900, 'type' => 'app', 'name' => 'docker-ce', 'description' => 'Docker CE',
                        'architecture' => 'x86', 'disk_size' => 5, 'deprecated' => null],
                    ['id' => 901, 'type' => 'app', 'name' => 'docker-ce', 'description' => 'Docker CE',
                        'architecture' => 'arm', 'disk_size' => 5, 'deprecated' => null],
                ], 'meta' => $page]);
            }

            if (str_contains($url, '/images')) {
                return Http::response(['images' => [
                    // شناسه‌های واقعیِ تغییرنامهٔ هتزنر برای ubuntu-24.04
                    ['id' => 161547269, 'type' => 'system', 'name' => 'ubuntu-24.04',
                        'description' => 'Ubuntu 24.04', 'os_flavor' => 'ubuntu', 'os_version' => '24.04',
                        'architecture' => 'x86', 'disk_size' => 5, 'deprecated' => null],
                    ['id' => 161547270, 'type' => 'system', 'name' => 'ubuntu-24.04',
                        'description' => 'Ubuntu 24.04', 'os_flavor' => 'ubuntu', 'os_version' => '24.04',
                        'architecture' => 'arm', 'disk_size' => 5, 'deprecated' => null],

                    // فقط x86 دارد — محدودیتش باید بماند
                    ['id' => 102, 'type' => 'system', 'name' => 'centos-stream-9',
                        'description' => 'CentOS Stream 9', 'os_flavor' => 'centos', 'os_version' => '9',
                        'architecture' => 'x86', 'disk_size' => 5, 'deprecated' => null],
                ], 'meta' => $page]);
            }

            return Http::response([], 404);
        });
    }

    /** @return \Illuminate\Support\Collection<int,array<string,mixed>> */
    private function images()
    {
        $cat = (new HetznerClient)->fetchCatalog();
        $this->assertTrue($cat['ok'], 'کاتالوگ باید خوانده شود: '.($cat['message'] ?? ''));

        return collect($cat['images']);
    }

    // ═══════════════════ باگ ۱: دیده‌نشدن ═══════════════════

    public function test_both_architecture_variants_are_kept(): void
    {
        $this->fakeHetzner();

        $ubuntu = $this->images()->where('key', 'ubuntu-24.04');

        $this->assertCount(2, $ubuntu, 'هر دو نسخه باید بمانند، نه اینکه یکی دیگری را بخورد');
        $this->assertEqualsCanonicalizing(['x86', 'arm'], $ubuntu->pluck('arch')->all());

        // 🔴 قلبِ باگ: قبلاً فقط یک ردیف می‌ماند و آن هم arm بود
        $this->assertContains('x86', $ubuntu->pluck('arch')->all(),
            'نسخهٔ x86 نباید با نسخهٔ arm بازنویسی شود');
    }

    public function test_provider_ref_is_unique_per_variant(): void
    {
        $this->fakeHetzner();

        $refs = $this->images()->pluck('provider_ref')->all();

        $this->assertSame(array_unique($refs), $refs, 'شناسهٔ تکراری یعنی بازنویسیِ ردیف');
        $this->assertContains('161547269', $refs, 'شناسهٔ عددی نگه داشته می‌شود، نه نام');
        $this->assertContains('161547270', $refs);
    }

    public function test_customer_facing_key_is_shared_across_architectures(): void
    {
        $this->fakeHetzner();

        // مشتری باید یک «اوبونتو ۲۴٫۰۴» ببیند، نه دو تا
        $this->assertSame(['ubuntu-24.04'],
            $this->images()->where('arch', 'x86')->where('key', 'ubuntu-24.04')
                ->pluck('key')->unique()->values()->all());
    }

    public function test_image_available_on_one_arch_only_keeps_that_constraint(): void
    {
        $this->fakeHetzner();

        // کلید از خانواده+نسخه ساخته می‌شود، نه از نامِ هتزنر: `centos-9`
        $centos = $this->images()->where('key', 'centos-9');

        $this->assertCount(1, $centos, 'این ایمیج فقط x86 دارد');
        $this->assertSame('x86', $centos->first()['arch']);
    }

    /** نرم‌افزارهای آماده هم دو نسخهٔ معماری دارند */
    public function test_app_images_keep_both_variants_too(): void
    {
        $this->fakeHetzner();

        $docker = $this->images()->where('key', 'app-docker');

        $this->assertCount(2, $docker, 'داکر هم x86 دارد هم arm');
        $this->assertEqualsCanonicalizing(['x86', 'arm'], $docker->pluck('arch')->all());
        $this->assertEqualsCanonicalizing(['900', '901'], $docker->pluck('provider_ref')->all());
    }

    // ═══════════════════ اثر روی صفحهٔ خرید ═══════════════════

    private function plan(string $ref, string $arch, int $cost): CloudPlan
    {
        CloudLocation::firstOrCreate(['code' => 'de-falkenstein'],
            ['country' => 'DE', 'city' => 'Falkenstein', 'is_active' => true]);

        return CloudPlan::create([
            'provider' => 'hetzner', 'provider_ref' => $ref,
            'provider_location' => 'fsn1', 'location_code' => 'de-falkenstein',
            'public_name' => 'CV-2-4', 'slug' => 'cv-2c-4g-40d-de-falkenstein',
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme',
            'traffic_gb' => 20480, 'cpu_kind' => 'shared', 'arch' => $arch,
            'cost_eur_cents' => $cost, 'price_eur_cents' => 570, 'price_irt' => 570000,
            'is_active' => true, 'in_stock' => true,
        ]);
    }

    private function image(string $ref, string $arch, string $kind = 'os', string $key = 'ubuntu-24.04'): void
    {
        CloudImage::create([
            'provider' => 'hetzner', 'provider_ref' => $ref, 'key' => $key,
            'kind' => $kind, 'family' => 'ubuntu', 'version' => '24.04', 'label' => 'Ubuntu 24.04',
            'arch' => $arch, 'min_disk_gb' => 5, 'is_active' => true,
        ]);
    }

    /** 🔴 آنچه مشتری واقعاً می‌دید: فهرستِ خالیِ سیستم‌عامل */
    public function test_x86_plan_offers_the_x86_image(): void
    {
        Http::fake();

        $plan = $this->plan('cx22', 'x86', 379);
        $this->image('161547269', 'x86');
        $this->image('161547270', 'arm');

        $this->assertContains('ubuntu-24.04', CloudStoreController::imageKeysFor($plan, 'os'));
    }

    public function test_arm_plan_also_offers_the_image(): void
    {
        Http::fake();

        $plan = $this->plan('cax11', 'arm', 300);
        $this->image('161547269', 'x86');
        $this->image('161547270', 'arm');

        $this->assertContains('ubuntu-24.04', CloudStoreController::imageKeysFor($plan, 'os'));
    }

    /** حالتِ باگ‌دار: اگر همه arm ذخیره شوند، پلنِ x86 چیزی ندارد */
    public function test_all_arm_images_would_leave_an_x86_plan_with_nothing(): void
    {
        Http::fake();

        $plan = $this->plan('cx22', 'x86', 379);
        $this->image('161547270', 'arm');

        $this->assertSame([], CloudStoreController::imageKeysFor($plan, 'os'),
            'این دقیقاً وضعیتی است که مشتری می‌دید');
    }

    // ═══════════════════ باگ ۲: تحویل ═══════════════════

    /** 🔴 پولِ گرفته‌شده و سرورِ تحویل‌نشده — سفارشِ ایمیجِ معماریِ اشتباه */
    public function test_ref_resolves_to_the_matching_architecture(): void
    {
        $this->image('161547269', 'x86');
        $this->image('161547270', 'arm');

        $this->assertSame('161547269', CloudImage::refFor('hetzner', 'ubuntu-24.04', 'x86'));
        $this->assertSame('161547270', CloudImage::refFor('hetzner', 'ubuntu-24.04', 'arm'));
    }

    public function test_ref_never_returns_a_mismatched_architecture(): void
    {
        $this->image('161547269', 'x86');   // فقط x86 موجود است

        $this->assertNull(CloudImage::refFor('hetzner', 'ubuntu-24.04', 'arm'),
            'به‌جای ردیفِ ناجور باید null بدهد تا فراخوان سراغِ زیرساختِ دیگر برود');

        $this->assertSame('161547269', CloudImage::refFor('hetzner', 'ubuntu-24.04', 'x86'));
    }

    /** زیرساختی که معماری اعلام نمی‌کند نباید از کار بیفتد */
    public function test_blank_arch_image_serves_any_plan(): void
    {
        $this->image('9001', '');

        $this->assertSame('9001', CloudImage::refFor('hetzner', 'ubuntu-24.04', 'x86'));
        $this->assertSame('9001', CloudImage::refFor('hetzner', 'ubuntu-24.04', 'arm'));
    }

    /** بدونِ معماری، رفتارِ قبلی حفظ می‌شود (سازگاریِ عقب‌رو) */
    public function test_ref_without_arch_still_resolves(): void
    {
        $this->image('161547269', 'x86');

        $this->assertSame('161547269', CloudImage::refFor('hetzner', 'ubuntu-24.04'));
        $this->assertNull(CloudImage::refFor('hetzner', 'no-such-os'));
    }
}

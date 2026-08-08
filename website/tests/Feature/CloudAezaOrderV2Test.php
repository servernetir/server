<?php

namespace Tests\Feature;

use App\Models\CloudPlan;
use App\Models\Setting;
use App\Services\Cloud\AezaClient;
use App\Services\Cloud\CloudProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * 🔴 باگ: هفته‌ها **هیچ سرورِ ابریِ زیرساختِ ۲ تحویل نشد** — مشتری پول می‌داد و
 * سرور نمی‌گرفت.
 *
 * هر سفارش با `proxy_internal_server_error` (کدِ ۵۰۰) برمی‌گشت. آن پیام از
 * گیت‌ویِ خودشان است و **فرقِ «شکلِ درخواستت را نمی‌شناسم» با «سرورِ من خراب
 * است» را نشان نمی‌دهد** — برای همین ماه‌ها شبیهِ خرابیِ سمتِ آنها به‌نظر
 * می‌رسید. نتیجه: `CloudProvisioner::quarantineProvider()` ۲۲۱ پلن را از فروش
 * برداشت و کسی نمی‌دانست چرا.
 *
 * پاسخِ کتبیِ پشتیبانی (مرداد ۱۴۰۵) شکلِ درست را داد. **چهار** تفاوت با آنچه
 * می‌فرستادیم، هرکدام به‌تنهایی کافی برای ۵۰۰:
 *
 *   ۱) مسیر `‎/api/v2/services/orders` است، نه `‎/api/services/orders`
 *   ۲) `orders` یک **آرایه** از سفارش است، نه فیلدهای صافِ سطحِ بالا
 *   ۳) `method` در **سطحِ بالا** می‌نشیند، بیرونِ سفارش
 *   ۴) `parameters` فقط سیستم‌عامل دارد — ما `name` را هم داخلش می‌گذاشتیم
 *
 * و دو چیزِ دیگر که پشتیبانی گفت و اینجا قفل می‌شود:
 *   • موجودیِ حساب **فقط یورو** می‌تواند باشد ⇒ مسیرِ نرخِ ارز (که ۵۰۰ می‌داد و
 *     `cloud:sync` را می‌خواباند) اصلاً لازم نیست.
 *   • **سندباکس ندارند** ⇒ هیچ‌کدام از این تست‌ها حق ندارد تماسِ واقعی بزند.
 *
 * ⚠️ `Http::swap(new Factory)` لازم است: `Http::fake` استابها را به ترتیبِ ثبت
 * می‌سنجد و **اولین تطبیق برنده است**، پس یک استابِ همه‌گیر از جای دیگر
 * (فیکسچرِ مشترک، تستِ قبلی) می‌تواند این تست را بی‌صدا بی‌اثر کند. یک بار
 * دقیقاً همین در این پروژه اتفاق افتاد.
 */
class CloudAezaOrderV2Test extends TestCase
{
    use RefreshDatabase;

    /** نشانیِ کاملی که پشتیبانی داد — کاملاً سخت‌کد، تا تغییرش بی‌صدا نگذرد */
    private const ORDER_URL = 'https://my.aeza.net/api/v2/services/orders';

    protected function setUp(): void
    {
        parent::setUp();

        Http::swap(new Factory);
        Sleep::fake();                       // حلقهٔ پی‌گیریِ سفارش، بی‌۷ ثانیه انتظار

        Setting::putSecret('aeza_api_token', 'k');
    }

    private function driver(): AezaClient
    {
        return app(AezaClient::class);
    }

    /** ورودیِ استانداردِ `createServer` — همان چیزی که `CloudProvisioner` می‌دهد */
    private function spec(array $over = []): array
    {
        return array_merge([
            'name'         => 'sn-svc-42',
            'plan_ref'     => '153',
            'location_ref' => 'nl',
            'image_ref'    => 'ubuntu_2404',
            'ssh_keys'     => [],
            'disk_gb'      => 60,
            'labels'       => [],
        ], $over);
    }

    // ═════════════════ شکلِ درخواست ═════════════════

    /**
     * ستونِ فقراتِ این فایل: بدنه باید **دقیقاً** همان چیزی باشد که پشتیبانی داد.
     * اگر این بشکند، تحویلِ خودکار دوباره مرده است.
     */
    public function test_order_goes_to_the_v2_endpoint_with_the_array_shaped_body(): void
    {
        Http::fake([
            '*' => Http::response(['data' => ['id' => 8801, 'createdServiceIds' => [55]]], 200),
        ]);

        $r = $this->driver()->createServer($this->spec());

        $this->assertTrue($r['ok'], (string) ($r['message'] ?? ''));

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST') {
                return false;
            }

            $body = $request->data();

            return $request->url() === self::ORDER_URL
                // `method` در **سطحِ بالا**، نه داخلِ سفارش
                && ($body['method'] ?? null) === 'balance'
                && ! isset($body['orders'][0]['method'])
                // `orders` یک آرایهٔ فهرستی با دقیقاً یک سفارش
                && is_array($body['orders'] ?? null)
                && array_is_list($body['orders'])
                && count($body['orders']) === 1
                && ($body['orders'][0]['productId'] ?? null) === 153
                && ($body['orders'][0]['count'] ?? null) === 1
                && ($body['orders'][0]['name'] ?? null) === 'sn-svc-42'
                && ($body['orders'][0]['term'] ?? null) === 'month'
                && ($body['orders'][0]['autoProlong'] ?? null) === false
                && ($body['orders'][0]['parameters']['os'] ?? null) === 'ubuntu_2404';
        });
    }

    /**
     * 🔴 بدنهٔ صافِ نسخهٔ ۱ **هرگز** نباید برود — همان چیزی بود که ۵۰۰ می‌گرفت.
     *
     * این تست عمداً «نبودِ» چیزها را می‌سنجد، نه بودنشان: یک بازگشتِ ناخواسته به
     * شکلِ قبلی می‌تواند تستِ بالا را هم پاس کند اگر روزی کسی هر دو شکل را
     * هم‌زمان بفرستد (که خودش ۵۰۰ می‌گیرد).
     */
    public function test_the_old_flat_v1_body_is_never_sent_again(): void
    {
        Http::fake([
            '*' => Http::response(['data' => ['id' => 1, 'createdServiceIds' => [2]]], 200),
        ]);

        $this->driver()->createServer($this->spec());

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST') {
                return true;                 // فقط دربارهٔ خودِ سفارش حرف می‌زنیم
            }

            $body = $request->data();

            // مسیرِ بی‌نسخه دیگر زده نمی‌شود
            $this->assertStringContainsString('/api/v2/services/orders', $request->url());

            // و هیچ‌کدام از فیلدهای صافِ نسخهٔ ۱ در سطحِ بالا نیست
            foreach (['productId', 'count', 'term', 'autoProlong', 'parameters'] as $flat) {
                $this->assertArrayNotHasKey($flat, $body, "فیلدِ «{$flat}» نباید در سطحِ بالا باشد");
            }

            return true;
        });
    }

    /**
     * ⚠️ `parameters` در مثالِ پشتیبانی **فقط** سیستم‌عامل دارد و `name` هم‌ترازِ
     * آن است. ما `name` را در هر دو جا می‌گذاشتیم؛ فیلدِ اضافه در بدنه‌ای که
     * اعتبارسنجیِ سخت‌گیر دارد، همان ۵۰۰ را برمی‌گرداند.
     */
    public function test_parameters_carry_only_the_image_and_never_the_name(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 1, 'createdServiceIds' => [2]]], 200)]);

        $this->driver()->createServer($this->spec());

        Http::assertSent(function ($request) {
            $params = $request->data()['orders'][0]['parameters'] ?? [];

            return $params === ['os' => 'ubuntu_2404'];
        });
    }

    /**
     * شناسهٔ محصول در مثالِ پشتیبانی **عدد** است. ما آن را از دیتابیس رشته‌ای
     * برمی‌داریم و رشتهٔ `"153"` در JSON با عددِ `153` یکی نیست.
     */
    public function test_product_id_is_sent_as_a_json_number(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 1, 'createdServiceIds' => [2]]], 200)]);

        $this->driver()->createServer($this->spec(['plan_ref' => '153']));

        Http::assertSent(function ($request) {
            $id = $request->data()['orders'][0]['productId'] ?? null;

            // نه فقط برابرِ ۱۵۳، بلکه **از نوعِ عدد**
            return $id === 153 && ! is_string($id);
        });
    }

    /** نرم‌افزارِ آماده (recipe) به فیلدِ خودش می‌رود، نه به `os` */
    public function test_recipe_image_goes_to_the_recipe_parameter(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 1, 'createdServiceIds' => [2]]], 200)]);

        $this->driver()->createServer($this->spec(['image_ref' => 'recipe:77']));

        Http::assertSent(fn ($request) => ($request->data()['orders'][0]['parameters'] ?? []) === ['recipe' => '77']);
    }

    // ═════════════════ خواندنِ پاسخ ═════════════════

    /**
     * پاسخِ نسخهٔ ۲ به‌احتمالِ زیاد **آرایه‌ای** است (ما یک آرایه سفارش فرستادیم)
     * و شکلِ دقیقش را ندیده‌ایم — سندباکس ندارند.
     *
     * 🔴 اگر شناسهٔ سرویس را پیدا نکنیم، سرویس به `order:` می‌افتد و مشتری تا
     * اجرای بعدیِ کرون منتظر می‌مانَد؛ پس جستجو باید شکلِ تودرتو را هم بگیرد.
     */
    public function test_service_id_is_found_inside_a_nested_v2_response(): void
    {
        Http::fake(['*' => Http::response([
            'data' => ['items' => [
                ['id' => 9001, 'createdServiceIds' => [12345]],
            ]],
        ], 200)]);

        $r = $this->driver()->createServer($this->spec());

        $this->assertTrue($r['ok']);
        $this->assertSame('12345', $r['ref'], 'شناسهٔ سرویس باید از ردیفِ تودرتو خوانده شود');
    }

    /**
     * 🔴 مهم‌ترین محافظِ پول: اگر سفارش ثبت شد ولی شناسهٔ سرویس هنوز نیامده،
     * باید `order:{id}` برگردد.
     *
     * بی‌این، `provider_ref` نال می‌مانَد، محافظِ «از قبل خریده‌ای» در
     * `CloudProvisioner` فعال نمی‌شود، و اجرای بعدیِ کرون **سرورِ دوم** می‌خرد.
     */
    public function test_pending_order_returns_an_order_ref_so_the_next_run_never_buys_twice(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 8801]], 200)]);

        $r = $this->driver()->createServer($this->spec());

        $this->assertTrue($r['ok']);
        $this->assertSame('order:8801', $r['ref']);
        $this->assertSame('building', $r['status']);
    }

    /** شناسهٔ سفارش می‌تواند داخلِ آرایه باشد — همان‌جا هم باید پیدا شود */
    public function test_order_id_is_found_when_the_response_is_a_list(): void
    {
        Http::fake(['*' => Http::response(['data' => ['items' => [['id' => 7702]]]], 200)]);

        $this->assertSame('order:7702', $this->driver()->createServer($this->spec())['ref']);
    }

    /**
     * پی‌گیریِ سفارشِ نیمه‌کاره: پشتیبانی فقط دربارهٔ **ثبتِ** سفارش نوشت، پس
     * خواندنش روی هر دو نسخه امتحان می‌شود. GET نه پول خرج می‌کند نه چیزی
     * می‌سازد، پس این تنها جایی است که حدس‌زدنِ مسیر بی‌خطر است.
     */
    public function test_resolve_order_falls_back_to_the_v1_read_path(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/api/v2/services/orders/')) {
                return Http::response(['error' => ['message' => 'Not found']], 404);
            }

            if (str_contains($request->url(), '/api/services/orders/')) {
                return Http::response(['data' => ['createdServiceIds' => [4242]]], 200);
            }

            return Http::response([], 200);
        });

        $this->assertSame('4242', $this->driver()->resolveOrder('order:8801'));
    }

    /**
     * سفارشِ ناموفق **هرگز** نباید `ref` بدهد — وگرنه ردیفِ نمونه به شناسه‌ای
     * وصل می‌شود که وجود ندارد و گزارشِ «شبح» را دروغ می‌کند.
     */
    public function test_a_rejected_order_yields_no_ref_and_an_explaining_message(): void
    {
        Http::fake(['*' => Http::response(
            ['error' => ['message' => 'proxy_internal_server_error']], 500
        )]);

        $r = $this->driver()->createServer($this->spec());

        $this->assertFalse($r['ok']);
        $this->assertNull($r['ref']);
        $this->assertSame('error', $r['status']);
        $this->assertStringContainsString('500', $r['message'], 'کدِ HTTP باید در پیام بماند');
    }

    // ═════════════════ ارز: دیگر هیچ تماسی برای نرخ ═════════════════

    /**
     * 🔴 `payment/currencies` کدِ ۵۰۰ می‌داد و چون کاتالوگ بی‌ضریب هیچ پلنی
     * نمی‌ساخت، `cloud:sync` هم با کدِ خروجیِ ۱ تمام می‌شد.
     *
     * پشتیبانی گفت موجودیِ حساب فقط یورو می‌تواند باشد ⇒ ضریبی لازم نیست. این
     * تست هم می‌سنجد که **اصلاً پرسیده نمی‌شود** و هم اینکه عدد به یورو خوانده
     * می‌شود؛ فقط دومی کافی نبود، چون تماسِ ۵۰۰ به‌تنهایی هم سینک را می‌کشت.
     */
    public function test_catalog_never_asks_for_a_currency_rate_and_reads_euro_directly(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'currencies')) {
                return Http::response(['error' => ['message' => 'proxy_internal_server_error']], 500);
            }

            if (str_contains($request->url(), 'services/products')) {
                return Http::response(['data' => ['items' => [$this->product()], 'total' => 1]], 200);
            }

            return Http::response(['data' => ['items' => [], 'total' => 0]], 200);
        });

        $cat = $this->driver()->fetchCatalog();

        $this->assertTrue($cat['ok'], (string) ($cat['message'] ?? ''));
        $this->assertCount(1, $cat['plans'], (string) ($cat['message'] ?? ''));

        // عددِ ۵۰۰ = ۵ یورو = ۵۰۰ سنت. هیچ ضریبی در کار نیست.
        $this->assertSame(500, $cat['plans'][0]['cost_eur_cents']);

        foreach (Http::recorded() as [$request]) {
            $this->assertStringNotContainsString('currencies', $request->url(),
                'مسیرِ نرخِ ارز دیگر نباید اصلاً زده شود');
        }
    }

    /** بی‌تنظیمِ کهنهٔ روبل هم کاتالوگ باید کامل ساخته شود */
    public function test_catalog_no_longer_depends_on_the_old_ruble_setting(): void
    {
        Setting::put('aeza_rub_per_eur', null);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'services/products')) {
                return Http::response(['data' => ['items' => [$this->product()], 'total' => 1]], 200);
            }

            return Http::response(['data' => ['items' => [], 'total' => 0]], 200);
        });

        $cat = $this->driver()->fetchCatalog();

        $this->assertCount(1, $cat['plans'], 'نبودِ نرخِ روبل دیگر نباید کاتالوگ را خالی کند');
        $this->assertSame(500, $cat['plans'][0]['cost_eur_cents']);
    }

    /**
     * شناسه‌ای که در `parameters.os` می‌رود باید **اسلاگِ رشته‌ای** باشد
     * (`ubuntu_2404`)، نه شناسهٔ عددی — تنها نمونهٔ معتبرِ ما از آن فیلد همین است.
     */
    public function test_operating_system_ref_prefers_the_string_slug_over_the_numeric_id(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/api/os')) {
                return Http::response(['data' => ['items' => [
                    ['id' => 940, 'slug' => 'ubuntu_2404', 'name' => 'Ubuntu 24.04'],
                ], 'total' => 1]], 200);
            }

            return Http::response(['data' => ['items' => [], 'total' => 0]], 200);
        });

        $images = $this->driver()->fetchCatalog()['images'];
        $os = collect($images)->firstWhere('kind', 'os');

        $this->assertNotNull($os);
        $this->assertSame('ubuntu_2404', $os['provider_ref'],
            '🔴 شناسهٔ عددی به `parameters.os` نمی‌خورد — سفارش رد می‌شود و پول رفته است');
        $this->assertSame('ubuntu-24.04', $os['key'], 'کلیدِ یکسان‌شده برای مشتری عوض نمی‌شود');
    }

    /** اگر آن API اسلاگ نداد، شناسهٔ عددی آخرین چاره است — نه رشتهٔ خالی */
    public function test_numeric_id_is_the_last_resort_when_no_slug_exists(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/api/os')) {
                return Http::response(['data' => ['items' => [
                    ['id' => 940, 'name' => 'Ubuntu 24.04'],
                ], 'total' => 1]], 200);
            }

            return Http::response(['data' => ['items' => [], 'total' => 0]], 200);
        });

        $os = collect($this->driver()->fetchCatalog()['images'])->firstWhere('kind', 'os');

        $this->assertSame('940', $os['provider_ref'] ?? null);
    }

    // ═════════════════ بازکردنِ پلن‌های قرنطینه‌شده ═════════════════

    /**
     * 🔴 قرنطینهٔ خودکار ۲۲۱ پلن را بست و تنها راهِ برگرداندنشان زدنِ دکمهٔ
     * ردیف‌به‌ردیف در پنل بود. `cloud:reopen` آن را یک‌جا انجام می‌دهد.
     *
     * ⚠️ **عمداً خودکار نیست** و در `routes/console.php` نمی‌نشیند: «سفارش دیگر
     * ۵۰۰ نمی‌دهد» را فقط یک سفارشِ واقعیِ موفق ثابت می‌کند و آن تصمیم — با
     * پولِ واقعی و بی‌سندباکس — مالِ کارفراست.
     */
    public function test_reopen_command_restores_only_the_automatic_quarantine(): void
    {
        $auto = $this->plan('a1', 'خودکار بسته شد ولی نه با پیشوندِ رسمی');
        $auto->update(['admin_note' => CloudProvisioner::QUARANTINE_PREFIX.' زیرساخت سفارش را نپذیرفت (۵۰۰)']);

        $byHand = $this->plan('a2', 'تحویلش دستی است، عمداً بسته');
        $open = $this->plan('a3', null);
        $open->update(['admin_disabled' => false]);

        $this->artisan('cloud:reopen', ['--force' => true])->assertExitCode(0);

        $this->assertFalse($auto->fresh()->admin_disabled, 'قرنطینهٔ خودکار باید باز شود');
        $this->assertNull($auto->fresh()->admin_note);

        $this->assertTrue($byHand->fresh()->admin_disabled,
            '🔴 تصمیمِ آگاهانهٔ مدیر نباید با یک فرمانِ گروهی پاک شود');
    }

    /** حالتِ آزمایشی باید فقط گزارش بدهد و **هیچ ردیفی** را عوض نکند */
    public function test_reopen_dry_run_changes_nothing(): void
    {
        $p = $this->plan('b1', CloudProvisioner::QUARANTINE_PREFIX.' چیزی');

        $this->artisan('cloud:reopen', ['--dry-run' => true])->assertExitCode(0);

        $this->assertTrue($p->fresh()->admin_disabled);
    }

    /** فقط یک زیرساخت — بقیه دست‌نخورده می‌مانند */
    public function test_reopen_can_be_limited_to_one_provider(): void
    {
        $aeza = $this->plan('c1', CloudProvisioner::QUARANTINE_PREFIX.' چیزی', 'aeza');
        $hetzner = $this->plan('c2', CloudProvisioner::QUARANTINE_PREFIX.' چیزی', 'hetzner');

        $this->artisan('cloud:reopen', ['provider' => 'aeza', '--force' => true])->assertExitCode(0);

        $this->assertFalse($aeza->fresh()->admin_disabled);
        $this->assertTrue($hetzner->fresh()->admin_disabled, 'زیرساختِ دیگر نباید باز شود');
    }

    /**
     * ⚠️ محافظِ ساده ولی حیاتی: هیچ‌کدام از این تست‌ها نباید تماسِ واقعی بزند.
     * سندباکسی وجود ندارد؛ یک `Http::fake` جاافتاده یعنی سفارشِ واقعی و پولِ
     * واقعی. اگر روزی کسی fake را برداشت، اینجا صدا در می‌آید.
     */
    public function test_no_test_in_this_file_can_reach_the_real_api(): void
    {
        Http::fake(['*' => Http::response(['data' => ['id' => 1, 'createdServiceIds' => [2]]], 200)]);

        $this->driver()->createServer($this->spec());

        $recorded = Http::recorded();

        $this->assertNotEmpty($recorded, 'اگر هیچ درخواستی ثبت نشده، یعنی fake اصلاً درگیر نشده');

        foreach ($recorded as [$request]) {
            $this->assertStringStartsWith('https://my.aeza.net/api/', $request->url());
        }
    }

    // ───────────────────────── کمکی ─────────────────────────

    /** محصولِ سرورِ مجازی با ساختارِ واقعیِ آن API — قیمت ۵۰۰ سنت = ۵ یورو */
    private function product(array $over = []): array
    {
        return array_merge([
            'id'             => 153,
            'name'           => 'NLs-2',
            'type'           => 'vps',
            'serviceHandler' => 'vm6',
            'configuration'  => [
                ['slug' => 'cpu', 'base' => 2],
                ['slug' => 'ram', 'base' => 4096],
                ['slug' => 'rom', 'base' => 60],
            ],
            'prices' => ['month' => 500],
            'group'  => [
                'id' => 12, 'name' => 'Netherlands, Amsterdam', 'type' => 'vps',
                'payload' => ['code' => 'nl', 'label' => 'NL-SHARED', 'mode' => 'shared'],
            ],
        ], $over);
    }

    private function plan(string $ref, ?string $note, string $provider = 'aeza'): CloudPlan
    {
        return CloudPlan::create([
            'provider' => $provider, 'provider_ref' => $ref, 'location_code' => 'nl-amsterdam',
            'public_name' => 'CV-2-4', 'slug' => 'cv-2-4-nl-amsterdam-'.$ref,
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 60, 'disk_type' => 'nvme',
            'traffic_gb' => 1024, 'cpu_kind' => 'shared', 'arch' => 'x86',
            'cost_eur_cents' => 500, 'price_eur_cents' => 730, 'price_irt' => 700000,
            'is_active' => true, 'in_stock' => true,
            'admin_disabled' => true, 'admin_note' => $note,
        ]);
    }
}

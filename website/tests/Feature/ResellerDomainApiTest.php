<?php

namespace Tests\Feature;

use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\CustomerApiToken;
use App\Models\CustomerProfile;
use App\Models\Domain;
use App\Models\Invoice;
use App\Services\Domain\Reseller\ResellerProgram;
use App\Services\Domain\TldGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * APIِ نمایندگیِ دامنه — مسیرهایی که پول جابه‌جا می‌کنند.
 *
 * ═══ چرا هر تست سه چیز را هم‌زمان می‌سنجد ═══
 *
 * درسِ ثبت‌شدهٔ این پروژه: **«کدِ ۲۰۰ یعنی هیچ.»** روی مسیری که از اعتبارِ
 * واقعی کسر می‌کند، حتی «پاسخِ درست» هم کافی نیست. هر تستِ پولیِ این فایل
 * سه ادعا دارد:
 *
 *   ۱. تعدادِ ردیفِ دیتابیس (دامنه / فاکتور)
 *   ۲. مجموعِ دفترِ اعتبار
 *   ۳. تعدادِ تماسِ بیرونی با رجیسترار
 *
 * چون هر سه خرابیِ گران‌قیمتی که این پروژه خورده، دقیقاً یکی از این سه را
 * می‌شکست در حالی که دو تای دیگر سالم بودند.
 */
class ResellerDomainApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.openprovider.username', 'test@example.com');
        config()->set('services.openprovider.password', 'secret');
        config()->set('services.openprovider.nameservers', ['ns1.servernet.cloud', 'ns2.servernet.cloud']);
        config()->set('services.openprovider.margin', ['default' => 40]);
        config()->set('domain_reseller.min_margin_pct', 8);

        TldGate::clear('com');
    }

    /**
     * ⚠️ **یک** `Http::fake` در کلِ تست و هیچ استابِ `'*'`ِ همه‌گیر.
     *
     * درسِ ثبت‌شده: استابها به ترتیبِ ثبت سنجیده می‌شوند و اولین تطبیق برنده
     * است، پس یک `'*'` هر `Http::fake()` بعدی را **بی‌اثر** می‌کند و تست
     * بی‌صدا هیچ‌چیز نمی‌سنجد.
     */
    private function fakeRegistrar(string $status = 'free', bool $registerOk = true): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();

        Http::fake([
            '*/auth/login*' => Http::response(['code' => 0, 'data' => ['token' => 't']]),
            '*/customers*'  => Http::response(['code' => 0, 'data' => ['handle' => 'AB123-NL']]),

            '*/domains/check*' => Http::response(['code' => 0, 'data' => ['results' => [[
                'domain'    => 'sanjesh-nemayande.com',
                'status'    => $status,
                'is_premium'=> false,
                'price'     => [
                    'reseller' => ['price' => 10.0, 'currency' => 'EUR'],
                ],
            ]]]]),

            // جستجوی دامنهٔ موجود نزدِ رجیسترار (idempotency): پیدا نشد
            '*/domains?*' => Http::response(['code' => 0, 'data' => ['results' => []]]),

            '*/domains*' => $registerOk
                ? Http::response(['code' => 0, 'data' => [
                    'id' => 5510, 'expiration_date' => '2027-01-01 00:00:00',
                ]])
                : Http::response(['code' => 307, 'desc' => 'taken'], 500),
        ]);

        // نرخِ ارز از تنظیماتِ مدیر، نه تماسِ شبکه‌ای
        \App\Models\Setting::put('pricing_rate_override', '100000');
    }

    private function reseller(int $credit = 0, string $level = 'gold'): Customer
    {
        $c = Customer::create([
            'email'    => 'r'.random_int(1000, 99999).'@x.com',
            'phone'    => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);

        CustomerProfile::create([
            'customer_id' => $c->id, 'type' => 'individual', 'is_default' => true,
            'status' => 'verified', 'email' => $c->email, 'mobile' => '09123456789',
            'country' => 'IR', 'province' => 'تهران', 'city' => 'تهران',
            'address' => 'خیابان نمونه', 'postal_code' => '1234567890',
            'first_name' => 'احسان', 'last_name' => 'ابراهیمی',
        ]);

        $c->forceFill(['is_reseller' => true, 'reseller_level' => $level])->save();

        if ($credit > 0) {
            CreditEntry::create([
                'customer_id' => $c->id, 'currency_code' => 'IRT',
                'amount' => $credit, 'balance_after' => $credit, 'reason' => 'topup',
            ]);
        }

        return $c->refresh();
    }

    /** @param array<int,string> $abilities */
    private function token(Customer $c, array $abilities = ['domains:write', 'domains:manage']): string
    {
        [, $plain] = CustomerApiToken::issue($c->id, 'whmcs', $abilities);

        return $plain;
    }

    private function call_(string $token, string $uri, array $body = [], array $headers = [])
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token] + $headers)
            ->postJson($uri, $body);
    }

    // ═══════════════ ۱) idempotency — گران‌ترین باگِ ممکن ═══════════════

    /**
     * 🔴 دو درخواستِ یکسان با یک کلید = **یک** دامنه، **یک** کسر، **یک** خرید.
     *
     * WHMCS خودش روی timeout درخواست را دوباره می‌فرستد. بی‌این محافظ:
     * دو ردیفِ `Domain`، دو فاکتور، دو کسرِ اعتبار، دو خرید از رجیسترار — و
     * کدِ ۲۰۰ روی هر دو. هیچ‌کس تا رسیدنِ صورت‌حسابِ رجیسترار خبردار نمی‌شود.
     */
    public function test_the_same_idempotency_key_never_buys_a_second_domain(): void
    {
        $this->fakeRegistrar();
        $c = $this->reseller(credit: 50_000_000);
        $t = $this->token($c);

        $body = ['domain' => 'sanjesh-nemayande.com', 'years' => 1];
        $head = ['Idempotency-Key' => 'whmcs-order-42'];

        $first = $this->call_($t, '/api/v1/domains', $body, $head);
        $second = $this->call_($t, '/api/v1/domains', $body, $head);

        $first->assertStatus(201);

        $this->assertSame(1, Domain::where('domain', 'sanjesh-nemayande.com')->count(),
            'درخواستِ دوم یک ردیفِ دامنهٔ تازه ساخت — قفلِ اتمیِ رجیسترار این حالت را نمی‌گیرد');

        $this->assertSame(1, Invoice::where('customer_id', $c->id)->count(),
            'دو فاکتور صادر شد');

        $this->assertSame(1, CreditEntry::where('customer_id', $c->id)
            ->where('amount', '<', 0)->count(),
            'اعتبار دو بار کسر شد');

        $this->assertTrue((bool) ($second->json('replayed') ?? false),
            'پاسخِ دوم باید پخشِ دوبارهٔ پاسخِ اول باشد، نه یک سفارشِ تازه');
    }

    // ═══════════════ ۲) پول بدونِ کالا، کالا بدونِ پول ═══════════════

    /** اعتبارِ ناکافی: نه دامنه‌ای، نه فاکتوری، نه یک ریال کسر */
    public function test_an_order_without_enough_credit_touches_nothing(): void
    {
        $this->fakeRegistrar();
        $c = $this->reseller(credit: 1000);
        $t = $this->token($c);

        $res = $this->call_($t, '/api/v1/domains', ['domain' => 'sanjesh-nemayande.com']);

        $res->assertStatus(402)->assertJsonPath('error', 'insufficient_credit');

        $this->assertSame(0, Domain::count());
        $this->assertSame(0, Invoice::count());
        $this->assertSame(1000, $c->creditBalance('IRT'), 'موجودی دست‌خورده');
    }

    /**
     * 🔴 پسوندِ مسدود **پیش از** گرفتنِ پول رد می‌شود.
     *
     * درسِ `zhina.shop`: گیتی که بعد از کسرِ اعتبار بنشیند فقط جای شکست را
     * عوض می‌کند — نماینده پولش رفته و دامنه‌ای ندارد.
     */
    public function test_a_blocked_tld_is_refused_before_any_money_moves(): void
    {
        $this->fakeRegistrar();
        $c = $this->reseller(credit: 50_000_000);
        $t = $this->token($c);

        TldGate::block('com', 'قراردادِ رجیستری امضا نشده است.');

        $res = $this->call_($t, '/api/v1/domains', ['domain' => 'sanjesh-nemayande.com']);

        $res->assertStatus(422)->assertJsonPath('error', 'tld_blocked');

        $this->assertSame(0, Invoice::count(), 'فاکتور صادر شد برای پسوندی که می‌دانیم ثبت نمی‌شود');
        $this->assertSame(50_000_000, $c->creditBalance('IRT'));
        Http::assertNothingSent();
    }

    // ═══════════════ ۳) کفِ حاشیه — محافظِ اصلیِ برنامه ═══════════════

    /**
     * 🔴 تخفیفِ سطح هرگز قیمت را زیرِ «بهای تمام‌شده + حداقلِ حاشیه» نمی‌بَرد.
     *
     * حاشیه در این سیستم per-TLD است؛ یک تخفیفِ ثابتِ ۱۵٪ روی پسوندی با
     * حاشیهٔ ۱۰٪ یعنی فروشِ زیرِ قیمتِ خرید، روی **هر** تراکنش، بی‌هیچ خطایی.
     */
    public function test_the_level_discount_can_never_price_below_cost(): void
    {
        $this->fakeRegistrar();

        // حاشیهٔ باریک (۱۰٪) در برابرِ تخفیفِ طلاییِ ۱۵٪
        config()->set('services.openprovider.margin', ['default' => 10]);

        $c = $this->reseller(credit: 50_000_000);
        $t = $this->token($c);

        $res = $this->call_($t, '/api/v1/domains/check', ['domain' => 'sanjesh-nemayande.com']);

        $res->assertOk();
        $row = $res->json('data.0');

        // بهای تمام‌شده: ۱۰ یورو × ۱۰۰٬۰۰۰ = ۱٬۰۰۰٬۰۰۰ تومان
        $cost = 10 * 100_000;
        $floor = (int) ceil($cost * 1.08);

        $this->assertGreaterThanOrEqual($floor, (int) $row['price']['register'],
            'قیمتِ نماینده زیرِ کفِ حاشیه رفت — یعنی هر فروش ضرر است');

        $this->assertTrue((bool) $row['price_floored'],
            'کف فعال شد ولی گفته نشد؛ نماینده تخفیفِ کامل را انتظار دارد و توضیحی نمی‌بیند');
    }

    /** و نیمهٔ دوم: وقتی حاشیه جا دارد، تخفیف **واقعاً** اعمال می‌شود */
    public function test_the_discount_really_applies_when_the_margin_allows_it(): void
    {
        $this->fakeRegistrar();
        config()->set('services.openprovider.margin', ['default' => 60]);

        $c = $this->reseller(credit: 50_000_000);
        $t = $this->token($c);

        $row = $this->call_($t, '/api/v1/domains/check', ['domain' => 'sanjesh-nemayande.com'])
            ->json('data.0');

        $this->assertFalse((bool) $row['price_floored']);
        $this->assertGreaterThan(0, (float) $row['discount_pct'],
            'نمایندهٔ طلایی هیچ تخفیفی نگرفت با اینکه حاشیه جا داشت');
        $this->assertLessThan((int) $row['price']['retail'], (int) $row['price']['register']);
    }

    // ═══════════════ ۴) مرزِ دسترسی ═══════════════

    /** توکنِ فقط‌خواندنی نباید بتواند پول خرج کند */
    public function test_a_read_only_token_cannot_buy_anything(): void
    {
        $this->fakeRegistrar();
        $c = $this->reseller(credit: 50_000_000);
        $t = $this->token($c, ['read']);

        $this->call_($t, '/api/v1/domains', ['domain' => 'sanjesh-nemayande.com'])
            ->assertStatus(403)
            ->assertJsonPath('error', 'insufficient_scope');

        $this->assertSame(0, Invoice::count());
    }

    /**
     * محدودیتِ IP باید روی **خودِ توکن** کار کند.
     *
     * 🔴 این تست وجود دارد چون `EnforceCustomerIp` — که ظاهراً همین کار را
     * می‌کند — مشتری را از `Auth::guard('customer')` می‌گیرد و مسیرِ توکن
     * هرگز واردِ guard نمی‌شود. یعنی آن میدل‌ور روی `api/v1` یک no-opِ کاملاً
     * بی‌صداست: صفحهٔ امنیت می‌گوید «محدود شده» و هیچ‌چیز محدود نشده.
     */
    public function test_the_ip_allowlist_lives_on_the_token_and_actually_blocks(): void
    {
        $this->fakeRegistrar();
        $c = $this->reseller(credit: 50_000_000);

        [$token, $plain] = CustomerApiToken::issue($c->id, 'whmcs', ['domains:write']);
        $token->forceFill(['allowed_cidrs' => ['203.0.113.7/32']])->save();

        $this->call_($plain, '/api/v1/domains', ['domain' => 'sanjesh-nemayande.com'])
            ->assertStatus(403)
            ->assertJsonPath('error', 'ip_not_allowed');

        $this->assertSame(0, Invoice::count());
    }

    /** توکنِ منقضی علتِ خودش را می‌گوید، نه یک «invalid_token»ِ گمراه‌کننده */
    public function test_an_expired_token_says_so(): void
    {
        $c = $this->reseller();
        [$token, $plain] = CustomerApiToken::issue($c->id, 'old', ['domains:write']);
        $token->forceFill(['expires_at' => now()->subDay()])->save();

        $this->call_($plain, '/api/v1/domains', ['domain' => 'x.com'])
            ->assertStatus(401)
            ->assertJsonPath('error', 'token_expired');
    }

    // ═══════════════ ۵) نشتِ داده ═══════════════

    /**
     * 🔴 بهایِ تمام‌شده و شناسهٔ رجیسترار هرگز به نماینده نمی‌رسند.
     *
     * حاشیهٔ سودِ ما دادهٔ داخلی است — همان قاعدهٔ `CloudPlan::$hidden`. و
     * `op_id`/`owner_handle` شناسه‌های حسابِ رجیستراری ما هستند.
     */
    public function test_our_cost_and_registrar_ids_never_reach_the_reseller(): void
    {
        $this->fakeRegistrar();
        $c = $this->reseller(credit: 50_000_000);
        $t = $this->token($c);

        $this->call_($t, '/api/v1/domains', ['domain' => 'sanjesh-nemayande.com'],
            ['Idempotency-Key' => 'k1'])->assertStatus(201);

        $body = $this->withHeaders(['Authorization' => 'Bearer '.$t])
            ->getJson('/api/v1/domains/sanjesh-nemayande.com')
            ->assertOk()
            ->getContent();

        foreach (['cost_amount', 'cost_currency', 'op_id', 'owner_handle', 'openprovider'] as $leak) {
            $this->assertStringNotContainsString($leak, $body,
                "«{$leak}» در پاسخِ API نشت کرد");
        }
    }

    // ═══════════════ ۶) دو صف که نباید هم را ببینند ═══════════════

    /**
     * 🔴 صفِ ثبت و صفِ تمدید هیچ اشتراکی ندارند.
     *
     * هر دو روی همان ستونِ `provision_status` می‌نشینند و فقط با `status` از
     * هم جدا می‌شوند. اگر APIِ نمایندگی مسیرِ سومی باز کند، **یک تمدید
     * به‌جای ثبت پردازش می‌شود** و دامنه دوباره خریده می‌شود.
     */
    public function test_the_api_never_puts_a_domain_in_both_queues(): void
    {
        $this->fakeRegistrar();
        $c = $this->reseller(credit: 200_000_000);
        $t = $this->token($c);

        $this->call_($t, '/api/v1/domains', ['domain' => 'sanjesh-nemayande.com'],
            ['Idempotency-Key' => 'k2'])->assertStatus(201);

        Domain::where('domain', 'sanjesh-nemayande.com')
            ->update(['status' => 'active', 'provision_status' => 'none', 'renew_toman' => 1_500_000]);

        $this->call_($t, '/api/v1/domains/sanjesh-nemayande.com/renew', ['years' => 1],
            ['Idempotency-Key' => 'k3'])->assertOk();

        $reg = Domain::query()->awaitingRegistration()->pluck('id')->all();
        $ren = Domain::query()->awaitingRenewal()->pluck('id')->all();

        $this->assertSame([], array_intersect($reg, $ren),
            'یک دامنه هم‌زمان در صفِ ثبت و صفِ تمدید است — یکی از دو کرون آن را دوباره می‌خرد');
    }

    /** ثبتِ دوباره روی دامنه‌ای که از قبل فعال است، رد می‌شود */
    public function test_registering_an_already_active_domain_is_refused(): void
    {
        $this->fakeRegistrar();
        $c = $this->reseller(credit: 200_000_000);
        $t = $this->token($c);

        $this->call_($t, '/api/v1/domains', ['domain' => 'sanjesh-nemayande.com'],
            ['Idempotency-Key' => 'k4'])->assertStatus(201);

        Domain::where('domain', 'sanjesh-nemayande.com')->update(['status' => 'active']);

        $this->call_($t, '/api/v1/domains', ['domain' => 'sanjesh-nemayande.com'],
            ['Idempotency-Key' => 'k5'])->assertStatus(409);

        $this->assertSame(1, Invoice::count());
    }

    // ═══════════════ ۷) عملیاتِ عمداً غایب ═══════════════

    /**
     * باز کردنِ قفلِ انتقال از API ممکن نیست.
     *
     * عملی که فقط محافظت **اضافه** می‌کند بی‌خطر است؛ عملی که محافظت را
     * برمی‌دارد باید انسان ببیندش. قفلِ باز پیش‌نیازِ بردنِ دامنه است.
     */
    public function test_the_transfer_lock_can_be_turned_on_but_never_off(): void
    {
        $this->fakeRegistrar();
        $c = $this->reseller(credit: 50_000_000);
        $t = $this->token($c);

        Domain::create([
            'customer_id' => $c->id, 'domain' => 'a.com', 'sld' => 'a', 'tld' => 'com',
            'registrar' => 'openprovider', 'status' => 'active', 'provision_status' => 'done',
            'op_id' => 99, 'period_years' => 1, 'is_locked' => true,
        ]);

        $this->call_($t, '/api/v1/domains/a.com/lock', ['locked' => false])
            ->assertStatus(403)
            ->assertJsonPath('error', 'panel_only');

        $this->assertTrue((bool) Domain::where('domain', 'a.com')->first()->is_locked);
    }

    // ═══════════════ ۸) سطح‌بندی ═══════════════

    /** ارتقا فوری است — نماینده نباید تا فردا صبح منتظر بمانَد */
    public function test_a_purchase_promotes_the_level_immediately(): void
    {
        $this->fakeRegistrar();
        config()->set('domain_reseller.levels', [
            ['key' => 'starter', 'name' => ['fa' => 'آغازین'], 'min_spend_irt' => 0, 'min_active_domains' => 0, 'discount_pct' => 0],
            ['key' => 'bronze', 'name' => ['fa' => 'برنز'], 'min_spend_irt' => 1000, 'min_active_domains' => 0, 'discount_pct' => 5],
        ]);

        $c = $this->reseller(credit: 50_000_000, level: 'starter');
        $t = $this->token($c);

        $this->call_($t, '/api/v1/domains', ['domain' => 'sanjesh-nemayande.com'],
            ['Idempotency-Key' => 'k6'])->assertStatus(201);

        $this->assertSame('bronze', $c->refresh()->reseller_level,
            'خرید انجام شد ولی سطح تکان نخورد — نماینده رابطهٔ علت و معلول را حس نمی‌کند');
    }

    /**
     * 🔴 تنزل **فوری نیست**.
     *
     * برنامهٔ وفاداری‌ای که یک ماهِ کم‌فروش را با حذفِ آنیِ سطح مجازات کند،
     * نماینده را همان روز به رقیب می‌فرستد.
     */
    public function test_a_drop_in_volume_does_not_demote_on_the_same_day(): void
    {
        config()->set('domain_reseller.levels', [
            ['key' => 'starter', 'name' => ['fa' => 'آغازین'], 'min_spend_irt' => 0, 'min_active_domains' => 0, 'discount_pct' => 0],
            ['key' => 'gold', 'name' => ['fa' => 'طلایی'], 'min_spend_irt' => 999_999_999, 'min_active_domains' => 0, 'discount_pct' => 15],
        ]);

        $c = $this->reseller(level: 'gold');
        $program = app(ResellerProgram::class);

        $first = $program->review($c);

        $this->assertFalse($first['changed'], 'همان روزِ اول تنزل داده شد');
        $this->assertSame('grace_started', $first['reason']);
        $this->assertNotNull($c->refresh()->reseller_level_locked_until,
            'مهلت روشن نشد — یعنی نماینده هیچ فرصتی برای جبران ندارد');

        // بعد از پایانِ مهلت، حالا تنزل مجاز است
        $c->forceFill(['reseller_level_locked_until' => now()->subDay()])->save();

        $second = $program->review($c->refresh());
        $this->assertTrue($second['changed']);
        $this->assertSame('demoted', $second['reason']);
    }

    /** تخفیفِ نمایندگی به هیچ‌کسِ غیرِ نماینده نمی‌رسد */
    public function test_a_normal_customer_gets_no_reseller_discount(): void
    {
        $this->fakeRegistrar();
        $c = $this->reseller(credit: 1_000_000);
        $c->forceFill(['is_reseller' => false])->save();

        $t = $this->token($c);

        $row = $this->call_($t, '/api/v1/domains/check', ['domain' => 'sanjesh-nemayande.com'])
            ->json('data.0');

        $this->assertSame(0.0, (float) $row['discount_pct']);
        $this->assertSame((int) $row['price']['retail'], (int) $row['price']['register']);
    }
    /**
     * 🔴 شکست نباید کلید را بسوزانَد.
     *
     * ماژولِ WHMCS کلید را از شناسهٔ سفارش می‌سازد و آن عوض نمی‌شود. اگر خطا
     * کش شود، نماینده‌ای که اعتبار شارژ کرده و همان سفارش را دوباره فرستاده،
     * تا ابد همان «اعتبار کافی نیست» را می‌گیرد — برای سفارشی که حالا پولش را
     * دارد.
     */
    public function test_a_failed_request_releases_its_idempotency_key(): void
    {
        $this->fakeRegistrar();
        $c = $this->reseller(credit: 1000);
        $t = $this->token($c);

        $head = ["Idempotency-Key" => "whmcs-order-99"];

        $this->call_($t, "/api/v1/domains", ["domain" => "sanjesh-nemayande.com"], $head)
            ->assertStatus(402);

        // نماینده حساب را شارژ می‌کند و **همان** سفارش را دوباره می‌فرستد
        \App\Models\CreditEntry::create([
            "customer_id" => $c->id, "currency_code" => "IRT",
            "amount" => 50_000_000, "balance_after" => 50_001_000, "reason" => "topup",
        ]);

        $this->call_($t, "/api/v1/domains", ["domain" => "sanjesh-nemayande.com"], $head)
            ->assertStatus(201);

        $this->assertSame(1, Domain::where("domain", "sanjesh-nemayande.com")->count());
    }
    // ═══════════════ ۹) محافظِ جهشِ ارز روی تمدید ═══════════════

    /**
     * 🔴 تمدید هرگز با نرخِ پارسال فروخته نمی‌شود.
     *
     * `renew_toman` در لحظهٔ **ثبت** ذخیره می‌شود. یک سال بعد، اگر ارز جهش
     * کرده باشد، همان عدد یعنی فروش زیرِ قیمتِ خرید — روی هر تمدید، روی همهٔ
     * دامنه‌ها، بی‌هیچ خطایی. و چون تمدید سالانه تکرار می‌شود، ضرر انباشته
     * است نه یک‌باره.
     */
    public function test_a_currency_jump_never_lets_a_renewal_sell_below_cost(): void
    {
        $this->fakeRegistrar();
        $c = $this->reseller(credit: 500_000_000);
        $t = $this->token($c);

        // دامنه‌ای که پارسال ثبت شده: بهای تمام‌شده ۱۰ یورو، نرخِ آن روز ۱۰۰٬۰۰۰
        $d = Domain::create([
            "customer_id" => $c->id, "domain" => "gozashte.com", "sld" => "gozashte", "tld" => "com",
            "registrar" => "openprovider", "status" => "active", "provision_status" => "none",
            "op_id" => 77, "period_years" => 1,
            "price_toman" => 1_400_000, "renew_toman" => 1_400_000,
            "cost_amount" => 1000, "cost_currency" => "EUR",   // ۱۰٫۰۰ یورو در واحدِ فرعی
        ]);

        // ارز دو برابر شد: بهای تمام‌شده حالا ۲٬۰۰۰٬۰۰۰ تومان است
        \App\Models\Setting::put("pricing_rate_override", "200000");

        $this->call_($t, "/api/v1/domains/gozashte.com/renew", ["years" => 1],
            ["Idempotency-Key" => "fx1"])->assertOk();

        $charged = (int) abs(CreditEntry::where("customer_id", $c->id)
            ->where("amount", "<", 0)->sum("amount"));

        $costToday = 10 * 200_000;              // ۲٬۰۰۰٬۰۰۰
        $floor = (int) ceil($costToday * 1.08); // + حداقلِ حاشیه

        $this->assertGreaterThanOrEqual($floor, $charged,
            "تمدید با نرخِ پارسال فروخته شد — روی هر تمدید ضرر می‌کنیم");

        $this->assertGreaterThan(1_400_000, $charged,
            "قیمتِ ذخیره‌شدهٔ پارسال بدونِ اصلاح استفاده شد");
    }

    /** و نیمهٔ دوم: اگر ارز تکان نخورده، قیمتِ ذخیره‌شده **دست نمی‌خورد** */
    public function test_a_stable_rate_leaves_the_stored_renewal_price_alone(): void
    {
        $this->fakeRegistrar();
        $c = $this->reseller(credit: 500_000_000);
        $t = $this->token($c);

        Domain::create([
            "customer_id" => $c->id, "domain" => "sabet.com", "sld" => "sabet", "tld" => "com",
            "registrar" => "openprovider", "status" => "active", "provision_status" => "none",
            "op_id" => 78, "period_years" => 1,
            // بهای تمام‌شده ۱۰ یورو × ۱۰۰٬۰۰۰ = ۱M؛ کف ≈ ۱٫۰۸M. قیمتِ ذخیره‌شده بالاتر است.
            "price_toman" => 2_000_000, "renew_toman" => 2_000_000,
            "cost_amount" => 1000, "cost_currency" => "EUR",
        ]);

        $this->call_($t, "/api/v1/domains/sabet.com/renew", ["years" => 1],
            ["Idempotency-Key" => "fx2"])->assertOk();

        $charged = (int) abs(CreditEntry::where("customer_id", $c->id)
            ->where("amount", "<", 0)->sum("amount"));

        // ۲٬۰۰۰٬۰۰۰ + مالیات — کف نباید بالاترش برده باشد
        $this->assertLessThan(2_500_000, $charged,
            "محافظِ ارز قیمتی را بالا برد که از قبل بالای کف بود");
    }
}
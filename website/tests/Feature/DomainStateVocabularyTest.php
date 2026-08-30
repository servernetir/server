<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Services\Domain\DomainSearch;
use App\Support\ErrorTracker;
use Database\Seeders\BillingFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ═══ یک واژه برای یک وضعیت، در هر سه ورودیِ فروشِ دامنه ═══
 *
 * سه مسیرِ جستجو داریم و هر سه **همین** سرویس را صدا می‌زنند:
 *
 *   ۱) صفحهٔ عمومی   `/domains`            → POST /api/domains/search
 *   ۲) پنلِ مشتری    `/account/domains`    → همان سرویس، سمتِ سرور
 *   ۳) جعبهٔ صفحهٔ اول                      → POST /api/domain-check
 *
 * پس نتیجهٔ **داده‌ای** هرگز نمی‌توانست فرق کند؛ چیزی که فرق می‌کرد **واژه**
 * بود، چون هر رابط خودش از روی `available`/`orderable` نتیجه می‌گرفت:
 *
 *   • پنل: پرمیوم را «آزاد» می‌خواند و دکمهٔ خرید می‌داد (۳۱۲ میلیون تومان
 *     با همان کلمه‌ای که یک دامنهٔ ۱٫۲ میلیونی).
 *   • صفحهٔ عمومی: «استعلام نشد» را «فعلاً قابل سفارش نیست» می‌خواند —
 *     عبارتی که پنل برای حالتِ **دیگری** به کار می‌بَرد — و علتِ صریحاً غلطِ
 *     «ارتباط با سرور برقرار نشد» را حتی به پسوندی می‌داد که اصلاً
 *     نمی‌فروشیم.
 *   • جعبهٔ صفحهٔ اول: هر چیزی جز `available` را «قبلاً ثبت شده است» می‌خواند.
 *
 * حالا وضعیت یک بار سمتِ سرور حساب می‌شود (`DomainSearch::stateOf`) و در
 * فیلدِ `state` می‌نشیند. این فایل قفلش می‌کند.
 *
 * 🔴 قواعدِ خودِ تست: هیچ تماسِ واقعی با رجیسترار. هر تست با `Http::swap`
 *    شروع می‌شود و **یک** `Http::fake()` می‌گذارد — استابِ دوم روی اولی سایه
 *    می‌اندازد و تست بی‌صدا هیچ‌چیز نمی‌سنجد.
 */
class DomainStateVocabularyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BillingFoundationSeeder::class);

        config([
            'services.openprovider.username' => 'u',
            'services.openprovider.password' => 'p',
            'services.openprovider.margin'   => ['default' => 0],
        ]);

        Cache::put('fx.usd_irt', [
            'currency' => 'USD', 'rate_toman' => 100000,
            'source' => 'test', 'at' => now()->toIso8601String(),
        ], now()->addHour());
    }

    // ═══════════════════════ ابزار ═══════════════════════

    private function fakeCheck(mixed $checkResponse): void
    {
        Http::swap(new Factory);
        Http::fake([
            '*/auth/login*'    => Http::response(['code' => 0, 'data' => ['token' => 'tok'], 'desc' => ''], 200),
            '*/domains/check*' => $checkResponse,
        ]);
    }

    /** پاسخِ موفقِ رجیسترار با ردیف‌های دلخواه */
    private function rows(array $results): mixed
    {
        return Http::response(['code' => 0, 'desc' => '', 'warnings' => [], 'data' => ['results' => $results]], 200);
    }

    private function customer(): Customer
    {
        $c = Customer::create([
            'email' => 'v'.random_int(1000, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);

        CustomerProfile::create([
            'customer_id' => $c->id, 'type' => 'individual', 'is_default' => true,
            'status' => 'verified', 'email' => $c->email, 'mobile' => '09123456789',
            'country' => 'IR', 'city' => 'تهران', 'address' => 'خیابان نمونه',
            'postal_code' => '1234567890', 'first_name' => 'ا', 'last_name' => 'ا',
        ]);

        return $c;
    }

    /** وضعیتی که نقطهٔ پایانیِ صفحهٔ عمومی برای این دامنه اعلام می‌کند */
    private function publicState(string $domain, string $tld): string
    {
        $json = $this->postJson(route('domain.search.check'), ['q' => $domain, 'tlds' => [$tld]])
            ->assertOk()->json();

        return (string) ($json['results'][0]['state'] ?? 'MISSING');
    }

    /** وضعیتی که پنل برای همان دامنه به ویو تحویل می‌دهد */
    private function panelState(string $domain, string $tld): string
    {
        $results = $this->actingAs($this->customer(), 'customer')
            ->get(route('account.domains', ['register' => $domain]))
            ->assertOk()->viewData('results');

        foreach ($results as $r) {
            if (($r['tld'] ?? '') === $tld) {
                return (string) ($r['state'] ?? 'MISSING');
            }
        }

        return 'NOT-FOUND';
    }

    /** وضعیتی که جعبهٔ صفحهٔ اول برای همان دامنه اعلام می‌کند */
    private function homeBoxState(string $domain): string
    {
        return (string) $this->postJson(route('domain.check'), ['domain' => $domain])
            ->assertOk()->json('result.state');
    }

    // ═══════════════════════ ۱) سه مسیر، یک واژه ═══════════════════════

    /**
     * @return array<string,array{0:mixed,1:string,2:string}>
     */
    public static function scenarios(): array
    {
        $free = ['domain' => 'zhina.com', 'status' => 'free', 'is_premium' => false,
            'price' => ['reseller' => ['price' => 10.0, 'currency' => 'USD']]];

        $premium = ['domain' => 'zhina.com', 'status' => 'free', 'is_premium' => true,
            'premium' => ['currency' => 'USD', 'price' => ['create' => 2500.0]],
            'price' => ['reseller' => ['price' => 10.0, 'currency' => 'USD']]];

        $taken = ['domain' => 'zhina.com', 'status' => 'active'];

        // آزاد است ولی رسیلری هیچ قیمتی نداد
        $noPrice = ['domain' => 'zhina.com', 'status' => 'free', 'is_premium' => false];

        return [
            'آزاد'                => [[$free], 'com', DomainSearch::STATE_FREE],
            'پرمیوم'              => [[$premium], 'com', DomainSearch::STATE_PREMIUM],
            'گرفته‌شده'            => [[$taken], 'com', DomainSearch::STATE_TAKEN],
            'بی‌قیمت'              => [[$noPrice], 'com', DomainSearch::STATE_NO_PRICE],
            'پاسخِ ناقص'          => [[], 'com', DomainSearch::STATE_UNCHECKED],
        ];
    }

    /**
     * 🔴 ادعای مرکزی: برای یک پاسخِ یکسانِ رجیسترار، هر سه مسیر **یک** وضعیت
     * می‌گویند. اگر روزی یکی از رابط‌ها دوباره خودش نتیجه‌گیری کند، این‌جا
     * می‌شکند.
     *
     */
    #[DataProvider("scenarios")]
    public function test_all_three_search_paths_report_the_same_state(array $results, string $tld, string $expected): void
    {
        $this->fakeCheck($this->rows($results));

        $this->assertSame($expected, $this->publicState('zhina.'.$tld, $tld), 'صفحهٔ عمومی');
        $this->assertSame($expected, $this->panelState('zhina.'.$tld, $tld), 'پنلِ مشتری');
        $this->assertSame($expected, $this->homeBoxState('zhina.'.$tld), 'جعبهٔ صفحهٔ اول');
    }

    // ═══════════════════════ ۲) شش وضعیتِ قابلِ تفکیک ═══════════════════════

    /** پسوندی که نمی‌فروشیم، «استعلام نشد» نیست — علتش را می‌گوییم */
    public function test_a_tld_we_do_not_sell_gets_its_own_state(): void
    {
        // رجیسترارِ اروپایی برای ‎.ir وضعیتِ error می‌دهد
        $this->fakeCheck($this->rows([['domain' => 'zhina.ir', 'status' => 'error']]));

        $this->assertSame(DomainSearch::STATE_UNSUPPORTED, $this->publicState('zhina.ir', 'ir'));
        $this->assertSame(DomainSearch::STATE_UNSUPPORTED, $this->panelState('zhina.ir', 'ir'));
    }

    /** همان پاسخِ error روی پسوندی که **می‌فروشیم** = «نتوانستیم استعلام کنیم» */
    public function test_an_unrecognised_status_on_a_tld_we_sell_is_unchecked_not_unsupported(): void
    {
        $this->fakeCheck($this->rows([['domain' => 'zhina.com', 'status' => 'error']]));

        $this->assertSame(DomainSearch::STATE_UNCHECKED, $this->publicState('zhina.com', 'com'));
        $this->assertSame(DomainSearch::STATE_UNCHECKED, $this->panelState('zhina.com', 'com'));
    }

    /** نرخِ ارز که نباشد، «قیمت در دسترس نیست» است — نه آزاد و نه گرفته‌شده */
    public function test_a_missing_exchange_rate_is_a_price_state_not_an_availability_state(): void
    {
        Cache::forget('fx.usd_irt');
        \App\Models\Setting::put('pricing_rate_override', '0');

        $this->fakeCheck($this->rows([
            ['domain' => 'zhina.com', 'status' => 'free',
                'price' => ['reseller' => ['price' => 10.0, 'currency' => 'USD']]],
        ]));

        $st = $this->publicState('zhina.com', 'com');

        $this->assertContains($st, [DomainSearch::STATE_NO_PRICE, DomainSearch::STATE_FREE],
            'حالتِ بی‌نرخ باید یا «قیمت نداریم» باشد یا واقعاً قیمت‌دار — هرگز «گرفته‌شده»');
        $this->assertNotSame(DomainSearch::STATE_TAKEN, $st);
    }

    /**
     * 🔴 مهم‌ترین ادعای امنیتیِ پول: هیچ وضعیتی جز `free`/`premium` حق ندارد
     *    خریدنی باشد. اگر روزی «نمی‌دانیم» خریدنی شود، پول گرفته می‌شود و ثبت
     *    شکست می‌خورد.
     */
    public function test_only_free_and_premium_are_ever_orderable(): void
    {
        foreach ([
            [['domain' => 'zhina.com', 'status' => 'active']],
            [['domain' => 'zhina.com', 'status' => 'error']],
            [['domain' => 'zhina.com', 'status' => 'free']],   // بی‌قیمت
            [],                                                 // پاسخِ ناقص
        ] as $rows) {
            $this->fakeCheck($this->rows($rows));

            $row = $this->postJson(route('domain.search.check'), ['q' => 'zhina.com', 'tlds' => ['com']])
                ->assertOk()->json('results.0');

            $this->assertNotSame(DomainSearch::STATE_FREE, $row['state']);
            $this->assertFalse((bool) $row['orderable'], 'وضعیتِ '.$row['state'].' نباید سفارش‌پذیر باشد');
            $this->assertNull($row['quote_id'], 'وضعیتِ '.$row['state'].' نباید استعلامِ قیمت بسازد');
        }
    }

    /**
     * 🔴 خطرِ فروشِ اشتباه: پنل تا امروز هیچ شاخهٔ پرمیومی نداشت و یک دامنهٔ
     * ۲۵۰۰ دلاری را با همان کلمهٔ «آزاد» و همان دکمهٔ خرید نشان می‌داد که یک
     * دامنهٔ ده‌دلاری. مشتری روی قیمت دقت نمی‌کند وقتی برچسب می‌گوید عادی است.
     */
    public function test_the_console_marks_a_premium_domain_as_premium(): void
    {
        $this->fakeCheck($this->rows([
            ['domain' => 'zhina.com', 'status' => 'free', 'is_premium' => true,
                'premium' => ['currency' => 'USD', 'price' => ['create' => 2500.0]],
                'price' => ['reseller' => ['price' => 10.0, 'currency' => 'USD']]],
        ]));

        $html = $this->actingAs($this->customer(), 'customer')
            ->get(route('account.domains', ['register' => 'zhina.com']))
            ->assertOk()->getContent();

        $this->assertStringContainsString(__('ui.dsr_premium_pill'), $html);
        $this->assertStringContainsString(__('ui.dsr_premium_note'), $html);

        // ⚠️ ادعا روی خودِ وضعیتِ ردیف، نه روی نبودِ یک واژه: «آزاد» ممکن است
        //    جای دیگری از همان صفحه مشروعاً بیاید و ادعای گشاد را بی‌معنا کند.
        $this->assertStringContainsString('data-state="premium"', $html);
        $this->assertStringNotContainsString('data-state="free"', $html,
            'دامنهٔ پرمیوم نباید به‌عنوانِ «آزاد» رندر شود');

        /*
        | پرمیوم همچنان خریدنی است — فقط برچسبش صادق شد.
        |
        | ⚠️ سنجه از «فرمِ POST با quote_id» به **لینکِ صفحهٔ تسویه** عوض شد.
        | تا امروز دکمهٔ خرید مستقیم فاکتور صادر می‌کرد و کاربر هرگز نه
        | نام‌سرور انتخاب می‌کرد نه مشخصاتِ مالک می‌داد — و ثبتِ خودکار
        | ساعت‌ها بعد به‌خاطرِ نبودِ همان مشخصات شکست می‌خورد، با پولِ
        | گرفته‌شده (`zhina.shop`). ادعا همان است («این ردیف خریدنی است»)؛
        | مسیرش عوض شده.
        */
        $this->assertStringContainsString('/domains/checkout/', $html);
    }

    /**
     * ⚠️ قرصِ بی‌کلاس بی‌رنگ رندر می‌شود و هیچ خطایی هم نمی‌دهد.
     * نسخهٔ قبلیِ صفحهٔ عمومی برای ردیفِ بی‌قیمت `<span class="dsx-pill">`
     * خالی می‌ساخت، در حالی که فقط `.free/.prem/.taken/.no` رنگ دارند.
     */
    public function test_every_pill_the_public_page_can_emit_has_a_colour_class(): void
    {
        $html = $this->get('/domains')->assertOk()->getContent();

        $this->assertStringNotContainsString('\'<span class="dsx-pill">\'', $html,
            'قرصِ بی‌کلاس برگشته — بی‌رنگ رندر می‌شود');
        $this->assertMatchesRegularExpression('~\.dsx-pill\.warn\{~', $html,
            'کلاسِ «استعلام نشد» تعریف نشده');
        $this->assertStringContainsString('dsx-pill \' + cls + \'', $html);
    }

    // ═══════════════════════ ۳) گزارشِ کارفرما ═══════════════════════

    /**
     * «از داخل پنل استعلام دامنه می‌کنم، می‌گوید در دسترس نیست.»
     *
     * سه ورودیِ کاملاً متفاوت، همان بنر: ۵۰۰ با کدِ ۱۹۶، قطعیِ اتصال، و
     * اعتبارنامهٔ خالی. هیچ‌کدام نباید عبارتِ «در دسترس نیست» تولید کند و
     * هیچ‌کدام نباید «ثبت‌شده» بگوید.
     */
    public function test_no_failure_mode_ever_says_the_domain_is_unavailable(): void
    {
        $cases = [
            'code 196' => fn () => $this->fakeCheck(
                Http::response(['code' => 196, 'desc' => 'Authentication/Authorization Failed'], 500)
            ),
            'connection failure' => fn () => $this->fakeCheck(
                fn () => throw new ConnectionException('no route to host')
            ),
            'blank credentials' => function () {
                config(['services.openprovider.username' => '', 'services.openprovider.password' => '']);
                $this->fakeCheck(Http::response(['code' => 0, 'data' => ['results' => []]], 200));
            },
        ];

        foreach ($cases as $label => $arrange) {
            $arrange();

            $html = $this->actingAs($this->customer(), 'customer')
                ->get(route('account.domains', ['register' => 'zhina.shop']))
                ->assertOk()->getContent();

            $this->assertStringNotContainsString('در دسترس نیست', $html, $label);
            $this->assertStringNotContainsString('pnl-pill mute">ثبت‌شده', $html, $label);
            $this->assertStringContainsString(__('ui.dsr_lookup_failed'), $html, $label);
            // استعلامِ شکست‌خورده هیچ راهِ خریدی نمی‌سازد — نه فرم، نه لینکِ تسویه
            $this->assertStringNotContainsString('/domains/checkout/', $html, $label);
        }
    }

    /**
     * پنل و صفحهٔ عمومی باید یک بنر بدهند — و صفحهٔ عمومی تا امروز **هیچ**
     * کانالی برای خرابی نداشت (`ok: true`ِ بی‌قیدوشرط).
     */
    public function test_the_public_endpoint_admits_the_lookup_failed(): void
    {
        $this->fakeCheck(Http::response(['code' => 196, 'desc' => 'Authentication/Authorization Failed'], 500));

        $json = $this->postJson(route('domain.search.check'), ['q' => 'zhina.com', 'tlds' => ['com']])
            ->assertOk()->json();

        $this->assertTrue($json['ok'], 'خودِ درخواست سرو شده — گاردِ حمل‌ونقل نباید ردیف‌ها را دور بیندازد');
        $this->assertFalse($json['lookup_ok'], 'ولی استعلام شکست خورده و پاکت باید اعترافش کند');
        $this->assertSame('lookup_failed', $json['reason']);
        $this->assertSame(DomainSearch::STATE_UNCHECKED, $json['results'][0]['state']);
    }

    /** و جعبهٔ صفحهٔ اول — همان جایی که باگ یک لایه بالاتر زنده مانده بود */
    public function test_the_home_box_never_calls_a_failed_lookup_registered(): void
    {
        $this->fakeCheck(Http::response(['code' => 196, 'desc' => 'Authentication/Authorization Failed'], 500));

        $json = $this->postJson(route('domain.check'), ['domain' => 'zhina.com'])->assertOk()->json();

        $this->assertFalse($json['result']['available']);
        $this->assertFalse($json['lookup_ok']);
        $this->assertSame(DomainSearch::STATE_UNCHECKED, $json['result']['state'],
            'جعبهٔ صفحهٔ اول باید «نمی‌دانم» بگوید، نه «قبلاً ثبت شده است»');
    }

    /**
     * ⚠️ گاردِ نمایش: خودِ جاوااسکریپتِ صفحهٔ اول هم باید سه‌حالته باشد.
     * فایلِ ایستا را می‌خوانیم چون این کد در مرورگر اجرا می‌شود و هیچ تستِ
     * سروری اجرایش نمی‌کند — همان الگوی «کدِ ۲۰۰ ولی صفحهٔ مرده».
     */
    public function test_the_home_box_script_branches_on_state_not_on_available_alone(): void
    {
        $js = file_get_contents(public_path('assets/js/site.js'));

        $this->assertStringContainsString('r.state ||', $js,
            'renderResult باید وضعیتِ سرور را بخوانَد');
        $this->assertStringContainsString('i18nUnchecked', $js);
        $this->assertStringContainsString('domain-result warn', $js);
        $this->assertStringNotContainsString('if (r.available) {', $js,
            'شاخهٔ دوحالتیِ قدیمی برگشته — هر چیزی جز آزاد دوباره «ثبت‌شده» می‌شود');

        // و رشته‌های سه حالتِ تازه باید واقعاً به مرورگر برسند
        foreach (['/', '/en', '/tr'] as $prefix) {
            $html = $this->get($prefix === '/' ? '/' : $prefix)->assertOk()->getContent();
            $this->assertStringContainsString('data-i18n-unchecked=', $html, $prefix);
            $this->assertStringContainsString('data-i18n-unsupported=', $html, $prefix);
            $this->assertStringContainsString('data-i18n-noprice=', $html, $prefix);
        }
    }

    // ═══════════════════════ ۴) مسیرِ خرید تا انتها ═══════════════════════

    /**
     * از یک نتیجهٔ آزادِ صفحهٔ عمومی تا فاکتور — همان مسیری که مشتری می‌رود.
     *
     * ⚠️ صفحهٔ عمومی عمداً فقط **نامِ دامنه** را تحویل می‌دهد (`?register=`) و
     * نه شناسهٔ استعلام: پنجرهٔ اعتبارِ استعلام ۱۵ دقیقه است و بینِ دیدنِ قیمت
     * و ورود به حساب ممکن است بیشتر طول بکشد. پنل دوباره استعلام می‌گیرد، پس
     * عددی که روی دکمهٔ خرید است همانی است که پرداخت می‌شود. این تست همان
     * تحویل‌دادن را می‌سنجد، نه فقط رسیدن به صفحه.
     */
    public function test_the_buy_path_completes_from_a_free_public_result_to_an_invoice(): void
    {
        $this->fakeCheck($this->rows([
            ['domain' => 'zhina.com', 'status' => 'free', 'is_premium' => false,
                'price' => ['reseller' => ['price' => 10.0, 'currency' => 'USD']]],
        ]));

        // ۱) صفحهٔ عمومی: نتیجهٔ آزاد با قیمت و شناسهٔ استعلام
        $row = $this->postJson(route('domain.search.check'), ['q' => 'zhina.com', 'tlds' => ['com']])
            ->assertOk()->json('results.0');

        $this->assertSame(DomainSearch::STATE_FREE, $row['state']);
        $this->assertTrue($row['orderable']);
        $this->assertGreaterThan(0, $row['price_toman']);

        // ۲) پنل: دکمهٔ خرید فقط نام را می‌برد، پنل دوباره استعلام می‌گیرد
        $c = $this->customer();

        $html = $this->actingAs($c, 'customer')
            ->get(route('account.domains', ['register' => 'zhina.com']))
            ->assertOk()->getContent();

        /*
        | ⚠️ مسیر عوض شد: پنل دیگر فرمِ POST نمی‌دهد، **لینکِ صفحهٔ تسویه**
        | می‌دهد که شناسهٔ استعلامِ تازه را می‌بَرد. ادعا همان است — «پنل خودش
        | دوباره استعلام می‌گیرد» — و همان چیزی را می‌سنجد.
        */
        $this->assertMatchesRegularExpression('~/domains/checkout/(\d+)~', $html,
            'پنل باید لینکِ تسویه با شناسهٔ استعلامِ تازه بدهد');

        preg_match('~/domains/checkout/(\d+)~', $html, $m);
        $quoteId = (int) $m[1];

        $this->assertNotSame((int) $row['quote_id'], $quoteId,
            'پنل باید استعلامِ تازه بگیرد، نه استعلامِ صفحهٔ عمومی');

        // ۳) سفارش → فاکتور
        $res = $this->actingAs($c, 'customer')
            ->post(route('account.domains.order'), ['quote_id' => $quoteId, 'years' => 1]);

        $res->assertRedirect();

        $invoice = \App\Models\Invoice::where('customer_id', $c->id)->latest('id')->first();

        $this->assertNotNull($invoice, 'مسیرِ خرید باید به یک فاکتور برسد');
        $this->assertSame('domain', $invoice->kind);
        $this->assertSame('IRT', $invoice->currency_code);

        $quote = \App\Models\DomainQuote::find($quoteId);

        $this->assertSame((int) $quote->sell_toman, (int) $invoice->subtotal,
            '🔴 مبلغِ فاکتور باید از استعلامِ ذخیره‌شده بیاید، نه از فرم');
        $this->assertSame((int) $invoice->subtotal + (int) $invoice->tax, (int) $invoice->total);

        // و دامنه باید در انتظارِ **پرداخت** بماند، نه در صفِ ثبت
        $d = \App\Models\Domain::where('domain', 'zhina.com')->first();
        $this->assertNotNull($d);
        $this->assertSame('pending', $d->status);
        $this->assertSame('none', $d->provision_status,
            'تا فاکتور پرداخت نشده، کرونِ ثبت نباید این دامنه را بردارد');
    }

    /** استعلامِ منقضی نباید خریدنی باشد — وگرنه به نرخِ دیروز می‌فروشیم */
    public function test_an_expired_quote_cannot_be_ordered(): void
    {
        $this->fakeCheck($this->rows([
            ['domain' => 'zhina.com', 'status' => 'free',
                'price' => ['reseller' => ['price' => 10.0, 'currency' => 'USD']]],
        ]));

        $c = $this->customer();

        $this->actingAs($c, 'customer')->get(route('account.domains', ['register' => 'zhina.com']))->assertOk();

        $quote = \App\Models\DomainQuote::latest('id')->first();
        $quote->forceFill(['honour_until' => now()->subMinute()])->save();

        $this->actingAs($c, 'customer')
            ->post(route('account.domains.order'), ['quote_id' => $quote->id, 'years' => 1])
            ->assertSessionHasErrors();

        $this->assertSame(0, \App\Models\Invoice::where('customer_id', $c->id)->count());
    }

    // ═══════════════════════ ۵) دیده‌شدن برای مدیر ═══════════════════════

    /**
     * 🔴 پاسخِ سؤالِ اولِ این کار: چرا `/admin/errors` بعد از گزارشِ کارفرما
     * صفر بود؟
     *
     * چون `DomainSearch` فقط `Log::warning` می‌زد و آن به
     * `storage/logs/laravel.log` می‌رود، در حالی که `/admin/errors` فقط
     * `storage/logs/tracker.jsonl` را می‌خوانَد که تنها با `ErrorTracker::*`
     * پر می‌شود. یعنی خرابی **ساختاراً نامرئی** بود، نه اینکه رخ نداده باشد.
     */
    public function test_a_failed_lookup_reaches_the_admin_error_tracker(): void
    {
        // ردیاب و گلوگاهِ فایلیِ `noteOnce` را `Tests\TestCase::setUp()` پاک
        // می‌کند — و از آن‌جا که مسیرشان حالا **پوشهٔ خصوصیِ همین پروسه** است،
        // پاک‌کردنِ دستی با `storage_path()` این‌جا فقط یک no-opِ گمراه‌کننده بود.
        $this->assertCount(0, ErrorTracker::recent(50));

        $this->fakeCheck(Http::response(['code' => 196, 'desc' => 'Authentication/Authorization Failed'], 500));

        $this->postJson(route('domain.search.check'), ['q' => 'zhina.com', 'tlds' => ['com']])->assertOk();

        $rows = ErrorTracker::recent(50);
        $hit = array_values(array_filter($rows, fn ($r) => ($r['area'] ?? '') === 'domain'));

        $this->assertNotEmpty($hit, 'یک استعلامِ شکست‌خورده باید در /admin/errors دیده شود');
        $this->assertStringContainsString('196', json_encode($hit[0], JSON_UNESCAPED_UNICODE),
            'کدِ خودِ رجیسترار باید در ردِ خطا باشد، وگرنه مدیر علت را نمی‌فهمد');
    }
}

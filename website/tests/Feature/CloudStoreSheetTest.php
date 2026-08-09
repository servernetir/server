<?php

namespace Tests\Feature;

use App\Models\CloudImage;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Customer;
use App\Models\Setting;
use App\Http\Controllers\Account\CloudStoreController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * «برگهٔ مقایسه» — فهرستِ پلن‌ها به‌شکلِ جدول.
 *
 * `CloudStoreTest` قراردادِ پول و تحویل را نگه می‌دارد، `CloudStoreSlipTest`
 * قراردادِ صفحه را؛ این فایل فقط چیزی را می‌سنجد که با این بازطراحی تازه شد:
 * ستون‌ها، مرتب‌سازی، فیلتر، جمع‌شدنِ ستونِ یک‌مقداری، و مهم‌تر از همه اینکه
 * فیلتر نتواند یک ردیفِ نامرئیِ **ارسال‌شدنی** جا بگذارد.
 *
 * ⚠️ «کدِ ۲۰۰ یعنی هیچ» — همه‌جا ساختار و مقدارِ رندرشده سنجیده می‌شود.
 * ⚠️ چیدمان و رفتارِ جاوااسکریپت روی این ماشین اجراشدنی نیست (CLAUDE.md §۸)،
 *    پس آن دو با سنجشِ **قرارداد در سورس** قفل می‌شوند: قاعدهٔ CSS که پنهان‌شدن
 *    را واقعی می‌کند، و زنجیرهٔ ترمیمِ انتخاب که باید هم‌زمان با آن باشد.
 */
class CloudStoreSheetTest extends TestCase
{
    use RefreshDatabase;

    private function u(): string
    {
        return route('account.cloud.store', [], false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // همان بازسازیِ ترتیبِ روت‌ها که دو تستِ خواهر دارند.
        if (! Route::has('account.cloud.store')) {
            Route::middleware(['web', 'auth:customer'])->prefix('account')->name('account.')->group(function () {
                Route::get('/cloud-store', [CloudStoreController::class, 'index'])->name('cloud.store');
                Route::post('/cloud-store', [CloudStoreController::class, 'order'])->name('cloud.store.place');
            });

            $mine = ['account.cloud.store', 'account.cloud.store.place'];
            $ordered = new RouteCollection;

            foreach (collect(Route::getRoutes()->getRoutes())
                ->sortBy(fn ($r) => in_array($r->getName(), $mine, true) ? 0 : 1)->all() as $route) {
                $ordered->add($route);
            }

            Route::setRoutes($ordered);
        }
    }

    // ═══════════════════ داده ═══════════════════

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'sheet'.random_int(1, 999999).'@example.com',
            'phone' => '0914'.random_int(1000000, 9999999),
            'password' => 'secret1234', 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    private function plan(array $over = []): CloudPlan
    {
        return CloudPlan::create(array_merge([
            'provider' => 'hetzner', 'provider_ref' => 'cx22', 'provider_location' => 'fsn1',
            'location_code' => 'de-frankfurt', 'public_name' => 'CV-2-4',
            'slug' => 'cv-2c-4g-40d-de-frankfurt',
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme',
            'traffic_gb' => 20480, 'cpu_kind' => 'shared', 'arch' => 'x86',
            'cost_eur_cents' => 379, 'price_eur_cents' => 570, 'price_irt' => 570000,
            'is_active' => true, 'in_stock' => true,
        ], $over));
    }

    private function image(array $over = []): CloudImage
    {
        return CloudImage::create(array_merge([
            'provider' => 'hetzner', 'provider_ref' => 'ubuntu-24.04', 'key' => 'ubuntu-24.04',
            'kind' => 'os', 'family' => 'ubuntu', 'version' => '24.04', 'label' => 'Ubuntu 24.04',
            'arch' => 'x86', 'min_disk_gb' => 5, 'is_active' => true,
        ], $over));
    }

    /** دو اندازهٔ اشتراکی با ترافیکِ یکسان — کاتالوگِ واقعیِ امروز */
    private function base(): void
    {
        CloudLocation::create(['code' => 'de-frankfurt', 'country' => 'DE', 'city' => 'Frankfurt', 'is_active' => true]);
        $this->plan();
        $this->plan([
            'provider_ref' => 'cx32', 'public_name' => 'CV-4-8',
            'slug' => 'cv-4c-8g-80d-de-frankfurt',
            'vcpu' => 4, 'ram_mb' => 8192, 'disk_gb' => 80,
            'cost_eur_cents' => 700, 'price_eur_cents' => 1000, 'price_irt' => 1000000,
        ]);
        $this->image();
    }

    /** یک ردیفِ پردازندهٔ اختصاصی — فیلتر فقط با این معنا پیدا می‌کند */
    private function dedicated(): void
    {
        $this->plan([
            'provider_ref' => 'ccx13', 'public_name' => 'CV-D-8-16',
            'slug' => 'cv-8c-16g-160d-de-frankfurt',
            'vcpu' => 8, 'ram_mb' => 16384, 'disk_gb' => 160, 'cpu_kind' => 'dedicated',
            'cost_eur_cents' => 1400, 'price_eur_cents' => 2000, 'price_irt' => 2000000,
        ]);
    }

    private function page(?Customer $c = null, string $qs = ''): string
    {
        return $this->actingAs($c ?? $this->customer(), 'customer')
            ->get($this->u().$qs)->assertOk()->getContent();
    }

    private function viewSrc(): string
    {
        return (string) file_get_contents(resource_path('views/account/cloud-store.blade.php'));
    }

    private function css(): string
    {
        return (string) file_get_contents(public_path('assets/css/panel.css'));
    }

    /** panel.css بی‌کامنت — وگرنه واژه‌ای که فقط در توضیح آمده، ادعا را می‌شکند */
    private function cssCode(): string
    {
        return (string) preg_replace('~/\*.*?\*/~s', '', $this->css());
    }

    /** فقط اسکریپت‌های خودِ سرورساز (نه اسکریپت‌های چیدمانِ پنل) */
    private function scripts(string $html): string
    {
        preg_match_all('~<script\b[^>]*>(.*?)</script>~is', $html, $m);

        return implode("\n", array_filter($m[1], fn ($s) => str_contains($s, 'cvb-')));
    }

    // ═══════════════════ ستون‌ها ═══════════════════

    /**
     * سرستون‌ها واقعاً رندر می‌شوند و **درونِ** همان ظرفی هستند که ردیف‌ها،
     * چون هر دو باید یک فهرستِ ستونِ مشترک را ارث ببرند.
     */
    public function test_the_sheet_renders_a_header_row_with_one_button_per_sortable_column(): void
    {
        $this->base();
        $html = $this->page();

        $this->assertSame(1, substr_count($html, 'id="cvb-sheeth"'), 'سرستون‌ها باید دقیقاً یک بار بیایند');

        $start = strpos($html, 'id="cvb-plans"');
        $this->assertNotFalse($start, 'ظرفِ فهرستِ پلن‌ها باید رندر شود');
        $this->assertLessThan(strpos($html, 'id="cvb-sheeth"'), $start,
            'سرستون‌ها باید داخلِ ظرفِ فهرست باشند، وگرنه ستون‌ها هم‌تراز نمی‌شوند');

        // ظرفِ اندازه‌گیری دورِ فهرست است (پرس‌وجوی ظرف)
        $this->assertStringContainsString('class="cvb-sheet"', $html);

        foreach ([
            'ord' => __('ui.cvb_plan'),
            'sv' => __('ui.cvb_cores'),
            'sr' => __('ui.cvb_ram'),
            'sd' => __('ui.cvb_disk'),
            'pr' => __('ui.cvb_amount'),
        ] as $key => $label) {
            $this->assertMatchesRegularExpression(
                '~<button type="button" class="cvb-sh [^"]*" data-sort="'.$key.'"~',
                $html,
                "سرستونِ «{$label}» ({$key}) رندر نشده"
            );
            $this->assertStringContainsString($label, $html);
        }

        // مبلغ در حالتِ ساعتی برچسبش عوض می‌شود، پس یک قلّابِ مشخص لازم دارد
        $this->assertSame(1, substr_count($html, 'id="cvb-sh-amt"'));
    }

    /** هر ردیفِ فروختنی کلیدهای مرتب‌سازی‌اش را دارد — و هیچ‌کدام «مبلغ» نیست */
    public function test_every_sellable_row_carries_ascii_sort_keys_and_never_a_price(): void
    {
        $this->base();
        $html = $this->page();

        $this->assertMatchesRegularExpression(
            '~data-slug="cv-4c-8g-80d-de-frankfurt" data-kind="shared" data-ord="\d+" data-sv="4" data-sr="8192" data-sd="80"~',
            $html,
            'کلیدهای مرتب‌سازی باید پس از data-slug و با عددِ خام بیایند'
        );

        // 🔴 مبلغ هرگز به‌عنوان صفتِ DOM نمی‌آید: مرتب‌سازی‌اش از D.prices خوانده
        // می‌شود، پس هیچ عددِ پولی دو بار روی صفحه نمی‌نشیند.
        $this->assertStringNotContainsString('data-sp=', $html);
        $this->assertDoesNotMatchRegularExpression('~data-s[a-z]="1000000"~', $html);

        // قلّابِ گروه‌بندی دست‌نخورده: یک ردیف به‌ازای هر اسلاگ
        foreach (['cv-2c-4g-40d-de-frankfurt', 'cv-4c-8g-80d-de-frankfurt'] as $slug) {
            $this->assertSame(1, substr_count($html, 'data-slug="'.$slug.'"'),
                'هر مشخصات باید دقیقاً یک ردیف داشته باشد');
        }
    }

    /**
     * 🔴 اسکریپتِ درون‌صفحه داخلِ همان سندی است که شمرده می‌شود. یک
     * `querySelectorAll('.cvb-plan[data-slug=…]')` بدونِ هیچ تغییری در نشانه‌گذاری
     * ادعای گروه‌بندی/سفیدبرچسبی را می‌شکند.
     */
    public function test_the_inline_script_never_writes_a_slug_or_value_literal(): void
    {
        $this->base();
        $js = $this->scripts($this->page());

        $this->assertNotSame('', trim($js), 'اسکریپت باید واقعاً استخراج شده باشد');

        foreach (['data-slug="', 'data-uslug="', 'value="'] as $bad) {
            $this->assertStringNotContainsString($bad, $js, "«{$bad}» نباید در جاوااسکریپت باشد");
        }

        // ولی واقعاً از انتخابگرِ «وجودِ صفت» استفاده می‌کند
        $this->assertStringContainsString('.cvb-plan[data-slug]', $js);
    }

    /** ردیف‌ها به ترتیبِ سرور می‌آیند و data-ord آن ترتیب را قطعی بازیافتنی می‌کند */
    public function test_the_default_dom_order_is_the_server_order_and_is_recoverable(): void
    {
        $this->base();
        $html = $this->page();

        preg_match_all('~data-slug="([^"]+)" data-kind="[^"]*" data-ord="(\d+)"~', $html, $m, PREG_SET_ORDER);

        $this->assertCount(2, $m, 'دو ردیفِ فروختنی باید باشد');
        $this->assertSame(['cv-2c-4g-40d-de-frankfurt', 'cv-4c-8g-80d-de-frankfurt'],
            array_column($m, 1), 'ترتیبِ سرور (کوچک به بزرگ) باید حفظ شود');
        $this->assertSame(['0', '1'], array_column($m, 2));

        // و ترتیبِ خودِ payload سرور دست‌نخورده مانده (قلّابِ CloudStoreSlipTest)
        $this->assertMatchesRegularExpression(
            '~"addon":\{"cv-2c-4g-40d-de-frankfurt":(true|false),"cv-4c-8g-80d-de-frankfurt":(true|false)\}~',
            $html
        );
    }

    // ═══════════════════ ستونِ یک‌مقداری ═══════════════════

    /**
     * ستونی که در کلِ این مکان یک مقدار دارد، ستون نیست؛ پانویس است.
     * امروز کاتالوگِ واقعی دقیقاً همین است: همه اشتراکی، همه ۲۰ TB.
     */
    public function test_a_column_with_a_single_value_collapses_into_a_footnote(): void
    {
        $this->base();
        $html = $this->page();

        // نه ستونِ ترافیک، نه ستونِ نوعِ پردازنده
        $this->assertStringNotContainsString('cvb-c-net', $html);
        $this->assertStringNotContainsString('cvb-c-kind', $html);
        $this->assertStringNotContainsString('data-sn=', $html, 'بی‌ستون، کلیدِ مرتب‌سازی هم بی‌معناست');
        $this->assertStringNotContainsString('has-net', $html);
        $this->assertStringNotContainsString('has-cpu', $html);

        // ولی خودِ مقدار گم نمی‌شود
        $this->assertStringContainsString(
            __('ui.cvb_same_all', ['label' => __('ui.cvb_traffic'), 'value' => fa_num('20 TB')]),
            $html,
            'ترافیکِ یکسان باید در پانویس بماند، نه اینکه غیب شود'
        );
        $this->assertStringContainsString(
            __('ui.cvb_same_all', ['label' => __('ui.cvb_cpu'), 'value' => __('ui.cvb_cpu_shared')]),
            $html
        );
    }

    /** ولی وقتی مقدارها فرق دارند، ستون و کلیدِ مرتب‌سازی‌اش می‌آیند */
    public function test_a_column_that_actually_varies_is_rendered_with_its_sort_key(): void
    {
        $this->base();
        $this->plan([
            'provider_ref' => 'cpx31', 'public_name' => 'CV-4-8-BIG',
            'slug' => 'cv-4c-8g-160d-de-frankfurt',
            'vcpu' => 4, 'ram_mb' => 8192, 'disk_gb' => 160, 'traffic_gb' => 40960,
            'cost_eur_cents' => 900, 'price_eur_cents' => 1400, 'price_irt' => 1400000,
        ]);

        $html = $this->page();

        $this->assertStringContainsString('has-net', $html);
        $this->assertStringContainsString('cvb-c-net', $html);
        $this->assertStringContainsString('data-sn="40960"', $html);
        $this->assertMatchesRegularExpression(
            '~<button type="button" class="cvb-sh cvb-sh-net" data-sort="sn"~', $html);
    }

    // ═══════════════════ فیلتر ═══════════════════

    /** فیلتری که چیزی برای فیلترکردن ندارد، اصلاً نمی‌آید */
    public function test_the_cpu_filter_is_absent_when_every_plan_shares_one_cpu_kind(): void
    {
        $this->base();
        $html = $this->page();

        $this->assertStringNotContainsString('id="cvb-kind"', $html,
            'با یک نوعِ پردازنده، زدنِ «اختصاصی» فهرست را بی‌توضیح خالی می‌کرد');
        $this->assertStringNotContainsString('id="cvb-kind-empty"', $html);
    }

    /** و وقتی هر دو نوع هست: فیلتر + حالتِ خالیِ آماده */
    public function test_the_cpu_filter_appears_with_an_empty_state_when_both_kinds_exist(): void
    {
        $this->base();
        $this->dedicated();

        $html = $this->page();

        $this->assertStringContainsString('id="cvb-kind"', $html);
        $this->assertStringContainsString('data-kind="dedicated"', $html);
        $this->assertStringContainsString('has-cpu', $html);
        $this->assertStringContainsString('cvb-c-kind', $html);

        // حالتِ خالی از پیش رندر شده و پنهان است — پیامِ ساختِ جاوااسکریپتی
        // زیرِ نگهبانِ «هر کلاس باید استایل داشته باشد» دیده نمی‌شود.
        $this->assertMatchesRegularExpression('~<p class="cvb-empty" id="cvb-kind-empty" hidden>~', $html);
        $this->assertStringContainsString(__('ui.cvb_kind_empty'), $html);
    }

    /**
     * 🔴 قراردادِ اصلیِ این تغییر: فیلتر نباید بتواند یک ردیفِ **نامرئیِ
     * ارسال‌شدنی** جا بگذارد.
     *
     * دو نیمهٔ این قفل باید همیشه با هم باشند و این تست همان جفت‌بودن را
     * می‌سنجد — چون تنها یکی از دو نیمه، از امروز **بدتر** است:
     *   الف) قاعدهٔ CSS که `hidden` را واقعی می‌کند (تا امروز فیلتر یک no-op
     *        بود، چون `display:flex`ِ نویسنده بر `[hidden]` می‌چربید)،
     *   ب) زنجیرهٔ ترمیمِ انتخاب: رادیوی پنهان‌شده از انتخاب درمی‌آید، اولین
     *      ردیفِ دیدنیِ فروختنی جایش را می‌گیرد، همان زنجیرهٔ یک انتخابِ دستی
     *      می‌دود، و اگر هیچ ردیفی نماند دکمهٔ پرداخت بسته می‌شود.
     *
     * (رفتارِ زمانِ اجرا روی این ماشین سنجیدنی نیست — CLAUDE.md §۸.)
     */
    public function test_a_filtered_out_plan_can_never_stay_selected_and_submittable(): void
    {
        $css = $this->cssCode();
        $js = $this->viewSrc();

        // الف) پنهان‌شدن واقعی است
        $this->assertStringContainsString('.cvb-plan[hidden]', $css,
            'بی‌این قاعده، صفتِ hidden روی ردیفِ پلن هیچ کاری نمی‌کند');
        $this->assertMatchesRegularExpression('~\.cvb-plan\[hidden\][^{]*\{display:none\}~', $css);

        // ب) و دقیقاً همان‌جا ترمیمِ انتخاب هم هست
        $this->assertStringContainsString('var applyKind = function(kind){', $js);
        $this->assertStringContainsString('c.hidden = hide;', $js);
        $this->assertStringContainsString('if (cur) { cur.checked = false; }', $js,
            'رادیوی پنهان‌شده باید از انتخاب دربیاید');
        $this->assertStringContainsString('if (!firstOk) { lockSubmit(true); }', $js,
            'اگر هیچ ردیفِ دیدنی نماند، دکمهٔ پرداخت باید بسته شود');
        $this->assertStringContainsString('kindEmpty.hidden = !!firstOk;', $js);

        // و همان زنجیره‌ای می‌دود که یک انتخابِ دستی می‌زند (برگه، ایمیج، مبلغ)
        $repair = substr($js, strpos($js, 'var applyKind = function(kind){'));
        $repair = substr($repair, 0, strpos($repair, 'form.querySelectorAll(\'#cvb-kind'));
        foreach (['mark(\'.cvb-plan\'', 'syncImages();', 'render();'] as $step) {
            $this->assertStringContainsString($step, $repair, "زنجیرهٔ ترمیم «{$step}» را ندارد");
        }

        // ⚠️ ولی عمداً مرحله را جلو نمی‌بَرد: فیلتر یک مقایسه است نه یک تصمیم
        $this->assertStringNotContainsString('openStep', $repair,
            'فیلتر نباید فهرست را زیرِ دستِ کاربر ببندد');
    }

    /** ردیفِ فیلترپذیر واقعاً نشانهٔ فیلترش را دارد — هم فروختنی، هم ناموجود */
    public function test_every_row_carries_the_filter_hook_including_the_unavailable_ones(): void
    {
        $this->base();
        $this->dedicated();
        $this->plan([
            'provider_ref' => 'ccx23', 'public_name' => 'CV-D-16-32',
            'slug' => 'cv-16c-32g-320d-de-frankfurt',
            'vcpu' => 16, 'ram_mb' => 32768, 'disk_gb' => 320, 'cpu_kind' => 'dedicated',
            'cost_eur_cents' => 2800, 'price_eur_cents' => 4000, 'price_irt' => 4000000,
            'in_stock' => false,
        ]);

        $html = $this->page();

        $this->assertMatchesRegularExpression(
            '~data-uslug="cv-16c-32g-320d-de-frankfurt" data-kind="dedicated"~', $html,
            'ردیفِ ناموجود هم باید با فیلتر پنهان/آشکار شود'
        );

        // هر ردیف — فروختنی یا ناموجود — دقیقاً یک قلّابِ فیلتر دارد
        preg_match_all('~data-(?:slug|uslug)="([^"]+)" data-kind="(shared|dedicated)"~', $html, $m, PREG_SET_ORDER);
        $this->assertCount(4, $m, 'چهار ردیف، چهار قلّابِ فیلتر');
        $this->assertSame(
            ['shared', 'shared', 'dedicated', 'dedicated'],
            array_column($m, 2)
        );
    }

    // ═══════════════════ ردیفِ ناموجود ═══════════════════

    /**
     * ناموجود در برگه: هم‌تراز، صادق، بی‌قیمت، بی‌رادیو — و بدونِ هیچ `<div>`
     * تودرتو، چون رجکسِ نگهبان تا **نخستین** بستنِ div می‌بندد.
     */
    public function test_an_unavailable_row_sits_in_the_sheet_aligned_priceless_and_unsubmittable(): void
    {
        $this->base();
        $this->plan([
            'provider_ref' => 'cx42', 'public_name' => 'CV-8-16',
            'slug' => 'cv-8c-16g-160d-de-frankfurt',
            'vcpu' => 8, 'ram_mb' => 16384, 'disk_gb' => 160,
            'cost_eur_cents' => 1400, 'price_eur_cents' => 2000, 'price_irt' => 2000000,
            'in_stock' => false,
        ]);

        $html = $this->page();

        // قلّابِ نشکستنی: data-uslug، نه data-slug
        $this->assertSame(1, substr_count($html, 'data-uslug="cv-8c-16g-160d-de-frankfurt"'));
        $this->assertSame(0, substr_count($html, 'data-slug="cv-8c-16g-160d-de-frankfurt"'));
        $this->assertStringNotContainsString('value="cv-8c-16g-160d-de-frankfurt"', $html);

        preg_match('~<div class="cvb-off cvb-plan" data-uslug="cv-8c-16g-160d-de-frankfurt".*?</div>~s', $html, $m);
        $this->assertNotEmpty($m, 'ردیفِ ناموجود باید رندر شود');
        $row = $m[0];

        // 🔴 پنجرهٔ رجکس واقعاً کلِ ردیف را گرفته — وگرنه ادعاهای پولیِ زیر پوچ‌اند
        $this->assertStringContainsString(__('ui.cvb_off_stock_sub'), $row,
            'پنجره باید تا انتهای ردیف برسد؛ یک <div> تودرتو آن را می‌بُرد و '
            .'ادعاهای «نه تومان، نه €» را بی‌صدا پوچ می‌کند');

        // فرزندان (یعنی همه‌چیز پس از تگِ بازِ خودِ ردیف) هیچ <div>ی ندارند
        $inner = substr($row, strpos($row, '>') + 1);
        $this->assertStringNotContainsString('<div', $inner, 'فرزندِ <div> در ردیفِ ناموجود ممنوع است');

        // هم‌ترازی: همان سلول‌های ردیفِ فروختنی
        foreach (['cvb-c cvb-c-cpu', 'cvb-c cvb-c-ram', 'cvb-c cvb-c-dsk'] as $cell) {
            $this->assertStringContainsString($cell, $row, "سلولِ «{$cell}» در ردیفِ ناموجود نیست");
        }

        // ستونِ مبلغ **خالی** است: نه قیمت، نه خط تیره، نه صفر، نه قلّابِ قیمت
        $this->assertStringNotContainsString('data-pp', $row);
        $this->assertStringNotContainsString('تومان', $row);
        $this->assertStringNotContainsString('€', $row);
        $this->assertStringNotContainsString('—', $row);
        $this->assertStringNotContainsString(fa_num(number_format(2000000)), $html);
    }

    // ═══════════════════ سفیدبرچسبی ═══════════════════

    /** مهم‌ترین قاعده — این‌بار با چهار زیرساخت روی همان برگه */
    public function test_no_provider_name_appears_anywhere_in_the_sheet(): void
    {
        $this->base();
        $this->plan([
            'provider' => 'aeza', 'provider_ref' => 'EPs-1', 'provider_location' => 'ru-1',
            'public_name' => 'CV-8-16', 'slug' => 'cv-8c-16g-160d-de-frankfurt',
            'vcpu' => 8, 'ram_mb' => 16384, 'disk_gb' => 160, 'traffic_gb' => 40960,
            'cost_eur_cents' => 1400, 'price_eur_cents' => 2000, 'price_irt' => 2000000,
        ]);
        $this->plan([
            'provider' => 'arvan', 'provider_ref' => 'ar-g2-2', 'provider_location' => 'ir-thr-c2',
            'public_name' => 'CV-D-16-32', 'slug' => 'cv-16c-32g-320d-de-frankfurt',
            'vcpu' => 16, 'ram_mb' => 32768, 'disk_gb' => 320, 'cpu_kind' => 'dedicated',
            'cost_eur_cents' => 2800, 'price_eur_cents' => 4000, 'price_irt' => 4000000,
        ]);
        $this->plan([
            'provider' => 'ovh', 'provider_ref' => 'b2-7', 'provider_location' => 'gra7',
            'public_name' => 'CV-32-64', 'slug' => 'cv-32c-64g-640d-de-frankfurt',
            'vcpu' => 32, 'ram_mb' => 65536, 'disk_gb' => 640,
            'cost_eur_cents' => 5600, 'price_eur_cents' => 8000, 'price_irt' => 0,
        ]);

        Setting::put('pricing_rate_override', '120000');
        Http::fake();
        $html = $this->page();

        // منویِ «سرورِ اختصاصیِ برندِ X» محصولِ دیگری است و از دعوی بیرون است
        $own = (string) preg_replace('~<a\b[^>]*href="[^"]*/dedicated/[^"]*"[^>]*>.*?</a>~is', '', $html);

        foreach (['hetzner', 'Hetzner', 'HETZNER', 'aeza', 'Aeza', 'AEZA', 'ovh', 'OVH',
            'arvan', 'Arvan', 'cx22', 'CX22', 'cx32', 'cx42', 'EPs-', 'eps-',
            'fsn1', 'hel1', 'gra7', 'ru-1', 'ir-thr-c2', 'b2-7', 'ar-g2-2'] as $secret) {
            $this->assertStringNotContainsString($secret, $own, "«{$secret}» نباید در HTML باشد");
        }

        // و برگه واقعاً پر است (وگرنه این تست هیچ‌چیز نمی‌سنجد)
        $this->assertGreaterThanOrEqual(3, substr_count($html, 'class="cvb-plan'));

        Http::assertNothingSent();
    }

    // ═══════════════════ صفحه‌کلید و چیدمان ═══════════════════

    /** انتخاب هنوز یک رادیوست و کلِ ردیف هدفِ کلیک — الگوی صفحه‌کلید دست‌نخورده */
    public function test_selection_is_still_a_keyboard_reachable_radio_inside_the_row(): void
    {
        $this->base();
        $html = $this->page();

        $this->assertMatchesRegularExpression(
            '~<label class="cvb-plan[^"]*" data-slug="[^"]+"[^>]*>\s*<input type="radio" name="plan"~',
            $html,
            'ردیف باید یک <label> با رادیوی درونی بماند'
        );

        $css = $this->css();
        $this->assertStringContainsString('.cvb-plan:has(input:focus-visible)', $css);
        // رادیو «پنهانِ دیداری» است نه display:none — وگرنه از ترتیبِ Tab بیرون می‌افتد
        $this->assertStringContainsString('.cvb-plan input,.cvb-img input,.cvb-seg input{position:absolute', $css);

        // قلّاب‌های برگه سرِ جایشان
        $this->assertStringContainsString('<b id="cvb-s-plan">', $html);
        $this->assertStringContainsString('id="cvb-h-low"', $html);
    }

    /**
     * 🔴 موبایل: تصمیم با **پرس‌وجوی ظرف** گرفته می‌شود، نه media query.
     *
     * عرضِ پنجره دربارهٔ این عنصر دروغ می‌گوید (در ۱۰۰۱px فهرست ~۲۸۹px است و در
     * ۹۹۹px ~۹۱۶px)، و یک آستانهٔ مشتق‌شده از عرضِ برگه با اولین تنظیمِ آن عرض
     * بی‌صدا غلط می‌شود. مهم‌تر: پیش‌فرضِ **بی‌پرس‌وجو کارت** است، پس نبودِ
     * پشتیبانی یعنی طرحِ دیروز، نه یک جدولِ شکسته.
     */
    public function test_the_sheet_is_container_queried_and_degrades_to_the_card_layout(): void
    {
        $css = $this->cssCode();

        $this->assertStringContainsString('.cvb-sheet{container:cvbs / inline-size}', $css);
        $this->assertMatchesRegularExpression('~@container cvbs \(min-width:460px\)~', $css);

        // پیش‌فرضِ بیرونِ هر پرس‌وجو هنوز همان گریدِ کارت است
        $this->assertStringContainsString(
            '.cvb-plans{display:grid;grid-template-columns:repeat(auto-fill,minmax(232px,1fr))', $css,
            'حالتِ کارت باید پیش‌فرضِ بی‌شرط بماند'
        );

        // 🔴 هیچ فهرستِ ستونی بیرون از پرس‌وجوی ظرف تعریف نمی‌شود — نه در ریشه،
        // نه در یک media query. وگرنه شکلِ برگه به عرضِ **پنجره** گره می‌خورد،
        // یعنی همان وارونگی‌ای که این طراحی برای فرارش ساخته شده.
        $gate = strpos($css, '@container cvbs (min-width:460px)');
        $this->assertNotFalse($gate);
        $this->assertSame(4, substr_count($css, '--cvb-cols:'), 'چهار ترکیبِ ستون، نه بیشتر');
        $this->assertSame(0, substr_count(substr($css, 0, $gate), '--cvb-cols:'),
            'فهرستِ ستون‌ها فقط داخلِ پرس‌وجوی ظرف تعریف شود');

        /*
        | بدنه هرگز افقی اسکرول نمی‌شود: هیچ overflow-x تازه‌ای در واژگانِ cvb نیست.
        |
        | 🔴 این ادعا **حاملش را عوض کرد**، و دلیلش مهم است.
        |
        | نسخهٔ قبلی `substr($css, strpos($css, '.cvb-wrap{'))` می‌گرفت — یعنی
        | «از این‌جا تا **تهِ فایل**». آن روزی درست بود که واژگانِ cvb آخرین چیزِ
        | panel.css بود. panel.css عمداً append-only است، پس اولین بلوکی که بعدش
        | اضافه شد (واژگانِ `.svc-*` پنل) داخلِ همین برش افتاد و ادعا را شکست —
        | در حالی که هیچ‌چیزِ cvb عوض نشده بود.
        |
        | بدتر از قرمزِ بی‌جا این بود که ادعا داشت دربارهٔ کدِ **دیگری** قضاوت
        | می‌کرد: یک `overflow-x` کاملاً درست (جعبهٔ اسکرولِ خودِ خطِ IP در کارتِ
        | سرور، که برای همین ساخته شده تا **بدنه** افقی نرود) این‌جا به‌عنوان
        | تخلفِ برگهٔ فروش گزارش می‌شد.
        |
        | حالا برش بر اساسِ **سلکتور** است نه موقعیت، پس هرچه بعدها به انتهای
        | فایل اضافه شود این ادعا را تکان نمی‌دهد و در عوض هر
        | `.cvb-…{…overflow-x…}` واقعی — حتی در وسطِ فایل یا داخلِ یک
        | media/container query — گرفته می‌شود.
        */
        preg_match_all('~([^{}]+)\{([^{}]*)\}~', $css, $rules, PREG_SET_ORDER);

        $cvb = '';
        foreach ($rules as $r) {
            if (str_contains($r[1], '.cvb-')) {
                $cvb .= $r[1].'{'.$r[2]."}\n";
            }
        }

        $this->assertNotSame('', $cvb, 'هیچ قاعدهٔ cvb پیدا نشد — برش کهنه شده و این ادعا دیگر چیزی نمی‌سنجد');
        $this->assertStringContainsString('.cvb-wrap{', $cvb, 'برشِ cvb باید خودِ ظرفِ اصلی را هم داشته باشد');

        $this->assertStringNotContainsString('overflow-x', $cvb,
            'اسکرولِ افقی راه‌حلِ این فهرست نیست — و بدنهٔ صفحه هرگز نباید افقی برود');

        // مسیرِ گرید سرتاسر می‌تواند کوچک شود (علتِ ریشه‌ایِ سرریزِ افقی)
        $this->assertStringContainsString('.cvb-wrap{display:grid;grid-template-columns:minmax(0,1fr) 340px', $css);
        $this->assertStringContainsString('.cvb-main{display:flex;flex-direction:column;gap:14px;min-width:0}', $css);
    }

    /** استایل هنوز در شیت است، نه در Blade */
    public function test_the_view_still_carries_no_css(): void
    {
        $blade = $this->viewSrc();

        $this->assertStringNotContainsString('<style', $blade);
        $this->assertStringNotContainsString('style="', $blade);
    }
}

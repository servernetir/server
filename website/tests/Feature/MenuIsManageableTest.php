<?php

namespace Tests\Feature;

use App\Models\MenuOverride;
use App\Models\User;
use App\Services\MenuManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 🔴 منوی هدر و فوتر باید از پنل مدیریت شود — بی‌آنکه سایت بیفتد.
 *
 * ═══ دو خرابی که این می‌بندد ═══
 *
 * ۱) لینک‌های فوتر تا امروز در Blade سخت‌کد بودند: هر تغییرِ کوچک یک دیپلوی
 *    می‌خواست، و «لینکِ فلان را بردار» چند روز طول می‌کشید.
 *
 * ۲) 🔴 و حالا که مقصدِ لینک‌ها را **مدیر** می‌نویسد، خطرِ قدیمی هزار برابر
 *    شده: فوتر روی **هر** صفحهٔ سایت رندر می‌شود و مرداد ۱۴۰۵ یک `lroute()`
 *    به روتِ بی‌نام همان‌جا کلِ en/tr را ۵۰۰ کرد. پس بیشترِ ادعاهای این پرونده
 *    دربارهٔ «چطور خراب نشود» است، نه «چطور کار کند».
 */
class MenuIsManageableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        MenuManager::forget();
    }

    private function menu(): MenuManager
    {
        MenuManager::forget();

        return app(MenuManager::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    // ═══════════════ ۱) فوتر نباید سایت را بیندازد ═══════════════

    /**
     * 🔴 مهم‌ترین ادعا: مقصدِ ناساختنی **رد** می‌شود، نه اینکه استثنا بدهد.
     *
     * روتی که امروز هست ممکن است فردا با یک دیپلوی نباشد. آن‌وقت هیچ‌کس چیزی
     * در پنل عوض نکرده، ولی کلِ سایت ۵۰۰ می‌دهد.
     */
    public function test_an_unbuildable_link_is_skipped_not_thrown(): void
    {
        MenuOverride::create([
            'menu' => 'footer', 'path' => 'footer:custom:broken', 'visible' => true,
            'label_fa' => 'خراب',
            'custom' => ['column' => 'company', 'route' => 'this.route.does.not.exist'],
        ]);

        $cols = $this->menu()->footer('fa');

        $texts = collect($cols)->flatMap(fn ($c) => collect($c['items'])->pluck('text'))->all();

        $this->assertNotContains('خراب', $texts, 'لینکِ ناساختنی نمایش داده شد');
        $this->assertNotSame([], $cols, 'کلِ فوتر به‌خاطرِ یک لینکِ بد از دست رفت');
    }

    /** و همان صفحه واقعاً ۲۰۰ می‌دهد — نه فقط تابع. */
    public function test_the_site_still_renders_with_a_broken_custom_link(): void
    {
        MenuOverride::create([
            'menu' => 'footer', 'path' => 'footer:custom:broken', 'visible' => true,
            'label_fa' => 'خراب',
            'custom' => ['column' => 'company', 'route' => 'nope.nope'],
        ]);

        $this->get('/')->assertOk();
    }

    /**
     * 🔴 `javascript:` در فوترِ **هر** صفحه یعنی XSS روی کلِ سایت.
     *
     * این فیلد را مدیر پر می‌کند، پس ورودیِ غریبه نیست — ولی ورودیِ آزاد هست،
     * و یک اشتباهِ کپی‌پیست کافی است.
     */
    public function test_a_javascript_url_never_reaches_the_page(): void
    {
        MenuOverride::create([
            'menu' => 'footer', 'path' => 'footer:custom:xss', 'visible' => true,
            'label_fa' => 'بد',
            'custom' => ['column' => 'company', 'url' => 'javascript:alert(1)'],
        ]);

        $hrefs = collect($this->menu()->footer('fa'))
            ->flatMap(fn ($c) => collect($c['items'])->pluck('href'))->all();

        foreach ($hrefs as $h) {
            $this->assertStringNotContainsStringIgnoringCase('javascript:', $h);
        }
    }

    /** ⚠️ و ذخیره‌اش هم همان لحظه رد شود، نه اینکه ساکت ناپدید شود. */
    public function test_saving_an_unknown_route_is_rejected_with_a_message(): void
    {
        $this->actingAs($this->admin())->post('/admin/menus/add', [
            'menu' => 'footer', 'scope' => 'company',
            'label_fa' => 'تست', 'target' => 'no.such.route',
        ])->assertSessionHasErrors('target');

        $this->assertSame(0, MenuOverride::count());
    }

    public function test_saving_a_real_route_name_is_accepted(): void
    {
        $this->actingAs($this->admin())->post('/admin/menus/add', [
            'menu' => 'footer', 'scope' => 'company',
            'label_fa' => 'وبلاگ ما', 'target' => 'blog.index',
        ])->assertSessionHasNoErrors();

        $texts = collect($this->menu()->footer('fa'))
            ->flatMap(fn ($c) => collect($c['items'])->pluck('text'))->all();

        $this->assertContains('وبلاگ ما', $texts);
    }

    // ═══════════════ ۲) رفتارِ امروز عوض نشود ═══════════════

    /**
     * 🔴 بی‌هیچ رویه‌ای، فوتر **دقیقاً** همان ۲۷ لینکِ قبل را بدهد.
     *
     * ⚠️ فهرست عمداً این‌جا سخت‌کد است و از خودِ config خوانده نمی‌شود: فیکسچری
     * که از همان منبعی ساخته شود که قرار است بسنجدش، هر خطایی را با خودش
     * تکرار می‌کند و همیشه سبز است. این‌ها از نسخهٔ **قبل از تغییر** برداشته
     * شده‌اند، یعنی از رفتاری که واقعاً روی سایت بود.
     */
    public function test_the_default_footer_is_unchanged(): void
    {
        app()->setLocale('fa');

        $expect = [
            lroute('hosting', 'linux'),
            lroute('catalog', ['category' => 'vps', 'slug' => 'iran']),
            lroute('catalog', ['category' => 'dedicated', 'slug' => 'iran']),
            lroute('catalog', ['category' => 'domain', 'slug' => 'popular-tlds']),
            lroute('catalog', ['category' => 'cloud', 'slug' => 'iaas']),
            lroute('solution', 'infrastructure'),
            lroute('solution', 'ai-agents'),
            lroute('solution', 'bpmn-erp'),
            lroute('solution', 'web-design'),
            lroute('solution', 'seo-services'),
            lroute('solution', 'managed'),
            lroute('solution', 'cloud-phone'),
            lroute('solutions.index'),
            lroute('about'),
            lroute('urmia.hub'),
            lroute('blog.index'),
            lroute('careers'),
            lroute('status'),
            lroute('sla'),
            lroute('speed'),
            lroute('terms'),
            lroute('aup'),
            lroute('abuse'),
            lroute('official'),
            lroute('badge'),
            lroute('privacy'),
            console_lroute('account.home'),
        ];

        $got = collect($this->menu()->footer('fa'))
            ->flatMap(fn ($c) => collect($c['items'])->pluck('href'))->all();

        $this->assertSame($expect, $got, 'فوتر با نسخهٔ سخت‌کدِ قبلی یکی نیست');
    }

    /**
     * ⚠️ و لینکِ فقط-فارسی در en/tr نیاید.
     *
     * 🔴 نسخهٔ en/tr صفحاتِ ارومیه با ۴۱۰ برداشته شد و روتشان نام ندارد. اگر
     * این شرط بیفتد، `lroute()` استثنا می‌دهد و چون فوتر روی هر صفحه است،
     * کلِ سایت در آن دو زبان ۵۰۰ می‌شود — همان اتفاقی که واقعاً افتاد.
     */
    public function test_a_persian_only_link_is_absent_from_the_other_languages(): void
    {
        $fa = collect($this->menu()->footer('fa'))->flatMap(fn ($c) => collect($c['items'])->pluck('href'))->count();
        $en = collect($this->menu()->footer('en'))->flatMap(fn ($c) => collect($c['items'])->pluck('href'))->count();

        $this->assertSame($fa - 1, $en, 'لینکِ فقط-فارسی در انگلیسی هم آمد یا برعکس');

        $this->get('/en')->assertOk();
        $this->get('/tr')->assertOk();
    }

    // ═══════════════ ۳) خودِ مدیریت ═══════════════

    public function test_the_admin_can_rename_a_link_in_all_three_languages(): void
    {
        $this->actingAs($this->admin())->post('/admin/menus/save', [
            'path' => 'footer:products:hosting', 'menu' => 'footer',
            'label_fa' => 'میزبانی وب', 'label_en' => 'Web Hosting!', 'label_tr' => 'Web Barındırma',
        ])->assertSessionHasNoErrors();

        foreach (['fa' => 'میزبانی وب', 'en' => 'Web Hosting!', 'tr' => 'Web Barındırma'] as $l => $want) {
            $texts = collect($this->menu()->footer($l))
                ->flatMap(fn ($c) => collect($c['items'])->pluck('text'))->all();

            $this->assertContains($want, $texts, 'متنِ '.$l.' اعمال نشد');
        }
    }

    public function test_the_admin_can_switch_a_link_off_and_back_on(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/menus/hide', [
            'path' => 'footer:company:badge', 'menu' => 'footer',
        ])->assertSessionHasNoErrors();

        app()->setLocale('fa');
        $hrefs = collect($this->menu()->footer('fa'))->flatMap(fn ($c) => collect($c['items'])->pluck('href'))->all();
        $this->assertNotContains(lroute('badge'), $hrefs);

        $this->actingAs($admin)->post('/admin/menus/hide', [
            'path' => 'footer:company:badge', 'menu' => 'footer',
        ]);

        $hrefs = collect($this->menu()->footer('fa'))->flatMap(fn ($c) => collect($c['items'])->pluck('href'))->all();
        $this->assertContains(lroute('badge'), $hrefs, 'روشن‌کردنِ دوباره کار نکرد');
    }

    /**
     * 🔴 گرهِ خاموش باید در **صفحهٔ مدیریت** بماند.
     *
     * اگر فهرست از خروجیِ رندر ساخته می‌شد، خاموش‌کردنِ یک لینک آن را از پنل هم
     * پاک می‌کرد و روشن‌کردنش فقط با دستکاریِ مستقیمِ دیتابیس ممکن بود.
     */
    public function test_a_hidden_node_is_still_listed_in_the_panel(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/menus/hide', [
            'path' => 'footer:company:badge', 'menu' => 'footer',
        ]);

        $html = $this->actingAs($admin)->get('/admin/settings?tab=menus')->assertOk()->getContent();

        $this->assertStringContainsString('footer:company:badge', $html,
            'گرهٔ خاموش از صفحهٔ مدیریت ناپدید شد — درِ یک‌طرفه');
    }

    /** حذفِ رویه = برگشت به پیش‌فرض، نه حذفِ لینک. */
    public function test_deleting_an_override_restores_the_default(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/menus/save', [
            'path' => 'footer:products:hosting', 'menu' => 'footer', 'label_fa' => 'عوض‌شده',
        ]);

        $row = MenuOverride::where('path', 'footer:products:hosting')->firstOrFail();

        $this->actingAs($admin)->delete('/admin/menus/'.$row->id)->assertSessionHasNoErrors();

        $texts = collect($this->menu()->footer('fa'))
            ->flatMap(fn ($c) => collect($c['items'])->pluck('text'))->all();

        $this->assertNotContains('عوض‌شده', $texts);
        $this->assertContains(__('ui.f_p1'), $texts, 'پیش‌فرض برنگشت');
    }

    // ═══════════════ ۴) ترتیب ═══════════════

    /**
     * 🔴 `sort`ِ نال یعنی «دست نزن».
     *
     * مرتب‌سازیِ ساده نال را صفر می‌خواند و هر ردیفِ دست‌نخورده را به ابتدای
     * فهرست می‌بُرد: یعنی ویرایشِ **متنِ** یک لینک، ترتیبِ کلِ منو را هم عوض
     * می‌کرد — تغییری که مدیر نخواسته و نمی‌فهمد از کجا آمد.
     */
    public function test_editing_a_label_does_not_reorder_the_menu(): void
    {
        app()->setLocale('fa');
        $before = collect($this->menu()->footer('fa'))
            ->flatMap(fn ($c) => collect($c['items'])->pluck('href'))->all();

        $this->actingAs($this->admin())->post('/admin/menus/save', [
            'path' => 'footer:company:privacy', 'menu' => 'footer', 'label_fa' => 'حریم خصوصی',
        ]);

        $after = collect($this->menu()->footer('fa'))
            ->flatMap(fn ($c) => collect($c['items'])->pluck('href'))->all();

        $this->assertSame($before, $after, 'ویرایشِ متن، ترتیب را جابه‌جا کرد');
    }

    /** و عددِ ترتیب واقعاً بالا می‌بَرد. */
    public function test_a_sort_number_moves_the_item_up(): void
    {
        app()->setLocale('fa');

        $this->actingAs($this->admin())->post('/admin/menus/save', [
            'path' => 'footer:products:cloud', 'menu' => 'footer', 'sort' => -5,
        ]);

        $first = $this->menu()->footer('fa')[0]['items'][0]['href'];

        $this->assertSame(lroute('catalog', ['category' => 'cloud', 'slug' => 'iaas']), $first);
    }

    /**
     * 🔴 و ردیفِ **بی‌عدد** نباید جلوی ردیفِ عددخورده بیفتد.
     *
     * ⚠️ نسخهٔ اولِ این پرونده فکر می‌کرد این را پوشش داده، ولی هر دو تستش
     * حالتی می‌ساختند که در آن **همهٔ** ردیف‌ها بی‌عدد بودند — یعنی شاخه‌ای که
     * نال را با عدد می‌سنجد اصلاً اجرا نمی‌شد. جهش‌سنجی نشان داد: «نال را صفر
     * بخوان» از هر دو تست سالم رد می‌شد.
     *
     * حالتِ واقعی همیشه مخلوط است: مدیر یکی-دو لینک را جابه‌جا می‌کند و بقیه
     * دست‌نخورده می‌مانند. اگر نال صفر خوانده شود، آن یکی که مدیر عمداً به
     * صدر برده، پشتِ همهٔ دست‌نخورده‌ها گم می‌شود.
     */
    public function test_untouched_items_never_jump_ahead_of_a_sorted_one(): void
    {
        app()->setLocale('fa');

        $this->actingAs($this->admin())->post('/admin/menus/save', [
            'path' => 'footer:company:privacy', 'menu' => 'footer', 'sort' => 1,
        ]);

        $company = collect($this->menu()->footer('fa')[2]['items'])->pluck('href')->all();

        $this->assertSame(lroute('privacy'), $company[0],
            'ردیفِ عددخورده پشتِ ردیف‌های بی‌عدد افتاد');

        // و بقیه ترتیبِ خودشان را نگه داشته‌اند
        $this->assertSame(lroute('about'), $company[1]);
        $this->assertSame(lroute('urmia.hub'), $company[2]);
    }

    // ═══════════════ ۵) هدر و مگامنو ═══════════════

    /**
     * ⚠️ رویه روی مگامنو **بعد** از `SiteMenu` می‌نشیند.
     *
     * 🔴 اگر جایش می‌نشست، گروهِ «موقعیت مکانی» که زنده از کاتالوگ ساخته می‌شود
     * یک عکسِ منجمد می‌شد و کشورِ تازه دیگر هرگز در منو نمی‌آمد — خرابیِ ساکتی
     * که ماه‌ها بعد به‌شکلِ «چرا سنگاپور در منو نیست» پیدا می‌شد.
     */
    public function test_the_live_vps_locations_survive_the_overlay(): void
    {
        $raw = app(\App\Services\SiteMenu::class)->mega();

        $count = fn ($m) => collect($m['vps']['groups'] ?? [])
            ->firstWhere('en', 'Locations')['items'] ?? [];

        $before = count($count($raw));

        MenuOverride::create([
            'menu' => 'mega', 'path' => 'mega:hosting', 'visible' => false,
        ]);

        $after = count($count($this->menu()->mega($raw)));

        $this->assertSame($before, $after, 'رویه گروهِ زندهٔ مکان‌ها را کوتاه کرد');
        $this->assertGreaterThan(0, $before, 'گروهِ مکان‌ها اصلاً پیدا نشد — پیش‌فرضِ تست عوض شده');
    }

    /** 🔴 خاموش‌کردنِ همه‌چیز نباید منوی خالی بدهد. */
    public function test_switching_everything_off_falls_back_to_the_default_menu(): void
    {
        $raw = app(\App\Services\SiteMenu::class)->mega();

        foreach (array_keys($raw) as $tab) {
            MenuOverride::create(['menu' => 'mega', 'path' => 'mega:'.$tab, 'visible' => false]);
        }

        $this->assertSame(array_keys($raw), array_keys($this->menu()->mega($raw)),
            'منوی خالی در هدرِ سایت — از منوی کهنه بدتر است');
    }

    public function test_a_header_menu_item_can_be_renamed(): void
    {
        $this->actingAs($this->admin())->post('/admin/menus/save', [
            'path' => 'services:ssl', 'menu' => 'services', 'label_fa' => 'گواهی امن',
        ])->assertSessionHasNoErrors();

        $items = $this->menu()->flat('services');
        $ssl = collect($items)->firstWhere('slug', 'ssl');

        $this->assertSame('گواهی امن', $ssl['fa']['t']);
        $this->assertNotSame('', $ssl['fa']['d'] ?? '', 'توضیحِ پیش‌فرض هم پاک شد');
    }

    // ═══════════════ ۶) نصبِ مهاجرت‌نخورده ═══════════════

    /**
     * ⚠️ تا وقتی مهاجرت روی سرور اجرا نشده، منو باید مثلِ امروز کار کند.
     *
     * جدولِ نبود نباید هدرِ سایت را بیندازد — همان درسی که تبِ پیام‌ها داد.
     */
    public function test_the_site_works_before_the_migration_runs(): void
    {
        \Illuminate\Support\Facades\Schema::drop('menu_overrides');
        MenuManager::forget();

        $this->get('/')->assertOk();
        $this->assertNotSame([], app(MenuManager::class)->footer('fa'));
    }

    /** و صفحهٔ تنظیمات هم باز شود و بگوید چرا. */
    public function test_the_settings_tab_explains_itself_before_the_migration(): void
    {
        \Illuminate\Support\Facades\Schema::drop('menu_overrides');

        $html = $this->actingAs($this->admin())->get('/admin/settings?tab=menus')
            ->assertOk()->getContent();

        $this->assertStringContainsString('مهاجرت', $html);
    }

    // ═══════════════ ۷) دسترسی ═══════════════

    public function test_a_non_admin_cannot_touch_the_menu(): void
    {
        $user = User::factory()->create(['role' => 'customer']);

        $this->actingAs($user)->post('/admin/menus/save', [
            'path' => 'footer:products:hosting', 'menu' => 'footer', 'label_fa' => 'هک',
        ])->assertForbidden();

        $this->assertSame(0, MenuOverride::count());
    }
}

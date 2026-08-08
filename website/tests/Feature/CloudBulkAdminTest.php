<?php

namespace Tests\Feature;

use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Setting;
use App\Models\User;
use App\Services\Cloud\CloudProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * اقدامِ گروهی روی `/admin/cloud` — و مهم‌تر از خودِ اقدام، **چیزی که انجام
 * نمی‌دهد**.
 *
 * ═══ باگی که این فایل برای نیامدنش نوشته شد ═══
 *
 * قرنطینهٔ خودکار یک‌بار **۲۲۱ پلن** را بست و تنها راهِ برگرداندنشان دکمهٔ «باز
 * کن» ردیف‌به‌ردیف بود، روی صفحه‌ای با سقفِ ۴۰۰ ردیف. راهِ حلِ ساده — «یک دکمهٔ
 * باز کردنِ همه» — از خودِ مشکل خطرناک‌تر است:
 *
 *  • پلنی که مدیر **آگاهانه** بسته (تحویلش دستی است، قیمتش غلط است، مشتری
 *    شکایت داشته) با یک کلیک برمی‌گشت روی ویترین. تصمیمِ انسانی پاک شدنی
 *    نیست و هیچ لاگی هم نمی‌گفت چه چیزی پاک شد.
 *  • پلنِ بی‌قیمت/ناموجود با «موفق» گزارش می‌شد، در حالی که هیچ اتفاقِ مفیدی
 *    نیفتاده بود: نشانِ «بستهٔ من» می‌رفت و علتِ واقعیِ نفروختن پنهان می‌شد.
 *
 * پس این تست‌ها همان تفکیکی را قفل می‌کنند که `cloud:reopen` دارد، و علاوه‌اش
 * این که **علت گزارش می‌شود** — موفقیتِ ساکت روی ردیفی که دست نخورده، از
 * نبودِ دکمه بدتر است.
 */
class CloudBulkAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('pricing_rate_override', '100000');
        Setting::put('cloud_margin_pct', '50');
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'مدیر', 'email' => 'bulk'.random_int(1, 999999).'@x.com',
            'password' => bcrypt('secret1234'), 'role' => 'admin',
        ]);
    }

    private function location(string $code = 'de-falkenstein', string $iso = 'DE'): void
    {
        CloudLocation::firstOrCreate(['code' => $code], [
            'country' => $iso, 'city' => ucfirst(explode('-', $code)[1] ?? 'x'), 'is_active' => true,
        ]);
    }

    private function plan(array $attr = []): CloudPlan
    {
        static $i = 0;
        $i++;

        return CloudPlan::create(array_merge([
            'provider'     => 'hetzner',
            'provider_ref' => 'ref'.$i,
            'location_code' => 'de-falkenstein',
            'public_name'  => 'CV-'.$i,
            'slug'         => 'cv-'.$i.'c-4g-40d-de-falkenstein',
            'vcpu'         => $i,
            'ram_mb'       => 4096,
            'disk_gb'      => 40,
            'cpu_kind'     => 'shared',
            'cost_eur_cents' => 379,
            'price_eur_cents' => 570,
            'price_irt'    => 570000,
            'is_active'    => true,
            'in_stock'     => true,
        ], $attr));
    }

    /** پلنی که قرنطینهٔ **خودکار** بسته — یعنی واقعاً قابلِ بازکردن */
    private function quarantined(array $attr = []): CloudPlan
    {
        return $this->plan(array_merge([
            'admin_disabled' => true,
            'admin_note'     => CloudProvisioner::QUARANTINE_PREFIX.' زیرساخت سفارش را نپذیرفت (۵۰۰)',
        ], $attr));
    }

    private function bulk(string $url, array $data = [])
    {
        return $this->actingAs($this->admin(), 'web')->post($url, $data);
    }

    // ═══════════════ ۱) بازکردنِ گروهی: چه چیزی را باز می‌کند ═══════════════

    public function test_bulk_open_reopens_only_the_ids_that_were_posted(): void
    {
        $this->location();
        $a = $this->quarantined();
        $b = $this->quarantined();
        $untouched = $this->quarantined();

        $this->bulk('/admin/cloud/plans/bulk-open', ['ids' => $a->id.','.$b->id])
            ->assertRedirect()->assertSessionHas('ok');

        $this->assertFalse((bool) $a->fresh()->admin_disabled);
        $this->assertFalse((bool) $b->fresh()->admin_disabled);

        // 🔴 قلبِ «هرگز بی‌صدا روی ۷۷۰ ردیف»: ردیفِ نافرستاده دست نخورده
        $this->assertTrue((bool) $untouched->fresh()->admin_disabled,
            'ردیفی که انتخاب نشده بود نباید عوض شود');
        $this->assertNotNull($untouched->fresh()->admin_note);
    }

    /** یادداشتِ قرنطینه هم باید پاک شود، وگرنه ردیفِ باز، «بسته» به‌نظر می‌رسد */
    public function test_bulk_open_clears_the_quarantine_note(): void
    {
        $this->location();
        $p = $this->quarantined();

        $this->bulk('/admin/cloud/plans/bulk-open', ['ids' => (string) $p->id]);

        $this->assertNull($p->fresh()->admin_note);
    }

    /**
     * 🔴 مهم‌ترین ادعای این فایل: پلنی که **مدیر آگاهانه** بست باز نمی‌شود.
     *
     * `cloud:reopen` همین تفکیک را دارد و دلیلش این است که وگرنه فرمان به‌جای
     * «واگردانیِ یک اشتباهِ خودکار»، تصمیم‌های انسانی را هم پاک می‌کرد.
     */
    public function test_bulk_open_never_reopens_a_hand_disabled_plan(): void
    {
        $this->location();
        $byHand = $this->plan(['admin_disabled' => true, 'admin_note' => 'تحویلش دستی است']);
        $noNote = $this->plan(['admin_disabled' => true, 'admin_note' => null]);
        $auto = $this->quarantined();

        $res = $this->bulk('/admin/cloud/plans/bulk-open',
            ['ids' => implode(',', [$byHand->id, $noNote->id, $auto->id])]);

        $res->assertRedirect();

        $this->assertTrue((bool) $byHand->fresh()->admin_disabled, 'تصمیمِ دستی نباید برگردد');
        $this->assertSame('تحویلش دستی است', $byHand->fresh()->admin_note, 'یادداشتِ مدیر هم باید بمانَد');

        // «نمی‌دانم کی بست» دلیلِ کافی برای بازکردنِ فروش نیست — همان قاعدهٔ فرمان
        $this->assertTrue((bool) $noNote->fresh()->admin_disabled,
            'یادداشتِ نال یعنی نمی‌دانیم چه کسی بست؛ باز نمی‌شود');

        // ولی قرنطینهٔ خودکارِ همان درخواست باز شده باشد
        $this->assertFalse((bool) $auto->fresh()->admin_disabled);

        // و علت **گزارش** شده باشد، نه بی‌صدا رد شده
        $msg = (string) session('ok');
        $this->assertStringContainsString('باز نشد', $msg);
        $this->assertStringContainsString('دستی', $msg);
        $this->assertStringContainsString('cloud:reopen --all', $msg,
            'مدیر باید بداند راهِ دیگری هست، وگرنه فکر می‌کند دکمه خراب است');
    }

    /**
     * 🔴 پلنی که به علتِ **دیگری** نمی‌فروشد هم باز نمی‌شود.
     *
     * بازکردنش فقط نشانِ «بستهٔ من» را برمی‌دارد و علتِ واقعیِ نفروختن را پنهان
     * می‌کند — یعنی مدیر فکر می‌کند کار تمام شده و کارت روی فروشگاه نیست.
     *
     * سه علت، و هر سه باید **به نام** گزارش شوند.
     */
    public function test_bulk_open_refuses_a_plan_that_is_unsellable_for_another_reason(): void
    {
        $this->location();

        $cases = [
            ['attr' => ['price_irt' => 0], 'reason' => 'قیمتِ تومانی ندارد'],
            ['attr' => ['in_stock' => false], 'reason' => 'ناموجود'],
            ['attr' => ['is_active' => false], 'reason' => 'غیرفعال'],
        ];

        foreach ($cases as $case) {
            $p = $this->quarantined($case['attr']);

            $this->bulk('/admin/cloud/plans/bulk-open', ['ids' => (string) $p->id])
                ->assertRedirect()
                ->assertSessionHas('err');   // هیچ‌چیز باز نشد ⇒ خطا، نه «موفق»

            $this->assertTrue((bool) $p->fresh()->admin_disabled,
                'ردیفِ غیرقابلِ فروش نباید باز شود: '.$case['reason']);

            $this->assertStringContainsString($case['reason'], (string) session('err'),
                'علتِ ردشدن باید در پیام بیاید، وگرنه مدیر نمی‌فهمد چرا اتفاقی نیفتاد');
        }
    }

    /** زیرساختِ خاموش هم همان دسته است: بازکردنِ پلنش هیچ کارتی روی سایت نمی‌آورد */
    public function test_bulk_open_refuses_a_plan_whose_provider_is_switched_off(): void
    {
        $this->location();
        $p = $this->quarantined(['provider' => 'aeza']);
        CloudPlan::setProviderDisabled('aeza', true);

        $this->bulk('/admin/cloud/plans/bulk-open', ['ids' => (string) $p->id])
            ->assertRedirect()->assertSessionHas('err');

        $this->assertTrue((bool) $p->fresh()->admin_disabled);
        $this->assertStringContainsString('زیرساختش خاموش', (string) session('err'));
    }

    /**
     * 🔴 محافظِ اصلی — و عمداً از بیرون سنجیده می‌شود.
     *
     * فهرستِ علت‌ها در کنترلر شرط‌های `CloudPlan::scopeSellable` را **تکرار**
     * می‌کند (چون هر شرط باید یک علتِ خواندنی بدهد و اسکوپ فقط بله/خیر می‌دهد).
     * این تکرار روزی از هم می‌افتد: کسی شرطِ پنجمی به اسکوپ اضافه می‌کند و
     * این‌جا جا می‌افتد.
     *
     * پس به‌جای مقایسهٔ کد، **نتیجه** را می‌سنجیم: هر ردیفی که این دکمه باز
     * کرد باید همان لحظه `sellable()` باشد. اگر شرطی جا بیفتد، همین تست
     * می‌شکند — نه ماه‌ها بعد روی فروشگاه.
     */
    public function test_every_plan_that_bulk_open_reopened_is_actually_sellable(): void
    {
        $this->location();

        $ids = collect([
            $this->quarantined(),                              // سالم
            $this->quarantined(['price_irt' => 0]),             // بی‌قیمت
            $this->quarantined(['in_stock' => false]),          // ناموجود
            $this->quarantined(['is_active' => false]),         // غیرفعال
            $this->plan(['admin_disabled' => true, 'admin_note' => 'دستی']),
        ])->pluck('id');

        $this->bulk('/admin/cloud/plans/bulk-open', ['ids' => $ids->implode(',')]);

        $opened = CloudPlan::whereIn('id', $ids)->where('admin_disabled', false)->pluck('id');
        $this->assertNotEmpty($opened, 'حداقل ردیفِ سالم باید باز شده باشد');

        $sellable = CloudPlan::query()->sellable()->whereIn('id', $ids)->pluck('id');

        $this->assertSame(
            $opened->sort()->values()->all(),
            $sellable->sort()->values()->all(),
            'هر ردیفی که باز شد باید همان لحظه قابلِ فروش باشد — وگرنه کارتِ خراب روی فروشگاه است'
        );
    }

    // ═══════════════ ۲) اعتبارسنجیِ فهرستِ ارسالی ═══════════════

    /** ⚠️ به فهرستِ POST اعتماد نمی‌شود: شناسهٔ ناموجود کنار می‌رود و **شمرده** می‌شود */
    public function test_bulk_open_ignores_ids_that_are_not_real_plans_and_says_so(): void
    {
        $this->location();
        $p = $this->quarantined();

        $this->bulk('/admin/cloud/plans/bulk-open', ['ids' => $p->id.',999001,999002'])
            ->assertRedirect()->assertSessionHas('ok');

        $this->assertFalse((bool) $p->fresh()->admin_disabled);
        $this->assertStringContainsString('ناشناخته', (string) session('ok'),
            'اختلافِ «انتخاب کردم» و «انجام شد» نباید بی‌صدا بمانَد');
    }

    public function test_bulk_action_with_an_empty_selection_changes_nothing(): void
    {
        $this->location();
        $p = $this->quarantined();

        $this->bulk('/admin/cloud/plans/bulk-open', ['ids' => ''])
            ->assertRedirect()->assertSessionHas('err');
        $this->bulk('/admin/cloud/plans/bulk-close', ['ids' => '  ,  , 0'])
            ->assertRedirect()->assertSessionHas('err');

        $this->assertTrue((bool) $p->fresh()->admin_disabled);
    }

    /**
     * فهرستِ خراب نباید نه ۵۰۰ بدهد نه به SQL برسد.
     *
     * ⚠️ نکتهٔ عمدی: هر توکن با `(int)` خوانده می‌شود، پس `«۹۹۹۹۹۹؛drop table»`
     * فقط عددِ ۹۹۹۹۹۹ می‌شود و بقیه‌اش دور می‌ریزد — هیچ رشته‌ای به پرس‌وجو
     * نمی‌رسد. تستِ قبلی همین‌جا اشتباه بود: توکنی که با «۱» شروع می‌شد به
     * شناسهٔ **واقعیِ** ۱ تبدیل می‌شد و پلنِ درست را می‌بست.
     */
    public function test_a_garbage_id_list_does_not_explode(): void
    {
        $this->location();
        $p = $this->plan();

        $this->bulk('/admin/cloud/plans/bulk-close',
            ['ids' => 'abc,-4,999999;drop table cloud_plans'])->assertRedirect();

        // جدول سرِ جایش است و پلنِ واقعی دست نخورده
        $this->assertSame(1, CloudPlan::count());
        $this->assertFalse((bool) $p->fresh()->admin_disabled);
    }

    // ═══════════════ ۳) بستنِ گروهی ═══════════════

    public function test_bulk_close_closes_only_the_selected_rows_with_a_shared_note(): void
    {
        $this->location();
        $a = $this->plan();
        $b = $this->plan();
        $safe = $this->plan();

        $this->bulk('/admin/cloud/plans/bulk-close', ['ids' => $a->id.','.$b->id, 'note' => 'گران شد'])
            ->assertRedirect()->assertSessionHas('ok');

        $this->assertTrue((bool) $a->fresh()->admin_disabled);
        $this->assertSame('گران شد', $a->fresh()->admin_note);
        $this->assertTrue((bool) $b->fresh()->admin_disabled);

        $this->assertFalse((bool) $safe->fresh()->admin_disabled, 'ردیفِ انتخاب‌نشده دست نخورده');

        // و واقعاً از فروشگاه بیرون رفته‌اند، نه فقط یک ستون در دیتابیس
        $slugs = CloudPlan::offers()->keys();
        $this->assertFalse($slugs->contains($a->slug));
        $this->assertTrue($slugs->contains($safe->slug));
    }

    /**
     * 🔴 بستنِ گروهی روی `admin_disabled` می‌نویسد نه `is_active`.
     *
     * وگرنه همگام‌سازیِ دو روز یک‌بار تصمیم را بی‌صدا برمی‌گردانْد — همان
     * درسی که برای دکمهٔ تک‌ردیفی گرفته شده بود.
     */
    public function test_bulk_close_does_not_touch_is_active(): void
    {
        $this->location();
        $p = $this->plan();

        $this->bulk('/admin/cloud/plans/bulk-close', ['ids' => (string) $p->id]);

        $this->assertTrue((bool) $p->fresh()->is_active, 'ستونِ سینک نباید دست بخورد');
    }

    /**
     * 🔴 یادداشتِ دستی نباید بتواند خودش را «قرنطینهٔ خودکار» جا بزند.
     *
     * اگر می‌شد، مدیر با نوشتنِ همان پیشوند در کادرِ دلیل، محافظِ «تصمیمِ دستی
     * را باز نکن» را دور می‌زد — یعنی محافظی که با یک متنِ تایپ‌شده خاموش
     * می‌شود.
     */
    public function test_a_typed_note_cannot_impersonate_the_automatic_quarantine(): void
    {
        $this->location();
        $p = $this->plan();

        $this->bulk('/admin/cloud/plans/bulk-close', [
            'ids'  => (string) $p->id,
            'note' => CloudProvisioner::QUARANTINE_PREFIX.' من دستی بستم',
        ]);

        $note = (string) $p->fresh()->admin_note;
        $this->assertStringStartsNotWith(CloudProvisioner::QUARANTINE_PREFIX, $note);

        // و در نتیجه بازکردنِ گروهی هم بازش نمی‌کند
        $this->bulk('/admin/cloud/plans/bulk-open', ['ids' => (string) $p->id]);
        $this->assertTrue((bool) $p->fresh()->admin_disabled);
    }

    public function test_bulk_close_reports_rows_that_were_already_closed(): void
    {
        $this->location();
        $open = $this->plan();
        $shut = $this->plan(['admin_disabled' => true, 'admin_note' => 'قبلاً']);

        $this->bulk('/admin/cloud/plans/bulk-close', ['ids' => $open->id.','.$shut->id]);

        $this->assertStringContainsString('از قبل بسته بود', (string) session('ok'));
        $this->assertSame('قبلاً', $shut->fresh()->admin_note, 'یادداشتِ قبلی بازنویسی نشود');
    }

    // ═══════════════ ۴) اجازه ═══════════════

    /** نویسنده به هیچ اقدامِ گروهی دسترسی ندارد — و هیچ ردیفی عوض نمی‌شود */
    public function test_author_cannot_run_a_bulk_action(): void
    {
        $this->location();
        $p = $this->quarantined();

        $author = User::create([
            'name' => 'نویسنده', 'email' => 'w'.random_int(1, 999999).'@x.com',
            'password' => bcrypt('secret1234'), 'role' => 'author',
        ]);

        foreach (['bulk-open', 'bulk-close'] as $action) {
            $this->actingAs($author, 'web')
                ->post('/admin/cloud/plans/'.$action, ['ids' => (string) $p->id])
                ->assertForbidden();
        }

        $this->assertTrue((bool) $p->fresh()->admin_disabled);
    }

    public function test_a_guest_cannot_run_a_bulk_action(): void
    {
        $this->location();
        $p = $this->quarantined();

        // ⚠️ عمداً `bulk()` نیست: آن کمک‌تابع خودش وارد می‌شود
        $this->post('/admin/cloud/plans/bulk-open', ['ids' => (string) $p->id])
            ->assertRedirect();

        $this->assertTrue((bool) CloudPlan::find($p->id)->admin_disabled);
    }

    // ═══════════════ ۵) خودِ صفحه ═══════════════

    /**
     * انتخاب باید **در دامنهٔ فیلترِ جاری** باشد، نه کلِ کاتالوگ.
     *
     * ⚠️ منطقِ انتخاب در مرورگر است و از تستِ HTTP دیده نمی‌شود، پس این تست
     * دو چیزِ سنجیدنی را قفل می‌کند: (۱) هر ردیفِ **همین فیلتر** جعبهٔ انتخابِ
     * خودش را با شناسهٔ واقعی دارد و (۲) صفحه هرگز با ردیفِ ازپیش‌انتخاب‌شده
     * نمی‌آید — وگرنه یک کلیک روی «بستن» ردیف‌هایی را می‌بست که مدیر ندیده.
     */
    public function test_the_table_gives_every_filtered_row_a_checkbox_and_none_is_pre_selected(): void
    {
        $this->location();
        $this->location('sg-singapore', 'SG');
        $de = $this->plan();
        $sg = $this->plan(['location_code' => 'sg-singapore']);

        $html = $this->actingAs($this->admin(), 'web')
            ->get('/admin/cloud?country=DE')->assertOk()->getContent();

        // جعبهٔ انتخابِ سراسری و جعبهٔ ردیفِ همین فیلتر
        $this->assertStringContainsString('id="cl-all"', $html);
        $this->assertStringContainsString('class="ad-pick cl-pick" value="'.$de->id.'"', $html);

        // ردیفِ بیرونِ فیلتر اصلاً در DOM نیست، پس انتخاب‌شدنی هم نیست
        $this->assertStringNotContainsString('class="ad-pick cl-pick" value="'.$sg->id.'"', $html);

        /* و هیچ جعبه‌ای از پیش تیک‌خورده نیست.
           ⚠️ الگو به `<input …>` محدود است: بی‌این، همان جاوااسکریپتِ صفحه
           (`querySelector('.cl-pick')` و بعدش `c.checked`) الگو را می‌خورانْد و
           تست چیزی را می‌سنجید که فکر نمی‌کردیم. */
        $this->assertDoesNotMatchRegularExpression('~<input[^>]*cl-pick[^>]*\bchecked\b~', $html);
    }

    /** فیلترِ «قرنطینهٔ خودکار» دقیقاً همان ردیف‌هایی را می‌دهد که باز شدنی‌اند */
    public function test_the_quarantine_filter_shows_only_automatically_closed_rows(): void
    {
        $this->location();
        $auto = $this->quarantined();
        $byHand = $this->plan(['admin_disabled' => true, 'admin_note' => 'دستی']);
        $live = $this->plan();

        $html = $this->actingAs($this->admin(), 'web')
            ->get('/admin/cloud?state=quarantined')->assertOk()->getContent();

        preg_match_all('~data-plan="([^"]+)"~', $html, $m);
        $slugs = $m[1];

        $this->assertContains($auto->slug, $slugs);
        $this->assertNotContains($byHand->slug, $slugs, 'بستهٔ دستی قرنطینه نیست');
        $this->assertNotContains($live->slug, $slugs);
    }

    /**
     * نشانه‌های فیلترِ سمتِ مرورگر باید از **سرور** بیایند.
     *
     * اگر «در حالِ فروش» در جاوااسکریپت دوباره تعریف شود، روزی که شرطی به
     * `scopeSellable` اضافه شود، جدولِ مدیر و فروشگاه دو حرفِ متفاوت می‌زنند و
     * هیچ خطایی هم در کار نیست.
     */
    public function test_each_row_carries_the_servers_own_state_tokens(): void
    {
        $this->location();
        $on = $this->plan();
        $oos = $this->plan(['in_stock' => false]);

        $html = $this->actingAs($this->admin(), 'web')->get('/admin/cloud')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '~data-plan="'.preg_quote($on->slug, '~').'"[^>]*data-state="[^"]*\bon\b~', $html);
        $this->assertMatchesRegularExpression(
            '~data-plan="'.preg_quote($oos->slug, '~').'"[^>]*data-state="[^"]*\boos\b~', $html);
        // و ردیفِ ناموجود هرگز «on» نمی‌شود
        $this->assertDoesNotMatchRegularExpression(
            '~data-plan="'.preg_quote($oos->slug, '~').'"[^>]*data-state="[^"]*\bon\b~', $html);
    }

    /**
     * 🔴 بخشِ «بهایِ زیرساخت گران شده» باید یک `<details>`ِ **بسته** باشد.
     *
     * کارفرما: «اگر نیاز شد خودم بازش میکنم». ولی شمارش باید در خودِ summary
     * باشد، وگرنه تاکردن یعنی پنهان‌کردن: مدیر بی‌بازکردن نمی‌فهمد چیزی داخلش
     * هست یا نه، و مهم‌ترین هشدارِ ضررِ در جریانِ این صفحه بی‌صدا می‌شود.
     */
    public function test_the_cost_risen_section_is_a_closed_details_with_its_count(): void
    {
        $this->location();
        $this->plan([
            'cost_eur_cents' => 500, 'previous_cost_eur_cents' => 379,
            'cost_changed_at' => now(),
        ]);

        $html = $this->actingAs($this->admin(), 'web')->get('/admin/cloud')->assertOk()->getContent();

        $this->assertStringContainsString('بهایِ زیرساخت گران شده', $html, 'بخش باید باشد');

        // تگِ باز کنندهٔ همان بخش را جدا می‌کنیم و می‌سنجیم `open` ندارد
        $this->assertMatchesRegularExpression('~<details[^>]*>\s*<summary[^>]*>\s*🔴 بهایِ زیرساخت گران شده~u', $html);

        preg_match('~(<details[^>]*>)(?=\s*<summary[^>]*>\s*🔴 بهایِ زیرساخت)~u', $html, $tag);
        $this->assertNotEmpty($tag, 'بخش باید در یک details باشد');
        $this->assertStringNotContainsString('open', $tag[1], 'باید پیش‌فرض **بسته** باشد');

        // شمارش در summary — «یک‌نگاهی بفهم چیزی داخلش هست یا نه»
        $this->assertMatchesRegularExpression(
            '~🔴 بهایِ زیرساخت گران شده.{0,220}'.fa_num(1).'~su', $html);
    }

    /** نوارِ اقدام باید تا انتخاب‌نشدن پنهان باشد و تعداد را صریح بگوید */
    public function test_the_action_bar_is_hidden_until_something_is_selected(): void
    {
        $this->location();
        $this->plan();

        $html = $this->actingAs($this->admin(), 'web')->get('/admin/cloud')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('~id="cl-bulk"[^>]*\shidden~', $html,
            'نوار باید با صفتِ hidden رندر شود، نه با CSSِ حدسی');
        $this->assertStringContainsString('ردیف انتخاب شده', $html);
        $this->assertStringContainsString('/admin/cloud/plans/bulk-open', $html);
        $this->assertStringContainsString('/admin/cloud/plans/bulk-close', $html);
    }

    /**
     * ⚠️ سقفِ ۴۰۰ ردیف باید همان‌جا هم گفته شود که «انتخابِ همه» را محدود
     * می‌کند، وگرنه مدیر فکر می‌کند همهٔ ردیف‌های فیلتر را برداشته.
     *
     * چون خودِ سقف در تست ساختنِ ۴۰۱ ردیف می‌خواهد، این‌جا فقط ادعای سبک‌تر را
     * می‌سنجیم: پیامِ بریدگیِ سرور همچنان سرِ جایش است.
     */
    public function test_the_truncation_notice_is_not_lost(): void
    {
        $this->location();
        foreach (range(1, 3) as $i) {
            $this->plan();
        }

        $html = $this->actingAs($this->admin(), 'web')->get('/admin/cloud')->assertOk()->getContent();

        // شمارشِ سرور دست‌نخورده مانده (تستِ CloudPlanTableTest هم رویش است)
        $this->assertStringContainsString(fa_num(3).' پلن با این فیلتر', $html);
        // و شمارشِ بی‌درنگ یک عنصرِ **جدا** است، پس رشتهٔ بالا را نمی‌شکند
        $this->assertStringContainsString('id="cl-count-live"', $html);
    }

    /**
     * 🔴 پیامِ خطای پنلِ مدیریت باید **رندر** شود.
     *
     * لایوت فقط `session('ok')` را چاپ می‌کرد، پس گزارشِ «چیزی باز نشد و
     * علتش این بود» — و ده‌ها `with('err', …)`ِ دیگرِ همین پنل — بی‌صدا گم
     * می‌شدند و مدیر یک ریدایرکتِ بی‌پیام می‌دید.
     */
    public function test_an_error_flash_is_actually_rendered_in_the_admin_shell(): void
    {
        $this->location();
        $p = $this->quarantined(['price_irt' => 0]);
        $admin = $this->admin();

        $this->actingAs($admin, 'web')
            ->post('/admin/cloud/plans/bulk-open', ['ids' => (string) $p->id]);

        $html = $this->actingAs($admin, 'web')->get('/admin/cloud')->assertOk()->getContent();

        $this->assertStringContainsString('ad-flash err', $html);
        $this->assertStringContainsString('قیمتِ تومانی ندارد', $html,
            'علتِ ردشدن باید روی صفحه دیده شود، نه فقط در session');
    }
}

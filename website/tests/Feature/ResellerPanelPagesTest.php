<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerApiToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * صفحاتِ نمایندگی — رندرِ **واقعی**، نه فقط کدِ ۲۰۰.
 *
 * ⚠️ درسِ ثبت‌شدهٔ پروژه: «کدِ ۲۰۰ یعنی هیچ.» یک متغیرِ جاافتاده در Blade یا
 * یک کلیدِ زبانِ نبود، صفحه را با کدِ ۲۰۰ و ظاهرِ سالم برمی‌گردانَد در حالی که
 * محتوایش خالی یا خام است. پس این تست‌ها **مقدارِ واقعی** را می‌سنجند.
 */
class ResellerPanelPagesTest extends TestCase
{
    use RefreshDatabase;

    private function customer(bool $reseller): Customer
    {
        $c = Customer::create([
            'email'    => 'p'.random_int(1000, 99999).'@x.com',
            'phone'    => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);

        if ($reseller) {
            $c->forceFill(['is_reseller' => true, 'reseller_level' => 'silver'])->save();
        }

        return $c->refresh();
    }

    public function test_the_api_documentation_page_renders_with_real_content(): void
    {
        $res = $this->get('/developers');

        $res->assertOk();

        // کلیدهای واقعی، نه فقط «صفحه بالا آمد»
        $res->assertSee('Idempotency-Key', false);
        $res->assertSee('domains:write', false);
        $res->assertSee('price_floored', false);
        $res->assertSee('insufficient_credit', false);

        /*
        | 🔴 اعداد از config می‌آیند نه از متنِ تایپ‌شده. اگر روزی کسی سقف را
        | در config عوض کند و مستندات عددِ قدیمی را نشان دهد، نماینده‌ای که
        | بر اساسش کد نوشته، خرابی‌اش را ماه‌ها بعد کشف می‌کند.
        */
        config()->set('domain_reseller.limits.max_years', 7);

        $this->get('/developers')->assertSee(fa_num('7'), false);
    }

    /**
     * 🔴 هر سه زبان باید **متنِ ترجمه‌شده** بدهند، نه فارسی زیرِ پرچمِ دیگر.
     *
     * ═══ چرا این تست هست ═══
     *
     * نسخهٔ اولِ این صفحه فقط فارسی نوشته شده بود، با این استدلال که مخاطبش
     * نمایندهٔ ایرانی است. ولی روت داخلِ closureِ `$site` است، پس
     * `/en/developers` و `/tr/developers` از همان روزِ اول ساخته می‌شدند و
     * ۲۰۰ می‌دادند — با محتوای کاملاً فارسی. یعنی بازدیدکنندهٔ انگلیسی‌زبان
     * صفحه‌ای می‌دید که به‌نظر **خراب** می‌رسد، نه ترجمه‌نشده. و هیچ تستی
     * نمی‌گرفتش چون هر سه نسخه سالم ۲۰۰ می‌دادند.
     *
     * ⚠️ ادعا روی **رشتهٔ ترجمه‌شده** است نه روی وضعیتِ ۲۰۰ — همان قاعدهٔ
     * «کدِ ۲۰۰ یعنی هیچ» در CLAUDE.md §۸.
     */
    public function test_the_documentation_is_actually_translated_in_all_three_locales(): void
    {
        $c = require resource_path('content/developers.php');

        foreach (['fa' => '', 'en' => '/en', 'tr' => '/tr'] as $loc => $prefix) {
            $res = $this->get($prefix.'/developers');
            $res->assertOk();

            // عنوان و مقدمهٔ همان زبان، از فایلِ محتوا خوانده می‌شوند تا تست
            // با ویرایشِ متن نشکند ولی با **نبودِ ترجمه** بشکند
            $res->assertSee($c['title'][$loc], false);
            $res->assertSee($c['s5_warn_title'][$loc], false);

            // و متنِ زبانِ دیگری نباید نشت کند
            foreach (array_diff(['fa', 'en', 'tr'], [$loc]) as $other) {
                $res->assertDontSee($c['s5_warn_title'][$other], false);
            }
        }
    }

    /**
     * دسترسی‌ها و عملیاتِ پنل‌محور در **هر سه** زبان توضیح دارند.
     *
     * ⚠️ فهرستشان از `CustomerApiToken::ABILITIES` و `config()` می‌آید (که
     * فارسی‌اند) و ترجمه در `content/developers.php` می‌نشیند. اگر روزی
     * دسترسیِ تازه‌ای اضافه شود و ترجمه‌اش جا بیفتد، صفحهٔ انگلیسی یک ردیفِ
     * فارسی نشان می‌دهد — بی‌هیچ خطایی. این تست همان را می‌گیرد.
     */
    public function test_every_scope_and_panel_only_operation_is_translated(): void
    {
        $c = require resource_path('content/developers.php');

        $expected = [
            's2_scope_desc' => array_keys(\App\Models\CustomerApiToken::ABILITIES),
            's9_desc'       => array_keys((array) config('domain_reseller.panel_only_operations')),
        ];

        $missing = [];

        foreach ($expected as $key => $keys) {
            foreach (['en', 'tr'] as $loc) {          // fa از خودِ منبع می‌آید
                foreach (array_diff($keys, array_keys($c[$key][$loc] ?? [])) as $k) {
                    $missing[] = $key.'.'.$loc.'.'.$k;
                }
            }
        }

        $this->assertSame([], $missing,
            "\nترجمه‌ی جاافتاده — صفحهٔ en/tr این ردیف‌ها را فارسی نشان می‌دهد:\n  "
            .implode("\n  ", $missing));
    }

    /**
     * 🔴 جدولِ خطاها باید **هر** شناسه‌ای را که API می‌تواند برگرداند پوشش دهد.
     *
     * ═══ چرا ═══
     *
     * نسخهٔ اولِ این صفحه ۱۸ شناسه را مستند کرده بود در حالی که کد ۲۸ تا
     * برمی‌گرداند. شناسهٔ مستندنشده بدترین نوعِ خطاست: سرویس‌گیرنده روی
     * `error` شرط می‌گذارد (چون خودِ مستندات همین را می‌گوید)، به شاخهٔ
     * ناشناخته می‌رسد، و آن‌جا یا می‌ترکد یا خطا را «موفق» می‌خوانَد. و چون
     * فقط در مسیرهای کم‌تکرار رخ می‌دهد، ماه‌ها دیده نمی‌شود.
     *
     * ⚠️ استخراج **از خودِ کد** است نه از یک فهرستِ دستی. فهرستِ دستی همان
     * چیزی است که از اول کهنه شد.
     */
    public function test_the_error_table_documents_every_identifier_the_api_can_emit(): void
    {
        $sources = [
            app_path('Http/Controllers/Api/DomainApiController.php'),
            app_path('Http/Middleware/CustomerApiToken.php'),
            app_path('Services/Domain/Reseller/ResellerOrderService.php'),
        ];

        $emitted = [];

        foreach ($sources as $file) {
            $src = file_get_contents($file);
            // کامنت‌ها حذف شوند، وگرنه نامِ شناسه‌ای که فقط در توضیح آمده هم شمرده می‌شود
            $src = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $src);

            /*
            | سه شکلِ صدورِ خطا در این کد، و هر سه لازم‌اند:
            |   ۱ فراخوانیِ کمکی        ->err('x' / ->deny('x' / ->fail('x'
            |   ۲ آرایهٔ خام             'error' => 'x'   (مسیرِ کسرِ اتمی)
            |   ۳ سه‌گانه داخلِ fail()   fail($a ? 'x' : 'y'
            |
            | ⚠️ نسخهٔ اولِ همین تست فقط شکلِ ۱ را می‌دید و چهار شناسه را
            | «مستندِ بی‌مصرف» گزارش کرد — یعنی استخراج‌گرِ ناقص، مستنداتِ درست
            | را متهم کرد. اگر آن‌وقت به تست اعتماد می‌کردم، ردیف‌های درست را
            | از مستندات حذف می‌کردم.
            */
            preg_match_all("~(?:->err|->deny|->fail)\(\s*'([a-z_]+)'~", $src, $m);
            $emitted = array_merge($emitted, $m[1]);

            preg_match_all("~'error'\s*=>\s*'([a-z_]+)'~", $src, $m2);
            $emitted = array_merge($emitted, $m2[1]);

            preg_match_all("~->fail\(\s*[^;]*?\?\s*'([a-z_]+)'\s*:\s*'([a-z_]+)'~s", $src, $m3);
            $emitted = array_merge($emitted, $m3[1], $m3[2]);

            preg_match_all("~'(token_expired|token_revoked)'\s*=>~", $src, $m4);
            $emitted = array_merge($emitted, $m4[1]);
        }

        $emitted = array_values(array_unique($emitted));
        sort($emitted);

        $c = require resource_path('content/developers.php');

        $undocumented = [];

        foreach ($emitted as $code) {
            foreach (['fa', 'en', 'tr'] as $loc) {
                if (! array_key_exists($code, $c['s6_rows'][$loc] ?? [])) {
                    $undocumented[] = $code.' ('.$loc.')';
                }
            }
        }

        $this->assertSame([], $undocumented,
            "\nشناسهٔ خطایی که API برمی‌گرداند ولی مستندات ندارد. سرویس‌گیرنده\n"
            ."روی شاخهٔ ناشناخته می‌افتد:\n  ".implode("\n  ", $undocumented));

        // و برعکس: ردیفِ مستندشده‌ای که هیچ کدی تولیدش نمی‌کند هم بدهی است،
        // چون یکپارچه‌سازی برایش شاخهٔ مرده می‌نویسد.
        $stale = array_values(array_diff(array_keys($c['s6_rows']['fa']), $emitted));

        $this->assertSame([], $stale,
            "\nردیفِ مستندشده‌ای که هیچ‌جای کد تولید نمی‌شود:\n  ".implode("\n  ", $stale));
    }

    /**
     * نسخهٔ چاپی/PDF واقعاً وجود دارد و همان محتوا را می‌دهد.
     *
     * ⚠️ پروژه هیچ کتابخانهٔ PDF ندارد و دیپلویِ پروداکشن فایل‌به‌فایل و بی‌SSH
     * است، پس افزودنِ وابستگیِ composer عملی نیست. الگوی جاافتادهٔ خودِ پروژه
     * (`account/invoice-print`) همین است: HTMLِ بهینه‌شده برای چاپ + «ذخیره به
     * PDF»ِ خودِ مرورگر.
     */
    public function test_the_print_view_serves_the_same_documentation(): void
    {
        $res = $this->get('/developers?print=1');

        $res->assertOk();
        $res->assertSee('Idempotency-Key', false);
        // چاپ باید خودکار باز شود، وگرنه دکمه فقط یک صفحهٔ دیگر است
        $res->assertSee('window.print()', false);
    }

    /** صفحهٔ نمایندگی برای مشتریِ عادی هم باز است و حالتِ معرفی نشان می‌دهد */
    public function test_a_non_reseller_sees_the_intro_state_not_a_404(): void
    {
        $c = $this->customer(reseller: false);

        $res = $this->actingAs($c, 'customer')->get('/account/reseller');

        $res->assertOk();
        $res->assertSee('هنوز فعال نشده');
        // نردبان سطح‌ها باید دیده شود، وگرنه صفحه چیزی برای فروختن ندارد
        $res->assertSee('تخفیف');
    }

    public function test_a_reseller_sees_their_level_and_the_module_download(): void
    {
        $c = $this->customer(reseller: true);

        $res = $this->actingAs($c, 'customer')->get('/account/reseller');

        $res->assertOk();
        $res->assertSee('نقره‌ای');
        $res->assertSee('/account/reseller/module/whmcs', false);
        // هشدارِ «هیچ توکنی نداری» باید باشد — بی‌آن نماینده افزونه را نصب
        // می‌کند و نمی‌فهمد چرا کار نمی‌کند
        $res->assertSee('هنوز هیچ توکن فعالی ندارید');
    }

    public function test_the_whmcs_module_downloads_as_a_zip_with_the_right_paths(): void
    {
        $c = $this->customer(reseller: true);

        $res = $this->actingAs($c, 'customer')->get('/account/reseller/module/whmcs');

        $res->assertOk();

        $tmp = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($tmp, $res->streamedContent() ?: file_get_contents(
            storage_path('app/servernet-whmcs-'.config('domain_reseller.whmcs.version').'.zip')
        ));

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($tmp) === true, 'فایل دانلودشده zip معتبر نیست');

        /*
        | ⚠️ مسیرِ داخلِ zip باید دقیقاً همان چیزی باشد که WHMCS لازم دارد.
        | اگر فایل‌ها در ریشه باشند، نماینده باید دستی پوشه بسازد — و اولین
        | کسی که اشتباه بسازد، ماژولِ «نصب‌شده‌ای» دارد که WHMCS نمی‌بیندش.
        */
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();
        @unlink($tmp);

        $this->assertContains('servernet/servernet.php', $names);
        $this->assertContains('servernet/lib/ServerNetApi.php', $names);
    }

    /**
     * افزونهٔ وردپرس هم با همان مسیرِ درستِ داخلِ zip بیرون می‌آید.
     *
     * ⚠️ نامِ پوشهٔ داخلِ zip دلخواه نیست: وردپرس افزونه را در
     * `wp-content/plugins/servernet-domains/` می‌خواهد. اگر فایل‌ها در
     * ریشه باشند، نماینده پوشه را دستی می‌سازد و اولین کسی که اشتباه
     * بسازد، افزونهٔ «نصب‌شده‌ای» دارد که وردپرس اصلاً نمی‌بیندش.
     */
    public function test_the_wordpress_plugin_downloads_with_the_right_folder(): void
    {
        $c = $this->customer(reseller: true);

        $res = $this->actingAs($c, 'customer')->get('/account/reseller/module/wordpress');
        $res->assertOk();

        $path = storage_path('app/servernet-wordpress-'.config('domain_reseller.wordpress.version').'.zip');
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true);

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) { $names[] = $zip->getNameIndex($i); }
        $zip->close();

        $this->assertContains('servernet-domains/servernet-domains.php', $names);
        $this->assertContains('servernet-domains/includes/class-servernet-woo.php', $names);
        $this->assertContains('servernet-domains/readme.txt', $names);

        // پسوندِ ناشناخته نباید چیزی بدهد
        $this->actingAs($c, 'customer')->get('/account/reseller/module/hacker')->assertNotFound();
    }

    /** آیتمِ منو فقط برای نماینده — و برای بقیه اصلاً نباشد */
    public function test_the_nav_item_appears_only_for_resellers(): void
    {
        $plain = $this->actingAs($this->customer(false), 'customer')->get('/account')->getContent();
        $this->assertStringNotContainsString('/account/reseller', $plain);

        $res = $this->actingAs($this->customer(true), 'customer')->get('/account')->getContent();
        $this->assertStringContainsString('/account/reseller', $res);
    }

    /** توکنِ باطل‌شده از فهرست می‌رود ولی ردیفش برای حسابرسی می‌مانَد */
    public function test_revoking_a_token_hides_it_but_keeps_the_audit_row(): void
    {
        $c = $this->customer(true);
        [$token] = CustomerApiToken::issue($c->id, 'whmcs', ['domains:write']);

        $this->actingAs($c, 'customer')
            ->post('/account/security/api-token/'.$token->id.'/delete')
            ->assertRedirect();

        $this->assertDatabaseHas('customer_api_tokens', ['id' => $token->id]);
        $this->assertNotNull($token->refresh()->revoked_at);
        $this->assertSame(0, $c->apiTokens()->usable()->count());
    }
    /**
     * 🔴 یک ۴۰۴ بعد از یک روتِ پارامتردار باید ۴۰۴ بماند، نه ۵۰۰.
     *
     * `Route::currentRouteName()` روی ۴۰۴ خالی نمی‌شود و نامِ آخرین روتِ
     * تطبیق‌یافتهٔ همان پروسه در آن می‌مانَد. سوییچرِ زبان در
     * `AppServiceProvider` با همان نام و **بی‌پارامتر** لینک می‌سازد و
     * `UrlGenerationException` می‌دهد — یعنی صفحهٔ ۴۰۴ می‌ترکد و کاربر ۵۰۰
     * می‌گیرد.
     *
     * ⚠️ زیرِ php-fpm پنهان است (هر درخواست پروسهٔ تازه) و فقط در تست، ورکرِ
     * صف و Octane دیده می‌شود. پس این تست تنها چیزی است که نگهش می‌دارد.
     */
    public function test_a_404_after_a_parameterised_route_stays_a_404(): void
    {
        $c = $this->customer(reseller: true);

        // اول یک روتِ پارامتردار که واقعاً تطبیق می‌کند
        $this->actingAs($c, 'customer')->get('/account/reseller/module/whmcs')->assertOk();

        // و بعد چیزی که هیچ روتی ندارد — باید ۴۰۴ بدهد نه ۵۰۰
        $this->actingAs($c, 'customer')->get('/account/reseller/module/hacker')->assertNotFound();
        $this->actingAs($c, 'customer')->get('/in-ja-hich-vaght-nist')->assertNotFound();
    }
}
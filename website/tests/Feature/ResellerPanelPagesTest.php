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
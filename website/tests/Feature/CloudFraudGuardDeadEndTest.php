<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use App\Services\Cloud\CloudFraudGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 🔴 بن‌بستِ صفِ بازبینی — اندازه‌گیری‌شده روی پروداکشن، این‌جا بازتولید می‌شود.
 *
 * محافظ سفارش را در `provision_status='manual'` نگه می‌دارد، و **تنها درِ خروج**
 * (دکمهٔ «تلاشِ دوباره»ی مدیر) دوباره از خودِ همان محافظ رد می‌شود. یعنی ردیفی که
 * یک بار پارک شد، تا ابد پارک می‌مانَد.
 *
 * و بدتر: شمارندهٔ سقفِ روزانه هیچ فیلترِ وضعیتی ندارد، پس **خودِ ردیف‌های
 * پارک‌شده** عددِ سقف را می‌سازند. صف، درِ خودش را قفل نگه می‌دارد.
 */
class CloudFraudGuardDeadEndTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'مدیر', 'email' => 'a'.random_int(1, 99999).'@x.com',
            'password' => bcrypt('secret1234'), 'role' => 'admin',
        ]);
    }

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'c'.random_int(1, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ])->fresh();
    }

    private function parkedService(Customer $c): Service
    {
        return Service::create([
            'customer_id' => $c->id, 'name' => 'سرور مجازی', 'currency_code' => 'IRT',
            'price' => 900000, 'tax_percent' => 0, 'cycle' => 'monthly',
            'status' => 'awaiting_provision', 'provision_status' => 'manual',
            'cloud_plan_id' => 1,
        ]);
    }

    /**
     * «تلاشِ دوباره»ی ساده هنوز — و **عمداً** — دوباره پارک می‌کند.
     *
     * ⚠️ این تست پس از افزودنِ رهاسازی هم باید سبز بمانَد: تلاشِ دوباره یعنی
     * «شاید علت رفع شده باشد»، نه «محافظ را کنار بگذار». اگر روزی این تست
     * قرمز شد یعنی کسی محافظ را از مسیرِ عادیِ تلاشِ دوباره برداشته و همان
     * دورزنیِ خاموشی ساخته که `CloudGuardOverrideTest` نبودش را قفل می‌کند.
     */
    public function test_the_admin_retry_puts_a_parked_order_straight_back_into_the_queue(): void
    {
        $c = $this->customer();

        $parked = collect(range(1, 5))->map(fn () => $this->parkedService($c));

        // محافظ همین حالا هم نگه می‌دارد — و علتش خودِ همین پنج ردیف است.
        $this->assertTrue(app(CloudFraudGuard::class)->check($c->fresh())['hold']);

        foreach ($parked as $s) {
            $this->actingAs($this->admin(), 'web')
                ->post('/admin/services/'.$s->id.'/provision');

            $this->assertSame('manual', $s->fresh()->provision_status,
                'سرویس #'.$s->id.' باید بعد از تلاشِ دوباره هنوز در صفِ دستی باشد');
        }

        // و هیچ تماسی با زیرساخت نرفته: بن‌بست است، نه خریدِ ناموفق.
        Http::assertNothingSent();
    }

    /**
     * 🔴 شمارنده خودارجاع است: ردیف‌هایی که محافظ **خودش** پارک کرده،
     * در شمارشِ سقفِ روزانه می‌آیند و سقف را برای همیشه بسته نگه می‌دارند.
     *
     * هیچ فیلترِ وضعیتی روی `$today` نیست — نه `status`, نه `provision_status`.
     */
    public function test_the_daily_counter_counts_orders_that_never_bought_a_server(): void
    {
        $c = $this->customer();

        // پنج سفارش که هیچ‌کدام سرور نخریده‌اند: یکی لغوشده، بقیه پارک‌شده.
        $this->parkedService($c)->forceFill(['status' => 'cancelled'])->save();
        foreach (range(1, 4) as $i) {
            $this->parkedService($c);
        }

        $v = app(CloudFraudGuard::class)->check($c->fresh());

        $this->assertTrue($v['hold'],
            'سقفِ روزانه سفارشِ بی‌سرور — حتی لغوشده — را هم می‌شمارد');
        $this->assertStringContainsString('۲۴ ساعت', (string) $v['reason']);
    }

    /**
     * 🔴 لایهٔ دومِ بن‌بست: حتی وقتی محافظ **دیگر نگه نمی‌دارد** هم ردیف
     * بیرون نمی‌آید.
     *
     * پنجرهٔ سقفِ روزانه غلتان است، پس بعد از ۲۴ ساعت سفارشِ پارک‌شده از شمارش
     * می‌افتد و `check()` اجازه می‌دهد. ولی `provision:run` فقط `pending` (و
     * `running`ِ کهنه) را برمی‌دارد — `manual` را هرگز. یعنی ردیف تا ابد
     * می‌مانَد، **بی‌آنکه دیگر هیچ قاعده‌ای نگهش داشته باشد**.
     */
    public function test_a_parked_order_is_never_picked_up_again_even_after_the_window_passes(): void
    {
        $c = $this->customer();
        $s = $this->parkedService($c);
        $s->forceFill(['created_at' => now()->subDays(3)])->save();

        // محافظ دیگر نگه نمی‌دارد — علت رفع شده است.
        $this->assertFalse(app(CloudFraudGuard::class)->check($c->fresh())['hold'],
            'بعد از پنجرهٔ ۲۴ ساعته، محافظ دیگر نباید نگه دارد');

        \Illuminate\Support\Facades\Artisan::call('provision:run');

        $this->assertSame('manual', $s->fresh()->provision_status,
            'کرون هرگز manual را برنمی‌دارد؛ رفعِ علت به‌تنهایی ردیف را آزاد نمی‌کند');
        Http::assertNothingSent();
    }

    /**
     * ✅ لایهٔ سومِ بن‌بست — **وارونه شد.**
     *
     * تا امروز تنها افورد‌نسِ تحویل در پنل پشتِ `@if($s->server_id || $s->domain)`
     * بود و سرورِ ابری هیچ‌کدام را ندارد، پس مدیر هیچ دکمه‌ای نمی‌دید و باید روت
     * را دستی POST می‌کرد. حالا گیت `CloudProvisioner::handles()` را هم می‌پذیرد
     * و سفارشِ پارک‌شده **دو** دکمه دارد: تلاشِ دوباره، و رهاسازیِ صریحِ محافظ.
     *
     * ⚠️ ادعای «هیچ ورودیِ WHM رندر نمی‌شود» هم این‌جاست: بی‌آن، مدیر مجبور
     * می‌شد یک سرورِ WHM و نامِ پکیج روی ردیفِ ابری بگذارد تا فرم اصلاً بفرستد.
     */
    public function test_the_admin_panel_now_offers_both_retry_and_override_for_a_parked_cloud_service(): void
    {
        $c = $this->customer();
        $s = $this->parkedService($c);

        $html = $this->actingAs($this->admin(), 'web')
            ->get('/admin/customers/'.$c->id)
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('/admin/services/'.$s->id.'/provision"', $html,
            'فرمِ تلاشِ دوباره باید برای سرویسِ ابری رندر شود');
        $this->assertStringContainsString('/admin/services/'.$s->id.'/provision-override', $html,
            'دکمهٔ رهاسازیِ محافظ باید روی سفارشِ پارک‌شده دیده شود');

        /* ⚠️ فقط **داخلِ همان فرم** سنجیده می‌شود، نه کلِ صفحه: فرمِ «فروشِ
           سرویسِ تازه» پایینِ همین صفحه هم `server_id` دارد و جستجوی سراسری
           بی‌آنکه چیزی ثابت کند قرمز می‌شد. */
        $this->assertTrue((bool) preg_match(
            '~<form[^>]+action="/admin/services/'.$s->id.'/provision"(.*?)</form>~s', $html, $m
        ), 'فرمِ تحویل پیدا نشد');

        $this->assertStringNotContainsString('name="server_id"', $m[1],
            'ردیفِ ابری نباید انتخابگرِ سرورِ WHM بگیرد — آن ستون روی سرویسِ ابری معنا ندارد');
        $this->assertStringNotContainsString('name="plan"', $m[1]);
    }

    /**
     * ⚠️ محدودهٔ شمارش **per-customer** است، نه سراسری.
     *
     * این مهم است چون گزارشِ اولیه می‌گفت «هر سفارشِ تازه از هر مشتری‌ای پارک
     * می‌شود». اگر این تست روزی بشکند یعنی کسی محدوده را سراسری کرده و یک
     * حسابِ پرمصرف می‌تواند فروشِ کلِ سایت را بخواباند.
     */
    public function test_one_customers_burst_does_not_hold_another_customers_order(): void
    {
        $noisy = $this->customer();
        foreach (range(1, 6) as $i) {
            $this->parkedService($noisy);
        }

        $this->assertTrue(app(CloudFraudGuard::class)->check($noisy->fresh())['hold']);
        $this->assertFalse(app(CloudFraudGuard::class)->check($this->customer())['hold'],
            'سقف باید per-customer باشد؛ سراسری‌بودنش یعنی یک حساب کلِ فروش را می‌بندد');
    }
}

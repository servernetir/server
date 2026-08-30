<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Domain\DomainSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * درصد سود دامنه و پیشنهادِ پسوندها.
 *
 * 🔴 چرا این تست‌ها نوشته شدند: کارفرما یک `.com` جستجو کرد و ۲٬۵۲۰٬۰۰۰ تومان
 * دید، در حالی که بهای تمام‌شده ~۲٬۰۱۶٬۰۰۰ بود. تفاوت دقیقاً ۲۵٪ حاشیهٔ سودی
 * بود که در `.env` پیش‌فرض نشسته بود — جایی که مدیر نه می‌دیدش نه می‌توانست
 * عوضش کند.
 */
class DomainMarginAndSuggestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.openprovider.username', 'u');
        config()->set('services.openprovider.password', 'p');
        Setting::put('pricing_rate_override', '200000');   // هر یورو ۲۰۰٬۰۰۰ تومان
    }

    /** یک استعلامِ موفق با قیمتِ مشخص */
    private function fake(float $create, ?float $renew = null, ?float $transfer = null): void
    {
        $price = ['reseller' => ['price' => $create, 'currency' => 'EUR']];

        if ($renew !== null) {
            $price['reseller']['renewal'] = ['price' => $renew, 'currency' => 'EUR'];
        }
        if ($transfer !== null) {
            $price['reseller']['transfer'] = ['price' => $transfer, 'currency' => 'EUR'];
        }

        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([
            '*/auth/login*'    => Http::response(['code' => 0, 'data' => ['token' => 't']]),
            '*/domains/check*' => Http::response(['code' => 0, 'data' => ['results' => [[
                'domain' => 'example.com', 'status' => 'free', 'price' => $price,
            ]]]]),
        ]);
    }

    private function first(): array
    {
        return app(DomainSearch::class)->search('example.com', ['com'])[0];
    }

    // ═══════════════ حاشیهٔ سود ═══════════════

    /** 🔴 پیش‌فرض باید **صفر** باشد — نه ۲۵٪ نامرئی */
    public function test_the_default_margin_is_zero(): void
    {
        $this->fake(10.0);

        // ۱۰ یورو × ۲۰۰٬۰۰۰ = ۲٬۰۰۰٬۰۰۰ و هیچ اضافه‌ای
        $this->assertSame(2000000, $this->first()['price_toman']);
    }

    /** مدیر باید بتواند از تنظیمات عوضش کند */
    public function test_the_admin_setting_drives_the_margin(): void
    {
        Setting::put('domain_margin_pct', '25');
        $this->fake(10.0);

        $this->assertSame(2500000, $this->first()['price_toman']);
    }

    /** و تنظیماتِ مدیر بر config مقدم است */
    public function test_the_admin_setting_beats_the_config_default(): void
    {
        config()->set('services.openprovider.margin', ['default' => 50]);
        Setting::put('domain_margin_pct', '10');
        $this->fake(10.0);

        $this->assertSame(2200000, $this->first()['price_toman']);
    }

    /** صفرِ صریح باید صفر بماند، نه اینکه به پیش‌فرض برگردد */
    public function test_an_explicit_zero_stays_zero(): void
    {
        config()->set('services.openprovider.margin', ['default' => 40]);
        Setting::put('domain_margin_pct', '0');
        $this->fake(10.0);

        $this->assertSame(2000000, $this->first()['price_toman']);
    }

    // ═══════════════ ثبت، تمدید، انتقال ═══════════════

    /** 🔴 هر سه قیمت باید در لحظه استعلام شوند و حاشیه بخورند */
    public function test_register_renew_and_transfer_are_all_quoted_with_margin(): void
    {
        Setting::put('domain_margin_pct', '10');
        $this->fake(create: 10.0, renew: 12.0, transfer: 11.0);

        $r = $this->first();

        $this->assertSame(2200000, $r['price_toman']);      // ۱۰ × ۲۰۰k × ۱٫۱
        $this->assertSame(2640000, $r['renew_toman']);      // ۱۲ × ۲۰۰k × ۱٫۱
        $this->assertSame(2420000, $r['transfer_toman']);   // ۱۱ × ۲۰۰k × ۱٫۱
    }

    /** اگر رسیلری قیمتِ انتقال ندهد، قیمتِ ثبت جایگزین می‌شود */
    public function test_a_missing_transfer_price_falls_back_to_the_register_price(): void
    {
        $this->fake(10.0);

        $r = $this->first();

        $this->assertSame($r['price_toman'], $r['transfer_toman']);
    }

    // ═══════════════ پیشنهادِ پسوند ═══════════════

    public function test_at_least_fifty_tlds_are_suggested(): void
    {
        $rc = new \ReflectionClass(DomainSearch::class);
        $tlds = $rc->getConstant('SUGGEST_TLDS');

        $this->assertGreaterThanOrEqual(50, count($tlds));
        $this->assertSame(count($tlds), count(array_unique($tlds)), 'پسوندِ تکراری');
    }

    /** 🔴 فهرست نباید بی‌صدا بریده شود */
    public function test_the_suggestion_list_is_never_silently_truncated(): void
    {
        $rc = new \ReflectionClass(DomainSearch::class);

        $this->assertGreaterThan(
            count($rc->getConstant('SUGGEST_TLDS')),
            $rc->getConstant('MAX_TLDS'),
            'سقف باید از فهرست بزرگ‌تر باشد وگرنه پسوندها بی‌خبر حذف می‌شوند'
        );
    }

    /**
     * ⚠️ `.ir` نباید پیشنهاد شود: از رسیلرِ اروپایی ده‌ها برابرِ قیمتِ مستقیمِ
     * ایرنیک درمی‌آید و نشان‌دادنش با آن قیمت به کلِ صفحه می‌گوید قیمت‌های
     * این‌جا بی‌ربط است.
     */
    public function test_dot_ir_is_not_suggested_until_the_irnic_path_exists(): void
    {
        $tlds = (new \ReflectionClass(DomainSearch::class))->getConstant('SUGGEST_TLDS');

        $this->assertNotContains('ir', $tlds);
    }

    /** ولی اگر کاربر خودش `.ir` تایپ کند، باید استعلام شود */
    public function test_a_user_typed_tld_is_always_checked_first(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([
            '*/auth/login*'    => Http::response(['code' => 0, 'data' => ['token' => 't']]),
            '*/domains/check*' => Http::response(['code' => 0, 'data' => ['results' => []]]),
        ]);

        app(DomainSearch::class)->search('example.ir');

        Http::assertSent(function ($r) {
            $names = collect($r->data()['domains'] ?? [])->pluck('extension')->all();

            return ($names[0] ?? null) === 'ir';
        });
    }
}

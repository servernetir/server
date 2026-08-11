<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\Domain;
use App\Models\DomainQuote;
use App\Models\Invoice;
use App\Services\Domain\DomainRegistrar;
use App\Services\Domain\TldGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * «پسوندی که می‌دانیم ثبت نمی‌شود، دوباره فروخته نشود.»
 *
 * ═══ چرا ═══
 *
 * قراردادِ رجیستریِ امضانشده یک شکستِ **ساختاری** است. بی‌دروازه، زنجیره این بود:
 *
 *   مشتریِ اول → پول داد → ثبت نشد → پارک → لغو و بازگشتِ وجه
 *   مشتریِ دوم → **دقیقاً همان مسیر**
 *
 * یعنی سامانه چیزی را که از قبل می‌دانست شکست می‌خورد دوباره می‌فروخت. کسی
 * ضررِ نقدی نمی‌کرد (پول برمی‌گشت) ولی هر بار یک مشتری تجربهٔ «پول دادم، چیزی
 * نگرفتم» می‌گرفت — و آن از نفروختن بدتر است.
 *
 * ⚠️ نیمهٔ دومِ هر محافظِ این‌شکلی مهم‌تر از نیمهٔ اول است: **زیادی نبندد.**
 * یک قطعیِ گذرا نباید فروشِ `.com` را بخواباند. تست‌های پایین هر دو جهت را
 * می‌سنجند.
 */
class DomainTldGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.openprovider.username', 'test@example.com');
        config()->set('services.openprovider.password', 'secret');
        config()->set('services.openprovider.nameservers', ['ns1.servernet.cloud', 'ns2.servernet.cloud']);
    }

    private function fake(int $code, string $desc = ''): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([
            '*/auth/login*' => Http::response(['code' => 0, 'data' => ['token' => 't']]),
            '*/customers*'  => Http::response(['code' => 0, 'data' => ['handle' => 'AB123-NL']]),
            '*/domains*'    => Http::response(['code' => $code, 'desc' => $desc], $code === 0 ? 200 : 500),
        ]);
    }

    private function customer(): Customer
    {
        $c = Customer::create([
            'email' => 'g'.random_int(1000, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);

        CustomerProfile::create([
            'customer_id' => $c->id, 'type' => 'individual', 'is_default' => true,
            'status' => 'verified', 'email' => $c->email, 'mobile' => '09123456789',
            'country' => 'IR', 'province' => 'تهران', 'city' => 'تهران',
            'address' => 'خیابان نمونه، کوچهٔ آزمایش', 'postal_code' => '1234567890',
            'first_name' => 'احسان', 'last_name' => 'ابراهیمی',
        ]);

        return $c;
    }

    private function domain(Customer $c, string $fqdn = 'partolastik.com'): Domain
    {
        [$sld, $tld] = Domain::splitFqdn($fqdn);

        return Domain::create([
            'customer_id' => $c->id, 'domain' => $fqdn, 'sld' => $sld, 'tld' => $tld,
            'registrar' => 'openprovider', 'status' => 'pending', 'provision_status' => 'pending',
            'period_years' => 1, 'price_toman' => 2500000,
        ]);
    }

    // ═══════════════ ۱) بسته می‌شود ═══════════════

    public function test_a_contract_failure_closes_the_whole_tld_not_just_that_domain(): void
    {
        $c = $this->customer();
        $this->fake(309, 'You have not signed the last version of the contract');

        app(DomainRegistrar::class)->register($this->domain($c));

        $this->assertTrue(TldGate::isBlocked('com'),
            'پسوند باز مانده — مشتریِ بعدی همان مسیرِ شکست‌خورده را می‌رود');
        $this->assertStringContainsString('قرارداد', (string) TldGate::reasonFor('com'));
    }

    /** 🔴 نیمهٔ دوم: شکستِ **معمولی** نباید کلِ پسوند را بخواباند */
    public function test_an_ordinary_failure_never_closes_the_tld(): void
    {
        $c = $this->customer();
        $this->fake(307, 'Domain is already registered by someone else');

        app(DomainRegistrar::class)->register($this->domain($c));

        $this->assertFalse(TldGate::isBlocked('com'),
            'یک شکستِ گذرا فروشِ کلِ پسوند را خواباند — این از خودِ باگ بدتر است');
    }

    /** بستنِ یک پسوند نباید به پسوندهای دیگر سرایت کند */
    public function test_blocking_one_tld_leaves_the_others_open(): void
    {
        TldGate::block('shop', 'قراردادِ رجیستری امضا نشده است.');

        $this->assertTrue(TldGate::isBlocked('shop'));
        $this->assertFalse(TldGate::isBlocked('com'));
        $this->assertFalse(TldGate::isBlocked('ir'));
    }

    /** «.COM» و «com» یک چیزند — وگرنه دروازه با یک نقطه دور زده می‌شود */
    public function test_the_tld_is_matched_regardless_of_dot_or_case(): void
    {
        TldGate::block('.COM', 'x');

        foreach (['com', '.com', 'COM', ' .Com '] as $v) {
            $this->assertTrue(TldGate::isBlocked($v), "«{$v}» تطبیق نخورد");
        }
    }

    // ═══════════════ ۲) پول گرفته نمی‌شود ═══════════════

    /**
     * 🔴 هستهٔ ادعا: پسوندِ بسته **پیش از گرفتنِ پول** رد می‌شود.
     *
     * ⚠️ ادعا روی نبودِ `Invoice` و `Domain` است، نه فقط روی پیامِ خطا: پیام
     * می‌تواند درست باشد در حالی که ردیفِ فاکتور از قبل ساخته شده — و همان
     * ردیف است که بعداً به مشتری فرستاده می‌شود.
     */
    public function test_a_blocked_tld_is_refused_before_any_money_is_taken(): void
    {
        $c = $this->customer();

        $quote = DomainQuote::create([
            'domain' => 'partolastik.com', 'tld' => 'com', 'registrar' => 'openprovider',
            'is_premium' => false, 'cost_amount' => 950, 'cost_currency' => 'EUR',
            'sell_toman' => 2500000, 'renew_toman' => 2500000,
            'honour_until' => now()->addMinutes(15),
        ]);

        TldGate::block('com', 'قراردادِ رجیستری امضا نشده است.');

        $this->actingAs($c, 'customer')
            ->post(route('account.domains.order'), ['quote_id' => $quote->id])
            ->assertSessionHasErrors();

        $this->assertSame(0, Invoice::count(), 'فاکتور ساخته شد — یعنی مسیرِ پول باز مانده بود');
        $this->assertSame(0, Domain::count(), 'ردیفِ دامنه ساخته شد در حالی که ثبتش ممکن نیست');
    }

    /** و پسوندِ باز دست‌نخورده می‌فروشد — دروازه نباید فروشِ سالم را بگیرد */
    public function test_an_open_tld_still_sells(): void
    {
        $c = $this->customer();

        TldGate::block('shop', 'x');   // پسوندِ **دیگری** بسته است

        $quote = DomainQuote::create([
            'domain' => 'partolastik.com', 'tld' => 'com', 'registrar' => 'openprovider',
            'is_premium' => false, 'cost_amount' => 950, 'cost_currency' => 'EUR',
            'sell_toman' => 2500000, 'renew_toman' => 2500000,
            'honour_until' => now()->addMinutes(15),
        ]);

        $this->actingAs($c, 'customer')
            ->post(route('account.domains.order'), ['quote_id' => $quote->id])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Invoice::count());
    }

    // ═══════════════ ۳) خودش باز می‌شود ═══════════════

    /**
     * ✅ خودشفایی: بعد از امضا، اولین ثبتِ موفق دروازه را باز می‌کند.
     *
     * ⚠️ بی‌این، مدیر باید یادش می‌ماند دو کار بکند (امضا + بازکردن) و کارِ
     * دوم دقیقاً همانی است که فراموش می‌شود — پسوند تا هفته‌ها بسته می‌مانْد و
     * هیچ خطایی هم هیچ‌جا نبود.
     */
    public function test_a_successful_registration_reopens_the_tld_by_itself(): void
    {
        $c = $this->customer();

        TldGate::block('com', 'قراردادِ رجیستری امضا نشده است.');

        // ثبتِ موفق: هم `findDomain` و هم `POST /domains` کدِ ۰ می‌دهند
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([
            '*/auth/login*' => Http::response(['code' => 0, 'data' => ['token' => 't']]),
            '*/customers*'  => Http::response(['code' => 0, 'data' => ['handle' => 'AB123-NL']]),
            '*/domains*'    => Http::response(['code' => 0, 'data' => [
                'id' => 9001, 'status' => 'ACT', 'results' => [],
                'expiration_date' => now()->addYear()->toDateString(),
            ]]),
        ]);

        $res = app(DomainRegistrar::class)->register($this->domain($c));

        $this->assertTrue($res['ok'], 'فیکسچر ثبت را موفق نکرد — پیش‌شرطِ خودِ سنجش');
        $this->assertFalse(TldGate::isBlocked('com'),
            'پسوند بعد از ثبتِ موفق هنوز بسته است — مدیر باید کارِ دستیِ دوم بکند');
    }

    /** بازکردنِ پسوندی که بسته نیست نباید چیزی خراب کند (هر ثبتِ موفق صدایش می‌زند) */
    public function test_clearing_an_open_tld_is_harmless(): void
    {
        TldGate::block('shop', 'x');

        TldGate::clear('com');

        $this->assertTrue(TldGate::isBlocked('shop'), 'بازکردنِ یکی، دیگری را هم باز کرد');
        $this->assertFalse(TldGate::isBlocked('com'));
    }
}

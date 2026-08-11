<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\Domain;
use App\Services\Domain\DomainRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * «قراردادِ رجیستری را امضا نکرده‌اید» — شکستی که تلاشِ دوباره هرگز حلش نمی‌کند.
 *
 * ═══ رخداد ═══
 *
 * مشتری وسطِ ثبتِ دامنه بود و در پنلِ مدیریت این آمد:
 *   «You have not signed the last version of the contract for registering
 *    this domain»
 *
 * ⚠️ **این پیام دربارهٔ حسابِ ماست، نه دربارهٔ مشتری و نه دربارهٔ آن دامنه.**
 * رجیسترار برای هر پسوند یک قراردادِ رجیستری دارد که فروشنده باید یک‌بار در
 * پنلِ خودش امضا کند. تا امضا نشود، هیچ دامنه‌ای با آن پسوند ثبت نمی‌شود و
 * **هیچ فیلدی در API از رویش رد نمی‌کند** — یک امضای حقوقی است، نه پارامتر.
 *
 * پس این فایل ادعا نمی‌کند که ثبت موفق می‌شود؛ ادعا می‌کند سامانه در برابرِ
 * این شکست **درست** رفتار کند:
 *
 *   ۱) بی‌درنگ به صفِ آدم برود، نه بعد از سه تلاشِ بی‌فایده
 *   ۲) پیامش به مدیر بگوید دقیقاً چه کار کند و برای کدام پسوند
 *   ۳) و این رفتار فقط برای همین کدها باشد، نه برای هر شکستِ دیگری
 */
class DomainContractNotSignedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.openprovider.username', 'test@example.com');
        config()->set('services.openprovider.password', 'secret');
        config()->set('services.openprovider.nameservers', ['ns1.servernet.cloud', 'ns2.servernet.cloud']);
    }

    /** ⚠️ کارخانهٔ نو: یک `Http::fake()`ِ همه‌گیرِ قبلی هر استابِ بعدی را بی‌اثر می‌کند */
    private function fake(int $code, string $desc): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([
            '*/auth/login*' => Http::response(['code' => 0, 'data' => ['token' => 't']]),
            '*/customers*'  => Http::response(['code' => 0, 'data' => ['handle' => 'AB123-NL']]),
            // اوپن‌پروایدر روی خطا هم HTTP 500 می‌دهد؛ خطای واقعی در `code` است
            '*/domains*'    => Http::response(['code' => $code, 'desc' => $desc], 500),
        ]);
    }

    private function domain(array $over = []): Domain
    {
        $c = Customer::create([
            'email' => 'd'.random_int(1000, 99999).'@x.com',
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

        return Domain::create(array_merge([
            'customer_id' => $c->id, 'domain' => 'zhina.shop', 'sld' => 'zhina', 'tld' => 'shop',
            'registrar' => 'openprovider', 'status' => 'pending', 'provision_status' => 'pending',
            'period_years' => 1, 'price_toman' => 2500000,
        ], $over));
    }

    // ═══════════════ ۱) بی‌درنگ به صفِ آدم ═══════════════

    /**
     * 🔴 هستهٔ ادعا: **اولین** تلاش پارک می‌کند، نه سومی.
     *
     * ⚠️ چرا این مهم است و صرفاً «تمیزکاری» نیست: هر تلاشِ اضافه یک تماسِ
     * واقعیِ دیگر با رجیستراری است که حسابِ ما قبلاً یک بار به‌خاطرِ تماسِ زیاد
     * علامت خورده — و هر سه قطعاً همان جواب را می‌دهند، چون پاسخ فقط با امضای
     * یک انسان عوض می‌شود.
     */
    public function test_an_unsigned_contract_parks_the_domain_on_the_very_first_try(): void
    {
        $d = $this->domain();

        $this->fake(309, 'You have not signed the last version of the contract for registering this domain');

        $res = app(DomainRegistrar::class)->register($d);

        $this->assertFalse($res['ok']);
        $this->assertTrue($res['manual'], 'به صفِ آدم نرفت — کرون تا ابد دوباره تلاش می‌کند');

        $d->refresh();

        $this->assertSame('manual', $d->provision_status);
        $this->assertSame(1, (int) $d->provision_tries,
            'بیش از یک تلاش زده شد؛ هر تلاش یک تماسِ بی‌فایده با حسابِ علامت‌خورده است');
        $this->assertNotSame('active', $d->status);
    }

    /** کدِ دومِ همان خانواده هم همان‌طور رفتار شود */
    public function test_the_other_contract_code_behaves_the_same(): void
    {
        $d = $this->domain();

        $this->fake(17001, 'You must sign a contract');

        $this->assertTrue(app(DomainRegistrar::class)->register($d)['manual']);
        $this->assertSame(1, (int) $d->fresh()->provision_tries);
    }

    // ═══════════════ ۲) پیامِ قابلِ اقدام ═══════════════

    /**
     * پیام باید سه چیز را بگوید، وگرنه مدیر می‌بیند و نمی‌داند چه کند:
     * کدام پسوند · کجا امضا کند · بعدش چه کند.
     */
    public function test_the_admin_message_says_which_tld_and_exactly_what_to_do(): void
    {
        $d = $this->domain();

        $this->fake(309, 'You have not signed the last version of the contract');

        app(DomainRegistrar::class)->register($d);

        $err = (string) $d->fresh()->provision_error;

        $this->assertStringContainsString('.shop', $err,
            'پسوند در پیام نیست — قرارداد per-TLD است و مدیر باید بداند کدام را امضا کند');
        $this->assertStringContainsString(DomainRegistrar::CONTRACTS_URL, $err,
            'نشانیِ صفحهٔ امضا در پیام نیست');
        $this->assertStringContainsString('امضا', $err);

        /*
        | ⚠️ پیامِ خامِ رجیسترار هم باید بمانَد. این ستون فقط برای مدیر است
        | (مشتری نمی‌بیندش) و اگر روزی معنای کد عوض شود، تنها ردِ واقعیت همان
        | متنِ اصلی است. ترجمهٔ تنها، عیب‌یابیِ فردا را کور می‌کند.
        */
        $this->assertStringContainsString('signed', $err,
            'متنِ اصلیِ رجیسترار دور ریخته شد — عیب‌یابیِ بعدی هیچ مرجعی ندارد');
    }

    // ═══════════════ ۳) و فقط همین کدها ═══════════════

    /**
     * 🔴 نیمهٔ دومِ هر «میان‌بُر»: مطمئن شو بقیه را با خودش نبرده.
     *
     * بی‌این تست، یک شرطِ زیادی‌گشاد هر شکستِ **گذرا** (قطعیِ لحظه‌ای، سکسکهٔ
     * رجیسترار) را هم مستقیم به صفِ دستی می‌فرستاد — یعنی دامنه‌ای که تلاشِ
     * دوم خودش درست می‌شد، منتظرِ آدم می‌مانْد.
     */
    public function test_an_ordinary_failure_still_retries_before_going_manual(): void
    {
        $d = $this->domain();

        $this->fake(307, 'Domain is already registered by someone else');

        $res = app(DomainRegistrar::class)->register($d);

        $this->assertFalse($res['manual'], 'شکستِ معمولی هم بی‌درنگ پارک شد');
        $this->assertSame('pending', $d->fresh()->provision_status);
        $this->assertSame(1, (int) $d->fresh()->provision_tries);
    }

    /** و تشخیص روی **کد** است، نه روی متنِ انگلیسی که هر وقت عوض شود */
    public function test_detection_is_by_numeric_code_not_by_english_text(): void
    {
        // متنِ قرارداد، ولی کدِ یک خطای دیگر ⇒ نباید میان‌بُر بخورد
        $d = $this->domain();

        $this->fake(307, 'You have not signed the last version of the contract');

        $this->assertFalse(app(DomainRegistrar::class)->register($d)['manual'],
            'تشخیص روی رشتهٔ انگلیسی بسته شده — رجیسترار متن را عوض کند، بی‌صدا می‌شکند');
    }

    /** و برعکس: کدِ درست با متنِ خالی هم باید گرفته شود */
    public function test_the_code_alone_is_enough_even_with_an_empty_message(): void
    {
        $d = $this->domain();

        $this->fake(309, '');

        $this->assertTrue(app(DomainRegistrar::class)->register($d)['manual']);
    }

    /** خودِ نگاشت — تا کسی کدی را بی‌قصد از فهرست برندارد */
    public function test_the_contract_codes_are_the_two_documented_ones(): void
    {
        $this->assertSame([309, 17001], DomainRegistrar::CONTRACT_CODES);

        $this->assertTrue(DomainRegistrar::isUnsignedContract(309));
        $this->assertTrue(DomainRegistrar::isUnsignedContract(17001));
        $this->assertFalse(DomainRegistrar::isUnsignedContract(307));
        $this->assertFalse(DomainRegistrar::isUnsignedContract(0));
    }
}

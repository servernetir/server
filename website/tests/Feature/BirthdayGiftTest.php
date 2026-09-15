<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\GiftCoupon;
use App\Models\Service;
use App\Models\Setting;
use App\Support\Jalali;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * هدیهٔ تولد — صدورِ کوپن.
 *
 * هر تست یک **ادعا** را می‌سنجد، نه اینکه فرمان بدونِ استثنا تمام شود. این
 * تنها مسیرِ سامانه است که بدونِ هیچ رویدادِ تجاری پول توزیع می‌کند، پس
 * ادعاهای «چه کسی نمی‌گیرد» این‌جا مهم‌تر از «چه کسی می‌گیرد» است.
 */
class BirthdayGiftTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'birthday.enabled' => true,
            'birthday.amount_irt' => 500_000,
            'birthday.valid_hours' => 24,
            'birthday.min_invoice_irt' => 1_500_000,
            'birthday.require_active_service' => true,
            'birthday.daily_cap' => 50,
        ]);
    }

    // ───────────────────────── فیکسچر ─────────────────────────

    private function customer(?string $birth = null, bool $withService = true): Customer
    {
        $c = Customer::create([
            'email' => 'c'.random_int(1, 9999999).'@t.test',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);

        CustomerProfile::create([
            'customer_id' => $c->id, 'type' => 'individual', 'status' => 'verified',
            'is_default' => true, 'email' => $c->email, 'mobile' => $c->phone,
            'country' => 'IR', 'first_name' => 'مهرداد', 'last_name' => 'ن',
            'birth_date' => $birth,
        ]);

        if ($withService) {
            Service::create([
                'customer_id' => $c->id, 'name' => 'سرور', 'plan' => 'x',
                'status' => 'active', 'cycle' => 'monthly', 'price' => 1000,
                'currency_code' => 'IRT',
            ]);
        }

        return $c->fresh();
    }

    /** میلادیِ معادلِ یک تاریخِ شمسی — تا تست هرگز تاریخِ میلادی حدس نزند */
    private function gregorianOf(int $jy, int $jm, int $jd): string
    {
        [$gy, $gm, $gd] = Jalali::toGregorian($jy, $jm, $jd);

        return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
    }

    /**
     * «امروز» را روی یک روزِ شمسیِ مشخص، به وقتِ تهران، بنشان.
     *
     * 🔴 هر فیکسچرِ زمان‌داری باید **بعد** از این ساخته شود. نسخهٔ اول
     * فاکتورها را با ساعتِ واقعی می‌ساخت و بعد ساعت را می‌برد، پس فاصله‌ها به
     * تاریخِ اجرای تست بند بودند: شش روز سبز ماند و بعد بی‌آنکه کدی عوض شود
     * قرمز شد.
     */
    private function travelToJalali(int $jy, int $jm, int $jd): void
    {
        Carbon::setTestNow(
            Jalali::startOfDay($jy, $jm, $jd, 'Asia/Tehran')->addHours(8)->utc()
        );
    }

    private function invoice(Customer $c): int
    {
        return (int) \DB::table('invoices')->insertGetId([
            'customer_id' => $c->id, 'number' => 'INV-'.random_int(100000, 999999),
            'status' => 'unpaid', 'currency_code' => 'IRT',
            'subtotal' => 2_000_000, 'total' => 2_000_000,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function coupons(Customer $c): int
    {
        return GiftCoupon::where('customer_id', $c->id)->count();
    }

    // ───────────────────────── صدور ─────────────────────────

    public function test_the_coupon_is_issued_on_the_jalali_birthday(): void
    {
        $c = $this->customer($this->gregorianOf(1370, 5, 15));

        $this->travelToJalali(1405, 5, 15);
        $this->artisan('birthday:gift')->assertSuccessful();

        $coupon = GiftCoupon::where('customer_id', $c->id)->first();

        $this->assertNotNull($coupon, 'تولدِ ۱۵ مرداد باید در ۱۵ مردادِ امسال کوپن بگیرد');
        $this->assertSame(500_000, $coupon->amount);
        $this->assertSame(1_500_000, $coupon->min_invoice);
        $this->assertTrue($coupon->isLive());
    }

    public function test_nothing_happens_on_any_other_day(): void
    {
        $c = $this->customer($this->gregorianOf(1370, 5, 15));

        $this->travelToJalali(1405, 5, 16);
        $this->artisan('birthday:gift')->assertSuccessful();

        $this->assertSame(0, $this->coupons($c));
    }

    /**
     * 🔴 مهم‌ترین تستِ این فایل.
     *
     * برنامه پول توزیع می‌کند و پیش‌فرضش باید «هیچ‌کاری نکن» باشد. اگر روزی
     * کسی `enabled` را به `true` تغییر دهد، این تست قرمز می‌شود و آن تغییر
     * یک **تصمیم** خواهد بود نه یک اتفاق.
     */
    public function test_it_is_off_unless_someone_turns_it_on(): void
    {
        $fromFile = require base_path('config/birthday.php');

        $this->assertFalse((bool) $fromFile['enabled'],
            'config/birthday.php باید پیش‌فرض خاموش بمانَد — این تنها مسیری است که بی‌رویدادِ تجاری پول می‌دهد');

        config(['birthday.enabled' => false]);
        $c = $this->customer($this->gregorianOf(1370, 5, 15));

        $this->travelToJalali(1405, 5, 15);
        $this->artisan('birthday:gift')->assertSuccessful();

        $this->assertSame(0, $this->coupons($c));
    }

    /** تنظیماتِ پنل بر فایلِ config می‌چربد — کارفرما باید بتواند خودش عوض کند */
    public function test_the_panel_settings_win_over_the_config_file(): void
    {
        $c = $this->customer($this->gregorianOf(1370, 5, 15));

        Setting::put('birthday_amount_irt', '750000');
        Setting::put('birthday_valid_hours', '48');
        Setting::put('birthday_min_invoice', '3000000');

        $this->travelToJalali(1405, 5, 15);
        $this->artisan('birthday:gift')->assertSuccessful();

        $coupon = GiftCoupon::where('customer_id', $c->id)->firstOrFail();

        $this->assertSame(750_000, $coupon->amount);
        $this->assertSame(3_000_000, $coupon->min_invoice);
        $this->assertSame(48, (int) round(now()->diffInHours($coupon->expires_at, false)));
    }

    /**
     * ⚠️ رشتهٔ خالی «صفر» نیست، «ست‌نشده» است.
     *
     * یکی‌گرفتنشان یعنی یک فیلدِ پاک‌شده در فرمِ تنظیمات مبلغِ هدیه را بی‌صدا
     * صفر می‌کرد و برنامه بی‌هیچ خطایی هیچ کوپنی صادر نمی‌کرد.
     */
    public function test_an_empty_setting_falls_back_to_config_not_zero(): void
    {
        $c = $this->customer($this->gregorianOf(1370, 5, 15));

        Setting::put('birthday_amount_irt', '');

        $this->travelToJalali(1405, 5, 15);
        $this->artisan('birthday:gift')->assertSuccessful();

        $this->assertSame(500_000, GiftCoupon::where('customer_id', $c->id)->firstOrFail()->amount);
    }

    /** مبلغِ صفر یعنی پیکربندی ناقص — پیامکِ «۰ تومان هدیه» از نفرستادن بدتر است */
    public function test_a_zero_amount_issues_nothing(): void
    {
        $c = $this->customer($this->gregorianOf(1370, 5, 15));

        Setting::put('birthday_amount_irt', '0');

        $this->travelToJalali(1405, 5, 15);
        $this->artisan('birthday:gift')->assertSuccessful();

        $this->assertSame(0, $this->coupons($c));
    }

    public function test_the_same_year_never_issues_twice(): void
    {
        $c = $this->customer($this->gregorianOf(1370, 5, 15));

        $this->travelToJalali(1405, 5, 15);
        $this->artisan('birthday:gift');
        $this->artisan('birthday:gift');
        $this->artisan('birthday:gift');

        $this->assertSame(1, $this->coupons($c), 'اجرای دوباره در همان روز نباید کوپنِ دوم بدهد');
    }

    /**
     * ⚠️ کوپنِ **مصرف‌شده** هم باید جلوی صدورِ دوباره را بگیرد.
     *
     * با شرطِ «فقط کوپنِ زنده»، مشتری‌ای که کوپنش را همان صبح خرج کرده،
     * اجرای بعدیِ کرون در همان روز کوپنِ تازه‌ای می‌گرفت.
     */
    public function test_a_spent_coupon_still_blocks_a_second_one(): void
    {
        $c = $this->customer($this->gregorianOf(1370, 5, 15));

        $this->travelToJalali(1405, 5, 15);
        $this->artisan('birthday:gift');

        GiftCoupon::where('customer_id', $c->id)->update(['used_at' => now()]);

        $this->artisan('birthday:gift')->assertSuccessful();

        $this->assertSame(1, $this->coupons($c));
    }

    public function test_the_next_year_issues_again(): void
    {
        $c = $this->customer($this->gregorianOf(1370, 5, 15));

        $this->travelToJalali(1405, 5, 15);
        $this->artisan('birthday:gift');

        $this->travelToJalali(1406, 5, 15);
        $this->artisan('birthday:gift');

        $this->assertSame(2, $this->coupons($c), 'سالِ بعد کوپنِ تازه باید برود — وگرنه برنامه یک‌بارمصرف است');
    }

    /**
     * متولدِ ۳۰ اسفند در سالِ غیرکبیسه روزِ تولد ندارد.
     *
     * ⚠️ بی‌این محافظ، آن مشتری سه سال از هر چهار سال هیچ تبریکی نمی‌گرفت و
     * هیچ خطایی هم تولید نمی‌شد.
     */
    public function test_someone_born_on_esfand_30_is_greeted_on_esfand_29(): void
    {
        $leap = 1403;
        $this->assertTrue(Jalali::isLeap($leap), 'فرضِ تست: ۱۴۰۳ کبیسه است');

        $c = $this->customer($this->gregorianOf($leap, 12, 30));

        $plain = 1405;
        $this->assertFalse(Jalali::isLeap($plain), 'فرضِ تست: ۱۴۰۵ کبیسه نیست');

        $this->travelToJalali($plain, 12, 29);
        $this->artisan('birthday:gift')->assertSuccessful();

        $this->assertSame(1, $this->coupons($c));
    }

    public function test_a_dormant_account_gets_nothing(): void
    {
        $c = $this->customer($this->gregorianOf(1370, 5, 15), withService: false);

        $this->travelToJalali(1405, 5, 15);
        $this->artisan('birthday:gift')->assertSuccessful();

        $this->assertSame(0, $this->coupons($c),
            'حسابِ بی‌سرویسِ فعال نباید کوپن بگیرد — کدی که هرگز استفاده نمی‌شود فقط هزینهٔ پیامک است');
    }

    public function test_dry_run_writes_nothing(): void
    {
        $c = $this->customer($this->gregorianOf(1370, 5, 15));

        $this->travelToJalali(1405, 5, 15);
        $this->artisan('birthday:gift', ['--dry' => true])->assertSuccessful();

        $this->assertSame(0, $this->coupons($c));
    }

    // ───────────────────────── خودِ کوپن ─────────────────────────

    /**
     * 🔴 کد نباید حرفِ مبهم داشته باشد.
     *
     * `0/O` و `1/I/L` در پیامک و پشتِ تلفن قابلِ تفکیک نیستند؛ مشتری کدِ درست
     * را وارد می‌کند و «نامعتبر» می‌گیرد — و آن‌وقت گمان می‌کند هدیه دروغ بوده.
     */
    public function test_the_code_avoids_ambiguous_characters(): void
    {
        for ($i = 0; $i < 40; $i++) {
            $body = substr(GiftCoupon::freshCode(), 3);

            $this->assertSame(0, preg_match('/[OIL01]/', $body),
                "کدِ «{$body}» حرفِ مبهم دارد");
        }
    }

    /** ارقامِ فارسی و فاصله و حروفِ کوچک همه باید پذیرفته شوند */
    public function test_the_code_input_is_normalised(): void
    {
        $this->assertSame('HB-AB23CD45', GiftCoupon::normalize(' hb-ab۲۳cd٤5 '));
    }

    /**
     * 🔴 قفلِ اتمی: دو مصرفِ هم‌زمان، فقط یکی باید بگیرد.
     *
     * با گاردِ کوئری‌محور (`if ($coupon->used_at === null)`) هر دو سبز می‌شدند
     * و یک کوپن دو فاکتور را می‌بست — دو برابرِ هدیه ضرر، بی‌هیچ خطایی.
     */
    public function test_only_one_claim_can_win(): void
    {
        $c = $this->customer();

        $coupon = GiftCoupon::create([
            'customer_id' => $c->id, 'code' => GiftCoupon::freshCode(),
            'currency_code' => 'IRT', 'amount' => 500_000, 'min_invoice' => 0,
            'reason' => 'birthday', 'expires_at' => now()->addHours(24),
        ]);

        // ⚠️ فاکتورِ واقعی لازم است: `used_invoice_id` کلیدِ خارجی دارد و
        //    شناسهٔ ساختگی همان‌جا رد می‌شود — که خودش یعنی محافظ کار می‌کند.
        $a = $this->invoice($c);
        $b = $this->invoice($c);

        $first  = $coupon->claim($a);
        $second = (clone $coupon)->claim($b);

        $this->assertTrue($first, 'اولین claim باید بگیرد');
        $this->assertFalse($second, 'دومین claim نباید بگیرد');
        $this->assertSame($a, (int) $coupon->fresh()->used_invoice_id);
    }

    /** کوپنِ منقضی حتی با claim مستقیم هم گرفته نمی‌شود */
    public function test_an_expired_coupon_cannot_be_claimed(): void
    {
        $c = $this->customer();

        $coupon = GiftCoupon::create([
            'customer_id' => $c->id, 'code' => GiftCoupon::freshCode(),
            'currency_code' => 'IRT', 'amount' => 500_000, 'min_invoice' => 0,
            'reason' => 'birthday', 'expires_at' => now()->subMinute(),
        ]);

        $this->assertFalse($coupon->claim($this->invoice($c)));
        $this->assertNull($coupon->fresh()->used_at);
    }
}

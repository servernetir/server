<?php

namespace Tests\Feature;

use App\Console\Commands\BirthdayGift;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\Service;
use App\Support\Jalali;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * هدیهٔ تولد.
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
            'birthday.expires_days' => 30,
            'birthday.require_active_service' => true,
            'birthday.daily_cap' => 50,
            'birthday.tiers' => [
                ['min_paid_irt' => 0, 'gift_irt' => 100_000],
                ['min_paid_irt' => 5_000_000, 'gift_irt' => 300_000],
            ],
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

    /** «امروز» را روی یک روزِ شمسیِ مشخص، به وقتِ تهران، بنشان */
    private function travelToJalali(int $jy, int $jm, int $jd): void
    {
        Carbon::setTestNow(
            Jalali::startOfDay($jy, $jm, $jd, 'Asia/Tehran')->addHours(8)->utc()
        );
    }

    private function gifts(Customer $c): int
    {
        return CreditEntry::where('customer_id', $c->id)
            ->where('reason', BirthdayGift::REASON_GIFT)->sum('amount');
    }

    // ───────────────────────── هدیه ─────────────────────────

    public function test_the_gift_lands_on_the_jalali_birthday(): void
    {
        $c = $this->customer($this->gregorianOf(1370, 5, 15));

        $this->travelToJalali(1405, 5, 15);
        $this->artisan('birthday:gift')->assertSuccessful();

        $this->assertSame(100_000, $this->gifts($c),
            'تولدِ ۱۵ مرداد باید در ۱۵ مردادِ امسال هدیه بگیرد');
    }

    public function test_nothing_happens_on_any_other_day(): void
    {
        $c = $this->customer($this->gregorianOf(1370, 5, 15));

        $this->travelToJalali(1405, 5, 16);
        $this->artisan('birthday:gift')->assertSuccessful();

        $this->assertSame(0, $this->gifts($c));
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

        $this->assertSame(0, $this->gifts($c));
    }

    public function test_the_same_year_never_pays_twice(): void
    {
        $c = $this->customer($this->gregorianOf(1370, 5, 15));

        $this->travelToJalali(1405, 5, 15);
        $this->artisan('birthday:gift');
        $this->artisan('birthday:gift');
        $this->artisan('birthday:gift');

        $this->assertSame(100_000, $this->gifts($c),
            'اجرای دوباره در همان روز نباید هدیهٔ دوم بدهد');
    }

    public function test_the_next_year_pays_again(): void
    {
        $c = $this->customer($this->gregorianOf(1370, 5, 15));

        $this->travelToJalali(1405, 5, 15);
        $this->artisan('birthday:gift');

        $this->travelToJalali(1406, 5, 15);
        $this->artisan('birthday:gift');

        $this->assertSame(200_000, $this->gifts($c),
            'سالِ بعد هدیهٔ تازه باید برود — وگرنه برنامه یک‌بارمصرف است');
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

        $this->assertSame(100_000, $this->gifts($c));
    }

    // ───────────────────────── چه کسی نمی‌گیرد ─────────────────────────

    public function test_a_dormant_account_gets_nothing(): void
    {
        $c = $this->customer($this->gregorianOf(1370, 5, 15), withService: false);

        $this->travelToJalali(1405, 5, 15);
        $this->artisan('birthday:gift')->assertSuccessful();

        $this->assertSame(0, $this->gifts($c),
            'حسابِ بی‌سرویسِ فعال نباید هدیه بگیرد — پولی که هرگز خرج نمی‌شود فقط بدهیِ دفتری می‌سازد');
    }

    public function test_the_tier_follows_the_money_not_the_invoice_count(): void
    {
        $big = $this->customer($this->gregorianOf(1370, 5, 15));

        \DB::table('invoices')->insert([
            'customer_id' => $big->id, 'number' => 'INV-1', 'status' => 'paid',
            'currency_code' => 'IRT', 'subtotal' => 6_000_000, 'total' => 6_000_000,
            'created_at' => now()->subDays(10), 'updated_at' => now(),
        ]);

        $this->travelToJalali(1405, 5, 15);
        $this->artisan('birthday:gift')->assertSuccessful();

        $this->assertSame(300_000, $this->gifts($big),
            'خریدِ ۶ میلیونی باید پلهٔ دوم را بگیرد');
    }

    public function test_a_purchase_older_than_a_year_does_not_count(): void
    {
        $c = $this->customer($this->gregorianOf(1370, 5, 15));

        \DB::table('invoices')->insert([
            'customer_id' => $c->id, 'number' => 'INV-2', 'status' => 'paid',
            'currency_code' => 'IRT', 'subtotal' => 6_000_000, 'total' => 6_000_000,
            'created_at' => now()->subDays(400), 'updated_at' => now(),
        ]);

        $this->travelToJalali(1405, 5, 15);
        $this->artisan('birthday:gift')->assertSuccessful();

        $this->assertSame(100_000, $this->gifts($c),
            'خریدِ کهنه نباید پله بدهد — معیار «۱۲ ماهِ گذشته» است');
    }

    // ───────────────────────── انقضا ─────────────────────────

    public function test_an_unspent_gift_is_taken_back_after_the_promised_days(): void
    {
        $c = $this->customer($this->gregorianOf(1370, 5, 15));

        $this->travelToJalali(1405, 5, 15);
        $this->artisan('birthday:gift');
        $this->assertSame(100_000, $c->creditBalance('IRT'));

        Carbon::setTestNow(now()->addDays(31));
        $this->artisan('birthday:gift')->assertSuccessful();

        $this->assertSame(0, $c->fresh()->creditBalance('IRT'),
            'پیامک وعدهٔ «۳۰ روز» داده؛ اگر اعتبار بمانَد آن جمله دروغ بوده');
    }

    public function test_a_spent_gift_is_never_clawed_back(): void
    {
        $c = $this->customer($this->gregorianOf(1370, 5, 15));

        $this->travelToJalali(1405, 5, 15);
        $this->artisan('birthday:gift');

        // مشتری همه‌اش را خرج کرد
        CreditEntry::create([
            'customer_id' => $c->id, 'currency_code' => 'IRT',
            'amount' => -100_000, 'balance_after' => 0,
            'reason' => 'invoice', 'note' => 'خرج شد',
        ]);

        Carbon::setTestNow(now()->addDays(31));
        $this->artisan('birthday:gift')->assertSuccessful();

        $this->assertSame(0, $c->fresh()->creditBalance('IRT'),
            'موجودی نباید منفی شود — بازپس‌گیری min(هدیه، موجودی) است');
    }

    /**
     * 🔴 پولی که مشتری خودش گذاشته دست‌نخورده می‌مانَد.
     *
     * اگر بازپس‌گیری کورکورانه مبلغِ کاملِ هدیه را بردارد، مشتری‌ای که هدیه را
     * خرج کرده و بعد شارژ کرده، از **پولِ خودش** ضرر می‌کند — یعنی برنامه‌ای
     * که برای خوش‌حالی ساخته شده، به یک شکایتِ مالی تبدیل می‌شود.
     */
    public function test_the_sweep_never_eats_more_than_it_gave(): void
    {
        $c = $this->customer($this->gregorianOf(1370, 5, 15));

        $this->travelToJalali(1405, 5, 15);
        $this->artisan('birthday:gift');

        CreditEntry::create([
            'customer_id' => $c->id, 'currency_code' => 'IRT',
            'amount' => 500_000, 'balance_after' => 600_000,
            'reason' => 'topup', 'note' => 'شارژِ خودِ مشتری',
        ]);

        Carbon::setTestNow(now()->addDays(31));
        $this->artisan('birthday:gift')->assertSuccessful();

        $this->assertSame(500_000, $c->fresh()->creditBalance('IRT'),
            'فقط همان ۱۰۰ هزارِ هدیه باید برگردد، نه یک ریال بیشتر');
    }

    public function test_the_sweep_stamps_so_it_never_runs_twice(): void
    {
        $c = $this->customer($this->gregorianOf(1370, 5, 15));

        $this->travelToJalali(1405, 5, 15);
        $this->artisan('birthday:gift');

        Carbon::setTestNow(now()->addDays(31));
        $this->artisan('birthday:gift');
        $this->artisan('birthday:gift');
        $this->artisan('birthday:gift');

        $this->assertSame(1, CreditEntry::where('customer_id', $c->id)
            ->where('reason', BirthdayGift::REASON_EXPIRE)->count(),
            'هر هدیه فقط یک بار منقضی می‌شود؛ وگرنه موجودی تا بی‌نهایت منفی می‌رفت');
    }

    /**
     * ⚠️ سوییپ پشتِ کلیدِ روشن/خاموش نیست.
     *
     * وعدهٔ «تا N روز» را قبلاً به مشتری داده‌ایم؛ خاموش‌کردنِ برنامه باید جلوی
     * هدیهٔ تازه را بگیرد، نه جلوی وعده‌ای که از قبل داده‌ایم.
     */
    public function test_turning_the_programme_off_still_honours_the_expiry(): void
    {
        $c = $this->customer($this->gregorianOf(1370, 5, 15));

        $this->travelToJalali(1405, 5, 15);
        $this->artisan('birthday:gift');

        config(['birthday.enabled' => false]);
        Carbon::setTestNow(now()->addDays(31));
        $this->artisan('birthday:gift')->assertSuccessful();

        $this->assertSame(0, $c->fresh()->creditBalance('IRT'));
    }

    // ───────────────────────── خشک ─────────────────────────

    public function test_dry_run_writes_nothing(): void
    {
        $c = $this->customer($this->gregorianOf(1370, 5, 15));

        $this->travelToJalali(1405, 5, 15);
        $this->artisan('birthday:gift', ['--dry' => true])->assertSuccessful();

        $this->assertSame(0, CreditEntry::where('customer_id', $c->id)->count());
    }
}

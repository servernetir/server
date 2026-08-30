<?php

namespace Tests\Feature;

use App\Models\BusinessEntry;
use App\Models\Server;
use App\Models\Setting;
use App\Services\Finance\BusinessLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `servers:post-rent` — ثبتِ خودکارِ اجارهٔ سرور در دفترِ مالی.
 *
 * 🔴 این فرمان **سابقهٔ مالی می‌نویسد**. پس ادعای هر تست یک عدد یا یک ردیفِ
 * دیتابیس است، نه «فرمان بدونِ خطا تمام شد». یک ردیفِ اشتباه در دفتر، بعداً
 * پایهٔ تصمیمِ مالیاتی و قیمت‌گذاری می‌شود.
 */
class ServerRentLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // نرخِ دستی تا تست به شبکه وابسته نباشد: ۱ یورو = ۱۰۰٬۰۰۰ تومان
        Setting::put('pricing_rate_override', '100000');
    }

    private function server(array $over = []): Server
    {
        static $n = 0;
        $n++;

        return Server::create($over + [
            'name' => 'SRV-'.$n, 'type' => 'whm', 'status' => 'active',
            'monthly_cost' => 3990, 'cost_currency' => 'EUR', 'billing_day' => 5,
            'vendor' => 'تأمین‌کنندهٔ الف',
        ]);
    }

    /**
     * ⚠️ نه `run` و نه `post`: اولی در `PHPUnit\TestCase` نهایی است و دومی
     * در `Illuminate\Foundation\Testing\TestCase` عمومی — هر دو تصادم
     * می‌کنند و خطاشان زمانِ بارگذاری است، نه زمانِ اجرا.
     */
    private function postRent(array $args = []): void
    {
        $this->artisan('servers:post-rent', $args)->assertSuccessful();
    }

    private function rentRows(): \Illuminate\Support\Collection
    {
        return BusinessEntry::where('kind', 'expense')->where('category', 'server')->get();
    }

    // ═══════════════ ثبتِ درست ═══════════════

    public function test_it_posts_one_expense_row_per_server_per_month(): void
    {
        $this->travelTo(Carbon::parse('2026-08-20 09:00:00'));
        $this->server(['monthly_cost' => 3990, 'cost_currency' => 'EUR']);

        $this->postRent();

        $rows = $this->rentRows();

        $this->assertCount(1, $rows);
        $this->assertSame(3_990_000, (int) $rows->first()->amount, 'سنتِ یورو درست تبدیل نشد');
        $this->assertSame('2026-08', $rows->first()->period);
        $this->assertSame('out', $rows->first()->direction);
    }

    /**
     * 🔴 هستهٔ idempotency: اجرای دوباره ردیفِ دوم نمی‌سازد.
     *
     * کرون روزانه می‌دود، پس در یک ماه سی بار روی همان سرور می‌گذرد. بی‌این
     * تضمین، اجارهٔ یک ماه سی بار در دفتر می‌نشست و سود منفی می‌شد.
     */
    public function test_running_it_thirty_times_still_leaves_one_row(): void
    {
        $this->travelTo(Carbon::parse('2026-08-06 09:00:00'));
        $this->server();

        foreach (range(1, 30) as $i) {
            $this->postRent();
        }

        $this->assertCount(1, $this->rentRows(), 'کرونِ روزانه ماه را چند بار ثبت کرد');
    }

    /** ماهِ بعد ردیفِ خودش را می‌گیرد — تکرارشونده یعنی همین */
    public function test_the_next_month_gets_its_own_row(): void
    {
        $s = $this->server(['billing_day' => 5]);

        $this->travelTo(Carbon::parse('2026-08-06 09:00:00'));
        $this->postRent();

        $this->travelTo(Carbon::parse('2026-09-06 09:00:00'));
        $this->postRent();

        $rows = $this->rentRows();

        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(['2026-08', '2026-09'], $rows->pluck('period')->all());
        $this->assertSame([$s->id, $s->id], $rows->pluck('ref_id')->map(fn ($v) => (int) $v)->all());
    }

    /** دو سرور، دو ردیف — کلید شاملِ شناسهٔ سرور است */
    public function test_two_servers_each_get_their_own_row(): void
    {
        $this->travelTo(Carbon::parse('2026-08-20 09:00:00'));
        $this->server();
        $this->server();

        $this->postRent();

        $this->assertCount(2, $this->rentRows());
    }

    // ═══════════════ چیزی که نباید ثبت شود ═══════════════

    /**
     * 🔴 مبلغِ وارد‌نشده حدس زده نمی‌شود.
     *
     * ثبتِ صفر یعنی «این ماه رایگان بود» — دروغی که ایندکسِ یکتا برای همیشه
     * تثبیتش می‌کند، چون بعداً جای همان ماه پر است.
     */
    public function test_a_server_without_a_price_is_never_posted(): void
    {
        $this->travelTo(Carbon::parse('2026-08-20 09:00:00'));
        $this->server(['monthly_cost' => null]);

        $this->postRent();

        $this->assertCount(0, $this->rentRows());
    }

    /** سرورِ رایگان هم ردیف نمی‌گیرد — صفر در دفتر معنایی ندارد */
    public function test_a_free_server_is_not_posted(): void
    {
        $this->travelTo(Carbon::parse('2026-08-20 09:00:00'));
        $this->server(['monthly_cost' => 0, 'cost_currency' => 'IRT']);

        $this->postRent();

        $this->assertCount(0, $this->rentRows());
    }

    /**
     * 🔴 نرخِ ارز نبود ⇒ رد، نه صفر.
     *
     * رد کردن برگشت‌پذیر است — اجرای فردا می‌تواند درستش کند. ثبتِ صفر نه:
     * ایندکسِ یکتا جای آن ماه را می‌گیرد و عددِ درست دیگر جا نمی‌شود.
     */
    public function test_an_unavailable_rate_skips_instead_of_posting_zero(): void
    {
        Setting::put('pricing_rate_override', '0');
        Cache::flush();
        Http::fake(['*' => Http::response('', 500)]);

        $this->travelTo(Carbon::parse('2026-08-20 09:00:00'));
        $this->server(['monthly_cost' => 3990, 'cost_currency' => 'EUR']);

        $this->postRent();

        $this->assertCount(0, $this->rentRows(), 'ردیفِ صفر ثبت شد و جای ماه را گرفت');
    }

    /** و فردا که نرخ برگشت، همان ماه ثبت می‌شود */
    public function test_a_skipped_month_can_still_be_posted_later(): void
    {
        Setting::put('pricing_rate_override', '0');
        Cache::flush();
        Http::fake(['*' => Http::response('', 500)]);

        $this->travelTo(Carbon::parse('2026-08-20 09:00:00'));
        $this->server(['monthly_cost' => 3990, 'cost_currency' => 'EUR']);
        $this->postRent();

        $this->assertCount(0, $this->rentRows());

        Setting::put('pricing_rate_override', '100000');
        $this->postRent();

        $this->assertCount(1, $this->rentRows(), 'ماهِ ردشده بعداً قابلِ ثبت نبود');
        $this->assertSame(3_990_000, (int) $this->rentRows()->first()->amount);
    }

    /**
     * 🔴 اجاره‌ای که هنوز سررسید نشده، هزینهٔ این ماه نیست.
     *
     * بی‌این شرط، فرمان روزِ اولِ ماه اجارهٔ کل ماه را ثبت می‌کرد و «سودِ این
     * ماه» تا روزِ صورت‌حساب مصنوعاً پایین می‌مانْد.
     */
    public function test_rent_is_not_posted_before_its_billing_day(): void
    {
        $this->travelTo(Carbon::parse('2026-08-03 09:00:00'));
        $this->server(['billing_day' => 20]);

        $this->postRent();

        $this->assertCount(0, $this->rentRows());

        $this->travelTo(Carbon::parse('2026-08-20 09:00:00'));
        $this->postRent();

        $this->assertCount(1, $this->rentRows());
    }

    // ═══════════════ ایمنیِ پروداکشن ═══════════════

    /**
     * 🔴 پیش از اجرای مهاجرت، فرمان باید آرام رد شود.
     *
     * مهاجرت‌های پروداکشن دستی اجرا می‌شوند و این فرمان از `schedule:run`
     * صدا زده می‌شود — یک استثنا این‌جا کلِ آن دقیقهٔ کرون را می‌کشد، یعنی
     * تحویلِ سرور و ثبتِ دامنه و فاکتورِ تمدید هم می‌ایستند.
     */
    public function test_it_exits_quietly_before_the_migration_has_run(): void
    {
        Schema::table('business_ledger', function ($t) {
            $t->dropUnique('business_ledger_period_unique');
            $t->dropColumn(['period', 'ref_id']);
        });

        $this->travelTo(Carbon::parse('2026-08-20 09:00:00'));
        $this->server();

        $this->artisan('servers:post-rent')->assertSuccessful();
        $this->assertCount(0, $this->rentRows());
    }

    /** حالتِ آزمایشی چیزی نمی‌نویسد */
    public function test_the_dry_run_writes_nothing(): void
    {
        $this->travelTo(Carbon::parse('2026-08-20 09:00:00'));
        $this->server();

        $this->postRent(['--dry' => true]);

        $this->assertCount(0, $this->rentRows());
    }

    /** ردیفِ خودکار «دستی» علامت نمی‌خورد — ردِ بازرسی باید راست بگوید */
    public function test_the_row_is_marked_automatic_not_manual(): void
    {
        $this->travelTo(Carbon::parse('2026-08-20 09:00:00'));
        $this->server();

        $this->postRent();

        $this->assertTrue($this->rentRows()->first()->isAuto());
    }

    // ═══════════════ اثر روی سود ═══════════════

    /**
     * 🔴 هدفِ نهاییِ کلِ این کار: سودِ `/admin/finance` باید اجاره را کم کند.
     *
     * پیش از این، درآمد خودکار ثبت می‌شد و هزینه دستی، پس حاشیهٔ سود روی
     * دادهٔ واقعیِ همین سایت ۹۶٫۷٪ نشان داده می‌شد.
     */
    public function test_posted_rent_actually_reduces_the_reported_profit(): void
    {
        $this->travelTo(Carbon::parse('2026-08-20 09:00:00'));
        $this->server(['monthly_cost' => 1000, 'cost_currency' => 'EUR']);   // ۱۰ یورو ⇒ ۱٬۰۰۰٬۰۰۰ ت

        $ledger = app(BusinessLedger::class);
        $before = $ledger->summary()['net_profit'];

        $this->postRent();

        $after = app(BusinessLedger::class)->summary()['net_profit'];

        $this->assertSame($before - 1_000_000, $after, 'اجاره از سود کم نشد');
    }

    /** مبلغِ تومانی تبدیل نمی‌شود */
    public function test_a_toman_priced_server_posts_its_exact_amount(): void
    {
        $this->travelTo(Carbon::parse('2026-08-20 09:00:00'));
        $this->server(['monthly_cost' => 2_500_000, 'cost_currency' => 'IRT']);

        $this->postRent();

        $this->assertSame(2_500_000, (int) $this->rentRows()->first()->amount);
    }

    /** کرون واقعاً ثبت شده — فرمانِ ثبت‌نشده هرگز اجرا نمی‌شود */
    public function test_the_command_is_actually_scheduled(): void
    {
        $this->assertStringContainsString(
            "Schedule::command('servers:post-rent')",
            file_get_contents(base_path('routes/console.php')),
            'فرمان در routes/console.php ثبت نشده'
        );
    }

    // ═══════════════ چیزهایی که بازبینیِ حریفانه پیدا کرد ═══════════════

    /**
     * 🔴 یک غلطِ تایپی در مبلغِ اجاره نباید تا ابد در دفتر بماند.
     *
     * ردیفِ اجاره `created_by = null` دارد، پس `isAuto()` صادق بود و گاردِ
     * حذف در `/admin/finance` جلویش را می‌گرفت. `firstOrCreate` هم ردیفِ
     * موجود را به‌روز نمی‌کند و روتِ ویرایشی وجود ندارد — یعنی مبلغِ غلط تا
     * ابد در سود و پایهٔ مالیات می‌مانْد.
     *
     * حذفش بی‌خطر است: اجرای بعدیِ کرون همان ماه را با مبلغِ درست می‌نشاند.
     */
    public function test_a_wrong_rent_row_can_be_deleted_and_reposted(): void
    {
        $this->travelTo(Carbon::parse('2026-08-20 09:00:00'));
        $s = $this->server(['monthly_cost' => 39900, 'cost_currency' => 'EUR']);   // صفرِ اضافه

        $this->postRent();
        $wrong = $this->rentRows()->first();
        $this->assertSame(39_900_000, (int) $wrong->amount);

        $admin = \App\Models\User::create([
            'name' => 'مدیر', 'email' => 'f'.random_int(1, 999999).'@x.com',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);

        $this->actingAs($admin, 'web')
            ->post('/admin/finance/'.$wrong->id.'/delete')
            ->assertSessionHasNoErrors();

        $this->assertCount(0, $this->rentRows(), 'ردیفِ اجاره حذف نشد');

        // اصلاحِ مبلغ و اجرای دوباره
        $s->update(['monthly_cost' => 3990]);
        $this->postRent();

        $this->assertSame(3_990_000, (int) $this->rentRows()->first()->amount);
    }

    /**
     * 🔴 ولی ردیفِ وصل به پرداختِ واقعی همچنان حذف نمی‌شود.
     *
     * گارد از `isAuto()` به `source_id` تغییر کرد؛ این تست می‌گیرد که آن
     * تغییر، حفاظتِ اصلی را باز نکرده باشد.
     */
    public function test_a_payment_linked_row_is_still_protected(): void
    {
        $entry = BusinessEntry::create([
            'currency_code' => 'IRT', 'direction' => 'in', 'kind' => 'revenue',
            'amount' => 500000, 'occurred_at' => now()->toDateString(),
            'source_type' => \App\Models\Payment::class, 'source_id' => 1,
        ]);

        $admin = \App\Models\User::create([
            'name' => 'مدیر', 'email' => 'g'.random_int(1, 999999).'@x.com',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);

        $this->actingAs($admin, 'web')
            ->post('/admin/finance/'.$entry->id.'/delete')
            ->assertSessionHasErrors('entry');

        $this->assertNotNull($entry->fresh(), 'ردیفِ پرداخت حذف شد');
    }

    /**
     * 🔴 نرخِ ارز یک بار برای کلِ اجرا گرفته می‌شود، نه یک بار به‌ازای هر سرور.
     *
     * `ExchangeRate::refresh()` روی شکست هیچ‌چیز کش نمی‌کند، پس با منبعِ خاموش
     * هر فراخوان یک `timeout(12)->retry(2)` است. ده سرور یعنی دقایقی انسداد
     * **داخلِ `schedule:run`** و ایستادنِ بقیهٔ کارهای آن دقیقه.
     */
    public function test_the_rate_is_fetched_once_for_the_whole_run(): void
    {
        Setting::put('pricing_rate_override', '0');
        Cache::flush();
        Http::fake(['*' => Http::response('', 500)]);

        $this->travelTo(Carbon::parse('2026-08-20 09:00:00'));

        foreach (range(1, 5) as $i) {
            $this->server(['monthly_cost' => 3990, 'cost_currency' => 'EUR']);
        }

        $this->postRent();

        $calls = count(Http::recorded());

        $this->assertLessThanOrEqual(3, $calls,
            'نرخ به‌ازای هر سرور دوباره گرفته شد ('.$calls.' فراخوان)');
    }

    /** `--dry` هیچ عارضه‌ای ندارد — حتی ردیفِ خطا و گلوگاه نمی‌نویسد */
    public function test_the_dry_run_writes_no_incident(): void
    {
        Setting::put('pricing_rate_override', '0');
        Cache::flush();
        Http::fake(['*' => Http::response('', 500)]);
        \App\Support\ErrorTracker::clear();

        $this->travelTo(Carbon::parse('2026-08-20 09:00:00'));
        $this->server(['monthly_cost' => 3990, 'cost_currency' => 'EUR']);

        $this->postRent(['--dry' => true]);

        $notes = collect(\App\Support\ErrorTracker::recent(50))
            ->filter(fn ($r) => str_contains(json_encode($r, JSON_UNESCAPED_UNICODE), 'اجارهٔ سرور'));

        $this->assertCount(0, $notes,
            'اجرای آزمایشی ردیفِ خطا نوشت و گلوگاهِ هشدارِ واقعی را سوزاند');
    }

    /**
     * پیش‌نمایش باید «از قبل بود» را از «ثبت می‌شود» تشخیص دهد.
     *
     * نسخهٔ اول همیشه «ثبت می‌شود» می‌گفت، یعنی دقیقاً همان سؤالی که مدیر با
     * `--dry` می‌پرسد بی‌جواب می‌مانْد.
     */
    public function test_the_dry_run_reports_an_already_posted_month_as_such(): void
    {
        $this->travelTo(Carbon::parse('2026-08-20 09:00:00'));
        $this->server();

        $this->postRent();

        $this->artisan('servers:post-rent', ['--dry' => true])
            ->expectsOutputToContain('از قبل بود: 1')
            ->assertSuccessful();
    }
}

<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * تقویم زیرِ بارِ واقعی — یک ماهِ شلوغ، نه یک ماهِ نمایشی.
 *
 * تست‌های موجود هر لایه را با **یک** ردیف می‌سنجند و همه سبزند؛ ولی ماهی که
 * مدیر واقعاً می‌بیند ده‌ها سررسید در چند روز دارد. سه چیز فقط زیرِ بار
 * می‌شکنند و هیچ‌کدام خطا تولید نمی‌کنند:
 *
 *   ۱) خانهٔ روز فقط ۳ رویداد جا می‌دهد — بقیه باید **در payload** بمانند
 *      وگرنه کشوی جزئیاتِ روز همان ۳ تا را نشان می‌دهد و ۱۲ سررسید ناپدید
 *      می‌شود، با کدِ ۲۰۰.
 *   ۲) سقفِ هر لایه باید **گزارش** شود؛ لایهٔ بریده‌شده نباید شبیهِ لایهٔ کامل
 *      باشد.
 *   ۳) شمارِ پرس‌وجو نباید با شمارِ رویداد رشد کند — روی همان دیتابیسی که
 *      نشست و کش هم رویش است.
 */
class AdminCalendarLoadTest extends TestCase
{
    use RefreshDatabase;

    /** ۱۵ مرداد ۱۴۰۵ = ۲۰۲۶-۰۸-۰۶ (اولِ مرداد = ۲۰۲۶-۰۷-۲۳) */
    private const BUSY_DAY = '2026-08-06';

    private function staff(): User
    {
        return User::create([
            'name' => 'مدیر', 'email' => 'load'.random_int(1, 999999).'@x.com',
            'password' => bcrypt('secret1234'), 'role' => 'admin',
        ]);
    }

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'c'.random_int(1, 999999).'@example.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('oldsecret'), 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    /** فاکتورهای پرداخت‌نشده، همه روی یک روز */
    private function invoices(Customer $c, int $n, string $day = self::BUSY_DAY): void
    {
        for ($i = 0; $i < $n; $i++) {
            Invoice::create([
                'customer_id' => $c->id, 'currency_code' => 'IRT',
                'subtotal' => 100000 * ($i + 1), 'tax' => 0, 'total' => 100000 * ($i + 1),
                'status' => 'unpaid', 'due_at' => $day.' 12:00:00',
            ]);
        }
    }

    /** دامنه‌های در آستانهٔ تمدید، همه روی یک روز */
    private function domains(Customer $c, int $n, string $day = self::BUSY_DAY): void
    {
        // ⚠️ `domains` روی (domain, registrar) یکتاست — نامِ تکراری در فراخوانِ
        // دوم تست را با خطای دیتابیس می‌کُشد، نه با ادعای شکست‌خورده.
        static $seq = 0;

        for ($i = 0; $i < $n; $i++, $seq++) {
            Domain::create([
                'customer_id' => $c->id, 'domain' => 'load'.$seq.'.test', 'sld' => 'load'.$seq,
                'tld' => 'test', 'status' => 'active', 'expires_at' => $day.' 10:00:00',
            ]);
        }
    }

    /**
     * 🔴 خانه ۳ تا نشان می‌دهد، ولی payload باید هر ۱۵ تا را داشته باشد.
     *
     * `MAX_IN_CELL = 3` در `admin-calendar.js` فقط **نمایش** را می‌بُرد. اگر
     * روزی کسی همان بریدن را به سمتِ سرور ببرد تا «سبک‌تر» شود، کشوی جزئیاتِ
     * روز هم ۱۲ سررسید را از دست می‌دهد — و چون خانه همان «+۱۲ مورد دیگر» را
     * نشان می‌دهد، صفحه دقیقاً مثلِ حالتِ سالم به‌نظر می‌رسد.
     */
    public function test_a_crowded_day_keeps_every_event_in_the_payload(): void
    {
        $c = $this->customer();
        $this->invoices($c, 10);
        $this->domains($c, 5);

        $res = $this->actingAs($this->staff(), 'web')
            ->getJson('/admin/calendar/events?y=1405&m=5')->assertOk()->json();

        $onBusyDay = collect($res['events'])->where('date', '1405-05-15');

        $this->assertCount(15, $onBusyDay,
            'خانه ۳ تا نشان می‌دهد؛ payload باید هر ۱۵ تا را داشته باشد وگرنه کشوی روز دروغ می‌گوید');

        $this->assertSame(10, $onBusyDay->where('type', 'payment_due')->count());
        $this->assertSame(5, $onBusyDay->where('type', 'domain_renewal')->count());

        // و هیچ‌کدام بی‌صدا بریده نشده باشد
        $this->assertSame([], $res['truncated']);
        $this->assertSame([], $res['failures']);
    }

    /** بریدنِ نمایش فقط در مرورگر است و «+N مورد دیگر» را هم نشان می‌دهد */
    public function test_the_cell_cap_lives_in_the_browser_and_announces_itself(): void
    {
        $js = file_get_contents(public_path('assets/js/admin-calendar.js'));

        $this->assertStringContainsString('MAX_IN_CELL = 3', $js);
        $this->assertStringContainsString('evs.slice(0, MAX_IN_CELL)', $js);
        $this->assertStringContainsString('مورد دیگر', $js,
            'خانهٔ پر باید بگوید چند تا را نشان نداده؛ بی‌آن، ۱۲ سررسید بی‌هیچ نشانه‌ای غیب می‌شود');
    }

    /**
     * 🔴 لایهٔ بریده‌شده نباید شبیهِ لایهٔ کامل باشد.
     *
     * سقف واقعی ۳۰۰ است؛ این‌جا پایین آورده می‌شود تا خودِ مسیرِ بریدن اجرا
     * شود (تراتِ `CapsLayerRows` سقف را **در پرس‌وجو** می‌گذارد، پس این تست
     * همان کدِ پروداکشن را می‌دواند، نه یک شاخهٔ تستی).
     */
    public function test_an_overflowing_layer_reports_the_cut_instead_of_lying(): void
    {
        config()->set('calendar.max_events_per_layer', 5);

        $c = $this->customer();
        $this->invoices($c, 8);

        $res = $this->actingAs($this->staff(), 'web')
            ->getJson('/admin/calendar/events?y=1405&m=5')->assertOk()->json();

        $this->assertContains('payment_due', $res['truncated'],
            'بریدنِ خاموش یعنی مدیر ۵ سررسید می‌بیند و باور می‌کند همین ۵ تاست');

        $this->assertLessThanOrEqual(5, collect($res['events'])->where('type', 'payment_due')->count());
    }

    /**
     * 🔴 شمارِ پرس‌وجو نباید با شمارِ رویداد رشد کند.
     *
     * هر provider یک پرس‌وجو دارد؛ ولی `CalendarItem` عنوان را از رابطهٔ
     * مشتری/سرویس می‌سازد و یک `$domain->customer->name` بی‌eager-load یعنی
     * ماهِ شلوغ ده‌ها کوئریِ اضافه — روی همان دیتابیسی که نشست هم رویش است.
     * ماهِ خلوت این را هرگز نشان نمی‌دهد، چون آن‌جا N برابرِ ۱ است.
     */
    public function test_a_busy_month_does_not_multiply_queries(): void
    {
        $staff = $this->staff();
        $c = $this->customer();

        $count = function () use ($staff): int {
            $n = 0;
            DB::listen(function () use (&$n) { $n++; });
            $this->actingAs($staff, 'web')
                ->getJson('/admin/calendar/events?y=1405&m=5')->assertOk();

            return $n;
        };

        $this->invoices($c, 5);
        $this->domains($c, 5);
        $light = $count();

        $this->invoices($c, 40, '2026-08-10');
        $this->domains($c, 40, '2026-08-11');
        $heavy = $count();

        $this->assertLessThanOrEqual($light + 2, $heavy,
            "ماهِ خلوت {$light} کوئری داشت و ماهِ شلوغ {$heavy} — یعنی جایی N+1 هست");
    }
}

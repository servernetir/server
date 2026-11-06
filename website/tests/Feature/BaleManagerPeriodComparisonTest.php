<?php

namespace Tests\Feature;

use App\Services\Bale\Management\BaleManagerDailyDigest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BaleManagerPeriodComparisonTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function digest(array $sections): BaleManagerDailyDigest
    {
        return new class($sections) extends BaleManagerDailyDigest {
            public function __construct(private array $fixtures) {}
            public function section(string $topic): array { return $this->fixtures[$topic] ?? ['available' => false]; }
        };
    }

    public function test_direction_absolute_change_and_percentage_use_recent_thirty_days_against_the_independent_prior_period(): void
    {
        foreach ([
            [500, 580, 'کاهش: 80 کاربر کمتر (13.8٪ افت)'],
            [580, 500, 'افزایش: 80 کاربر بیشتر (16.0٪ رشد)'],
            [500, 500, 'بدون تغییر: 0 کاربر (0.0٪)'],
            [5, 0, 'افزایش: 5 کاربر بیشتر؛ دورهٔ قبل صفر است، درصد قابل محاسبه نیست'],
            [0, 0, 'بدون تغییر: 0 کاربر؛ هر دو دوره صفر'],
            [0, 580, 'کاهش: 580 کاربر کمتر (100.0٪ افت)'],
        ] as [$current, $previous, $expected]) {
            $text = $this->digest(['stats' => ['available' => true, 'signups_24h' => 7, 'signups_30d' => $current,
                'signups_previous_30d' => $previous, 'delivered_24h' => null, 'delivered_30d' => null,
                'delivered_previous_30d' => null, 'customers_total' => 999]])->detail('stats');
            $this->assertStringContainsString('تغییر ثبت‌نام: *'.$expected.'* ', $text);
            $this->assertStringContainsString('یک ماه اخیر (۳۰ روز): ثبت‌نام '.number_format($current).' کاربر', $text);
            $this->assertStringContainsString('ماه پیش از آن (۳۰ روز): ثبت‌نام '.number_format($previous).' کاربر', $text);
            $this->assertStringContainsString('کل کاربران: 999 کاربر', $text);
        }
        Http::assertNothingSent();
    }

    public function test_missing_count_does_not_invent_zero_or_draw_a_directional_conclusion(): void
    {
        foreach ([[null, 580], [500, null], [null, null]] as [$current, $previous]) {
            $text = $this->digest(['stats' => ['available' => true, 'signups_24h' => 7, 'signups_30d' => $current,
                'signups_previous_30d' => $previous, 'delivered_24h' => null, 'delivered_30d' => null,
                'delivered_previous_30d' => null, 'customers_total' => 999]])->detail('stats');
            $line = array_values(array_filter(explode("\n", $text), fn ($line) => str_starts_with($line, 'تغییر ثبت‌نام:')))[0];
            $this->assertStringContainsString('مقایسه نامشخص', $line);
            foreach (['افزایش', 'کاهش', 'بدون تغییر', '٪', '0 کاربر'] as $claim) { $this->assertStringNotContainsString($claim, $line); }
        }
        Http::assertNothingSent();
    }

    public function test_google_uses_three_source_periods_and_compares_each_metric_without_summing_unique_users(): void
    {
        $period = fn (array $metrics, string $from, string $to, string $timezone) => ['status' => 'known',
            'start_date' => $from, 'end_date' => $to, 'timezone' => $timezone] + $metrics;
        $traffic = ['available' => true, 'as_of' => '2026-10-31T08:55:00Z',
            'ga4' => ['periods' => [
                'yesterday' => $period(['total_users' => 7, 'sessions' => 99], '2026-10-30', '2026-10-30', 'Asia/Tehran'),
                'recent_30d' => $period(['total_users' => 500, 'sessions' => 999], '2026-10-01', '2026-10-30', 'Asia/Tehran'),
                'previous_30d' => $period(['total_users' => 580, 'sessions' => 1999], '2026-09-01', '2026-09-30', 'Asia/Tehran'),
            ]], 'gsc' => ['periods' => [
                'yesterday' => $period(['clicks' => 4, 'impressions' => 44], '2026-10-29', '2026-10-29', 'America/Los_Angeles'),
                'recent_30d' => $period(['clicks' => 100, 'impressions' => 0], '2026-09-30', '2026-10-29', 'America/Los_Angeles'),
                'previous_30d' => $period(['clicks' => 200, 'impressions' => 0], '2026-08-31', '2026-09-29', 'America/Los_Angeles'),
            ]]];
        $text = $this->digest(['traffic' => $traffic])->detail('traffic');
        $this->assertStringContainsString('دیروز منبع: 7 کاربر یکتا (1405/08/08 تا 1405/08/08)', $text);
        $this->assertStringContainsString('یک ماه اخیر (۳۰ روز): 500 کاربر یکتا (1405/07/09 تا 1405/08/08)', $text);
        $this->assertStringContainsString('ماه پیش از آن (۳۰ روز): 580 کاربر یکتا (1405/06/10 تا 1405/07/08)', $text);
        $this->assertStringContainsString('تغییر کاربر یکتا: *کاهش: 80 کاربر یکتا کمتر (13.8٪ افت)* ', $text);
        $this->assertStringContainsString('تغییر کلیک: *کاهش: 100 کلیک کمتر (50.0٪ افت)* ', $text);
        $this->assertStringContainsString('تغییر نمایش: *بدون تغییر: 0 نمایش؛ هر دو دوره صفر* ', $text);
        $this->assertStringContainsString('America/Los_Angeles', $text);
        $this->assertStringContainsString('نه ۲۴ساعت شناور', $text);
        foreach (['1,080 کاربر', '999 کاربر یکتا', 'تقویمی قبل', 'http'] as $claim) { $this->assertStringNotContainsString($claim, $text); }
        $summary = $this->digest([])->text(['observed_at' => '2026-10-31T08:55:00Z', 'sections' => ['traffic' => $traffic]]);
        $this->assertLessThanOrEqual(3500, mb_strlen($summary));
        $this->assertStringContainsString('تغییر کلیک:', $summary);
        $this->assertStringContainsString('13.8٪ افت', $summary);
        Http::assertNothingSent();
    }

    public function test_old_or_unknown_google_period_cannot_be_relabelled_as_a_new_thirty_day_window(): void
    {
        $text = $this->digest(['traffic' => ['available' => true,
            'ga4' => ['periods' => ['recent_30d' => ['status' => 'unknown', 'total_users' => 500],
                'previous_month' => ['status' => 'known', 'total_users' => 580]]],
            'gsc' => ['periods' => []]]])->detail('traffic');
        $this->assertStringContainsString('یک ماه اخیر (۳۰ روز): نامشخص کاربر یکتا', $text);
        $this->assertStringContainsString('ماه پیش از آن (۳۰ روز): نامشخص کاربر یکتا', $text);
        $this->assertStringContainsString('تغییر کاربر یکتا: مقایسه نامشخص', $text);
        $this->assertStringNotContainsString('13.8', $text);
        $this->assertStringNotContainsString('580 کاربر', $text);
        Http::assertNothingSent();
    }
}

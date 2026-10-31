<?php

namespace Tests\Feature;

use App\Services\Bale\Management\BaleManagerDailyDigest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BaleManagerDailyDigestLayoutTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function snapshot(array $sections): array
    {
        return ['observed_at' => '2026-10-31T08:55:00Z', 'sections' => $sections];
    }

    private function fixtureDigest(array $sections): BaleManagerDailyDigest
    {
        return new class($sections) extends BaleManagerDailyDigest {
            public function __construct(private array $fixtures) {}
            public function section(string $topic): array { return $this->fixtures[$topic] ?? ['available' => false]; }
        };
    }

    public function test_summary_has_separate_timestamp_spaced_bold_headings_and_readable_currency_rows(): void
    {
        $text = app(BaleManagerDailyDigest::class)->text($this->snapshot([
            'stats' => ['available' => true, 'signups_24h' => 13, 'signups_previous_month' => 277, 'customers_total' => 642,
                'delivered_24h' => 18, 'delivered_previous_month' => 131, 'month_label' => '2026-09 میلادی'],
            'fx' => ['available' => true, 'rows' => ['USD' => ['rate_toman' => 281580, 'stale' => false],
                'EUR' => ['rate_toman' => 313060, 'stale' => true], 'TRY' => null]],
        ]));

        $this->assertStringStartsWith("📋 *گزارش روزانه مدیریت* \n1405/08/09 12:25 تهران\n\n📊 *مشتری و تحویل* \n", $text);
        $this->assertStringContainsString('ثبت‌نام: ۲۴ساعت 13 کاربر · ماه قبل 277 کاربر · کل 642 کاربر', $text);
        $this->assertStringContainsString('تحویل ثبت‌شده: ۲۴ساعت 18 سرویس · ماه قبل 131 سرویس', $text);
        $this->assertStringContainsString("\n\n💱 *نرخ ارز* \n• USD: 281,580 تومان\n• EUR: 313,060 تومان (قدیمی)\n• TRY: نامشخص", $text);
        preg_match_all('/\*[^*]+\*/u', $text, $headings, PREG_OFFSET_CAPTURE);
        foreach ($headings[0] as [$heading, $offset]) {
            $this->assertSame(' ', substr($text, $offset - 1, 1));
            $this->assertSame(' ', substr($text, $offset + strlen($heading), 1));
        }
        $this->assertCount(13, $headings[0]);
        $this->assertStringContainsString('بازهٔ ماه قبل (تقویم میلادی): 1405/06/10 تا 1405/07/08', $text);
        $this->assertDoesNotMatchRegularExpression('/\b20\d{2}-\d{2}/', $text);
        Http::assertNothingSent();
    }

    public function test_known_balances_get_their_own_rows_and_unknown_names_are_grouped_once_and_wrapped(): void
    {
        $names = ['Hetzner Cloud', 'Aeza', 'ArvanCloud (ابرآروان)', 'OVHcloud', 'Proxmox (Tehran)',
            'SaladCloud (GPU)', 'Hetzner Robot (Dedicated)', 'LightNode'];
        $rows = [
            ['label' => 'Openprovider', 'status' => 'known', 'value' => '70.31', 'currency' => 'USD', 'observed_at' => '2026-10-31T07:43:00Z'],
            ['label' => 'VPS.TC', 'status' => 'known', 'value' => '1000.02', 'currency' => 'TRY', 'observed_at' => '2026-10-31T07:43:00Z', 'stale' => true],
        ];
        foreach ($names as $name) { $rows[] = ['label' => $name, 'status' => 'unknown', 'value' => null]; }
        $text = app(BaleManagerDailyDigest::class)->text($this->snapshot(['balances' => ['available' => true, 'rows' => $rows]]));
        $this->assertStringContainsString("🏦 *موجودی زیرساخت* \n• Openprovider: 70.31 USD؛ مشاهده 1405/08/09 11:13 تهران\n• VPS.TC: 1,000.02 TRY (قدیمی)؛ مشاهده 1405/08/09 11:13 تهران\nنامشخص: ", $text);
        $balances = explode("\n\n📈", explode("🏦 *موجودی زیرساخت* \n", $text)[1])[0];
        foreach ($names as $name) { $this->assertSame(1, substr_count($balances, $name)); }
        $this->assertSame(1, substr_count($balances, 'نامشخص'));
        $this->assertStringNotContainsString('0 USD', $balances);
        $this->assertStringNotContainsString('0 EUR', $balances);
        $this->assertStringContainsString("،\n", $balances);
        foreach (explode("\n", $balances) as $line) { $this->assertLessThanOrEqual(100, mb_strlen($line)); }
        Http::assertNothingSent();
    }

    public function test_balance_details_keep_each_unknown_reason_and_full_observation_date(): void
    {
        $digest = new class extends BaleManagerDailyDigest {
            protected function balances(): array
            {
                return ['available' => true, 'rows' => [
                    ['label' => 'Fixture One', 'status' => 'unknown', 'value' => null, 'reason' => 'not_configured'],
                    ['label' => 'Fixture Two', 'status' => 'unknown', 'value' => null, 'reason' => 'observation_expired'],
                    ['label' => 'Fixture Three', 'status' => 'known', 'value' => '0.00', 'currency' => 'EUR', 'observed_at' => '2026-10-31T07:43:00Z'],
                ]];
            }
        };
        $text = $digest->detail('balances');
        $this->assertStringContainsString("\n• Fixture One: نامشخص؛ اتصال حساب کامل نیست\n\n• Fixture Two: نامشخص؛ مشاهده قبلی قدیمی است\n\n", $text);
        $this->assertStringContainsString('• Fixture Three: 0.00 EUR؛ مشاهده 1405/08/09 11:13 تهران', $text);
        Http::assertNothingSent();
    }

    public function test_long_summary_keeps_every_section_heading_footer_and_complete_markdown_within_limit(): void
    {
        $rows = [];
        for ($i = 0; $i < 60; $i++) { $rows[] = ['label' => 'Fixture '.$i.' '.str_repeat('نام بلند ', 25), 'status' => 'unknown', 'value' => null]; }
        $text = app(BaleManagerDailyDigest::class)->text($this->snapshot(['balances' => ['available' => true, 'rows' => $rows]]));
        $this->assertLessThanOrEqual(3500, mb_strlen($text));
        foreach (['مشتری و تحویل', 'دریافت موفق', 'دامنه', 'پیگیری هرمس', 'ایمیل', 'تقویم', 'استعلام', 'محتوا',
            'ورودی سایت', 'نرخ ارز', 'موجودی زیرساخت', 'افزایش هزینه تأمین‌کننده'] as $label) {
            $this->assertStringContainsString(' *'.$label.'* ', $text);
        }
        $this->assertSame(26, substr_count($text, '*'));
        $this->assertStringContainsString('… جزئیات با دکمهٔ همین بخش.', $text);
        $this->assertStringEndsWith('مقدار ناموجود صفر فرض نشده است.', $text);
        Http::assertNothingSent();
    }

    public function test_provider_labels_cannot_create_markdown_headings_or_links_in_the_summary(): void
    {
        $text = app(BaleManagerDailyDigest::class)->text($this->snapshot(['balances' => ['available' => true, 'rows' => [
            ['label' => '*Stored* _value_ [link](https://example.test)', 'status' => 'unknown', 'value' => null],
        ]]]));
        $this->assertStringContainsString('＊Stored＊ ＿value＿ ［link］(https://example.test)', $text);
        $this->assertSame(26, substr_count($text, '*'));
        $this->assertStringNotContainsString('[link]', $text);
        Http::assertNothingSent();
    }

    public function test_payment_details_group_exact_decimal_strings_with_explicit_units_and_jalali_window(): void
    {
        $digest = $this->fixtureDigest(['payments' => ['available' => true, 'count' => 4,
            'start_at' => '2026-10-30T21:00:00Z', 'end_at' => '2026-10-31T21:00:00Z',
            'currencies' => [
                ['currency' => 'IRT', 'amount_minor' => 25113053, 'count' => 1],
                ['currency' => 'IRR', 'amount_minor' => 123456, 'count' => 1],
                ['currency' => 'USD', 'amount_minor' => 123456789012345, 'count' => 1],
                ['currency' => 'USDT', 'amount_minor' => 1000001, 'count' => 1],
            ]]]);
        $text = $digest->detail('payments');
        $this->assertStringStartsWith('💰 *دریافت موفق* ', $text);
        $this->assertStringContainsString('۲۴ ساعت گذشته: 4 پرداخت موفق', $text);
        foreach (['25,113,053 تومان (IRT)', '123,456 ریال (IRR)', '1,234,567,890,123.45 USD', '1.000001 USDT'] as $amount) {
            $this->assertStringContainsString($amount, $text);
        }
        $this->assertStringContainsString('از 1405/08/09 00:30 تا پیش از 1405/08/10 00:30 تهران', $text);
        $this->assertStringContainsString('این عدد درآمد فروش یا سود نیست', $text);
        $this->assertDoesNotMatchRegularExpression('/\b20\d{2}-\d{2}/', $text);
        Http::assertNothingSent();
    }

    public function test_mail_items_are_separate_safe_cards_with_tehran_jalali_timestamps(): void
    {
        $digest = $this->fixtureDigest(['mail' => ['available' => true, 'received_24h' => 2, 'pending' => 8,
            'items' => [
                ['id' => 71, 'subject' => '*Subject*', 'summary' => '[Unsafe](https://example.test)', 'received_at' => '2026-10-30T21:00:00Z'],
                ['id' => 72, 'subject' => 'Second', 'summary' => 'Short summary', 'received_at' => '2026-10-31T07:43:00Z'],
            ]]]);
        $text = $digest->detail('mail');
        $this->assertStringContainsString("نامه تازه در ۲۴ ساعت: 2 نامه\nکل صف پاسخ: 8 نامه", $text);
        $this->assertStringContainsString("✉️  *نامه #71* \nعنوان: ＊Subject＊\nخلاصه: ［Unsafe］(https://example.test)\nدریافت: 1405/08/09 00:30 تهران\n\n✉️  *نامه #72* ", $text);
        $this->assertStringNotContainsString('[Unsafe]', $text);
        Http::assertNothingSent();
    }

    public function test_all_detail_views_use_spaced_bold_headings_units_and_jalali_dates_without_changing_month_basis(): void
    {
        $digest = $this->fixtureDigest([
            'stats' => ['available' => true, 'signups_24h' => 2, 'signups_previous_month' => 13, 'customers_total' => 40,
                'delivered_24h' => 3, 'delivered_previous_month' => 17, 'signups_30d' => 14, 'delivered_30d' => 18, 'month_label' => '2026-09 میلادی'],
            'hermes' => ['available' => true, 'task_counts' => ['waiting_owner' => 3, 'waiting_customer' => 20, 'investigating' => 1, 'resolved' => 4],
                'alerts' => [['code' => 'wallet_runway_low'], ['code' => 'wallet_runway_low']], 'last_run_at' => 1793442300],
            'domains' => ['available' => true, 'registered_24h' => 3, 'renewed_24h_recorded' => 1],
            'fx' => ['available' => true, 'rows' => ['USD' => ['rate_toman' => 281580, 'stale' => true, 'at' => '2026-10-31T07:43:00Z']]],
        ]);
        $stats = $digest->detail('stats');
        $this->assertStringContainsString(" *ثبت‌نام کاربران* \n۲۴ ساعت گذشته: 2 کاربر\nماه قبل: 13 کاربر\nکل کاربران: 40 کاربر", $stats);
        $this->assertStringContainsString('بازهٔ ماه قبل (تقویم میلادی): 1405/06/10 تا 1405/07/08', $stats);
        $hermes = $digest->detail('hermes');
        $this->assertStringContainsString(' *منتظر تصمیم: 3 مورد* ', $hermes);
        $this->assertStringContainsString('منتظر مشتری: 20 مورد', $hermes);
        $this->assertStringContainsString(' *هشدار فعال: 2 مورد* ', $hermes);
        $this->assertStringContainsString('• 2 مورد: اعتبار طبق نرخ فعلی زیر ۲۴ساعت است', $hermes);
        $this->assertStringContainsString('ثبت در ۲۴ ساعت گذشته: 3 دامنه', $digest->detail('domains'));
        $this->assertStringContainsString('USD: 281,580 تومان (قدیمی)؛ مشاهده 1405/08/09 11:13 تهران', $digest->detail('fx'));
        foreach (['stats', 'hermes', 'mail', 'traffic', 'fx', 'balances', 'domains', 'payments', 'calendar'] as $topic) {
            $text = $digest->detail($topic);
            $this->assertDoesNotMatchRegularExpression('/\b20\d{2}-\d{2}/', $text);
            preg_match_all('/\*[^*]+\*/u', $text, $matches, PREG_OFFSET_CAPTURE);
            $this->assertNotEmpty($matches[0]);
            foreach ($matches[0] as [$token, $offset]) {
                $this->assertSame(' ', substr($text, $offset - 1, 1));
                $this->assertSame(' ', substr($text, $offset + strlen($token), 1));
            }
        }
        Http::assertNothingSent();
    }

    public function test_calendar_summary_keeps_seven_day_actions_and_partial_coverage_distinct_from_zero(): void
    {
        $calendar = ['available' => true, 'partial' => true, 'count' => null, 'observed_count' => 0,
            'count_7d' => null, 'observed_count_7d' => 3, 'content_count' => 2, 'omitted_count' => 0,
            'coverage_labels' => ['پرداخت', 'محتوا'], 'failed_layers' => ['هاست'], 'items' => [
                ['title' => 'Supplier invoice', 'at' => '2026-11-02T06:30:00Z', 'money' => '12.50 USD', 'type_label' => 'پرداخت'],
            ]];
        $text = $this->fixtureDigest(['calendar' => $calendar])->detail('calendar');
        $this->assertStringContainsString('امروز و فردا: نامشخص رویداد؛ حداقل 0 رویداد مشاهده‌شده', $text);
        $this->assertStringContainsString('۷ روز آینده: نامشخص رویداد؛ حداقل 3 رویداد مشاهده‌شده', $text);
        $this->assertStringContainsString('برنامهٔ محتوا در ۷ روز: حداقل 2 مورد مشاهده‌شده', $text);
        $this->assertStringContainsString('زمان: 1405/08/11 10:00 تهران · پرداخت', $text);
        $this->assertStringContainsString('منابع ناموفق: هاست', $text);
        $this->assertStringContainsString(' *پوشش ناقص* ', $text);
        $this->assertStringContainsString(' *Supplier invoice* ', $text);
        Http::assertNothingSent();
    }

    public function test_report_jalali_date_is_independent_of_site_locale_at_tehran_new_year_boundary(): void
    {
        $this->app->setLocale('en');
        $text = app(BaleManagerDailyDigest::class)->text(['observed_at' => '2026-03-20T21:00:00Z', 'sections' => []]);
        $this->assertStringContainsString('1405/01/01 00:30 تهران', $text);
        $this->assertDoesNotMatchRegularExpression('/\b2026[-\/]/', $text);
        Http::assertNothingSent();
    }

}

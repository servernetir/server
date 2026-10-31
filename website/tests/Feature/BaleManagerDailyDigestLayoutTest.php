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

    public function test_summary_has_separate_timestamp_spaced_bold_headings_and_readable_currency_rows(): void
    {
        $text = app(BaleManagerDailyDigest::class)->text($this->snapshot([
            'stats' => ['available' => true, 'signups_24h' => 13, 'signups_previous_month' => 277, 'customers_total' => 642,
                'delivered_24h' => 18, 'delivered_previous_month' => 131, 'month_label' => '2026-09 میلادی'],
            'fx' => ['available' => true, 'rows' => ['USD' => ['rate_toman' => 281580, 'stale' => false],
                'EUR' => ['rate_toman' => 313060, 'stale' => true], 'TRY' => null]],
        ]));

        $this->assertStringStartsWith("📋 *گزارش روزانه مدیریت* \n2026-10-31 12:25 تهران\n\n📊 *مشتری و تحویل* \n", $text);
        $this->assertStringContainsString('ثبت‌نام: ۲۴ساعت 13 · ماه قبل 277 · کل 642', $text);
        $this->assertStringContainsString('تحویل ثبت‌شده: ۲۴ساعت 18 · ماه قبل 131 (2026-09 میلادی)', $text);
        $this->assertStringContainsString("\n\n💱 *نرخ ارز* \n• USD: 281,580 تومان\n• EUR: 313,060 تومان (قدیمی)\n• TRY: نامشخص", $text);
        preg_match_all('/\*[^*]+\*/u', $text, $headings, PREG_OFFSET_CAPTURE);
        foreach ($headings[0] as [$heading, $offset]) {
            $this->assertSame(' ', substr($text, $offset - 1, 1));
            $this->assertSame(' ', substr($text, $offset + strlen($heading), 1));
        }
        $this->assertCount(13, $headings[0]);
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
        $this->assertStringContainsString("🏦 *موجودی زیرساخت* \n• Openprovider: 70.31 USD؛ مشاهده 11:13\n• VPS.TC: 1000.02 TRY (قدیمی)؛ مشاهده 11:13\nنامشخص: ", $text);
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
        $this->assertStringContainsString("\n• Fixture One: نامشخص؛ اتصال حساب کامل نیست\n• Fixture Two: نامشخص؛ مشاهده قبلی قدیمی است\n", $text);
        $this->assertStringContainsString('• Fixture Three: 0.00 EUR؛ مشاهده 2026-10-31 11:13', $text);
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
}

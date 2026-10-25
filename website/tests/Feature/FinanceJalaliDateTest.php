<?php

namespace Tests\Feature;

use App\Models\BusinessEntry;
use App\Models\FinanceTaxProfile;
use App\Models\User;
use App\Services\Finance\FinanceFilter;
use App\Support\FinanceDates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinanceJalaliDateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-25 08:00:00', 'UTC'));
        $this->actingAs(User::create(['name' => 'Finance test', 'email' => Str::uuid().'@example.test', 'password' => bcrypt('test'), 'role' => 'admin']));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_picker_opens_the_selected_jalali_month_and_supplies_canonical_dates(): void
    {
        $this->getJson('/admin/jdate?date=2026-10-25')->assertOk()
            ->assertJsonPath('jy', 1405)->assertJsonPath('jm', 8)
            ->assertJsonPath('selected_iso', '2026-10-25')->assertJsonPath('selected_jalali', '1405-08-03')
            ->assertJsonPath('selected_label', '۱۴۰۵/۰۸/۰۳')->assertJsonPath('cells.0.iso', '2026-10-23');
        $this->getJson('/admin/jdate?y=1403&m=12')->assertOk()->assertJsonCount(30, 'cells')
            ->assertJsonPath('cells.29.iso', '2025-03-20');
    }

    public function test_previous_farvardin_rolls_to_esfand_and_extreme_month_input_is_rejected(): void
    {
        $this->getJson('/admin/jdate?y=1405&m=0')->assertOk()->assertJsonPath('jy', 1404)->assertJsonPath('jm', 12);
        $this->getJson('/admin/jdate?y=1405&m=13')->assertOk()->assertJsonPath('jy', 1406)->assertJsonPath('jm', 1);
        $this->getJson('/admin/jdate?y=1405&m=999999999')->assertStatus(422);
        $this->getJson('/admin/jdate?y[]=1405&m=1')->assertStatus(422);
    }

    public function test_manual_persian_digits_and_leap_day_are_validated_by_the_server(): void
    {
        $this->getJson('/admin/jdate?'.http_build_query(['jalali' => '۱۴۰۳/۱۲/۳۰']))->assertOk()->assertJsonPath('selected_iso', '2025-03-20');
        $this->getJson('/admin/jdate?'.http_build_query(['jalali' => '۱۴۰۵/۱۲/۳۰']))->assertStatus(422);
        $this->assertSame('2026-10-25', FinanceDates::iso('١٤٠٥/٠٨/٠٣'));
        $this->assertSame('2026-10-25', FinanceDates::iso('2026-10-25'));
    }

    public function test_jalali_filters_select_the_same_rows_as_iso_dates_and_preserve_picker_values(): void
    {
        foreach (['2026-10-22', '2026-10-23', '2026-10-25', '2026-10-26'] as $day) {
            BusinessEntry::create(['currency_code' => 'IRT', 'direction' => 'out', 'kind' => 'expense', 'category' => 'other', 'amount' => 100, 'occurred_at' => $day]);
        }
        $this->get('/admin/finance?'.http_build_query(['from' => '۱۴۰۵/۰۸/۰۱', 'to' => '۱۴۰۵/۰۸/۰۳']))
            ->assertOk()->assertViewHas('summary', fn ($s) => $s['expense'] === 200)
            ->assertSee('data-jdate', false)->assertSee('۱۴۰۵/۰۸/۰۱')->assertDontSee('type="date"', false);
        $this->get('/admin/finance?from=1405-12-30')->assertSessionHasErrors('from');
    }

    public function test_current_month_and_year_shortcuts_are_jalali(): void
    {
        $month = FinanceFilter::fromRequest(Request::create('/', 'GET', ['range' => 'month']));
        $this->assertSame('2026-10-23', $month->from->toDateString());
        $this->assertSame('2026-11-21', $month->to->toDateString());
        $year = FinanceFilter::fromRequest(Request::create('/', 'GET', ['range' => 'year']));
        $this->assertSame('2026-03-21', $year->from->toDateString());
        $this->assertSame('2027-03-20', $year->to->toDateString());
    }

    public function test_manual_finance_date_and_tax_period_are_stored_as_canonical_dates(): void
    {
        $this->post('/admin/finance', ['kind' => 'expense', 'amount' => 1000, 'category' => 'other', 'occurred_at' => '۱۴۰۵/۰۸/۰۲', 'note' => 'Jalali input'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('2026-10-24', BusinessEntry::sole()->occurred_at->toDateString());
        $this->post('/admin/finance/accounting/tax-profile', ['from_date' => '۱۴۰۵/۰۱/۰۱', 'to_date' => '۱۴۰۵/۱۲/۲۹',
            'legal_name' => 'Test company', 'entity_type' => 'corporate', 'income_rate' => '25', 'loss_relief_irt' => '0',
            'other_addbacks_irt' => '0', 'vat_carry_irt' => '0', 'legal_basis' => 'Test reviewed annual period'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('2026-03-21', FinanceTaxProfile::sole()->from_date->toDateString());
        $this->assertSame('2027-03-20', FinanceTaxProfile::sole()->to_date->toDateString());
    }

    public function test_all_financial_screens_use_the_shared_picker(): void
    {
        foreach (['/admin/finance', '/admin/transactions', '/admin/finance/accounting', '/admin/finance/bank-reconciliation'] as $uri) {
            $this->get($uri)->assertOk()->assertSee('data-jdate', false)->assertDontSee('type="date"', false)->assertDontSee('type="month"', false);
        }
    }

    public function test_investment_analysis_accepts_jalali_dated_flows(): void
    {
        $this->post('/admin/finance/accounting/investment', ['discount_rate' => '0', 'currency' => 'USD', 'cash_flows' => "1404/10/11,-1000\n1405/10/11,1200"])
            ->assertSessionHasNoErrors()->assertSessionHas('investment', fn ($r) => (float) $r['roi_percent'] === 20.0);
    }
}

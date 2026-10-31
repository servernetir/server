<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\AccountingController;
use App\Models\FinanceAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FinanceFundingPresentationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private FinanceAccount $company;
    private FinanceAccount $partnerRial;
    private FinanceAccount $partnerLira;
    private FinanceAccount $vendor;
    private string $card;
    private string $iban;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 31)->setTime(10, 0));
        Http::preventStrayRequests();
        Http::fake();
        Mail::fake();
        Storage::fake('local');
        $this->admin = User::create([
            'name' => 'Synthetic funding reviewer', 'email' => 'funding-ui@example.test',
            'role' => 'admin', 'password' => bcrypt('synthetic-password'),
        ]);
        $this->card = $this->syntheticCard('999999000000001');
        $this->iban = $this->syntheticIban('IR', '999'.str_pad('1', 19, '0', STR_PAD_LEFT));
        $this->company = FinanceAccount::create([
            'name' => 'Synthetic company bank', 'type' => 'bank', 'currency_code' => 'IRR',
            'owner_name' => 'Synthetic legal entity', 'created_by' => $this->admin->id,
            'bank_details' => ['bank_name' => 'Synthetic company bank', 'card_number' => $this->card, 'iban' => $this->iban],
        ]);
        $this->partnerRial = FinanceAccount::create([
            'name' => 'Synthetic partner rial', 'type' => 'partner', 'currency_code' => 'IRR',
            'owner_name' => 'شریک آزمایشی', 'partner_key' => 'SYNTHETIC-PARTNER-01', 'created_by' => $this->admin->id,
            'bank_details' => ['bank_name' => 'Synthetic rial bank', 'card_number' => $this->syntheticCard('999999000000002'), 'iban' => $this->syntheticIban('IR', '999'.str_pad('2', 19, '0', STR_PAD_LEFT))],
        ]);
        $this->partnerLira = FinanceAccount::create([
            'name' => 'Synthetic partner lira', 'type' => 'partner', 'currency_code' => 'TRY',
            'owner_name' => 'Synthetic Partner '.str_repeat('long-owner-', 10), 'partner_key' => 'SYNTHETIC-PARTNER-01', 'created_by' => $this->admin->id,
            'bank_details' => ['bank_name' => 'Synthetic lira bank', 'card_number' => $this->syntheticCard('999999000000003'), 'iban' => $this->syntheticIban('TR', '999990'.str_repeat('9', 16))],
        ]);
        $this->vendor = FinanceAccount::create([
            'name' => 'Synthetic prepaid provider', 'type' => 'vendor', 'currency_code' => 'USD',
            'created_by' => $this->admin->id,
        ]);
        $this->actingAs($this->admin, 'web');
        $this->withSession(['_token' => 'synthetic-funding-ui-csrf']);
        $this->app['request']->setLaravelSession($this->app['session']->driver());
    }

    public static function locales(): array
    {
        return [['fa'], ['en'], ['tr']];
    }

    #[DataProvider('locales')]
    public function test_accounting_workspace_masks_bank_identity_and_separates_funding_purposes(string $locale): void
    {
        app()->setLocale($locale);
        session()->put('_old_input', ['funding_mode' => 'advance', 'related_voucher_id' => '741', 'card_number' => $this->card, 'iban' => $this->iban]);
        $before = $this->financialSnapshot();
        $request = Request::create(route('admin.accounting'), 'GET', ['from' => '2026-10-01', 'to' => '2026-10-31']);
        $request->setUserResolver(fn () => $this->admin);
        $request->setLaravelSession($this->app['session']->driver());
        $html = app(AccountingController::class)->index($request)->render();
        $xpath = $this->xpath($html);

        $this->assertStringNotContainsString($this->card, $html);
        $this->assertStringNotContainsString($this->iban, $html);
        $this->assertStringContainsString(substr($this->card, -4), $html);
        $this->assertStringContainsString('Synthetic company bank', $html);
        $this->assertStringContainsString('Synthetic partner rial', $html);
        $this->assertStringContainsString('Synthetic partner lira', $html);
        $this->assertStringContainsString('SYNTHETIC-PARTNER-01', $html);

        $create = $xpath->query('//form[@data-account-create]')->item(0);
        $this->assertNotNull($create);
        $this->assertSame(route('admin.accounting.accounts'), $create->getAttribute('action'));
        $updates = $xpath->query('//form[@data-account-update]');
        $this->assertGreaterThanOrEqual(3, $updates->length);
        foreach ($updates as $form) {
            $this->assertSame(1, $xpath->query('.//input[@name="account_id" and @type="hidden"]', $form)->length);
        }
        foreach ($xpath->query('//input[@name="card_number" or @name="iban"]') as $input) {
            $this->assertSame('', $input->getAttribute('value'), 'Bank identifiers are never prefilled in HTML.');
        }

        $voucher = $xpath->query('//form[@data-finance-voucher]')->item(0);
        $this->assertNotNull($voucher);
        $this->assertSame(route('admin.accounting.store'), $voucher->getAttribute('action'));
        $this->assertSame(1, $xpath->query('.//select[@name="type"]/option[@value="partner_advance"]', $voucher)->length);
        $this->assertSame(1, $xpath->query('.//select[@name="type"]/option[@value="partner_advance_transfer"]', $voucher)->length);
        $this->assertSame(1, $xpath->query('.//select[@name="funding_mode"]/option[@value="personal"]', $voucher)->length);
        $this->assertSame(1, $xpath->query('.//select[@name="funding_mode"]/option[@value="advance"]', $voucher)->length);
        $this->assertSame(1, $xpath->query('.//input[@name="related_voucher_id"]', $voucher)->length);
        $this->assertSame(1, $xpath->query('.//select[@name="funding_mode"]/option[@value="advance" and @selected]', $voucher)->length);
        $this->assertSame('741', $xpath->query('.//input[@name="related_voucher_id"]', $voucher)->item(0)->getAttribute('value'));

        $supplier = $xpath->query('//form[@data-supplier-debit]')->item(0);
        $this->assertNotNull($supplier);
        $this->assertSame(route('admin.accounting.store'), $supplier->getAttribute('action'));
        $this->assertSame('multipart/form-data', $supplier->getAttribute('enctype'));
        $this->assertSame(1, $xpath->query('.//input[@name="workflow" and @value="supplier_debit"]', $supplier)->length);
        $this->assertSame(1, $xpath->query('.//input[@name="business_entry_id" and @required]', $supplier)->length);
        $this->assertSame(1, $xpath->query('.//input[@name="evidence" and @type="file" and @required]', $supplier)->length);
        $this->assertSame(1, $xpath->query('.//select[@name="funding_account_id"]/option[@value="'.$this->vendor->id.'"]', $supplier)->length);
        foreach ([$this->company, $this->partnerRial, $this->partnerLira] as $account) {
            $this->assertSame(0, $xpath->query('.//select[@name="funding_account_id"]/option[@value="'.$account->id.'"]', $supplier)->length);
        }

        foreach ([$create, $voucher, $supplier] as $form) {
            $this->assertSame('post', strtolower($form->getAttribute('method')));
            $this->assertSame(1, $xpath->query('.//input[@name="_token" and @value="synthetic-funding-ui-csrf"]', $form)->length);
            $this->assertDoesNotMatchRegularExpression('/\\bfinance\\.[a-z_]+/', $form->textContent);
        }
        $this->assertSame($before, $this->financialSnapshot(), 'Rendering must not create a funding or supplier transaction.');
        Http::assertNothingSent();
        Mail::assertNothingSent();
        if ($directory = getenv('FINANCE_FUNDING_UI_FIXTURES')) {
            if (! is_dir($directory)) {
                mkdir($directory, 0700, true);
            }
            file_put_contents($directory.'/'.$locale.'.html', $html);
        }
        if (getenv('FINANCE_EXPECTED_DB')) {
            $this->assertSame(getenv('FINANCE_EXPECTED_DB'), config('database.default'));
        }
    }

    public function test_account_validation_does_not_flash_bank_identifiers_into_the_session_or_html(): void
    {
        $before = FinanceAccount::count();
        $response = $this->from(route('admin.accounting'))->post(route('admin.accounting.accounts'), [
            'name' => '', 'type' => 'partner', 'currency_code' => 'IRR',
            'owner_name' => 'Synthetic owner', 'partner_key' => 'SYNTHETIC-NEW',
            'bank_name' => 'Synthetic bank', 'card_number' => $this->card, 'iban' => $this->iban,
        ]);
        $response->assertRedirect()->assertSessionHasErrors('name');
        $this->assertSame($before, FinanceAccount::count());
        $old = session()->get('_old_input', []);
        $this->assertArrayNotHasKey('card_number', $old);
        $this->assertArrayNotHasKey('iban', $old);
        $this->assertArrayNotHasKey('bank_details', $old);
        $this->assertStringNotContainsString($this->card, json_encode($old, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($this->iban, json_encode($old, JSON_THROW_ON_ERROR));
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_registry_rejection_also_redacts_private_input_without_mutating_existing_accounts(): void
    {
        $before = FinanceAccount::count();
        $response = $this->from(route('admin.accounting'))->post(route('admin.accounting.accounts'), [
            'account_id' => $this->company->id, 'name' => $this->company->name,
            'type' => 'partner', 'currency_code' => 'IRR', 'owner_name' => 'Synthetic owner',
            'partner_key' => 'SYNTHETIC-PARTNER-01', 'card_number' => $this->card, 'iban' => $this->iban,
        ]);
        $response->assertRedirect()->assertSessionHasErrors();
        $this->assertSame($before, FinanceAccount::count());
        $this->assertSame('bank', $this->company->fresh()->type);
        $this->assertArrayNotHasKey('card_number', session()->get('_old_input', []));
        $this->assertArrayNotHasKey('iban', session()->get('_old_input', []));
    }

    public function test_non_admin_cannot_create_finance_accounts(): void
    {
        $staff = User::create([
            'name' => 'Synthetic support user', 'email' => 'funding-support@example.test',
            'role' => 'support', 'password' => bcrypt('synthetic-password'),
        ]);
        $before = FinanceAccount::count();
        $this->actingAs($staff, 'web')->post(route('admin.accounting.accounts'), [
            'name' => 'Should not exist', 'type' => 'partner', 'currency_code' => 'TRY',
            'owner_name' => 'Synthetic owner', 'partner_key' => 'SYNTHETIC-NOT-ALLOWED',
        ])->assertForbidden();
        $this->assertSame($before, FinanceAccount::count());
    }

    private function financialSnapshot(): array
    {
        $result = [];
        foreach (['finance_vouchers', 'finance_lines', 'finance_documents', 'business_entries', 'payments', 'credit_entries'] as $table) {
            if (Schema::hasTable($table)) {
                $result[$table] = DB::table($table)->count();
            }
        }

        return $result;
    }

    private function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new \DOMXPath($dom);
    }

    private function syntheticCard(string $prefix): string
    {
        for ($digit = 0; $digit <= 9; $digit++) {
            $number = $prefix.$digit;
            $sum = 0;
            foreach (str_split(strrev($number)) as $index => $char) {
                $n = (int) $char * ($index % 2 ? 2 : 1);
                $sum += $n > 9 ? $n - 9 : $n;
            }
            if ($sum % 10 === 0) {
                return $number;
            }
        }
        throw new \LogicException('Synthetic card checksum failed.');
    }

    private function syntheticIban(string $country, string $bban): string
    {
        $digits = '';
        foreach (str_split($country) as $char) {
            $digits .= (string) (ord($char) - 55);
        }
        $remainder = 0;
        foreach (str_split($bban.$digits.'00') as $digit) {
            $remainder = ($remainder * 10 + (int) $digit) % 97;
        }

        return $country.str_pad((string) (98 - $remainder), 2, '0', STR_PAD_LEFT).$bban;
    }
}

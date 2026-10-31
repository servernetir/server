<?php

namespace Tests\Feature;

use App\Models\BusinessEntry;
use App\Models\FinanceAccount;
use App\Models\FinanceLine;
use App\Models\FinanceVoucher;
use App\Models\User;
use App\Services\Finance\DocumentedAccounting;
use App\Services\Finance\FinanceAccountRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FinanceAccountIdentityTest extends TestCase
{
    use RefreshDatabase;

    private function registration(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Synthetic partner bank', 'type' => 'partner', 'currency_code' => 'IRR',
            'owner_name' => 'Synthetic partner', 'partner_key' => 'PARTNER-TEST',
            'bank_name' => 'Synthetic bank', 'iban' => $this->iban('IR', '42'),
            'card_number' => '4111111111111111',
        ], $overrides);
    }

    /** Algorithmically generated, nonproduction identifiers, not a real bank account. */
    private function iban(string $country, string $tail): string
    {
        $bban = str_pad($tail, 22, '0', STR_PAD_LEFT);
        $digits = $bban;
        foreach (str_split($country) as $letter) {
            $digits .= ord($letter) - 55;
        }
        $remainder = 0;
        foreach (str_split($digits.'00') as $digit) {
            $remainder = ($remainder * 10 + (int) $digit) % 97;
        }

        return $country.str_pad((string) (98 - $remainder), 2, '0', STR_PAD_LEFT).$bban;
    }

    private function registry(): FinanceAccountRegistry
    {
        return app(FinanceAccountRegistry::class);
    }

    public function test_identity_is_encrypted_hidden_and_masked_without_creating_money(): void
    {
        $d = $this->registration();
        $a = $this->registry()->createOrUpdate($d, 1)->fresh();
        $raw = DB::table('finance_accounts')->where('id', $a->id)->value('bank_details');
        $this->assertIsString($raw);
        $this->assertStringNotContainsString($d['iban'], $raw);
        $this->assertStringNotContainsString($d['card_number'], $raw);
        $this->assertSame($d['iban'], $a->bank_details['iban']);
        $this->assertSame($d['card_number'], $a->bank_details['card_number']);
        $this->assertArrayNotHasKey('bank_details', $a->toArray());
        $this->assertArrayNotHasKey('bank_iban_fingerprint', $a->toArray());
        $this->assertStringNotContainsString($d['iban'], $a->toJson());
        $this->assertStringNotContainsString($d['card_number'], $a->toJson());
        $masked = $a->maskedBankIdentity();
        $this->assertSame($a->id, $masked['id']);
        $this->assertSame('1111', $masked['card_last4']);
        $this->assertSame(substr($d['iban'], -4), $masked['iban_last4']);
        $this->assertSame('IR', $masked['iban_country']);
        $this->assertSame('PARTNER-TEST', $masked['partner_key']);
        $this->assertSame($d['owner_name'], $masked['owner_name']);
        $audit = DB::table('finance_audit_events')->where('action', 'account_created')->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString($d['iban'], $audit->details);
        $this->assertStringNotContainsString($d['card_number'], $audit->details);
        $this->assertSame(0, FinanceVoucher::count());
        $this->assertSame(0, FinanceLine::count());
        $this->assertSame(0, BusinessEntry::count());
    }

    public function test_repeated_normalized_iban_reuses_account_without_another_audit(): void
    {
        $d = $this->registration(['partner_key' => 'partner-test']);
        $first = $this->registry()->createOrUpdate($d, 1);
        $d['iban'] = strtolower(chunk_split($d['iban'], 4, ' '));
        $d['card_number'] = '۴۱۱۱ ۱۱۱۱ ۱۱۱۱ ۱۱۱۱';
        $again = $this->registry()->createOrUpdate($d, 2);
        $this->assertSame($first->id, $again->id);
        $this->assertSame('PARTNER-TEST', $again->partner_key);
        $this->assertSame(1, FinanceAccount::count());
        $this->assertSame(1, DB::table('finance_audit_events')->count());
        $this->assertSame(1, $again->created_by);
    }

    public function test_conflicting_owner_cannot_capture_an_existing_iban(): void
    {
        $a = $this->registry()->createOrUpdate($this->registration(), 1);
        try {
            $this->registry()->createOrUpdate($this->registration(['owner_name' => 'Different synthetic partner']), 2);
            $this->fail('A conflicting owner must not reuse an account identifier.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('iban', $e->errors());
        }
        $this->assertSame('Synthetic partner', $a->fresh()->owner_name);
        $this->assertSame(1, FinanceAccount::count());
        $this->assertSame(1, DB::table('finance_audit_events')->count());
    }

    public function test_separate_currency_accounts_can_share_partner_code_without_comparing_owner_spelling(): void
    {
        $iran = $this->registry()->createOrUpdate($this->registration(['owner_name' => 'شریک آزمایشی']), 1);
        $turkey = $this->registry()->createOrUpdate($this->registration([
            'name' => 'Synthetic Turkish account', 'owner_name' => 'Synthetic Partner Latin',
            'currency_code' => 'TRY', 'iban' => $this->iban('TR', '63'),
        ]), 1);
        $this->assertNotSame($iran->id, $turkey->id);
        $this->assertSame($iran->partner_key, $turkey->partner_key);
        $this->assertSame('TR', $turkey->maskedBankIdentity()['iban_country']);
        $this->assertSame('TRY', $turkey->currency_code);
    }

    public function test_explicit_legacy_identity_update_preserves_type_currency_and_omitted_private_fields(): void
    {
        $a = FinanceAccount::create(['name' => 'Legacy company account', 'type' => 'bank', 'currency_code' => 'IRT', 'created_by' => 1]);
        $d = $this->registration(['account_id' => $a->id, 'name' => 'Synthetic company account', 'type' => 'bank',
            'currency_code' => 'IRT', 'owner_name' => 'Synthetic company', 'partner_key' => null]);
        $this->registry()->createOrUpdate($d, 2);
        $updated = $this->registry()->createOrUpdate([
            'account_id' => $a->id, 'name' => 'Renamed synthetic company', 'type' => 'bank', 'currency_code' => 'IRT',
            'iban' => '', 'card_number' => '',
        ], 2);
        $this->assertSame($a->id, $updated->id);
        $this->assertSame('bank', $updated->type);
        $this->assertSame('IRT', $updated->currency_code);
        $this->assertSame('Synthetic company', $updated->owner_name);
        $this->assertSame($d['iban'], $updated->bank_details['iban']);
        $this->assertSame($d['card_number'], $updated->bank_details['card_number']);
        $this->assertSame(1, $updated->created_by);
        $event = DB::table('finance_audit_events')->where('action', 'account_identity_updated')->latest('id')->first();
        $this->assertStringContainsString('Legacy company account', DB::table('finance_audit_events')->where('action', 'account_identity_updated')->first()->details);
        $this->assertStringNotContainsString($d['iban'], $event->details);
        $this->assertStringNotContainsString($d['card_number'], $event->details);
        $this->assertSame(0, FinanceVoucher::count());
    }

    #[DataProvider('lockedFields')]
    public function test_existing_account_is_never_reclassified_or_recurrency_converted(string $field, string $value): void
    {
        $a = $this->registry()->createOrUpdate($this->registration(), 1);
        $this->expectException(ValidationException::class);
        $this->registry()->createOrUpdate($this->registration(['account_id' => $a->id, $field => $value]), 1);
    }

    public static function lockedFields(): array
    {
        return [['type', 'bank'], ['currency_code', 'TRY']];
    }

    #[DataProvider('invalidIdentifiers')]
    public function test_invalid_identifier_or_owner_never_creates_partial_metadata(array $invalid, string $field): void
    {
        try {
            $this->registry()->createOrUpdate($this->registration($invalid), 1);
            $this->fail('Invalid account identity must be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
        $this->assertSame(0, FinanceAccount::count());
        $this->assertSame(0, DB::table('finance_audit_events')->count());
        $this->assertSame(0, FinanceVoucher::count());
    }

    public static function invalidIdentifiers(): array
    {
        return [
            [['iban' => 'IR000000000000000000000000'], 'iban'],
            [['iban' => 'TR000000000000000000000000'], 'iban'],
            [['card_number' => '4111111111111112'], 'card_number'],
            [['card_number' => '4111'], 'card_number'],
            [['owner_name' => ''], 'owner_name'],
            [['partner_key' => 'unsafe/key'], 'partner_key'],
        ];
    }

    public function test_vendor_cannot_hold_a_personal_bank_identifier(): void
    {
        $this->expectException(ValidationException::class);
        $this->registry()->createOrUpdate($this->registration(['type' => 'vendor', 'partner_key' => null]), 1);
    }

    public function test_partner_code_can_be_backfilled_but_cannot_be_reassigned_after_posting(): void
    {
        $a = FinanceAccount::create(['name' => 'Legacy partner', 'type' => 'partner', 'currency_code' => 'IRR',
            'owner_name' => 'Synthetic legacy partner', 'created_by' => 1]);
        app(DocumentedAccounting::class)->post([
            'idempotency_key' => (string) Str::uuid(), 'type' => 'opening_balance',
            'occurred_at' => now('Asia/Tehran')->toDateString(), 'service_line' => 'other', 'category' => 'other',
            'amount_irt' => 100, 'original_currency' => 'IRR', 'original_minor' => 1000, 'settlement_minor' => 0,
            'funding_account_id' => null, 'target_account_id' => $a->id, 'bank_reference' => 'SYNTHETIC-OPENING',
            'tax_status' => 'pending', 'vat_status' => 'none', 'note' => 'Synthetic test opening evidence',
        ], 1);
        $d = ['account_id' => $a->id, 'name' => $a->name, 'type' => 'partner', 'currency_code' => 'IRR', 'partner_key' => 'PARTNER-LEGACY'];
        $this->registry()->createOrUpdate($d, 1);
        try {
            $this->registry()->createOrUpdate(array_replace($d, ['partner_key' => 'DIFFERENT-PARTNER']), 1);
            $this->fail('Posted partner identity must not be reassigned.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('partner_key', $e->errors());
        }
        $this->assertSame('PARTNER-LEGACY', $a->fresh()->partner_key);
        $this->assertSame(1, FinanceVoucher::count());
    }

    public function test_database_unique_identifier_protects_against_writers_bypassing_registry(): void
    {
        $a = $this->registry()->createOrUpdate($this->registration(), 1);
        $this->expectException(QueryException::class);
        DB::table('finance_accounts')->insert([
            'name' => 'Conflicting synthetic account', 'type' => 'partner', 'currency_code' => 'IRR',
            'owner_name' => 'Different synthetic owner', 'bank_iban_fingerprint' => $a->getRawOriginal('bank_iban_fingerprint'),
            'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_browser_validation_does_not_flash_full_identifiers_or_render_them(): void
    {
        $admin = User::create(['name' => 'Synthetic identity admin', 'email' => 'identity-admin@example.test',
            'password' => bcrypt('synthetic-password'), 'role' => 'admin']);
        $d = $this->registration(['iban' => 'IR000000000000000000000000']);
        $response = $this->actingAs($admin)->from('/admin/finance/accounting#accounts')
            ->post('/admin/finance/accounting/accounts', $d);
        $response->assertRedirect()->assertSessionHasErrors('iban');
        $old = session()->getOldInput();
        $this->assertArrayNotHasKey('iban', $old);
        $this->assertArrayNotHasKey('card_number', $old);
        $this->assertArrayNotHasKey('bank_details', $old);
        $this->assertSame(0, FinanceAccount::count());
    }
}

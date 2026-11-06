<?php

namespace App\Services\Finance;

use App\Models\FinanceAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class FinanceAccountRegistry
{
    /**
     * Register metadata only. An account or a bank identifier never creates an opening balance.
     * Callers must exclude card_number and iban when flashing validation input.
     */
    public function createOrUpdate(array $data, int $actor): FinanceAccount
    {
        abort_unless(Schema::hasColumns('finance_accounts', ['bank_details', 'bank_iban_fingerprint', 'partner_key']), 409, __('finance.not_ready'));
        $d = Validator::make($data, [
            'account_id' => ['nullable', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(['bank', 'partner', 'vendor'])],
            'currency_code' => ['required', Rule::in(array_keys(AccountingMoney::EXPONENTS))],
            'owner_name' => ['nullable', 'string', 'max:150'],
            'partner_key' => ['nullable', 'string', 'max:80', 'regex:/\A[A-Za-z0-9._-]+\z/D'],
            'bank_name' => ['nullable', 'string', 'max:150'],
            'iban' => ['nullable', 'string', 'max:80'],
            'card_number' => ['nullable', 'string', 'max:80'],
        ])->validate();
        $d['name'] = trim($d['name']);
        $this->check($d['name'] !== '', 'name', 'invalid_bank_identity');
        if (array_key_exists('owner_name', $d)) {
            $d['owner_name'] = filled($d['owner_name']) ? trim($d['owner_name']) : null;
        }
        if (array_key_exists('partner_key', $d)) {
            $d['partner_key'] = filled($d['partner_key']) ? strtoupper(trim($d['partner_key'])) : null;
        }
        $identifiers = [];
        if (filled($d['bank_name'] ?? null)) {
            $identifiers['bank_name'] = trim($d['bank_name']);
        }
        if (filled($d['iban'] ?? null)) {
            $iban = strtoupper(preg_replace('/[\s\p{Z}]+/u', '', $this->digits($d['iban'])));
            $this->check($this->validIban($iban), 'iban', 'invalid_bank_identity');
            $identifiers['iban'] = $iban;
        }
        if (filled($d['card_number'] ?? null)) {
            $card = preg_replace('/[\s\p{Z}-]+/u', '', $this->digits($d['card_number']));
            $this->check($this->validCard($card), 'card_number', 'invalid_bank_identity');
            $identifiers['card_number'] = $card;
        }
        $this->check($d['type'] !== 'vendor' || $identifiers === [], 'bank_name', 'invalid_bank_identity');
        $this->check($d['type'] === 'partner' || empty($d['partner_key']), 'partner_key', 'invalid_bank_identity');

        return DB::transaction(function () use ($d, $identifiers, $actor) {
            // Same lock order as documented posting: control, then accounts.
            DB::table('finance_controls')->where('id', 1)->lockForUpdate()->firstOrFail();
            $account = ! empty($d['account_id'])
                ? FinanceAccount::whereKey($d['account_id'])->lockForUpdate()->first() : null;
            $this->check(empty($d['account_id']) || $account !== null, 'account_id', 'account_identity_conflict');
            if ($account) {
                $this->check($account->type === $d['type'] && $account->currency_code === $d['currency_code'], 'type', 'account_type_locked');
            }
            $owner = array_key_exists('owner_name', $d) ? $d['owner_name'] : $account?->owner_name;
            $this->check($d['type'] !== 'partner' || filled($owner), 'owner_name', 'account_owner_required');
            $partnerKey = array_key_exists('partner_key', $d) ? $d['partner_key'] : $account?->partner_key;
            if ($account && filled($account->partner_key) && $account->partner_key !== $partnerKey
                && DB::table('finance_lines')->where('account_id', $account->id)->exists()) {
                $this->check(false, 'partner_key', 'account_type_locked');
            }
            $details = array_merge($account?->bank_details ?? [], $identifiers);
            $fingerprint = isset($details['iban']) ? hash('sha256', $details['iban']) : null;
            $sameIban = $fingerprint ? FinanceAccount::where('bank_iban_fingerprint', $fingerprint)->lockForUpdate()->first() : null;
            if ($sameIban && (! $account || $sameIban->id !== $account->id)) {
                // Repeated create is harmless; editing a different account cannot capture its IBAN.
                $this->check($account === null && $this->sameRegistration($sameIban, $d, $owner, $partnerKey, $identifiers),
                    'iban', 'account_identity_conflict');

                return $sameIban;
            }
            $before = $account?->maskedBankIdentity();
            $account ??= new FinanceAccount;
            $account->fill([
                'name' => $d['name'], 'type' => $d['type'], 'currency_code' => $d['currency_code'],
                'owner_name' => $owner, 'partner_key' => $partnerKey,
                'bank_details' => $details === [] ? null : $details, 'bank_iban_fingerprint' => $fingerprint,
            ]);
            if (! $account->exists) {
                $account->created_by = $actor;
            }
            if ($account->exists && ! $account->isDirty()) {
                return $account;
            }
            $creating = ! $account->exists;
            $account->save();
            app(DocumentedAccounting::class)->audit($creating ? 'account_created' : 'account_identity_updated', $actor, $account->id, [
                'before' => $before, 'after' => $account->maskedBankIdentity(),
            ]);

            return $account;
        }, 3);
    }

    private function sameRegistration(FinanceAccount $account, array $d, ?string $owner, ?string $partnerKey, array $identifiers): bool
    {
        if ($account->type !== $d['type'] || $account->currency_code !== $d['currency_code']
            || $account->owner_name !== $owner || $account->partner_key !== $partnerKey) {
            return false;
        }
        $old = $account->bank_details ?? [];
        foreach ($identifiers as $key => $value) {
            if (($old[$key] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    private function digits(string $value): string
    {
        return strtr($value, array_combine(
            preg_split('//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY),
            str_split('01234567890123456789'),
        ));
    }

    private function validIban(string $iban): bool
    {
        // Turkey's reserved field is zero; its account field may contain ASCII letters.
        if (! preg_match('/\A(?:IR[0-9]{24}|TR[0-9]{7}0[A-Z0-9]{16})\z/D', $iban)) {
            return false;
        }
        $remainder = 0;
        foreach (str_split(substr($iban, 4).substr($iban, 0, 4)) as $char) {
            $digits = ctype_digit($char) ? $char : (string) (ord($char) - 55);
            foreach (str_split($digits) as $digit) {
                $remainder = ($remainder * 10 + (int) $digit) % 97;
            }
        }

        return $remainder === 1;
    }

    private function validCard(string $card): bool
    {
        if (! preg_match('/\A[0-9]{16,19}\z/D', $card) || preg_match('/\A([0-9])\1+\z/D', $card)) {
            return false;
        }
        $sum = 0;
        foreach (str_split(strrev($card)) as $index => $digit) {
            $value = (int) $digit * ($index % 2 === 1 ? 2 : 1);
            $sum += $value > 9 ? $value - 9 : $value;
        }

        return $sum % 10 === 0;
    }

    private function check(bool $ok, string $field, string $key): void
    {
        if (! $ok) {
            throw ValidationException::withMessages([$field => __('finance.'.$key)]);
        }
    }
}

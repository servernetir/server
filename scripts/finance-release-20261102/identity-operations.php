<?php
declare(strict_types=1);

use App\Models\FinanceAccount;
use App\Models\User;
use App\Services\Finance\DocumentedAccounting;
use App\Services\Finance\FinanceAccountRegistry;
use Illuminate\Support\Facades\DB;

function financeIdentityBankSettings(bool $lock = false): array
{
    $query = DB::table('settings')->whereIn('key', ['bank_holder', 'bank_name', 'bank_account', 'bank_sheba', 'bank_card'])
        ->orderBy('key');
    if ($lock) {
        $query->lockForUpdate();
    }
    return $query->pluck('value', 'key')->all();
}

function financeIdentityDigits(string $value): string
{
    return strtr($value, array_combine(
        preg_split('//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY),
        str_split('01234567890123456789')
    ));
}

function financeIdentityLedgerState(): array
{
    return [
        'vouchers' => DB::table('finance_vouchers')->count(),
        'lines' => (array) DB::table('finance_lines')->selectRaw(
            'COUNT(*) AS row_count, COALESCE(SUM(debit_irt),0) AS debit_irt, COALESCE(SUM(credit_irt),0) AS credit_irt'
        )->first(),
    ];
}

function financeIdentityMasked(FinanceAccount $account, array $expected): array
{
    $details = $account->bank_details ?? [];
    foreach (['iban', 'card_number'] as $field) {
        if (filled($expected[$field] ?? null) && ($details[$field] ?? null) !== $expected[$field]) {
            throw new RuntimeException('identity_roundtrip');
        }
    }
    $raw = (string) $account->getRawOriginal('bank_details');
    if ($raw === '' || $raw === json_encode($details) || !is_array($details)) {
        throw new RuntimeException('identity_encryption');
    }
    foreach (['iban', 'card_number'] as $field) {
        if (filled($details[$field] ?? null) && str_contains($raw, $details[$field])) {
            throw new RuntimeException('identity_plaintext');
        }
    }
    $serialized = $account->toArray();
    if (array_key_exists('bank_details', $serialized) || array_key_exists('bank_iban_fingerprint', $serialized)) {
        throw new RuntimeException('identity_serialization');
    }
    return $account->maskedBankIdentity();
}

function financeRegisterIdentities($app, string $home, string $stage, array $scope, array $plan,
    array $identityPlan, array $input): array
{
    $context = financeReleaseContext($app, $home, $stage, $scope, $plan);
    $state = json_decode(file_get_contents(financeReleasePrivatePath($stage, 'install-state.json')), true, 32, JSON_THROW_ON_ERROR);
    financeReleaseAfter($app, $home, $stage, $scope, $plan, $context, $state);
    foreach (['servernet.cloud', 'console.servernet.cloud'] as $host) {
        $activationPath = financeReleasePrivatePath($stage, 'activation-' . $host . '.json');
        if (!is_file($activationPath)) {
            throw new RuntimeException('identity_activation_missing');
        }
        $activation = json_decode(file_get_contents($activationPath), true, 32, JSON_THROW_ON_ERROR);
        if (($activation['ok'] ?? false) !== true || ($activation['domain'] ?? null) !== $host
            || ($activation['source_commit'] ?? null) !== $plan['source_commit']) {
            throw new RuntimeException('identity_activation_mismatch');
        }
    }
    if (!preg_match('/\Afinance-identities-20261102-[a-f0-9]{12}\z/D', $identityPlan['release_id'] ?? '')
        || !is_int($identityPlan['actor_id'] ?? null)
        || !is_string($identityPlan['actor_name'] ?? null)
        || !preg_match('/\A[a-f0-9]{64}\z/D', $identityPlan['payload_sha256'] ?? '')
        || !is_string($input['identity_data_b64'] ?? null)
        || ($bytes = base64_decode($input['identity_data_b64'], true)) === false
        || strlen($bytes) > 16000 || hash('sha256', $bytes) !== $identityPlan['payload_sha256']) {
        throw new RuntimeException('identity_plan');
    }
    $payload = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
    unset($bytes);
    if (!is_array($payload) || !financeReleaseSameKeys($payload, ['accounts']) || count($payload['accounts']) !== 2
        || array_column($payload['accounts'], 'currency_code') !== ['IRR', 'TRY']
        || !is_string($identityPlan['partner_key'] ?? null)) {
        throw new RuntimeException('identity_payload');
    }
    $actor = User::find($identityPlan['actor_id']);
    if (!$actor || !$actor->isAdmin() || $actor->name !== $identityPlan['actor_name']) {
        throw new RuntimeException('identity_actor');
    }
    $result = DB::transaction(function () use ($app, $identityPlan, $payload) {
        DB::table('finance_controls')->where('id', 1)->lockForUpdate()->firstOrFail();
        $existing = DB::table('finance_audit_events')->where('action', 'account_identity_updated')
            ->where('details->release_id', $identityPlan['release_id'])->first();
        if ($existing) {
            $details = json_decode($existing->details, true, 32, JSON_THROW_ON_ERROR);
            if (($details['payload_sha256'] ?? null) !== $identityPlan['payload_sha256']) {
                throw new RuntimeException('identity_replay_conflict');
            }
            return ['ok' => true, 'action' => 'register', 'idempotent' => true, 'audit_id' => $existing->id,
                'release_id' => $identityPlan['release_id'], 'accounts' => $details['accounts'],
                'financial_state_unchanged' => true, 'bank_details_encrypted' => true];
        }
        foreach ($identityPlan['existing_account_hashes'] as $id => $expected) {
            $row = DB::table('finance_accounts')->where('id', $id)->lockForUpdate()->first();
            if (!$row || hash('sha256', json_encode((array) $row, JSON_THROW_ON_ERROR)) !== $expected) {
                throw new RuntimeException('identity_account_changed');
            }
        }
        $settings = financeIdentityBankSettings(true);
        if (hash('sha256', json_encode($settings, JSON_THROW_ON_ERROR)) !== $identityPlan['company_settings_fingerprint']
            || ($settings['bank_holder'] ?? null) !== $identityPlan['company_owner_name']
            || ($settings['bank_name'] ?? null) !== $identityPlan['company_bank_name']
            || !filled($settings['bank_sheba'] ?? null)) {
            throw new RuntimeException('identity_company_source');
        }
        $before = financeIdentityLedgerState();
        $registry = $app->make(FinanceAccountRegistry::class);
        $accounts = [];
        foreach ($payload['accounts'] as $index => $data) {
            if (($data['type'] ?? null) !== 'partner' || ($data['partner_key'] ?? null) !== $identityPlan['partner_key']
                || ($data['account_id'] ?? null) !== $identityPlan['partner_account_ids'][$index]) {
                throw new RuntimeException('identity_account_target');
            }
            $account = $registry->createOrUpdate($data, $identityPlan['actor_id'])->fresh();
            if ($account->type !== 'partner' || $account->currency_code !== $data['currency_code']
                || $account->partner_key !== $identityPlan['partner_key'] || $account->owner_name !== $data['owner_name']) {
                throw new RuntimeException('identity_saved_metadata');
            }
            $accounts[] = financeIdentityMasked($account, $data);
        }
        $companyIban = strtoupper(preg_replace('/[\s\p{Z}]+/u', '', financeIdentityDigits((string) $settings['bank_sheba'])));
        if (preg_match('/\A[0-9]{24}\z/D', $companyIban)) {
            $companyIban = 'IR' . $companyIban;
        }
        $companyData = [
            'account_id' => $identityPlan['company_account_id'],
            'name' => $identityPlan['company_account_name'], 'type' => 'bank', 'currency_code' => 'IRR',
            'owner_name' => trim($settings['bank_holder']), 'bank_name' => trim($settings['bank_name']),
            'iban' => $companyIban,
            'card_number' => preg_replace('/[\s\p{Z}-]+/u', '', financeIdentityDigits((string) ($settings['bank_card'] ?? ''))),
        ];
        $company = $registry->createOrUpdate($companyData, $identityPlan['actor_id'])->fresh();
        if ($company->type !== 'bank' || $company->currency_code !== 'IRR' || filled($company->partner_key)
            || $company->owner_name !== $companyData['owner_name']) {
            throw new RuntimeException('identity_company_metadata');
        }
        $accounts[] = financeIdentityMasked($company, $companyData);
        if (financeIdentityLedgerState() !== $before
            || hash('sha256', json_encode(financeIdentityBankSettings(true), JSON_THROW_ON_ERROR))
                !== $identityPlan['company_settings_fingerprint']) {
            throw new RuntimeException('identity_financial_state_changed');
        }
        $details = ['release_id' => $identityPlan['release_id'], 'payload_sha256' => $identityPlan['payload_sha256'],
            'accounts' => $accounts, 'financial_state_unchanged' => true,
            'company_settings_fingerprint' => $identityPlan['company_settings_fingerprint'],
            'authorization_channel' => 'owner_requested_cpanel_release'];
        $app->make(DocumentedAccounting::class)->audit('account_identity_updated', $identityPlan['actor_id'],
            $identityPlan['batch_subject_id'], $details);
        $audit = DB::table('finance_audit_events')->where('action', 'account_identity_updated')
            ->where('details->release_id', $identityPlan['release_id'])->firstOrFail();
        return ['ok' => true, 'action' => 'register', 'idempotent' => false, 'audit_id' => $audit->id,
            'release_id' => $identityPlan['release_id'], 'accounts' => $accounts,
            'financial_state_unchanged' => true, 'bank_details_encrypted' => true];
    }, 3);
    financeReleaseJson($stage, 'identity-state.json', $result);
    return $result;
}

<?php

namespace App\Services\Finance;

use App\Models\BankAccount;
use App\Models\Customer;
use App\Models\FinanceAccount;
use App\Models\FinanceDocument;
use App\Models\FinanceVoucher;
use App\Models\Payment;
use App\Models\User;
use App\Models\WalletRefundRequest;
use App\Support\ErrorTracker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Lock order: customer -> refund -> finance control -> funding account. No bank transfer is initiated here. */
final class WalletRefunds
{
    public function __construct(private Wallet $wallet, private DocumentedAccounting $journal) {}

    public function ready(): bool
    {
        return Schema::hasTable('wallet_refund_permissions') && Schema::hasTable('wallet_refund_requests')
            && Schema::hasTable('wallet_refund_events') && $this->journal->ready();
    }

    /** Identity captured at payout; never substitute a current account for historical evidence. */
    public static function fundingAccountSnapshot(?object $paidEvent): ?array
    {
        if (! $paidEvent || $paidEvent->action !== 'paid') {
            return null;
        }
        $details = json_decode((string) $paidEvent->details, true);
        $snapshot = is_array($details) ? ($details['funding_account_snapshot'] ?? null) : null;
        $keys = ['id', 'name', 'type', 'currency_code', 'owner_name'];
        if (! is_array($snapshot) || array_diff($keys, array_keys($snapshot))
            || ! is_int($snapshot['id']) || $snapshot['id'] < 1 || ! is_string($snapshot['name']) || trim($snapshot['name']) === ''
            || ! in_array($snapshot['type'], ['bank', 'partner'], true) || ! in_array($snapshot['currency_code'], ['IRT', 'IRR'], true)
            || ($snapshot['owner_name'] !== null && ! is_string($snapshot['owner_name']))) {
            return null;
        }

        return array_intersect_key($snapshot, array_flip($keys));
    }

    public function enabled(int $customerId): bool
    {
        return $this->ready() && (bool) DB::table('wallet_refund_permissions')->where('customer_id', $customerId)->value('enabled');
    }

    public function hasHistory(int $customerId): bool
    {
        return (Schema::hasTable('wallet_refund_permissions') && DB::table('wallet_refund_permissions')->where('customer_id', $customerId)->exists())
            || (Schema::hasTable('wallet_refund_requests') && WalletRefundRequest::where('customer_id', $customerId)->exists());
    }

    public function visible(int $customerId): bool
    {
        return $this->ready() && ($this->enabled($customerId) || WalletRefundRequest::where('customer_id', $customerId)->exists());
    }

    private function check(bool $condition, string $key): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['refund' => __('refund.'.$key)]);
        }
    }

    private function event(int $customerId, ?WalletRefundRequest $r, string $actorType, int $actorId, string $action, array $details = [], string $actorName = ''): void
    {
        DB::table('wallet_refund_events')->insert(['customer_id' => $customerId, 'request_id' => $r?->id,
            'actor_type' => $actorType, 'actor_id' => $actorId, 'actor_name' => mb_substr($actorName, 0, 150), 'action' => $action,
            'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    public function permission(Customer $customer, bool $enabled, string $reason, User $actor): void
    {
        abort_unless($actor->isAdmin(), 403);
        $this->check($this->ready(), 'not_ready');
        $this->check(mb_strlen(trim($reason)) >= 5, 'invalid_state');
        DB::transaction(function () use ($customer, $enabled, $reason, $actor) {
            Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $before = (bool) DB::table('wallet_refund_permissions')->where('customer_id', $customer->id)->value('enabled');
            if ($before === $enabled) {
                return;
            }
            $permission = DB::table('wallet_refund_permissions')->where('customer_id', $customer->id);
            $values = ['enabled' => $enabled, 'changed_by' => $actor->id, 'updated_at' => now()];
            if ((clone $permission)->exists()) {
                $permission->update($values);
            } else {
                DB::table('wallet_refund_permissions')->insert($values + ['customer_id' => $customer->id, 'created_at' => now()]);
            }
            $this->event($customer->id, null, 'staff', $actor->id, 'permission_changed', ['enabled' => $enabled, 'reason' => $reason], $actor->name);
        }, 3);
    }

    private function verifiedBank(int $customerId, int $bankId): BankAccount
    {
        $bank = BankAccount::whereKey($bankId)->where('customer_id', $customerId)->lockForUpdate()->first();
        $this->check($bank !== null && $bank->status === 'verified' && $bank->name_matched && filled($bank->owner_name) && $this->validIban((string) $bank->iban), 'verified_bank');

        return $bank;
    }

    private function validIban(string $iban): bool
    {
        if (! preg_match('/^IR[0-9]{24}$/D', $iban)) {
            return false;
        }

        // Keep each step below 970: no big integers or optional BCMath extension.
        $remainder = 0;
        foreach (str_split(substr($iban, 4).'1827'.substr($iban, 2, 2)) as $digit) {
            $remainder = ($remainder * 10 + (int) $digit) % 97;
        }

        return $remainder === 1;
    }

    public function request(Customer $customer, string $key, int $amount, int $bankId, string $note): WalletRefundRequest
    {
        $this->check($this->ready(), 'not_ready');
        $this->check(Str::isUuid($key) && $amount > 0 && $amount <= 1_000_000_000_000, 'invalid_amount');
        $hash = hash('sha256', json_encode([$customer->id, $amount, $bankId, $note], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($customer, $key, $amount, $bankId, $note, $hash) {
            $c = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            if ($existing = WalletRefundRequest::where('public_id', $key)->first()) {
                $this->check((int) $existing->customer_id === $c->id && hash_equals($existing->payload_hash, $hash), 'duplicate_mismatch');

                return $existing;
            }
            $this->check($c->isActive() && $this->enabled($c->id), 'disabled');
            $this->check(! WalletRefundRequest::where('active_customer_id', $c->id)->exists(), 'pending_exists');
            $bank = $this->verifiedBank($c->id, $bankId);
            $this->check($this->wallet->availableOf($c->id, 'IRT') >= $amount, 'insufficient');
            $r = WalletRefundRequest::create(['public_id' => $key, 'payload_hash' => $hash, 'customer_id' => $c->id,
                'active_customer_id' => $c->id, 'bank_account_id' => $bank->id, 'currency_code' => 'IRT', 'amount_irt' => $amount,
                'status' => 'requested', 'customer_note' => $note, 'bank_snapshot' => [
                    'iban' => $bank->iban, 'owner_name' => $bank->owner_name, 'bank_name' => $bank->bankLabel(),
                    'verified_at' => $bank->verified_at?->toIso8601String(),
                ]]);
            $this->event($c->id, $r, 'customer', $c->id, 'requested', ['amount_irt' => $amount], $c->code);
            DB::afterCommit(fn () => app(WalletRefundNotifications::class)->requested($r));

            return $r;
        }, 3);
    }

    private function lock(WalletRefundRequest $request): WalletRefundRequest
    {
        Customer::whereKey($request->customer_id)->lockForUpdate()->firstOrFail();

        return WalletRefundRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
    }

    private function checkSnapshot(WalletRefundRequest $r): void
    {
        $bank = $this->verifiedBank((int) $r->customer_id, (int) $r->bank_account_id);
        $this->check(hash_equals((string) $r->bank_snapshot['iban'], (string) $bank->iban)
            && $r->bank_snapshot['owner_name'] === $bank->owner_name, 'bank_changed');
    }

    public function approve(WalletRefundRequest $request, User $actor, string $note, ?int $sourcePayment, string $sourceNote): WalletRefundRequest
    {
        abort_unless($actor->isAdmin(), 403);

        return DB::transaction(function () use ($request, $actor, $note, $sourcePayment, $sourceNote) {
            $r = $this->lock($request);
            $this->check(mb_strlen(trim($note)) >= 5 && mb_strlen(trim($sourceNote)) >= 10, 'invalid_source');
            if ($r->status === 'approved') {
                $this->check($r->review_note === $note && (int) $r->source_payment_id === (int) $sourcePayment
                    && $r->source_note === $sourceNote, 'duplicate_mismatch');

                return $r;
            }
            $this->check($r->status === 'requested', 'invalid_state');
            $this->checkSnapshot($r);
            $this->check($this->wallet->availableOf($r->customer_id, 'IRT') >= 0, 'insufficient');
            $this->checkSource($r, $sourcePayment);
            $r->forceFill(['status' => 'approved', 'review_note' => $note, 'source_payment_id' => $sourcePayment,
                'source_note' => $sourceNote, 'reviewed_by' => $actor->id, 'reviewed_at' => now()])->save();
            $this->event($r->customer_id, $r, 'staff', $actor->id, 'approved', ['source_payment_id' => $sourcePayment, 'note' => $note, 'source_note' => $sourceNote], $actor->name);
            DB::afterCommit(fn () => app(WalletRefundNotifications::class)->status($r));

            return $r;
        }, 3);
    }

    private function checkSource(WalletRefundRequest $r, ?int $sourcePayment): void
    {
        if (! $sourcePayment) {
            return;
        }
        $pay = Payment::whereKey($sourcePayment)->where('customer_id', $r->customer_id)->lockForUpdate()->first();
        $this->check($pay !== null && $pay->status === 'paid' && $pay->currency_code === 'IRT'
            && ! in_array($pay->gateway, ['credit', 'gift'], true), 'invalid_source');
        $paidBefore = (int) WalletRefundRequest::where('source_payment_id', $sourcePayment)->where('status', 'paid')
            ->where('id', '!=', $r->id)->sum('amount_irt');
        $this->check($paidBefore + $r->amount_irt <= $pay->amount, 'invalid_source');
    }

    public function close(WalletRefundRequest $request, string $status, string $note, Customer|User $actor): WalletRefundRequest
    {
        $isCustomer = $actor instanceof Customer;
        abort_unless($isCustomer ? $status === 'cancelled' && $actor->id === (int) $request->customer_id
            : $actor->isAdmin() && $status === 'rejected', 403);

        return DB::transaction(function () use ($request, $status, $note, $actor, $isCustomer) {
            $r = $this->lock($request);
            if ($r->status === $status) {
                return $r;
            }
            $this->check(in_array($r->status, $isCustomer ? ['requested'] : WalletRefundRequest::OPEN, true), 'invalid_state');
            $r->forceFill(['status' => $status, 'active_customer_id' => null, 'review_note' => $note,
                'reviewed_by' => $isCustomer ? null : $actor->id, 'reviewed_at' => now()])->save();
            $this->event($r->customer_id, $r, $isCustomer ? 'customer' : 'staff', $actor->id, $status, ['note' => $note], $isCustomer ? $actor->code : $actor->name);
            DB::afterCommit(fn () => app(WalletRefundNotifications::class)->status($r));

            return $r;
        }, 3);
    }

    public function paid(WalletRefundRequest $request, User $actor, int $accountId, string $date, string $reference, UploadedFile $receipt, ?int $bankTransactionId = null): WalletRefundRequest
    {
        abort_unless($actor->isAdmin(), 403);
        $this->check($this->ready(), 'not_ready');
        $reference = strtr(trim($reference), array_combine(preg_split('//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY), str_split('01234567890123456789')));
        $reference = preg_replace('/\s+/u', '', $reference);
        $this->check((bool) preg_match('/^[a-zA-Z0-9_.\/:\-]{4,150}$/D', $reference), 'invalid_reference');
        $this->check(in_array($receipt->getMimeType(), ['application/pdf', 'image/jpeg', 'image/png'], true)
            && $receipt->getSize() > 0 && $receipt->getSize() <= 10 * 1024 * 1024, 'invalid_receipt');
        $receiptHash = hash_file('sha256', $receipt->getRealPath());
        $hash = hash('sha256', json_encode([$accountId, $date, $reference, $receiptHash, $bankTransactionId], JSON_THROW_ON_ERROR));
        $path = $receipt->store('finance/wallet-refunds/'.$request->public_id, 'local');
        $this->check(is_string($path) && $path !== '', 'invalid_receipt');
        try {
            $r = DB::transaction(function () use ($request, $actor, $accountId, $date, $reference, $receipt, $receiptHash, $hash, $path, $bankTransactionId) {
                $r = $this->lock($request);
                if ($r->status === 'paid') {
                    $this->check(hash_equals((string) $r->payout_hash, $hash), 'duplicate_mismatch');

                    return $r;
                }
                $this->check($r->status === 'approved', 'invalid_state');
                $this->checkSnapshot($r);
                $this->checkSource($r, $r->source_payment_id ? (int) $r->source_payment_id : null);
                $this->check($date >= $r->created_at->copy()->timezone('Asia/Tehran')->toDateString()
                    && $date <= now('Asia/Tehran')->toDateString(), 'invalid_date');
                $control = DB::table('finance_controls')->where('id', 1)->lockForUpdate()->first();
                $this->check($control !== null && (! $control->locked_through || $date > $control->locked_through), 'period_locked');
                $account = FinanceAccount::whereKey($accountId)->lockForUpdate()->first();
                $this->check($account !== null && in_array($account->type, ['bank', 'partner'], true)
                    && in_array($account->currency_code, ['IRT', 'IRR'], true), 'funding_bank');
                $this->check($account->type !== 'partner' || filled($account->owner_name), 'funding_owner');
                // Personal funds create a partner payable; they are not a company bank balance.
                $this->check($account->type === 'bank' || $bankTransactionId === null, 'invalid_bank_row');
                $native = $account->currency_code === 'IRR' ? $r->amount_irt * 10 : $r->amount_irt;
                if ($account->type === 'bank') {
                    foreach ([$this->journal->balance($account, $date), $this->journal->balance($account)] as $balance) {
                        $this->check($balance['irt'] >= $r->amount_irt && $balance['native'] >= $native, 'insufficient_bank');
                    }
                }
                $fundingSnapshot = ['id' => (int) $account->id, 'name' => $account->name, 'type' => $account->type,
                    'currency_code' => $account->currency_code, 'owner_name' => $account->owner_name];
                $fingerprint = hash('sha256', $accountId.'|'.strtolower($reference));
                $this->check(! WalletRefundRequest::where('payout_fingerprint', $fingerprint)->exists(), 'reference_used');
                if ($bankTransactionId) {
                    $b = DB::table('finance_bank_transactions')->where('id', $bankTransactionId)->lockForUpdate()->first();
                    $this->check($b !== null && (int) $b->account_id === $accountId && $b->currency_code === $account->currency_code
                        && $b->occurred_at === $date && (int) $b->debit_minor === $native && (int) $b->credit_minor === 0, 'invalid_bank_row');
                    $this->check(! FinanceVoucher::where('bank_transaction_id', $bankTransactionId)->exists(), 'reference_used');
                }
                // Stop holding only within this transaction; the debit then checks all OTHER holds.
                $r->forceFill(['status' => 'paid', 'active_customer_id' => null, 'paid_at' => now(), 'paid_by' => $actor->id,
                    'payout_date' => $date, 'bank_reference' => $reference, 'payout_fingerprint' => $fingerprint, 'payout_hash' => $hash])->save();
                $entry = $this->wallet->debit($r->customer_id, 'IRT', $r->amount_irt, 'wallet_refund_payout', $r, 'Wallet refund '.$r->public_id);
                $voucher = FinanceVoucher::create(['idempotency_key' => (string) Str::uuid(), 'payload_hash' => $hash,
                    'type' => 'wallet_refund_payout', 'occurred_at' => $date, 'service_line' => 'other', 'category' => 'wallet_refund',
                    'vendor' => $r->customer->code, 'bank_reference' => $reference,
                    'note' => 'Wallet liability settlement '.$r->public_id.' / '.$r->source_note,
                    'amount_irt' => $r->amount_irt, 'original_currency' => 'IRT', 'original_minor' => $r->amount_irt,
                    'settlement_currency' => $account->currency_code, 'settlement_minor' => $native, 'funding_account_id' => $accountId,
                    'bank_transaction_id' => $bankTransactionId, 'tax_status' => 'pending', 'vat_status' => 'none', 'created_by' => $actor->id]);
                $this->journal->line($voucher, 'customer_wallet_liability', $r->amount_irt, 0);
                $this->journal->line($voucher, $account->type === 'partner' ? 'partner_current' : 'bank', 0, $r->amount_irt, $accountId, 0, $native);
                $doc = FinanceDocument::create(['voucher_id' => $voucher->id, 'path' => $path,
                    'name' => mb_substr(basename($receipt->getClientOriginalName()), 0, 200), 'mime' => $receipt->getMimeType(),
                    'size' => $receipt->getSize(), 'sha256' => $receiptHash]);
                $r->forceFill(['voucher_id' => $voucher->id, 'document_id' => $doc->id, 'credit_entry_id' => $entry->id])->save();
                $this->event($r->customer_id, $r, 'staff', $actor->id, 'paid', ['voucher_id' => $voucher->id,
                    'document_sha256' => $receiptHash, 'amount_irt' => $r->amount_irt, 'bank_reference' => $reference,
                    'payout_date' => $date, 'funding_account_id' => $accountId, 'funding_account_snapshot' => $fundingSnapshot], $actor->name);
                $this->journal->audit('wallet_refund_paid', $actor->id, $voucher->id, ['refund' => $r->public_id]);
                DB::afterCommit(fn () => app(WalletRefundNotifications::class)->status($r));

                return $r;
            }, 3);

            return $r;
        } finally {
            // A late commit/notification failure must never remove a receipt already referenced by a committed voucher.
            try {
                if (! FinanceDocument::where('path', $path)->exists()) {
                    Storage::disk('local')->delete($path);
                }
            } catch (\Throwable $e) {
                ErrorTracker::note('finance', $e, ['area' => 'refund-receipt-retention', 'request' => $request->public_id]);
            }
        }
    }
}

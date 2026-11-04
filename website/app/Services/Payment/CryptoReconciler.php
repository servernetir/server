<?php

namespace App\Services\Payment;

use App\Models\CreditEntry;
use App\Models\CryptoPayment;
use App\Models\CryptoWallet;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\ErrorTracker;
use Illuminate\Support\Facades\DB;

/**
 * A deposit is claimed and settled in one transaction. Ambiguity never credits.
 * A failure on one payment must not starve the remaining payments.
 */
class CryptoReconciler
{
    private const CLOCK_SKEW_SECONDS = 120;

    public function __construct(
        private readonly TronWatcher $tron,
        private readonly PaymentService $payments,
        private readonly BscWatcher $bsc,
    ) {}

    public function sweep(): array
    {
        $stat = ['checked' => 0, 'confirmed' => 0, 'expired' => 0, 'unmatched' => 0, 'errors' => 0];
        $failedChains = [];
        $failedPayments = [];

        foreach (CryptoPayment::watchable()->whereIn('chain', ['tron', 'bsc'])->lazyById(100) as $cp) {
            if (isset($failedChains[$cp->chain])) {
                continue;
            }
            $stat['checked']++;

            try {
                $deposits = $cp->chain === 'bsc'
                    ? $this->bsc->deposits($cp->address, $cp->asset, $cp->created_at->timestamp - self::CLOCK_SKEW_SECONDS)
                    : $this->tron->deposits($cp->address, $cp->asset);
            } catch (\Throwable $e) {
                // Do not expire a chain whose deposits could not be read.
                $failedChains[$cp->chain] = true;
                $stat['errors']++;
                $this->failure($cp, 'watcher', $e);
                continue;
            }

            try {
                foreach ($deposits as $deposit) {
                    $result = $this->apply($cp, $deposit);
                    if ($result !== null) {
                        $stat[$result]++;
                    }
                }
            } catch (\Throwable $e) {
                // apply() rolls back both claim and money. Retry on the next sweep;
                // do not turn this infrastructure failure into customer lateness.
                $failedPayments[] = $cp->id;
                $stat['errors']++;
                $this->failure($cp, 'settlement', $e);
            }
        }

        foreach (CryptoPayment::where('status', 'pending')
            ->whereNotIn('chain', array_keys($failedChains))
            ->whereNotIn('id', $failedPayments)->where('expires_at', '<', now())->lazyById(100) as $cp) {
            try {
                $stat['expired'] += DB::transaction(function () use ($cp) {
                    $row = CryptoPayment::whereKey($cp->id)->lockForUpdate()->first();
                    if ($row === null || $row->status !== 'pending' || $row->received_atomic > 0) {
                        return 0;
                    }
                    $row->forceFill(['status' => 'expired'])->save();
                    $this->releaseOwnedWallet($row);
                    return 1;
                }, 3);
            } catch (\Throwable $e) {
                $stat['errors']++;
                $this->failure($cp, 'expiry', $e);
            }
        }

        $stat['orphan_freed'] = $this->freeOrphanedWallets($stat);
        return $stat;
    }

    private function failure(CryptoPayment $cp, string $stage, \Throwable $error): void
    {
        // Exception messages can contain SQL, payment data or provider secrets.
        ErrorTracker::noteOnce('crypto', 'Crypto reconciliation failed; payment requires retry.', 300, [
            'crypto_payment' => $cp->id, 'chain' => $cp->chain,
            'stage' => $stage, 'exception' => $error::class,
        ]);
    }

    private function freeOrphanedWallets(array &$stat): int
    {
        $count = 0;
        foreach (CryptoWallet::whereNotNull('busy_payment_id')->lazyById(100) as $candidate) {
            try {
                $count += DB::transaction(function () use ($candidate) {
                    $wallet = CryptoWallet::whereKey($candidate->id)->lockForUpdate()->first();
                    if ($wallet === null || $wallet->busy_payment_id === null) {
                        return 0;
                    }
                    $holder = CryptoPayment::find($wallet->busy_payment_id);
                    if ($holder !== null && $holder->isOpen()) {
                        return 0;
                    }
                    $wallet->release();
                    return 1;
                }, 3);
            } catch (\Throwable $e) {
                $stat['errors']++;
                ErrorTracker::noteOnce('crypto', 'Crypto wallet release failed; reservation retained.', 300,
                    ['wallet' => $candidate->id, 'exception' => $e::class]);
            }
        }
        return $count;
    }

    /** Never release a wallet now reserved by a different checkout. */
    private function releaseOwnedWallet(CryptoPayment $row): void
    {
        $wallet = CryptoWallet::whereKey($row->crypto_wallet_id)->lockForUpdate()->first();
        if ($wallet !== null && (int) $wallet->busy_payment_id === (int) $row->id) {
            $wallet->release();
        }
    }

    private function apply(CryptoPayment $cp, array $deposit): ?string
    {
        $txid = $deposit['txid'] ?? null;
        if (($deposit['confirmed'] ?? false) !== true || ! is_string($txid)
            || $txid === '' || strlen($txid) > 128
            || ($deposit['asset'] ?? null) !== $cp->asset
            || ($deposit['decimals'] ?? null) !== $cp->decimals
            || ! is_int($deposit['amount'] ?? null) || $deposit['amount'] <= 0) {
            return null;
        }
        if ($cp->chain === 'bsc') {
            if (! preg_match('/^0x[0-9a-fA-F]{64}$/D', $txid)) {
                return null;
            }
            $txid = strtolower($txid);
        }
        $timestamp = (int) ($deposit['timestamp'] ?? 0);
        if ($timestamp > 0 && $timestamp < $cp->created_at->timestamp - self::CLOCK_SKEW_SECONDS) {
            return null;
        }

        return DB::transaction(function () use ($cp, $deposit, $txid, $timestamp) {
            $row = CryptoPayment::whereKey($cp->id)->lockForUpdate()->first();
            if ($row === null || ! in_array($row->status, ['pending', 'seen', 'expired'], true)) {
                return null;
            }
            // The database UNIQUE(chain, txid) remains the last concurrent-writer guard.
            if (CryptoPayment::where('chain', $row->chain)->where('txid', $txid)->exists()) {
                return null;
            }

            $row->received_atomic += $deposit['amount'];
            $row->txid = $txid;
            $row->confirmations = $row->chain === 'bsc'
                ? max(0, (int) ($deposit['confirmations'] ?? 0)) : TronWatcher::MIN_CONFIRMATIONS;

            if ($timestamp <= 0 || $timestamp > now()->timestamp + self::CLOCK_SKEW_SECONDS) {
                return $this->review($row, 'Deposit timestamp is missing or invalid; manual review.');
            }
            if ($row->status === 'expired' || $timestamp > $row->expires_at->timestamp) {
                // Recent expired payments are observed, never automatically resurrected.
                return $this->review($row, 'Deposit detected after checkout expiry; manual reconciliation required.');
            }
            $slack = (int) ceil($row->amount_atomic * CryptoPayment::TOLERANCE_BP / 10000);
            if ($row->received_atomic > $row->amount_atomic + $slack) {
                return $this->review($row, 'Deposit differs from the locked quote; manual review.');
            }
            if (! $row->isPaidEnough()) {
                return $this->review($row, 'Deposit is below the locked quote; manual review.');
            }
            $row->status = 'seen';
            $row->save();

            return $this->settle($row) ? 'confirmed' : 'unmatched';
        }, 3);
    }

    private function review(CryptoPayment $row, string $note): string
    {
        $row->forceFill(['status' => 'manual', 'note' => $note])->save();
        $this->releaseOwnedWallet($row);
        return 'unmatched';
    }

    private function settle(CryptoPayment $row): bool
    {
        $invoice = $row->invoice;
        if ($invoice === null || ! $invoice->isPayable()) {
            $this->review($row, 'Invoice is no longer payable; manual reconciliation required.');
            return false;
        }

        $payment = Payment::firstOrCreate(
            ['external_ref' => $row->chain.':'.$row->txid],
            ['invoice_id' => $invoice->id, 'customer_id' => $invoice->customer_id,
                'gateway' => 'crypto', 'currency_code' => $row->invoice_currency,
                'amount' => $row->invoice_amount, 'status' => 'redirected'],
        );
        // Match the PaymentService lock order: payment -> invoice -> wallet customer.
        $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
        $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
        if ($payment->invoice_id !== $invoice->id || $payment->customer_id !== $invoice->customer_id
            || $payment->gateway !== 'crypto' || $payment->amount !== $row->invoice_amount
            || $payment->currency_code !== $row->invoice_currency || $payment->isPaid()
            || ! $invoice->isPayable() || $invoice->due() !== $row->invoice_amount
            || $invoice->currency_code !== $row->invoice_currency
            || $invoice->customer_id !== $row->customer_id) {
            $this->review($row, 'Payment or invoice changed since the quote; manual reconciliation required.');
            return false;
        }

        Customer::whereKey($invoice->customer_id)->lockForUpdate()->firstOrFail();
        // Backwards-compatible fail-closed guard for legacy manual credits whose
        // full transaction hash was recorded in the mandatory audit note.
        if (strlen($row->txid) >= 32 && CreditEntry::where('amount', '>', 0)
            ->whereRaw('LOWER(note) LIKE ?', ['%'.strtolower($row->txid).'%'])
            ->lockForUpdate()->exists()) {
            $this->review($row, 'Transaction is referenced by an existing wallet credit; manual reconciliation required.');
            return false;
        }

        $out = $this->payments->settleConfirmed($payment, $row->txid);
        if (! $out->ok) {
            $this->review($row, 'Payment settlement was not confirmed; manual review.');
            return false;
        }
        $row->forceFill(['status' => 'confirmed', 'confirmed_at' => now()])->save();
        $this->releaseOwnedWallet($row);
        return true;
    }
}


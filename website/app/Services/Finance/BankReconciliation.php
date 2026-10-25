<?php

namespace App\Services\Finance;

use App\Models\FinanceAccount;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BankReconciliation
{
    private function check(bool $ok, string $key = 'bank_invalid'): void
    {
        if (! $ok) {
            throw ValidationException::withMessages(['bank' => __('finance.'.$key)]);
        }
    }

    /** A statement is evidence, not a posting, sale, payment settlement or wallet topup. */
    public function import(FinanceAccount $account, string $csv, string $privatePath, int $actor): int
    {
        $this->check($account->type === 'bank');
        $this->check(strlen($csv) <= 5 * 1024 * 1024);
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $csv));
        rewind($stream);
        try {
            $header = fgetcsv($stream, escape: '');
            $this->check($header === ['date', 'reference', 'credit', 'debit', 'currency'], 'bank_csv_header');
            $rows = [];
            while (($r = fgetcsv($stream, escape: '')) !== false) {
                if ($r === [null]) {
                    continue;
                }
                $this->check(count($r) === 5 && count($rows) < 10000);
                [$date,$ref,$credit,$debit,$cur] = array_map('trim', $r);
                $this->check(preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) === 1 && Carbon::createFromFormat('!Y-m-d', $date)->format('Y-m-d') === $date);
                $this->check($date <= now('Asia/Tehran')->toDateString() && $ref !== '' && mb_strlen($ref) <= 150 && $cur === $account->currency_code);
                $in = AccountingMoney::minor($credit, $cur, 'bank');
                $out = AccountingMoney::minor($debit, $cur, 'bank');
                $this->check(($in > 0 && $out === 0) || ($out > 0 && $in === 0));
                $rows[] = ['date' => $date, 'reference' => $ref, 'credit' => $in, 'debit' => $out, 'currency' => $cur];
            }
            $this->check(count($rows) > 0);
        } finally {
            fclose($stream);
        }

        return DB::transaction(function () use ($account, $rows, $csv, $privatePath, $actor) {
            FinanceAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            $hash = hash('sha256', $csv);
            $existing = DB::table('finance_bank_imports')->where('account_id', $account->id)->where('sha256', $hash)->first();
            if ($existing) {
                return 0;
            }
            $id = DB::table('finance_bank_imports')->insertGetId(['account_id' => $account->id, 'path' => $privatePath, 'sha256' => $hash, 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
            $count = 0;
            foreach ($rows as $row) {
                $fingerprint = hash('sha256', $account->id.'|'.json_encode($row, JSON_THROW_ON_ERROR));
                if (DB::table('finance_bank_transactions')->where('fingerprint', $fingerprint)->exists()) {
                    continue;
                }
                DB::table('finance_bank_transactions')->insert(['import_id' => $id, 'account_id' => $account->id,
                    'occurred_at' => $row['date'], 'reference' => $row['reference'], 'credit_minor' => $row['credit'], 'debit_minor' => $row['debit'],
                    'currency_code' => $row['currency'], 'fingerprint' => $fingerprint, 'created_at' => now(), 'updated_at' => now()]);
                $count++;
            }
            app(DocumentedAccounting::class)->audit('statement_imported', $actor, $id, ['rows' => $count, 'sha256' => $hash]);

            return $count;
        }, 3);
    }

    public function match(int $bankId, int $paymentId, int $amount, int $fee, string $reference, int $actor): int
    {
        return DB::transaction(function () use ($bankId, $paymentId, $amount, $fee, $reference, $actor) {
            $bank = DB::table('finance_bank_transactions')->where('id', $bankId)->lockForUpdate()->first();
            $p = Payment::whereKey($paymentId)->lockForUpdate()->first();
            $this->check($bank && $p && $p->status === 'paid' && ! in_array($p->gateway, ['credit', 'gift'], true));
            $this->check($bank->credit_minor > 0 && $p->currency_code === $bank->currency_code && $amount > 0 && $fee >= 0 && mb_strlen($reference) >= 3);
            $used = (int) DB::table('finance_bank_matches')->where('bank_transaction_id', $bankId)->whereNull('voided_at')->sum('amount_minor');
            $paid = DB::table('finance_bank_matches')->where('payment_id', $paymentId)->whereNull('voided_at')->selectRaw('COALESCE(SUM(amount_minor+fee_minor),0) as n')->first();
            $this->check($used + $amount <= (int) $bank->credit_minor && (int) $paid->n + $amount + $fee <= (int) $p->amount, 'bank_overmatch');
            $id = DB::table('finance_bank_matches')->insertGetId(['bank_transaction_id' => $bankId, 'payment_id' => $paymentId,
                'amount_minor' => $amount, 'fee_minor' => $fee, 'reference' => $reference, 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
            app(DocumentedAccounting::class)->audit('bank_matched', $actor, $id, ['payment_id' => $paymentId, 'bank_id' => $bankId]);

            return $id;
        }, 3);
    }

    public function void(int $id, string $reason, int $actor): void
    {
        DB::transaction(function () use ($id, $reason, $actor) {
            $m = DB::table('finance_bank_matches')->where('id', $id)->lockForUpdate()->first();
            $this->check($m && ! $m->voided_at && mb_strlen(trim($reason)) >= 5);
            DB::table('finance_bank_matches')->where('id', $id)->update(['voided_at' => now(), 'voided_by' => $actor, 'void_reason' => $reason, 'updated_at' => now()]);
            app(DocumentedAccounting::class)->audit('bank_match_voided', $actor, $id, ['reason' => $reason]);
        }, 3);
    }
}

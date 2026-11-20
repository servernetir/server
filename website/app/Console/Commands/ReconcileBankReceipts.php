<?php

namespace App\Console\Commands;

use App\Models\BankTransferReceipt;
use App\Services\Billing\BankReceiptReviewer;
use Illuminate\Console\Command;

class ReconcileBankReceipts extends Command
{
    protected $signature = 'billing:bank-receipts {--receipt= : بررسی یک رسید مشخص} {--apply : ترمیم همان رسید پس از تطبیق}';

    protected $description = 'ممیزی فقط‌خواندنی رسیدهای تأییدشده با فاکتور تسویه‌نشده؛ ترمیم فقط تک‌رسید';

    public function handle(BankReceiptReviewer $reviewer): int
    {
        $id = $this->option('receipt');
        if (($id !== null && ! preg_match('/\A[1-9][0-9]*\z/D', (string) $id))
            || ($this->option('apply') && $id === null)) {
            $this->error('اعمال اصلاح فقط با شناسه یک رسید مشخص مجاز است.');
            return self::FAILURE;
        }
        $query = BankTransferReceipt::where('status', 'approved')->with('invoice')->orderBy('id');
        if ($id !== null) {
            $query->whereKey($id);
        }
        $found = false;
        $issues = 0;
        foreach ($query->lazyById(100) as $receipt) {
            $found = true;
            $invoice = $receipt->invoice;
            if ($invoice?->status === 'paid' && $invoice->due() === 0) {
                continue;
            }
            $issues++;
            $this->line(json_encode(['receipt_id' => $receipt->id, 'invoice_id' => $receipt->invoice_id,
                'issue' => 'approved_receipt_invoice_unsettled', 'apply' => (bool) $this->option('apply')]));
            if ($this->option('apply')) {
                $result = $reviewer->reconcileApproved($receipt);
                $this->line(json_encode($result, JSON_UNESCAPED_UNICODE));
                return $result['ok'] ? self::SUCCESS : self::FAILURE;
            }
        }
        $this->line(json_encode(['issues' => $issues, 'read_only' => ! $this->option('apply')]));
        return $id !== null && ! $found ? self::FAILURE : self::SUCCESS;
    }
}

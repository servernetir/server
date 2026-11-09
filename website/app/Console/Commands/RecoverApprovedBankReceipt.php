<?php

namespace App\Console\Commands;

use App\Services\Billing\ApprovedBankReceiptRecovery;
use Illuminate\Console\Command;

class RecoverApprovedBankReceipt extends Command
{
    protected $signature = 'billing:recover-approved-bank-receipt
        {receipt : Exact receipt ID reviewed by the operator}
        {--customer= : Expected customer ID}
        {--invoice= : Expected invoice ID}
        {--amount= : Expected amount in IRT}
        {--apply : Apply the reviewed recovery; omitted means read-only preview}';

    protected $description = 'Preview or explicitly recover one approved top-up receipt affected by a legacy reference collision';

    public function handle(ApprovedBankReceiptRecovery $recovery): int
    {
        $values = [$this->argument('receipt'), $this->option('customer'), $this->option('invoice'), $this->option('amount')];
        foreach ($values as $value) {
            if (! is_scalar($value) || ! preg_match('/^[1-9][0-9]*$/D', (string) $value)
                || filter_var($value, FILTER_VALIDATE_INT) === false) {
                $this->error('Supply receipt, --customer, --invoice and --amount as explicit positive integers.');

                return self::FAILURE;
            }
        }

        try {
            $result = $recovery->run(...[...array_map('intval', $values), (bool) $this->option('apply')]);
        } catch (\RuntimeException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
        $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}

<?php

namespace App\Services\Bale\Management;

use App\Models\WalletRefundRequest;

/** Binds the owner's confirmation to the exact refund and bank evidence shown. */
final class BaleRefundReview
{
    public static function fingerprint(WalletRefundRequest $request): string
    {
        return hash('sha256', json_encode([
            'id' => (int) $request->id, 'public_id' => (string) $request->public_id,
            'customer_id' => (int) $request->customer_id, 'amount_irt' => (int) $request->amount_irt,
            'bank_account_id' => (int) $request->bank_account_id, 'bank_snapshot' => $request->bank_snapshot,
        ], JSON_THROW_ON_ERROR));
    }
}

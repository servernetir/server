<?php

namespace Tests\Support;

use App\Models\Setting;
use App\Services\Cloud\VpsTcApi;
use App\Services\Cloud\VpsTcMoneyContract;
use Illuminate\Support\Facades\Http;

/** An unrelated, reviewed paid anchor; it cannot stand in for the business invoice. */
trait VpsTcMoneyContractFixture
{
    private array $moneyAnchorInvoice;
    private array $moneyAnchorService;
    private array $moneyProof;

    private function reviewedMoneyContract(): array
    {
        $this->moneyAnchorInvoice = ['id' => '9001', 'status' => 'Paid', 'currency' => 'TRY', 'rate' => '45',
            'subtotal' => '125.55', 'tax' => '25.11', 'tax2' => '0', 'grandtotal' => '150.66',
            'total' => '0', 'credit' => '150.66', 'discounts' => [],
            'items' => [['type' => 'Hosting', 'item_id' => '9002', 'qty' => '1']]];
        $this->moneyAnchorService = ['id' => '9002', 'name' => 'Reviewed monthly VPS',
            'billingcycle' => 'Monthly', 'date_created' => '2026-01-01'];
        $this->moneyProof = ['version' => 1, 'account_fingerprint' => app(VpsTcApi::class)->accountFingerprint(),
            'base_currency' => 'USD', 'account_currency' => 'TRY', 'multiplier' => '45', 'payment_method' => '120',
            'source' => ['kind' => 'reviewed_portal_currency_table', 'url' => 'https://www.vps.tc/en/vps', 'sha256' => str_repeat('a', 64),
                'reviewed_at' => now()->toIso8601String(), 'product_id' => '12', 'base_price' => '2.79', 'native_price' => '125.55'],
            'anchor' => ['invoice_id' => '9001', 'service_id' => '9002', 'product_id' => '12', 'cycle' => 'm',
                'base_price' => '2.79', 'tax_percent' => '20',
                'service_sha256' => VpsTcMoneyContract::serviceFingerprint($this->moneyAnchorService),
                'financial_sha256' => VpsTcMoneyContract::financialFingerprint($this->moneyAnchorInvoice)]];
        $this->saveMoneyContract();
        return ['*/api/invoice/9001' => fn () => Http::response(['invoice' => $this->moneyAnchorInvoice]),
            '*/api/service/9002' => fn () => Http::response(['service' => $this->moneyAnchorService])];
    }

    private function saveMoneyContract(): void
    {
        Setting::putSecret(VpsTcMoneyContract::KEY, json_encode($this->moneyProof, JSON_THROW_ON_ERROR));
    }
}

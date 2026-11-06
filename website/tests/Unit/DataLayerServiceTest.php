<?php

namespace Tests\Unit;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Services\Analytics\DataLayerService;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

class DataLayerServiceTest extends TestCase
{
    public function test_toman_is_normalised_to_iso_irr_without_pii(): void
    {
        $item = new InvoiceItem(['title' => 'Cloud plan CV-2', 'quantity' => 2, 'unit_price' => 50_000, 'line_total' => 100_000]);
        $item->id = 91;
        $invoice = new Invoice(['kind' => 'service', 'currency_code' => 'IRT', 'tax' => 9_000]);
        $invoice->id = 12;
        $invoice->setRelation('items', new Collection([$item]));
        $payment = new Payment(['invoice_id' => 12, 'currency_code' => 'IRT', 'amount' => 109_000]);
        $payment->id = 44;
        $payment->setRelation('invoice', $invoice);

        $payload = DataLayerService::purchasePayload($payment);

        $this->assertSame('payment:44', $payload['ecommerce']['transaction_id']);
        $this->assertSame('IRR', $payload['ecommerce']['currency']);
        $this->assertSame(1_090_000, $payload['ecommerce']['value']);
        $this->assertSame(90_000, $payload['ecommerce']['tax']);
        $this->assertSame(500_000, $payload['ecommerce']['items'][0]['price']);
        $json = json_encode($payload);
        foreach (['email', 'phone', 'mobile', 'ip', 'password', 'card_mask', 'error_message'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
    }

    public function test_invoice_funnel_payload_uses_the_purchase_contract(): void
    {
        $item = new InvoiceItem(['title' => 'VPS Germany', 'quantity' => 1, 'unit_price' => 250_000, 'line_total' => 250_000]);
        $item->id = 19;
        $invoice = new Invoice(['kind' => 'service', 'currency_code' => 'IRT', 'total' => 275_000]);
        $invoice->id = 81;
        $invoice->setRelation('items', new Collection([$item]));

        $payload = DataLayerService::invoicePayload($invoice);

        $this->assertSame('view_cart', $payload['event']);
        $this->assertSame('IRR', $payload['ecommerce']['currency']);
        $this->assertSame(2_750_000, $payload['ecommerce']['value']);
        $this->assertSame('invoice_item:19', $payload['ecommerce']['items'][0]['item_id']);
        $this->assertSame(2_500_000, $payload['ecommerce']['items'][0]['price']);
    }

    public function test_wallet_funding_is_not_an_ecommerce_purchase(): void
    {
        $invoice = new Invoice(['kind' => 'topup', 'currency_code' => 'IRT']);
        $invoice->id = 12;
        $payment = new Payment(['invoice_id' => 12, 'currency_code' => 'IRT', 'amount' => 100_000]);
        $payment->id = 44;
        $payment->setRelation('invoice', $invoice);

        $payload = DataLayerService::purchasePayload($payment);

        $this->assertSame([
            'event' => 'wallet_topup',
            'funding_reference' => 'payment:44',
            'funding_amount' => 100_000,
            'funding_currency' => 'IRT',
        ], $payload);
        $this->assertArrayNotHasKey('ecommerce', $payload);
        $this->assertArrayNotHasKey('value', $payload);
        $this->assertArrayNotHasKey('currency', $payload);
    }

    public function test_funding_then_spending_wallet_credit_has_only_one_purchase(): void
    {
        $events = [];
        foreach (['topup', 'service'] as $index => $kind) {
            $invoice = new Invoice(['kind' => $kind, 'currency_code' => 'IRT', 'total' => 100_000]);
            $invoice->id = 12 + $index;
            $invoice->setRelation('items', new Collection());
            $payment = new Payment(['invoice_id' => $invoice->id, 'currency_code' => 'IRT', 'amount' => 100_000]);
            $payment->id = 44 + $index;
            $payment->setRelation('invoice', $invoice);
            $events[] = DataLayerService::purchasePayload($payment);
        }

        $this->assertSame(['wallet_topup', 'purchase'], array_column($events, 'event'));
        $this->assertSame('payment:45', $events[1]['ecommerce']['transaction_id']);
        $this->assertSame('IRR', $events[1]['ecommerce']['currency']);
        $this->assertSame(1_000_000, $events[1]['ecommerce']['value']);
        $this->assertSame(100_000, $events[0]['funding_amount']);
    }

    public function test_funding_falls_back_to_invoice_currency_without_changing_its_unit(): void
    {
        $invoice = new Invoice(['kind' => 'topup', 'currency_code' => 'USD']);
        $invoice->id = 12;
        $payment = new Payment(['invoice_id' => 12, 'amount' => 25]);
        $payment->id = 44;
        $payment->setRelation('invoice', $invoice);

        $payload = DataLayerService::purchasePayload($payment);

        $this->assertSame('USD', $payload['funding_currency']);
        $this->assertSame(25, $payload['funding_amount']);
        $this->assertArrayNotHasKey('ecommerce', $payload);
    }
}

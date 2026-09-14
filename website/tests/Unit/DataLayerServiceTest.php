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
}

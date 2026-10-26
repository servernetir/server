<?php

namespace Tests\Feature;

use App\Services\Cloud\VpsTcInvoiceAmounts;
use Tests\TestCase;

class VpsTcInvoiceAmountsTest extends TestCase
{
    private function creditSettled(array $overrides = []): array
    {
        return array_replace(['subtotal' => '125.55', 'tax' => '25.11', 'tax2' => '0.00',
            'total' => '0.00', 'credit' => '150.66', 'currency' => 'TRY', 'status' => 'Paid',
            'grandtotal' => '150.66', 'discounts' => [],
            // These fields are deliberately irrelevant: their FX semantics are not established.
            'rate' => 45, 'rate2' => 0, 'rate3' => 1], $overrides);
    }

    public function test_zero_due_does_not_hide_the_gross_credit_already_spent(): void
    {
        $bill = $this->creditSettled();
        $this->assertSame('150.66', VpsTcInvoiceAmounts::inspect($bill)['gross']);
        $this->assertNull(VpsTcInvoiceAmounts::bounded($bill, '125.55'));
        $this->assertSame('150.66', VpsTcInvoiceAmounts::bounded($bill, '150.66')['gross']);
    }

    public function test_card_settled_and_partially_credited_invoices_keep_the_same_gross(): void
    {
        foreach ([['total' => '150.66', 'credit' => '0.00'], ['total' => '100.66', 'credit' => '50.00']] as $payment) {
            $this->assertSame('150.66', VpsTcInvoiceAmounts::inspect($this->creditSettled($payment))['gross']);
        }
    }

    public function test_conflicting_grand_total_subtotal_tax_or_credit_is_not_a_valid_cost(): void
    {
        foreach ([['grandtotal' => '150.65'], ['subtotal' => '125.54'], ['tax' => '25.10'],
            ['tax2' => '1.00'], ['credit' => '150.65'], ['total' => '1.00']] as $conflict) {
            $this->assertNull(VpsTcInvoiceAmounts::inspect($this->creditSettled($conflict)));
        }
        $this->assertSame('150.66', VpsTcInvoiceAmounts::inspect($this->creditSettled(['grandtotal' => '150.66']))['gross']);
    }

    public function test_missing_or_malformed_fields_cannot_be_guessed_as_zero(): void
    {
        foreach (['total', 'credit', 'subtotal', 'tax'] as $field) {
            $bill = $this->creditSettled(); unset($bill[$field]);
            $this->assertNull(VpsTcInvoiceAmounts::inspect($bill));
        }
        foreach ([null, 'Array', '-1', '1,000.00', '1e2', true, [], INF] as $bad) {
            $this->assertNull(VpsTcInvoiceAmounts::inspect($this->creditSettled(['credit' => $bad])));
        }
        $this->assertNull(VpsTcInvoiceAmounts::inspect($this->creditSettled(['grandtotal' => null])));
        $this->assertNull(VpsTcInvoiceAmounts::inspect($this->creditSettled(['discount' => '1.00'])));
        $this->assertNull(VpsTcInvoiceAmounts::inspect($this->creditSettled(['discounts' => [['amount' => '1.00']]])));
    }

    public function test_six_decimal_native_amounts_are_not_rounded_or_fx_converted(): void
    {
        $bill = $this->creditSettled(['subtotal' => '2.79', 'tax' => '0.558', 'credit' => '3.348',
            'grandtotal' => '3.348', 'currency' => 'USD']);
        $this->assertSame('3.348', VpsTcInvoiceAmounts::inspect($bill)['gross']);
        $this->assertNull(VpsTcInvoiceAmounts::bounded($bill, '3.34'));
        $this->assertNotNull(VpsTcInvoiceAmounts::bounded($bill, '3.35'));
    }

    public function test_documented_shape_without_grand_total_remains_cross_checked(): void
    {
        $bill = $this->creditSettled(); unset($bill['grandtotal']);
        $this->assertSame('150.66', VpsTcInvoiceAmounts::inspect($bill)['gross']);
        $bill['credit'] = '150.65';
        $this->assertNull(VpsTcInvoiceAmounts::inspect($bill));
    }

    public function test_native_json_numbers_with_zero_due_have_the_same_verified_gross(): void
    {
        $bill = $this->creditSettled(['subtotal' => 125.55, 'tax' => 25.11, 'tax2' => 0,
            'grandtotal' => 150.66, 'credit' => 150.66, 'total' => 0]);
        $this->assertSame('150.66', VpsTcInvoiceAmounts::bounded($bill, '150.66')['gross']);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Service;
use App\Services\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * سررسیدِ بعدی باید از پایانِ دورهٔ خریداری‌شده جلو برود، نه از لحظهٔ پرداخت.
 *
 * فاکتورِ تمدید چند روز پیش از سررسید صادر می‌شود؛ اگر از now() حساب می‌شد، هر
 * دوره چند روز کوتاه‌تر می‌شد و مشتری در سال بیش از ۱۲ ماه پول می‌داد — و روی
 * دورهٔ شش‌ماهه/سالانه این خطا ۶ و ۱۲ برابر می‌شود.
 */
class BillingPeriodAnchorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Mail::fake();
    }

    private function paidService(string $cycle, ?Carbon $nextDue): Service
    {
        $customer = Customer::create([
            'email' => 'c'.random_int(1, 99999).'@x.com', 'phone' => '0912'.random_int(1000000, 9999999),
            'password' => 'x', 'status' => 'active', 'locale' => 'fa',
        ]);

        $service = Service::create([
            'customer_id' => $customer->id, 'name' => 'هاست', 'currency_code' => 'IRT',
            'price' => 250000, 'tax_percent' => 0, 'cycle' => $cycle, 'status' => 'active',
            'activated_at' => now()->subMonths(1), 'next_due_at' => $nextDue,
        ]);

        $invoice = Invoice::create([
            'customer_id' => $customer->id, 'service_id' => $service->id, 'kind' => 'service',
            'currency_code' => 'IRT', 'subtotal' => 250000, 'tax' => 0, 'total' => 250000,
            'paid' => 0, 'status' => 'unpaid', 'issued_at' => now(),
        ]);
        $payment = \App\Models\Payment::create([
            'invoice_id' => $invoice->id, 'customer_id' => $customer->id, 'gateway' => 'bale',
            'currency_code' => 'IRT', 'amount' => 250000, 'status' => 'redirected', 'external_ref' => 'X',
        ]);

        // پرداختِ کاملِ فاکتور، پنج روز *پیش* از سررسید (مثلِ کرونِ تمدید)
        app(PaymentService::class)->settleConfirmed($payment, 'REF-'.random_int(1, 9999));

        return $service->refresh();
    }

    /** پرداختِ زودهنگام نباید دوره را کوتاه کند */
    public function test_early_payment_keeps_the_period_boundary(): void
    {
        Carbon::setTestNow('2026-03-10 10:00:00');
        $due = Carbon::parse('2026-03-15');          // سررسید ۵ روز دیگر

        $service = $this->paidService('monthly', $due);

        // باید ۱۵ فروردین بعدی باشد (۱۵ آوریل)، نه ۱۰ آوریل
        $this->assertSame('2026-04-15', $service->next_due_at->toDateString());
        Carbon::setTestNow();
    }

    /** روی دورهٔ سالانه هم همان قاعده — یک سال از سررسید، نه از پرداخت */
    public function test_yearly_period_advances_from_due_date(): void
    {
        Carbon::setTestNow('2026-03-10 10:00:00');
        $service = $this->paidService('yearly', Carbon::parse('2026-03-15'));

        $this->assertSame('2027-03-15', $service->next_due_at->toDateString());
        Carbon::setTestNow();
    }

    /** سرویسِ خیلی عقب‌افتاده باید به آینده برسد، نه در گذشته بماند */
    public function test_long_overdue_service_catches_up_to_the_future(): void
    {
        Carbon::setTestNow('2026-06-01 10:00:00');
        $service = $this->paidService('monthly', Carbon::parse('2026-01-10'));

        $this->assertTrue($service->next_due_at->isFuture(), 'سررسید باید در آینده باشد');
        // روزِ دوره (۱۰) حفظ می‌شود
        $this->assertSame(10, $service->next_due_at->day);
        Carbon::setTestNow();
    }

    /** سرویسِ تازه (بدونِ سررسیدِ قبلی) از زمانِ فعال‌سازی جلو می‌رود */
    public function test_new_service_anchors_on_activation(): void
    {
        Carbon::setTestNow('2026-03-10 10:00:00');
        $service = $this->paidService('semiannual', null);

        $this->assertNotNull($service->next_due_at);
        $this->assertTrue($service->next_due_at->isFuture());
        Carbon::setTestNow();
    }
}

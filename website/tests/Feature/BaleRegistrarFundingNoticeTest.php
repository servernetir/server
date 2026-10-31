<?php

namespace Tests\Feature;

use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Setting;
use App\Services\Bale\BaleNotifier;
use App\Services\Domain\DomainRegistrar;
use App\Services\Notify\BaleManagerPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** The first paid registrar funding failure is visible without changing delivery or money rules. */
class BaleRegistrarFundingNoticeTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Support\SupplierDomainPrices;

    private \ArrayObject $messages;
    private \ArrayObject $emails;
    private string $providerFailure = 'Insufficient credit';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->configureSupplierPrices();
        // Procurement reads the verified Navasan EUR record; the old pricing
        // override alone is deliberately no longer accepted by CloudPricing.
        \Tests\Support\NavasanRates::store(['EUR' => 100000]);
        Setting::put(BaleManagerPolicy::SETTING, '1');
        config(['services.openprovider.nameservers' => ['ns1.example.invalid', 'ns2.example.invalid'],
            'services.openprovider.registrant' => [],
            'servernet.contact.notify_phone' => '09120000000',
            'servernet.contact.notify_chat_id' => '100001',
            'servernet.contact.notify_phones' => [], 'servernet.contact.notify_chat_ids' => [],
            'servernet.contact.email' => 'manager@example.invalid']);

        $this->messages = new \ArrayObject;
        $this->emails = new \ArrayObject;
        Mail::shouldReceive('mailer')->with('smtp')->andReturnSelf();
        Mail::shouldReceive('raw')->andReturnUsing(function ($text, $callback) { $this->emails[] = $text; });
        $bale = \Mockery::mock(BaleNotifier::class);
        $bale->shouldReceive('toAdminButtonsAt')->andReturnUsing(function ($phone, $chat, $text, $buttons) {
            $this->messages[] = ['text' => $text, 'buttons' => $buttons];
            return true;
        });
        $bale->shouldReceive('toAdminAt')->andReturnUsing(function ($phone, $chat, $text) {
            $this->messages[] = ['text' => $text, 'buttons' => []];
        });
        $this->app->instance(BaleNotifier::class, $bale);

        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(array_merge($this->supplierPriceStubs(), [
            '*/customers*' => Http::response(['code' => 0, 'data' => ['handle' => 'SYNTHETIC-NL']]),
            '*/domains*' => function ($request) {
                if ($request->method() === 'GET') {
                    return Http::response(['code' => 0, 'data' => ['results' => []]]);
                }
                // -1 is a generic failure sentinel, not a claimed registrar funding code.
                return Http::response(['code' => -1, 'desc' => $this->providerFailure], 500);
            },
        ]));
    }

    public function test_first_paid_insufficient_credit_failure_sends_one_bale_only_and_keeps_retry_pending(): void
    {
        [$domain, $invoice, $customer] = $this->paidOrder();
        $money = $this->moneyState($invoice, $customer);
        $result = app(DomainRegistrar::class)->register($domain);

        $this->assertFalse($result['ok']);
        $this->assertFalse($result['manual'], $result['message']);
        $this->assertSame('pending', $domain->fresh()->provision_status);
        $this->assertSame('pending', $domain->fresh()->status);
        $this->assertSame(1, (int) $domain->fresh()->provision_tries);
        $this->assertCount(1, $this->messages);
        $this->assertCount(0, $this->emails);
        foreach ([$domain->domain, 'Openprovider', $customer->code, 'Insufficient credit', 'اقدام:'] as $part) {
            $this->assertStringContainsString($part, $this->messages[0]['text']);
        }
        $this->assertSame($money, $this->moneyState($invoice, $customer));
    }

    public function test_same_failure_is_coalesced_for_six_hours_without_changing_third_attempt_manual_escalation_or_email(): void
    {
        [$domain, $invoice, $customer] = $this->paidOrder();
        $money = $this->moneyState($invoice, $customer);
        $registrar = app(DomainRegistrar::class);
        $first = $registrar->register($domain);
        $this->assertFalse($first['manual'], $first['message']);
        $this->travel(2)->minutes();
        $this->assertFalse($registrar->register($domain->fresh())['manual']);
        // The third failure is still within six hours, well beyond the general 90-second window.
        $this->travel(357)->minutes();
        $this->assertTrue($registrar->register($domain->fresh())['manual']);

        $this->assertSame(3, (int) $domain->fresh()->provision_tries);
        $this->assertSame('manual', $domain->fresh()->provision_status);
        $this->assertSame('pending', $domain->fresh()->status);
        $this->assertCount(1, $this->messages);
        $this->assertCount(1, $this->emails, 'The existing manual-escalation email remains independent of Bale coalescing.');
        $this->assertSame($money, $this->moneyState($invoice, $customer));
        $posts = Http::recorded(fn ($r) => $r->method() === 'POST'
            && str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/domains'));
        $this->assertCount(3, $posts, 'The notification policy must not change the existing attempt flow.');
    }

    public function test_policy_disabled_keeps_first_funding_failure_silent_and_pending(): void
    {
        Setting::put(BaleManagerPolicy::SETTING, '0');
        [$domain] = $this->paidOrder();
        $result = app(DomainRegistrar::class)->register($domain);
        $this->assertFalse($result['manual'], $result['message']);
        $this->assertSame(1, (int) $domain->fresh()->provision_tries);
        $this->assertSame('pending', $domain->fresh()->provision_status);
        $this->assertCount(0, $this->messages);
        $this->assertCount(0, $this->emails);
    }

    public function test_nonfunding_provider_failure_does_not_create_a_new_first_attempt_alert(): void
    {
        $this->providerFailure = 'Temporary registrar gateway error';
        [$domain] = $this->paidOrder();
        $result = app(DomainRegistrar::class)->register($domain);
        $this->assertFalse($result['manual'], $result['message']);
        $this->assertSame(1, (int) $domain->fresh()->provision_tries);
        $this->assertSame('pending', $domain->fresh()->provision_status);
        $this->assertCount(0, $this->messages);
        $this->assertCount(0, $this->emails);
    }

    public function test_changed_cause_and_an_independent_domain_remain_immediate(): void
    {
        [$domain, , $customer] = $this->paidOrder();
        $registrar = app(DomainRegistrar::class);
        $registrar->register($domain);
        $this->providerFailure = 'Not enough funds';
        $registrar->register($domain->fresh());
        [$other] = $this->paidOrder($customer, 'second-funding-notice.com');
        $registrar->register($other);
        $this->assertCount(3, $this->messages);
        $this->assertStringContainsString('Not enough funds', $this->messages[1]['text']);
        $this->assertStringContainsString($other->domain, $this->messages[2]['text']);
        $this->assertCount(0, $this->emails);
    }

    public function test_funding_marker_expiry_and_verified_recovery_allow_a_new_incident(): void
    {
        $policy = app(BaleManagerPolicy::class);
        $rows = ['دامنه' => 'recovered-example.com', 'مشتری' => 'Synthetic (SN-900001)',
            'رجیسترار' => 'Openprovider', 'علت' => 'Insufficient balance'];
        $decision = $policy->decide('admin.domain_register_failed', 'fixture', $rows);
        $this->assertTrue($policy->claim($decision));
        $this->assertFalse($policy->claim($decision));
        $this->travel(361)->minutes();
        $this->assertTrue($policy->claim($decision), 'The marker expires after six hours.');
        $this->assertFalse($policy->claim($decision));
        BaleManagerPolicy::clearDomainFundingAttempt($rows['دامنه'], $rows['مشتری']);
        $this->assertTrue($policy->claim($decision), 'A verified success clears reporting state for a later independent incident.');
    }

    private function paidOrder(?Customer $customer = null, string $fqdn = 'funding-notice.com'): array
    {
        if (! $customer) {
            $customer = Customer::create(['code' => 'SN-900001', 'email' => 'funding-customer@example.invalid',
                'phone' => '09121111111', 'password' => bcrypt('fixture'), 'status' => 'active', 'locale' => 'fa']);
            CustomerProfile::create(['customer_id' => $customer->id, 'type' => 'individual', 'is_default' => true,
                'status' => 'verified', 'email' => $customer->email, 'mobile' => $customer->phone,
                'country' => 'IR', 'province' => 'تهران', 'city' => 'تهران', 'address' => 'نشانی ساختگی آزمون',
                'postal_code' => '1234567890', 'first_name' => 'آزمایش', 'last_name' => 'موجودی']);
            CreditEntry::create(['customer_id' => $customer->id, 'currency_code' => 'IRT',
                'amount' => 5000000, 'balance_after' => 5000000, 'reason' => 'adjustment']);
        }
        [$sld, $tld] = explode('.', $fqdn, 2);
        $domain = Domain::create(['customer_id' => $customer->id, 'domain' => $fqdn, 'sld' => $sld, 'tld' => $tld,
            'registrar' => 'openprovider', 'status' => 'pending', 'provision_status' => 'pending',
            'period_years' => 1, 'price_toman' => 2500000]);

        return [$domain, $this->paidDomainInvoice($domain), $customer];
    }

    private function moneyState(Invoice $invoice, Customer $customer): array
    {
        return ['invoice' => $invoice->fresh()->getRawOriginal(),
            'credit' => DB::table('credit_ledger')->where('customer_id', $customer->id)->orderBy('id')
                ->get()->map(fn ($row) => (array) $row)->all(),
            'payments' => DB::table('payments')->count(), 'business_entries' => DB::table('business_ledger')->count()];
    }
}

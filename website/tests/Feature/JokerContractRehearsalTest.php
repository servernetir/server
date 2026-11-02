<?php

namespace Tests\Feature;

use App\Models\DomainSupplierAccount;
use App\Services\Domain\Providers\JokerAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Offline rehearsal of the documented wire protocol, not an OT&E acceptance receipt. */
class JokerContractRehearsalTest extends TestCase
{
    use RefreshDatabase;

    public static function financialOperations(): array
    {
        return ['registration' => ['create', 'domain-register'], 'renewal' => ['renew', 'domain-renew'],
            'transfer' => ['transfer', 'domain-transfer-in-reseller']];
    }

    #[DataProvider('financialOperations')]
    public function test_contact_to_order_pending_and_confirmed_delivery(string $operation, string $command): void
    {
        Http::preventStrayRequests();
        $account = new DomainSupplierAccount(['provider' => 'joker', 'environment' => 'ote',
            'credentials' => ['api_key' => 'synthetic-ote-key'], 'settings' => ['supplier_fee_pct' => 0, 'mandatory_fee_minor' => 0]]);
        $reply = fn (string $body = '', string $headers = '') => Http::response("Status-Code: 1000\n".$headers."\n".$body);
        $complete = false; $mutations = [];
        $columns = 'order_id,proc_id,created_at,status,type,amount_brutto,amount_netto,currency,identifier';
        Http::fake(function (Request $request) use (&$complete, &$mutations, $reply, $operation, $command, $columns) {
            // Every attempted request, including accidental unfaked traffic, is checked.
            $this->assertSame('dmapi.ote.joker.com', parse_url($request->url(), PHP_URL_HOST));
            $path = basename(parse_url($request->url(), PHP_URL_PATH));
            if ($path === 'login') return $reply('', "Auth-SID: synthetic-ote-session\n");
            if ($path === 'contact-create') {
                $mutations[] = $path;
                $this->assertSame('Y', $request['individual']);
                $this->assertSame('Synthetic', $request['fname']);
                $this->assertSame('Contact', $request['lname']);
                return $reply('', "Proc-ID: contact-1\nTracking-ID: contact-track-1\nResult: ACK\n");
            }
            if ($path === 'query-contact-list') return $reply("CCOM-SYNTHETIC\n");
            if ($path === 'query-order-list' && $request['proc-id'] === 'contact-1') {
                return $reply("1\tcontact-1\t2026-11-02T12:00:00Z\tok\tcreate-contact\t0.00\t0.00\t\tCCOM-SYNTHETIC\n", "Columns: $columns\n");
            }
            if ($path === 'domain-check') {
                $status = $operation === 'create' ? 'available' : 'unavailable';
                return $reply("domain-status: $status\ndomain-class: standard\ndomain-price-$operation: 8.21 USD 1y\n");
            }
            if ($path === 'query-domain-list') return $reply('synthetic.com'."\t".($complete ? '2028-11-02' : '2027-11-02')."\tlock\n");
            if ($path === $command) {
                $mutations[] = $path;
                $this->assertSame('POST', $request->method());
                $this->assertSame('8.21', $request['max-price']);
                $this->assertStringNotContainsString('auth-sid', $request->url());
                if ($operation === 'renew') {
                    $this->assertSame(2028, $request['expyear']);
                    $this->assertNull($request['period']);
                } else {
                    $this->assertSame('CCOM-SYNTHETIC', $request['owner-c']);
                    $this->assertSame(0, $request['autorenew']);
                    $this->assertSame($operation === 'create' ? 12 : null, $request['period']);
                    if ($operation === 'transfer') $this->assertSame('synthetic-epp-secret', $request['transfer-auth-id']);
                }
                return $reply('', "Proc-ID: order-2\nTracking-ID: order-track-2\nResult: ACK\n");
            }
            if ($path === 'result-list') {
                $state = $complete ? 'ack' : '?';
                return $reply("20261102120000\torder-track-2\torder-2\t$command\tsynthetic.com\t$state\tsynthetic-cltrid\n");
            }
            if ($path === 'result-retrieve') return $reply('', "Result: ACK\n");
            if ($path === 'query-order-list') {
                return $reply("2\torder-2\t2026-11-02T12:00:00Z\tok\t$operation\t8.21\t8.21\tUSD\tsynthetic.com\n", "Columns: $columns\n");
            }
            $this->fail('Unexpected synthetic endpoint: '.$path);
        });
        $adapter = new JokerAdapter($account);
        $payload = ['name' => ['first_name' => 'Synthetic', 'last_name' => 'Contact'], 'email' => 'synthetic@example.test',
            'address' => ['street' => 'Test Street 12', 'city' => 'Test City', 'zipcode' => '12345', 'country' => 'US'],
            'phone' => ['country_code' => '+1', 'area_code' => '202', 'subscriber_number' => '5550100']];
        $contact = $adapter->createContact('com', $payload, 'synthetic-contact');
        $this->assertSame('queued', $contact['status']);
        $contact = $adapter->reconcileContact($contact + ['tld' => 'com']);
        $this->assertSame('succeeded', $contact['status']);
        $quote = $adapter->quote('synthetic.com', $operation, 12);
        $context = ['max_price_minor' => $quote['amount_minor'], 'currency' => 'USD', 'target_expiration_year' => 2028,
            'object_id' => 'synthetic.com', 'contact_handles' => array_fill_keys(['owner', 'admin', 'tech', 'billing'], $contact['handle']),
            'auth_code' => 'synthetic-epp-secret', 'name_servers' => ['ns1.example.test', 'ns2.example.test']];
        $accepted = $adapter->submit($operation, 'synthetic.com', 12, $context);
        $this->assertSame('queued', $accepted['status']);
        $journal = $accepted + ['operation' => $operation, 'fqdn' => 'synthetic.com', 'request_context' => $context];
        $this->assertSame('queued', $adapter->reconcile($journal)['status']);
        $complete = true;
        $delivered = $adapter->reconcile($journal);
        $this->assertSame('succeeded', $delivered['status']);
        $this->assertSame('2028-11-02', $delivered['expires_at']);
        $this->assertSame(821, $delivered['purchase_minor']);
        $this->assertSame('provider_reported', $delivered['cost_status']);
        $this->assertSame('succeeded', $adapter->reconcile($journal)['status']);
        $this->assertSame(['contact-create', $command], $mutations);
    }
}

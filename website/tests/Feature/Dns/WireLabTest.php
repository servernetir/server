<?php

namespace Tests\Feature\Dns;

use App\Services\Dns\DelegationInspector;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * پرس‌وجوی مستقیم از PowerDNSِ واقعیِ آزمایشگاه (infra/dns/lab/lab-up.sh).
 * فقط با DNS_LAB=1 و DNS_LAB_IP (نشانیِ WSL) اجرا می‌شود.
 */
#[Group('pdns-lab')]
class WireLabTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('DNS_LAB') !== '1' || ! getenv('DNS_LAB_IP')) {
            $this->markTestSkipped('DNS_LAB=1 and DNS_LAB_IP are required');
        }
    }

    public function test_secondaries_answer_authoritatively_over_udp_and_tcp(): void
    {
        $i = new DelegationInspector(allowPrivate: true);
        $ip = getenv('DNS_LAB_IP');

        foreach ([5301, 5302] as $port) {
            $udp = $i->soaAt($ip, $port, 'catalog.invalid');
            $tcp = $i->soaAt($ip, $port, 'catalog.invalid', tcpOnly: true);
            $this->assertSame('answer', $udp['state'], "udp :$port");
            $this->assertTrue($udp['aa'], "AA :$port");
            $this->assertSame('tcp', $tcp['transport']);
            $this->assertSame($udp['serial'], $tcp['serial']);
        }
    }

    public function test_no_recursion_for_foreign_names(): void
    {
        $r = (new DelegationInspector(allowPrivate: true))->soaAt(getenv('DNS_LAB_IP'), 5301, 'google.com');
        $this->assertSame('refused', $r['state']);
    }
}

<?php
// DNS-only behavior tests, runnable without Laravel dependencies or network access.
require __DIR__.'/../../app/Services/Dns/Wire/DnsFailure.php';
require __DIR__.'/../../app/Services/Dns/Wire/DnsMessage.php';
require __DIR__.'/../../app/Services/Dns/Wire/DnsResponse.php';
require __DIR__.'/../../app/Services/Dns/Wire/DnsWireClient.php';
require __DIR__.'/../../app/Services/Dns/HostingDomainConnection.php';

use App\Services\Dns\HostingDomainConnection;
use App\Services\Dns\Wire\{DnsResponse, DnsFailure, DnsWireClient};

final class HostingFixtureDns extends DnsWireClient
{
    public array $calls = [];
    public function __construct(public array $fixtures) {}
    public function query(string $server, int $port, string $name, string $type, bool $rd = false, float $timeout = self::DEFAULT_TIMEOUT, bool $tcpOnly = false): DnsResponse|DnsFailure
    {
        $this->calls[] = [$server, $name, $type];
        $values = $this->fixtures[$server][$type] ?? null;
        if ($values === null) return new DnsFailure(DnsFailure::TIMEOUT, 'fixture');
        $rcode = is_int($values) ? $values : 0;
        $rows = is_array($values) ? array_map(fn ($data) => ['name' => $name, 'type' => $type, 'data' => $data], $values) : [];
        return new DnsResponse(1, 0, true, false, false, true, true, false, false, $rcode, null, $rows, [], [], null);
    }
}
$ns = ['ns1.host.example', 'ns2.host.example'];
$ip = '203.0.113.10';
$good = ['NS' => $ns, 'A' => [$ip], 'AAAA' => []];
$cases = [
    'both resolvers point to assigned IP' => [$good, $good, 'connected'],
    'custom DNS with direct A still requires assigned NS' => [array_replace($good, ['NS' => ['other.example']]), array_replace($good, ['NS' => ['other.example']]), 'needs_setup'],
    'only one assigned NS with correct A is not green' => [array_replace($good, ['NS' => [$ns[0]]]), array_replace($good, ['NS' => [$ns[0]]]), 'needs_setup'],
    'extra nameserver with correct A is not green' => [array_replace($good, ['NS' => [...$ns, 'other.example']]), array_replace($good, ['NS' => [...$ns, 'other.example']]), 'needs_setup'],
    'resolver disagreement on NS with correct A is pending' => [$good, array_replace($good, ['NS' => ['other.example']]), 'propagating'],
    'NS order with correct A can be green' => [array_replace($good, ['NS' => array_reverse($ns)]), $good, 'connected'],
    'NS case and trailing dots are normalized' => [array_replace($good, ['NS' => array_map(fn ($v) => strtoupper($v).'.', $ns)]), $good, 'connected'],
    'wrong IP with correct NS is pending' => [array_replace($good, ['A' => ['203.0.113.20']]), array_replace($good, ['A' => ['203.0.113.20']]), 'propagating'],
    'unconfigured domain needs setup' => [['NS' => [], 'A' => [], 'AAAA' => []], ['NS' => [], 'A' => [], 'AAAA' => []], 'needs_setup'],
    'NXDOMAIN needs setup' => [['NS' => 3, 'A' => 3, 'AAAA' => 3], ['NS' => 3, 'A' => 3, 'AAAA' => 3], 'needs_setup'],
    'resolver disagreement is pending' => [$good, ['NS' => [], 'A' => [], 'AAAA' => []], 'propagating'],
    'one resolver failure is not green' => [$good, [], 'unknown'],
    'contradictory NXDOMAIN is unknown' => [array_replace($good, ['NS' => 3]), $good, 'unknown'],
    'SERVFAIL is unknown' => [['NS' => 2, 'A' => 2, 'AAAA' => 2], $good, 'unknown'],
    'all failures are unknown' => [[], [], 'unknown'],
    'mixed IP records are not green' => [array_replace($good, ['A' => [$ip, '203.0.113.20']]), array_replace($good, ['A' => [$ip, '203.0.113.20']]), 'propagating'],
    'unverified IPv6 record is not green' => [array_replace($good, ['AAAA' => ['2001:db8::1']]), array_replace($good, ['AAAA' => ['2001:db8::1']]), 'propagating'],
    'NS order is irrelevant' => [array_replace($good, ['NS' => array_reverse($ns), 'A' => []]), array_replace($good, ['A' => []]), 'propagating'],
];
$count = 0;
foreach ($cases as $label => [$first, $second, $state]) {
    $dns = new HostingFixtureDns(['1.1.1.1' => $first, '8.8.8.8' => $second]);
    $result = (new HostingDomainConnection($dns))->inspectDomain('website.example', $ns, $ip);
    if ($result['state'] !== $state) throw new RuntimeException($label.': '.json_encode($result));
    if (count($dns->calls) !== 6) throw new RuntimeException('Unexpected DNS budget');
    $count++;
}
foreach ([['invalid', $ns, $ip], ['website.example', [], $ip], ['website.example', ['ns1.example.test'], $ip], ['website.example', $ns, '']] as $args) {
    $dns = new HostingFixtureDns([]);
    $result = (new HostingDomainConnection($dns))->inspectDomain(...$args);
    if ($result['state'] !== 'unconfigured' || $dns->calls) throw new RuntimeException('Invalid configuration must not query DNS');
    $count++;
}
echo $count." DNS behavior scenarios passed; zero network requests.\n";


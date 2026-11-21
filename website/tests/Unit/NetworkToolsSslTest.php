<?php

namespace Tests\Unit;

use App\Services\NetworkTools;
use PHPUnit\Framework\TestCase;

class NetworkToolsSslTest extends TestCase
{
    private function probe(array $records): NetworkTools
    {
        return new class($records) extends NetworkTools {
            public ?string $connectedTo = null;
            public array $sslOptions = [];
            public function __construct(private array $records) {}
            protected function doh(string $endpoint, string $name, string $type, bool $dnssec = false): ?array
            {
                return ['Answer' => $this->records[$type] ?? []];
            }
            protected function connectSsl(string $address, $context, int &$errno, string &$errstr): mixed
            {
                $this->connectedTo = $address;
                $this->sslOptions = stream_context_get_options($context)['ssl'];
                $errstr = 'fixture has no socket';
                return false;
            }
        };
    }

    public function test_ipv6_only_host_uses_a_bracketed_public_address_and_keeps_sni(): void
    {
        $probe = $this->probe(['AAAA' => [['type' => 28, 'data' => '2606:4700:4700::1111']]]);
        $result = $probe->ssl('ipv6.example.test');
        $this->assertSame('no_ssl', $result['error']);
        $this->assertSame('ssl://[2606:4700:4700::1111]:443', $probe->connectedTo);
        $this->assertSame('ipv6.example.test', $probe->sslOptions['peer_name']);
        $this->assertTrue($probe->sslOptions['SNI_enabled']);
    }

    public function test_private_or_mixed_ipv6_answers_never_open_a_socket(): void
    {
        foreach (['::1', 'fd00::1', 'fe80::1', '::ffff:127.0.0.1'] as $private) {
            $probe = $this->probe(['AAAA' => [
                ['type' => 28, 'data' => '2606:4700:4700::1111'], ['type' => 28, 'data' => $private],
            ]]);
            $this->assertSame('invalid_domain', $probe->ssl('ipv6.example.test')['error']);
            $this->assertNull($probe->connectedTo);
        }
    }

    public function test_public_ipv4_destination_still_uses_the_validated_address(): void
    {
        $probe = $this->probe(['A' => [['type' => 1, 'data' => '8.8.8.8']]]);
        $this->assertSame('no_ssl', $probe->ssl('ipv4.example.test', 8443)['error']);
        $this->assertSame('ssl://8.8.8.8:8443', $probe->connectedTo);
    }
}

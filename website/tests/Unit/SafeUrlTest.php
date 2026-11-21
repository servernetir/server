<?php

namespace Tests\Unit;

use App\Services\SafeUrl;
use PHPUnit\Framework\TestCase;

class SafeUrlTest extends TestCase
{
    public function test_nonpublic_addresses_and_embedded_credentials_are_rejected(): void
    {
        foreach (['http://127.0.0.1', 'http://10.0.0.1', 'http://169.254.169.254',
            'http://100.64.0.1', 'http://[::1]', 'http://[::ffff:127.0.0.1]',
            'https://user:password@8.8.8.8', 'file:///tmp/test'] as $url) {
            $this->assertFalse(SafeUrl::allowed($url), $url);
            $this->assertNull(SafeUrl::curlOptionsFor($url), $url);
        }
    }

    public function test_public_address_connections_disable_proxies_and_automatic_redirects(): void
    {
        $options = SafeUrl::curlOptionsFor('https://8.8.8.8/example');
        $this->assertIsArray($options);
        $this->assertSame('', $options[CURLOPT_PROXY]);
        $this->assertFalse($options[CURLOPT_FOLLOWLOCATION]);
    }

    public function test_each_redirect_destination_is_checked_before_the_next_request(): void
    {
        $calls = [];
        $result = SafeUrl::resolveRedirects('https://8.8.8.8/path', function ($url) use (&$calls) {
            $calls[] = $url;

            return 'http://127.0.0.1/private';
        });
        $this->assertNull($result);
        $this->assertSame(['https://8.8.8.8/path'], $calls);
    }
}

<?php

namespace Tests\Unit;

use App\Services\Growth\GrowthClaims;
use PHPUnit\Framework\TestCase;

class GrowthClaimsBoundaryTest extends TestCase
{
    public function test_editorial_words_containing_sla_do_not_become_business_promises(): void
    {
        $claims = new GrowthClaims;
        foreach (['Kıyaslama testlerinde, 2-4 mikrosaniye gecikme ölçüldü.', 'Kıyaslama testleri, 64 bayt ve 256 bayt paketler kullandı.',
            'Translation of 3 models was evaluated.', 'Tesla 4 GPU results'] as $text) {
            $this->assertSame([], $claims->findings($text));
        }
    }

    public function test_real_sla_and_uptime_promises_are_still_flagged(): void
    {
        $claims = new GrowthClaims;
        foreach (['SLA: 99.9%', 'Our uptime is 99 percent', 'SLA 99', 'SLA99', '99.99% guaranteed'] as $text) {
            $this->assertContains('sla', $claims->findings($text));
        }
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PythonHostingSeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_python_purchase_pages_explain_runtime_fit_and_link_to_real_alternatives(): void
    {
        foreach ([
            'fa' => ['', 'هاست پایتون یا سرور مجازی؟'],
            'en' => ['/en', 'Python hosting or a VPS?'],
            'tr' => ['/tr', 'Python hosting mi VPS mi?'],
        ] as [$prefix, $heading]) {
            $html = $this->get($prefix.'/hosting/python')->assertOk()->getContent();

            $this->assertStringContainsString($heading, $html);
            $this->assertStringContainsString('href="'.url($prefix.'/vps/hourly').'"', $html);
            $this->assertStringContainsString('href="'.url($prefix.'/gpu').'"', $html);
            $this->assertStringContainsString('href="'.url($prefix.'/domains').'"', $html);
            $this->assertStringContainsString('WebSocket', $html);
            $this->assertStringContainsString('MySQL/MariaDB', $html);
        }

        $turkish = $this->get('/tr/hosting/python')->getContent();
        $this->assertMatchesRegularExpression('~<title>[^<]*Python Hosting Satın Al~u', $turkish);

        $hourly = $this->get('/vps/hourly')->assertOk()->getContent();
        $this->assertStringContainsString('href="'.url('/hosting/python').'"', $hourly);
    }
}

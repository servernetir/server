<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StudioHomeHeroTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_rotates_five_localized_services_and_keeps_stats_in_the_intro(): void
    {
        Http::preventStrayRequests();
        config(['services.gtm.enabled' => false, 'studio.enabled' => true,
            'studio.client_secret' => str_repeat('s', 48),
            'studio.redirect_uri' => 'https://studio.servernet.test/auth/callback']);
        foreach (['/' => 'fa', '/en' => 'en', '/tr' => 'tr'] as $path => $locale) {
            $html = $this->get($path)->assertOk()->getContent();
            $this->assertLessThan(strpos($html, 'id="studio"'), strpos($html, 'id="hero-infrastructure"'));
            $this->assertStringContainsString('data-hero-carousel', $html);
            $this->assertStringContainsString('data-interval="6000"', $html);
            $this->assertSame(5, substr_count($html, 'data-hero-slide="'));
            $this->assertSame(5, substr_count($html, 'data-hero-target="'));
            $this->assertStringContainsString('class="sn-home-intro"', $html);
            $this->assertStringContainsString('data-hero-autoplay', $html);
            $this->assertStringContainsString('aria-hidden="true" inert', $html);
            $this->assertStringContainsString('data-sn-globe', $html);
            $this->assertStringContainsString('href="https://studio.servernet.cloud/"', $html);
            $this->assertStringContainsString('assets/css/studio-carousel.css', $html);
            $this->assertStringContainsString('assets/js/studio-carousel.js', $html);
            $prefix = $locale === 'fa' ? '' : '/'.$locale;
            $this->assertStringContainsString('https://console.servernet.cloud'.$prefix.'/account/cloud-store"', $html);
            $this->assertStringContainsString('https://console.servernet.cloud'.$prefix.'/account/cloud-store?billing_mode=hourly"', $html);
            $this->assertLessThan(strpos($html, 'id="hero-gpu"'), strpos($html, 'id="hero-infrastructure"'));
            $this->assertLessThan(strpos($html, 'id="studio"'), strpos($html, 'id="hero-gpu"'));
            $this->assertStringContainsString($prefix.'/studio/download#studio-web-install', $html);
            foreach (['/gpu', '/cloud/n8n', '/hosting/linux', '/hosting/windows', '/hosting/wordpress', '/hosting/woocommerce', '/hosting/python', '/hosting/email', '/hosting/backup', '/hosting/download'] as $target) {
                $this->assertStringContainsString($prefix.$target.'"', $html);
            }
            $this->assertStringNotContainsString('studio_carousel.', $html);
            $this->assertStringNotContainsString('home_slides.', $html);
        }
        Http::assertNothingSent();
    }

    public function test_unconfigured_studio_does_not_advertise_an_unavailable_product(): void
    {
        config(['studio.enabled' => false]);
        $html = $this->get('/')->assertOk()->assertDontSee('data-studio-home', false)->getContent();
        $this->assertSame(4, substr_count($html, 'data-hero-slide="'));
        $this->assertSame(4, substr_count($html, 'data-hero-target="'));
        $this->assertStringContainsString('data-hero-carousel', $html);
        $this->assertStringContainsString('id="hero-n8n"', $html);
        $this->assertStringContainsString('id="hero-hosting"', $html);
    }
}

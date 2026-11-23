<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StudioHomeHeroTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_rotates_seven_localized_slides_and_keeps_stats_in_the_intro(): void
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
            $this->assertSame(7, substr_count($html, 'data-hero-slide="'));
            $this->assertSame(7, substr_count($html, 'data-hero-target="'));
            $this->assertStringContainsString('class="sn-home-intro"', $html);
            $this->assertStringContainsString('data-hero-autoplay', $html);
            $this->assertStringContainsString('aria-hidden="true" inert', $html);
            $this->assertStringContainsString('data-sn-home-globe', $html);
            $this->assertStringContainsString('href="https://studio.servernet.cloud/"', $html);
            $this->assertStringContainsString('assets/css/studio-carousel.css', $html);
            $this->assertStringContainsString('assets/js/studio-carousel.js', $html);
            $prefix = $locale === 'fa' ? '' : '/'.$locale;
            $this->assertStringContainsString('https://console.servernet.cloud'.$prefix.'/account/cloud-store"', $html);
            $this->assertStringContainsString('https://console.servernet.cloud'.$prefix.'/account/cloud-store?billing_mode=hourly"', $html);
            $this->assertLessThan(strpos($html, 'id="hero-gpu"'), strpos($html, 'id="hero-infrastructure"'));
            $this->assertLessThan(strpos($html, 'id="studio"'), strpos($html, 'id="hero-gpu"'));
            $this->assertLessThan(strpos($html, 'id="hero-email"'), strpos($html, 'id="hero-hosting"'));
            $this->assertLessThan(strpos($html, 'id="hero-parts"'), strpos($html, 'id="hero-email"'));
            $this->assertStringContainsString('assets/css/home-parts-slide.css', $html);
            $this->assertStringContainsString(__('home_slides.parts_title'), $html);
            $this->assertSame(4, substr_count($html, 'data-parts-category="'));
            $this->assertSame(4, substr_count($html, 'class="sn-parts-piece"'));
            $this->assertSame(1, substr_count($html, 'data-parts-chassis-art'));
            $this->assertStringContainsString('data-hero-image-src=', $html);
            $this->assertStringContainsString('class="sn-parts-halo" aria-hidden="true"', $html);
            $this->assertStringContainsString('class="sn-parts-chassis"', $html);
            $this->assertStringNotContainsString('sn-parts-frame', $html);
            $this->assertStringContainsString(__('studio_carousel.brand'), $html);
            $this->assertStringContainsString(__('home_slides.vps_accent'), $html);
            $this->assertStringContainsString('class="sn-mail-visual"', $html);
            $this->assertStringContainsString($prefix.'/studio/download#studio-web-install', $html);
            foreach (['/gpu', '/cloud/n8n', '/hosting/linux', '/hosting/windows', '/hosting/wordpress', '/hosting/woocommerce', '/hosting/python', '/hosting/email', '/hosting/backup', '/hosting/download', '/transactional-email', '/transactional-email#pricing', '/parts', '/parts/cpu', '/parts/ram', '/parts/disk', '/parts/raid', '/parts/chassis', '/servers/hp/gen8', '/servers/hp/gen9', '/servers/hp/gen10', '/servers/hp/gen11', '/servers/hp/gen12'] as $target) {
                $this->assertStringContainsString($prefix.$target.'"', $html);
            }
            $this->assertStringNotContainsString('studio_carousel.', $html);
            $this->assertStringNotContainsString('home_slides.', $html);
            $this->assertStringNotContainsString('transactional-email.slide_', $html);
        }
        Http::assertNothingSent();
    }

    public function test_unconfigured_studio_does_not_advertise_an_unavailable_product(): void
    {
        config(['studio.enabled' => false]);
        $html = $this->get('/')->assertOk()->assertDontSee('data-studio-home', false)->getContent();
        $this->assertSame(6, substr_count($html, 'data-hero-slide="'));
        $this->assertSame(6, substr_count($html, 'data-hero-target="'));
        $this->assertStringContainsString('data-hero-carousel', $html);
        $this->assertStringContainsString('id="hero-n8n"', $html);
        $this->assertStringContainsString('id="hero-hosting"', $html);
        $this->assertStringContainsString('id="hero-email"', $html);
        $this->assertStringContainsString('id="hero-parts"', $html);
    }
}

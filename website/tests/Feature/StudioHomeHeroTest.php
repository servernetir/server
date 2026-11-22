<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StudioHomeHeroTest extends TestCase
{
    use RefreshDatabase;

    public function test_carousel_starts_with_infrastructure_and_offers_studio_in_every_locale(): void
    {
        Http::preventStrayRequests();
        config(['services.gtm.enabled' => false, 'studio.enabled' => true,
            'studio.client_secret' => str_repeat('s', 48),
            'studio.redirect_uri' => 'https://studio.servernet.test/auth/callback']);
        foreach (['/' => 'fa', '/en' => 'en', '/tr' => 'tr'] as $path => $locale) {
            $html = $this->get($path)->assertOk()->getContent();
            $this->assertLessThan(strpos($html, 'id="studio"'), strpos($html, 'id="hero-infrastructure"'));
            $this->assertStringContainsString('data-hero-carousel', $html);
            $this->assertStringContainsString('data-interval="7000"', $html);
            $this->assertStringContainsString('data-hero-autoplay', $html);
            $this->assertStringContainsString('aria-hidden="true" inert', $html);
            $this->assertStringContainsString('data-sn-globe', $html);
            $this->assertStringContainsString('href="https://studio.servernet.cloud/"', $html);
            $this->assertStringContainsString('assets/css/studio-carousel.css', $html);
            $this->assertStringContainsString('assets/js/studio-carousel.js', $html);
            $prefix = $locale === 'fa' ? '' : '/'.$locale;
            $this->assertStringContainsString($prefix.'/studio/download#studio-web-install', $html);
            $this->assertStringNotContainsString('studio_carousel.', $html);
        }
        Http::assertNothingSent();
    }

    public function test_unconfigured_studio_does_not_advertise_an_unavailable_product(): void
    {
        config(['studio.enabled' => false]);
        $this->get('/')->assertOk()->assertDontSee('data-studio-home', false)
            ->assertDontSee('data-hero-carousel', false)->assertDontSee('data-hero-autoplay', false)
            ->assertDontSee('assets/css/studio-carousel.css', false)
            ->assertDontSee('assets/js/studio-carousel.js', false);
    }
}

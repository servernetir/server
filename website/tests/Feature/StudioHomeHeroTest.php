<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StudioHomeHeroTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_introduces_configured_studio_before_infrastructure_in_every_locale(): void
    {
        Http::preventStrayRequests();
        config(['services.gtm.enabled' => false, 'studio.enabled' => true,
            'studio.client_secret' => str_repeat('s', 48),
            'studio.redirect_uri' => 'https://studio.servernet.test/auth/callback']);
        foreach (['/' => 'fa', '/en' => 'en', '/tr' => 'tr'] as $path => $locale) {
            $html = $this->get($path)->assertOk()->getContent();
            $this->assertLessThan(strpos($html, '<section class="hero sn-hero">'), strpos($html, 'id="studio"'));
            $this->assertStringContainsString('href="https://studio.servernet.cloud/"', $html);
            $this->assertStringContainsString('assets/css/studio-home.css', $html);
            $this->assertStringContainsString('assets/js/studio-home.js', $html);
            $prefix = $locale === 'fa' ? '' : '/'.$locale;
            $this->assertStringContainsString($prefix.'/studio/download#studio-web-install', $html);
            $this->assertStringNotContainsString('studio_home.', $html);
        }
        Http::assertNothingSent();
    }

    public function test_unconfigured_studio_does_not_advertise_an_unavailable_product(): void
    {
        config(['studio.enabled' => false]);
        $this->get('/')->assertOk()->assertDontSee('data-studio-home', false)
            ->assertDontSee('assets/css/studio-home.css', false)
            ->assertDontSee('assets/js/studio-home.js', false);
    }
}

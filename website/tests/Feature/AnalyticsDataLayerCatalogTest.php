<?php

namespace Tests\Feature;

use App\Services\Analytics\DataLayerService;
use Tests\TestCase;

class AnalyticsDataLayerCatalogTest extends TestCase
{
    public function test_datalayer_service_flashes_signup_and_login_events(): void
    {
        $mockCustomer = (object) ['id' => 9999];

        DataLayerService::flashSignUp($mockCustomer, 'mobile_otp');
        $this->assertEquals([
            'event'   => 'sign_up',
            'method'  => 'mobile_otp',
        ], session(DataLayerService::SESSION_AUTH_KEY));

        DataLayerService::flashLogin($mockCustomer, 'mobile_otp');
        $this->assertEquals([
            'event'   => 'login',
            'method'  => 'mobile_otp',
        ], session(DataLayerService::SESSION_AUTH_KEY));
    }

    public function test_gtm_head_renders_servernet_analytics_helper(): void
    {
        $response = $this->get('/');
        $response->assertOk();
        $response->assertSee('ServerNetAnalytics=w.ServerNetAnalytics', false);
        $response->assertSee('view_item_list', false);
        $response->assertSee('begin_checkout', false);
        $response->assertSee('configure_product', false);
        $response->assertSee("gtag('consent','default'", false);
        $response->assertSee("analytics_storage:'granted'", false);
        $response->assertSee("s.id='snet-gtm'", false);
        $response->assertDontSee('analytics-consent', false);
        $response->assertDontSee('snet_analytics_consent', false);
        $response->assertSee('trackFunnel', false);
        $response->assertDontSee("auth('customer')->id()", false);
    }

    public function test_disabled_analytics_does_not_render_or_open_google_csp_hosts(): void
    {
        config()->set('services.gtm.enabled', false);
        config()->set('services.gtm.id', 'GTM-TEST123');

        $response = $this->get('/')->assertOk();
        $response->assertDontSee('googletagmanager.com', false);
        $response->assertDontSee('analytics-consent', false);

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringNotContainsString('googletagmanager.com', $csp);
        $this->assertStringNotContainsString('google-analytics.com', $csp);
    }
}

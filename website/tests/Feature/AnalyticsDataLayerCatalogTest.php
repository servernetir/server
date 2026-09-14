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
            'user_id' => '9999',
        ], session(DataLayerService::SESSION_AUTH_KEY));

        DataLayerService::flashLogin($mockCustomer, 'mobile_otp');
        $this->assertEquals([
            'event'   => 'login',
            'method'  => 'mobile_otp',
            'user_id' => '9999',
        ], session(DataLayerService::SESSION_AUTH_KEY));
    }

    public function test_gtm_head_renders_servernet_analytics_helper(): void
    {
        $response = $this->get('/');
        $response->assertOk();
        $response->assertSee('window.ServerNetAnalytics', false);
        $response->assertSee('view_item_list', false);
        $response->assertSee('begin_checkout', false);
        $response->assertSee('configure_product', false);
    }
}

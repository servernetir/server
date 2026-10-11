<?php

namespace Tests\Feature;

use App\Models\CloudLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CloudScheduledNetworkNoticeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_iraq_notice_is_localized_and_does_not_appear_for_other_locations(): void
    {
        CloudLocation::create(['code' => 'iq-baghdad', 'country' => 'IQ', 'city' => 'Baghdad', 'is_active' => true]);
        CloudLocation::create(['code' => 'ph-manila', 'country' => 'PH', 'city' => 'Manila', 'is_active' => true]);
        $this->travelTo(new \DateTimeImmutable('2026-10-12 03:35:00 UTC'));

        $this->get('/cloud/iq-baghdad')->assertOk()->assertSee('اطلاعیه شبکه عراق')->assertSee('۰۷:۰۰ تا ۰۷:۴۰ تهران');
        $this->get('/en/cloud/iq-baghdad')->assertOk()->assertSee('Iraq network notice')->assertSee('03:30–04:10 UTC');
        $this->get('/tr/cloud/iq-baghdad')->assertOk()->assertSee('Irak ağ duyurusu');
        $this->get('/en/cloud/ph-manila')->assertOk()->assertDontSee('Iraq network notice');
    }

    public function test_the_iraq_notice_is_not_left_as_a_permanent_outage_warning(): void
    {
        CloudLocation::create(['code' => 'iq-baghdad', 'country' => 'IQ', 'city' => 'Baghdad', 'is_active' => true]);
        foreach (['2026-10-10 23:59:59 UTC', '2026-10-13 00:00:00 UTC'] as $date) {
            $this->travelTo(new \DateTimeImmutable($date));
            $this->get('/en/cloud/iq-baghdad')->assertOk()->assertDontSee('Iraq network notice');
        }
    }
}

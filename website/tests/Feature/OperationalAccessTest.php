<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OperationalAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['code' => 0], 200)]);
        Mail::fake();
    }

    public function test_diagnostics_reject_guests_before_network_access(): void
    {
        foreach (['sms-status', 'crypto-status', 'openprovider', 'health', 'classcheck', 'tables', 'db-status', 'preflight', 'whoami'] as $path) {
            $this->get('/system/'.$path)->assertForbidden();
        }
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_lower_staff_roles_cannot_read_diagnostics(): void
    {
        foreach (['support', 'author'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->get('/system/sms-status')->assertForbidden();
            $this->get('/system/openprovider')->assertForbidden();
        }
        Http::assertNothingSent();
    }

    public function test_admin_can_read_configuration_without_delivery(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get('/system/sms-status')->assertOk()->assertJsonStructure(['bale_relay']);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_admin_get_cannot_trigger_side_effects(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get('/system/openprovider?probe=1')->assertStatus(405);
        $this->get('/system/openprovider?mailtest=1')->assertStatus(405);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_basic_liveness_remains_public(): void
    {
        $this->get('/up')->assertOk();
        $this->get('/healthz')->assertOk();
    }
}

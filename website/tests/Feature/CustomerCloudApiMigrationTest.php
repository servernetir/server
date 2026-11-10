<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerApiToken;
use Tests\TestCase;

class CustomerCloudApiMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(app()->environment('testing'));
        $this->artisan('migrate:fresh')->assertSuccessful();
    }

    protected function tearDown(): void
    {
        // Rebuild only the disposable test database; avoid unrelated historical down migrations.
        $this->artisan('migrate:fresh')->assertSuccessful();
        parent::tearDown();
    }

    public function test_migration_round_trip_preserves_existing_tokens_and_permissions(): void
    {
        $c = Customer::create(['email' => 'migration@example.test', 'status' => 'active']);
        [$token, $plain] = CustomerApiToken::issue($c->id, 'Legacy read', ['read']);
        $migration = require database_path('migrations/2026_11_10_000100_create_customer_api_operations.php');
        $migration->down(); $migration->up();
        $this->assertSame(['read'], $token->fresh()->abilities);
        $this->assertNull($token->fresh()->cloud_service_ids);
        $this->assertSame($token->id, CustomerApiToken::findByPlain($plain)->id);
        $this->assertFalse($token->fresh()->can('cloud:power'));
    }
}

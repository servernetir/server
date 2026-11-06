<?php

namespace Tests\Feature;

use App\Models\CrmSuppression;
use App\Models\OutreachContact;
use App\Models\User;
use App\Services\Crm\OutboundSuppression;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OutboundSuppressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_seo_unsubscribe_blocks_crm_without_copying_private_rows(): void
    {
        OutreachContact::create(['host' => 'first.example', 'email' => 'owner@example.com',
            'unsubscribed_at' => now(), 'status' => 'skipped']);
        $before = DB::table('crm_suppression')->count();

        $this->assertTrue(CrmSuppression::blocks(' OWNER@EXAMPLE.COM '));
        $this->assertSame($before, DB::table('crm_suppression')->count());
    }

    public function test_crm_domain_stop_blocks_seo_import_and_single_send(): void
    {
        Mail::fake();
        CrmSuppression::add('owner@example.com', 'unsubscribe');
        $this->assertTrue(OutreachContact::isSuppressed('sales@example.com'));
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson('/admin/seo/list', ['list' => 'example.com, sales@example.com'])
            ->assertOk()->assertJsonPath('added', 0);
        $this->postJson('/admin/seo/send-one', ['url' => 'https://example.com', 'email' => 'sales@example.com'])
            ->assertStatus(422)->assertJsonPath('error', 'unsubscribed');
        Mail::assertNothingSent();
    }

    public function test_unsuppressed_address_is_allowed_and_invalid_address_is_blocked(): void
    {
        $this->assertFalse(OutboundSuppression::blocks('owner@example.com'));
        foreach ([null, '', 'not-an-email'] as $email) {
            $this->assertTrue(OutboundSuppression::blocks($email));
        }
    }

    public function test_incomplete_installation_cannot_prove_permission_to_contact(): void
    {
        Schema::rename('crm_suppression', 'crm_suppression_fixture_unavailable');
        try {
            $this->assertTrue(OutboundSuppression::blocks('owner@example.com'));
        } finally {
            Schema::rename('crm_suppression_fixture_unavailable', 'crm_suppression');
        }
    }
}

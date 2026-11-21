<?php
namespace Tests\Feature;
use App\Models\{Customer, McpAdminAction, Service, Setting, Ticket, User};
use App\Services\Mcp\{AdminAccess, ManagementRead, TicketReplies};
use App\Services\Ticket\TicketReplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Token;
use Tests\TestCase;
class AdminMcpManagementTest extends TestCase
{
    use RefreshDatabase;
    private function owner(array $scopes = [AdminAccess::READ, AdminAccess::TICKET_REPLY]): User
    {
        $u = User::factory()->create(['role' => 'admin']);
        Setting::put('mcp_admin_enabled', '1'); Setting::put('mcp_admin_owner_user_id', (string) $u->id);
        Setting::put('mcp_admin_user_ids', json_encode([$u->id]));
        Token::create(['id' => str_repeat('a', 80), 'user_id' => $u->id, 'client_id' => (string) Str::uuid(), 'scopes' => $scopes, 'revoked' => false, 'expires_at' => now()->addHour()]);
        return $u->withAccessToken(new AccessToken(['oauth_access_token_id' => str_repeat('a', 80),
            'oauth_user_id' => (string) $u->id, 'oauth_scopes' => $scopes]));
    }
    private function ticket(): Ticket
    {
        $c = Customer::create(['email' => 'fixture@example.test', 'status' => 'active']);
        return Ticket::create(['customer_id' => $c->id, 'subject' => 'fixture', 'status' => 'open',
            'department' => 'support', 'priority' => 'normal']);
    }
    public function test_ticket_pagination_and_secret_screening(): void
    {
        $t = $this->ticket();
        for ($i = 0; $i < 22; $i++) $t->messages()->create(['author_role' => 'customer', 'body' => 'message '.$i]);
        $t->messages()->create(['author_role' => 'staff', 'body' => 'password=private123 https://example.test/sso?token=private', 'is_internal' => true]);
        $r = app(ManagementRead::class); $p = $r->ticket($t->id);
        $this->assertCount(20, $p['messages']); $this->assertTrue($p['content_is_untrusted']);
        $this->assertTrue($p['messages'][19]['internal']);
        $this->assertStringNotContainsString('private123', json_encode($p));
        $this->assertStringNotContainsString('/sso', json_encode($p));
        $this->assertCount(3, $r->ticket($t->id, $p['next_before_id'])['messages']);
    }
    public function test_service_projection_excludes_credentials_and_filters_customer(): void
    {
        $c = $this->ticket()->customer;
        Service::create(['customer_id' => $c->id, 'name' => 'fixture', 'status' => 'active', 'cycle' => 'monthly',
            'currency_code' => 'IRR', 'price' => 100000, 'password' => 'private', 'provision_meta' => ['token' => 'private'], 'panel_url' => 'https://example.test/sso']);
        $r = app(ManagementRead::class); $row = $r->services($c->id)['services'][0];
        $this->assertSame('IRR', $row['currency_code']);
        foreach (['password', 'provision_meta', 'panel_url'] as $key) $this->assertArrayNotHasKey($key, $row);
        $this->assertSame([], $r->services($c->id + 100)['services']);
    }
    public function test_same_reply_id_sends_once_and_is_encrypted(): void
    {
        $u = $this->owner(); $t = $this->ticket(); $id = (string) Str::uuid(); $w = app(TicketReplies::class);
        $this->assertTrue($w->post($u, $id, $t->id, 'Private internal fixture', 0, true)['ok']);
        $this->assertTrue($w->post($u, $id, $t->id, 'Private internal fixture', 0, true)['replayed']);
        $this->assertSame(1, $t->messages()->count()); $this->assertTrue($t->messages()->first()->is_internal);
        $this->assertSame('open', $t->fresh()->status);
        $this->assertStringNotContainsString('Private internal fixture', McpAdminAction::find($id)->getRawOriginal('command'));
    }
    public function test_changed_payload_is_rejected(): void
    {
        $u = $this->owner(); $t = $this->ticket(); $id = (string) Str::uuid(); $w = app(TicketReplies::class);
        $w->post($u, $id, $t->id, 'first', 0, true);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $w->post($u, $id, $t->id, 'different', 0, true);
    }
    public function test_stale_ticket_does_not_send(): void
    {
        $u = $this->owner(); $t = $this->ticket();
        $t->messages()->create(['author_role' => 'customer', 'body' => 'new']);
        try { app(TicketReplies::class)->post($u, (string) Str::uuid(), $t->id, 'old', 0); $this->fail('Must reject stale reply'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(409, $e->getStatusCode()); }
        $this->assertDatabaseCount('mcp_admin_actions', 0); $this->assertSame(1, $t->messages()->count());
    }
    public function test_new_request_key_cannot_race_an_unreconciled_reply(): void
    {
        $u = $this->owner(); $t = $this->ticket(); $id = (string) Str::uuid();
        $this->mock(TicketReplyService::class)->shouldReceive('post')->once()->andThrow(new \RuntimeException('fixture'));
        app(TicketReplies::class)->post($u, $id, $t->id, 'fixture', 0);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(TicketReplies::class)->post($u, (string) Str::uuid(), $t->id, 'fixture', 0);
    }

    public function test_infrastructure_scope_cannot_reply(): void
    {
        $u = $this->owner([AdminAccess::READ, AdminAccess::EXECUTE]);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(TicketReplies::class)->post($u, (string) Str::uuid(), $this->ticket()->id, 'fixture', 0);
    }
    public function test_unknown_outcome_is_never_sent_again(): void
    {
        $u = $this->owner(); $t = $this->ticket(); $id = (string) Str::uuid();
        $this->mock(TicketReplyService::class)->shouldReceive('post')->once()->andThrow(new \RuntimeException('fixture'));
        $w = app(TicketReplies::class);
        $this->assertSame('unknown', $w->post($u, $id, $t->id, 'fixture', 0)['outcome']);
        $this->assertTrue($w->post($u, $id, $t->id, 'fixture', 0)['replayed']);
    }
}

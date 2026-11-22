<?php

namespace Tests\Feature;

use App\Models\McpAdminAction;
use App\Models\Setting;
use App\Models\User;
use App\Services\Mcp\AdminAccess;
use App\Services\Mcp\CommandApprovals;
use App\Services\Mcp\SshGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Token;
use Tests\TestCase;

class AdminMcpCommandsTest extends TestCase
{
    use RefreshDatabase;

    private function operator(array $scopes = [AdminAccess::READ, AdminAccess::EXECUTE]): User
    {
        $u = User::factory()->create(['role' => 'admin']);
        Setting::put('mcp_admin_enabled', '1');
        Setting::put('mcp_admin_owner_user_id', (string) $u->id);
        Setting::put('mcp_admin_user_ids', json_encode([$u->id]));
        // Existing cases exercise the explicit manual fallback.
        Setting::put('mcp_admin_ssh_auto_approve', '0');
        return $this->attachOperatorToken($u, $scopes);
    }

    private function attachOperatorToken(User $u, array $scopes): User
    {
        $id = Str::random(80);
        Token::create(['id' => $id, 'user_id' => $u->id, 'client_id' => (string) Str::uuid(),
            'scopes' => $scopes, 'revoked' => false, 'expires_at' => now()->addHour()]);
        return $u->withAccessToken(new AccessToken(['oauth_access_token_id' => $id,
            'oauth_user_id' => (string) $u->id, 'oauth_scopes' => $scopes]));
    }

    private function gateway(string $fingerprint = 'approved-target'): \Mockery\MockInterface
    {
        $mock = $this->mock(SshGateway::class);
        $mock->shouldReceive('targetHash')->with('proxmox-ir')->andReturn(hash('sha256', $fingerprint));
        return $mock;
    }

    public function test_request_is_encrypted_and_cannot_execute_before_owner_approval(): void
    {
        $u = $this->operator(); $gateway = $this->gateway();
        $gateway->shouldNotReceive('run');
        $proposal = app(CommandApprovals::class)->request($u, 'proxmox-ir', 'systemctl restart example.service');
        $action = McpAdminAction::findOrFail($proposal['id']);
        $this->assertStringNotContainsString('systemctl', $action->getRawOriginal('command'));
        $this->assertSame('awaiting_owner_approval', $proposal['status']);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(CommandApprovals::class)->execute($u, $action->id);
    }

    public function test_approved_command_executes_once_and_result_is_encrypted(): void
    {
        $u = $this->operator(); $gateway = $this->gateway();
        $gateway->shouldReceive('run')->once()->with('proxmox-ir', 'uptime')->andReturn(['ok' => true, 'output' => 'private-output']);
        $service = app(CommandApprovals::class);
        $id = $service->request($u, 'proxmox-ir', 'uptime')['id'];
        $service->approve($u, $id);
        $this->assertTrue($service->execute($u, $id)['ok']);
        $this->assertTrue($service->execute($u, $id)['replayed']);
        $this->assertStringNotContainsString('private-output', McpAdminAction::find($id)->getRawOriginal('result'));
    }

    public function test_unknown_outcome_is_not_reexecuted(): void
    {
        $u = $this->operator(); $gateway = $this->gateway();
        $gateway->shouldReceive('run')->once()->andThrow(new \RuntimeException('lost transport'));
        $service = app(CommandApprovals::class); $id = $service->request($u, 'proxmox-ir', 'reboot')['id'];
        $service->approve($u, $id);
        $this->assertSame('unknown', $service->execute($u, $id)['outcome']);
        $this->assertTrue($service->execute($u, $id)['replayed']);
    }

    public function test_another_owner_cannot_read_or_approve_a_command(): void
    {
        $u = $this->operator(); $this->gateway()->shouldNotReceive('run');
        $id = app(CommandApprovals::class)->request($u, 'proxmox-ir', 'uptime')['id'];
        $other = $this->operator();
        $this->actingAs($other, 'web')->get('/admin/mcp/actions/'.$id)->assertNotFound();
        $this->post('/admin/mcp/actions/'.$id.'/approve', ['accept' => '1'])->assertNotFound();
    }

    public function test_read_scope_cannot_create_execution_requests(): void
    {
        $u = $this->operator([AdminAccess::READ]);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(CommandApprovals::class)->request($u, 'proxmox-ir', 'uptime');
    }

    public function test_same_owner_with_another_token_cannot_execute_the_approval(): void
    {
        $u = $this->operator(); $this->gateway()->shouldNotReceive('run');
        $service = app(CommandApprovals::class); $id = $service->request($u, 'proxmox-ir', 'uptime')['id'];
        $service->approve($u, $id);
        $this->attachOperatorToken($u, [AdminAccess::READ, AdminAccess::EXECUTE]);
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $service->execute($u, $id);
    }

    public function test_changed_target_or_host_key_invalidates_approval(): void
    {
        $u = $this->operator(); $this->gateway();
        $service = app(CommandApprovals::class); $id = $service->request($u, 'proxmox-ir', 'uptime')['id'];
        $service->approve($u, $id);
        $this->gateway('different-target')->shouldNotReceive('run');
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $service->execute($u, $id);
    }

    public function test_expired_or_in_progress_actions_cannot_be_retried(): void
    {
        $u = $this->operator(); $this->gateway()->shouldNotReceive('run');
        $service = app(CommandApprovals::class); $id = $service->request($u, 'proxmox-ir', 'uptime')['id'];
        $service->approve($u, $id);
        McpAdminAction::whereKey($id)->update(['status' => 'executing']);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $service->execute($u, $id);
    }

    public function test_expired_pending_request_cannot_be_approved(): void
    {
        $u = $this->operator(); $this->gateway()->shouldNotReceive('run');
        $service = app(CommandApprovals::class); $id = $service->request($u, 'proxmox-ir', 'uptime')['id'];
        McpAdminAction::whereKey($id)->update(['expires_at' => now()->subMinute()]);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $service->approve($u, $id);
    }

    public function test_default_policy_approves_without_a_console_visit_and_executes_once(): void
    {
        $u = $this->operator();
        Setting::put('mcp_admin_ssh_auto_approve', null);
        $this->gateway()->shouldReceive('run')->once()->with('proxmox-ir', 'uptime')->andReturn(['ok' => true]);
        $service = app(CommandApprovals::class);
        $proposal = $service->request($u, 'proxmox-ir', 'uptime');
        $this->assertSame('approved', $proposal['status']);
        $this->assertSame('automatic', $proposal['approval_mode']);
        $action = McpAdminAction::findOrFail($proposal['id']);
        $this->assertNotNull($action->approved_at);
        $this->assertStringNotContainsString('uptime', $action->getRawOriginal('command'));
        $this->assertTrue($service->execute($u, $action->id)['ok']);
        $this->assertTrue($service->execute($u, $action->id)['replayed']);
    }

    public function test_automatic_policy_does_not_grant_execute_scope_or_access_to_other_admins(): void
    {
        $owner = $this->operator([AdminAccess::READ]);
        Setting::put('mcp_admin_ssh_auto_approve', '1');
        $other = $this->attachOperatorToken(User::factory()->create(['role' => 'admin']), [AdminAccess::EXECUTE]);
        $this->gateway()->shouldNotReceive('run');
        foreach ([$owner, $other] as $user) {
            try {
                app(CommandApprovals::class)->request($user, 'proxmox-ir', 'uptime');
                $this->fail('Unauthorized execution request was accepted.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
        $this->assertSame(0, McpAdminAction::count());
    }

    public function test_automatic_approval_is_still_bound_to_the_requesting_token(): void
    {
        $u = $this->operator(); Setting::put('mcp_admin_ssh_auto_approve', '1');
        $this->gateway()->shouldNotReceive('run');
        $service = app(CommandApprovals::class); $id = $service->request($u, 'proxmox-ir', 'uptime')['id'];
        $this->attachOperatorToken($u, [AdminAccess::EXECUTE]);
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $service->execute($u, $id);
    }

    public function test_automatic_approval_rejects_changed_host_keys(): void
    {
        $u = $this->operator(); Setting::put('mcp_admin_ssh_auto_approve', '1'); $this->gateway();
        $service = app(CommandApprovals::class); $id = $service->request($u, 'proxmox-ir', 'uptime')['id'];
        $this->gateway('changed')->shouldNotReceive('run');
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $service->execute($u, $id);
    }

    public function test_automatic_approval_does_not_retry_unknown_results(): void
    {
        $u = $this->operator(); Setting::put('mcp_admin_ssh_auto_approve', '1');
        $this->gateway()->shouldReceive('run')->once()->andThrow(new \RuntimeException('lost transport'));
        $service = app(CommandApprovals::class); $id = $service->request($u, 'proxmox-ir', 'uptime')['id'];
        $this->assertSame('unknown', $service->execute($u, $id)['outcome']);
        $this->assertTrue($service->execute($u, $id)['replayed']);
    }

    public function test_automatic_approval_does_not_revive_expired_actions(): void
    {
        $u = $this->operator(); Setting::put('mcp_admin_ssh_auto_approve', '1');
        $this->gateway()->shouldNotReceive('run');
        $service = app(CommandApprovals::class); $id = $service->request($u, 'proxmox-ir', 'uptime')['id'];
        McpAdminAction::whereKey($id)->update(['expires_at' => now()->subSecond()]);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $service->execute($u, $id);
    }

    public function test_only_the_enabled_owner_can_change_the_policy(): void
    {
        $u = $this->operator();
        $this->actingAs($u, 'web')->post('/admin/mcp/command-policy', ['automatic' => '1'])->assertRedirect('/admin/mcp');
        $this->assertTrue(AdminAccess::autoApproveCommands($u));
        $this->post('/admin/mcp/command-policy', ['automatic' => '0'])->assertRedirect('/admin/mcp');
        $this->assertFalse(AdminAccess::autoApproveCommands($u));
        $other = User::factory()->create(['role' => 'admin']);
        $this->actingAs($other, 'web')->post('/admin/mcp/command-policy', ['automatic' => '1'])->assertForbidden();
        $this->assertFalse(AdminAccess::autoApproveCommands($u));
    }

    public function test_expired_pending_page_explains_the_missing_approval_form(): void
    {
        $u = $this->operator(); $this->gateway();
        $id = app(CommandApprovals::class)->request($u, 'proxmox-ir', 'uptime')['id'];
        McpAdminAction::whereKey($id)->update(['expires_at' => now()->subMinute()]);
        $this->actingAs($u, 'web')->get('/admin/mcp/actions/'.$id)->assertOk()
            ->assertSee(__('mcp_admin.command_expired'))->assertDontSee('name="accept"', false);
    }
}

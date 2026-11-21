<?php

namespace Tests\Feature;

use App\Services\Mcp\SshGateway;
use Tests\TestCase;

class AdminMcpSshTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/servernet-mcp-ssh-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
        foreach (['identity', 'known_hosts'] as $name) {
            file_put_contents($this->dir.'/'.$name, 'synthetic-fixture'); chmod($this->dir.'/'.$name, 0600);
        }
        $registry = ['fixture' => ['host' => 'example.test', 'user' => 'ops', 'port' => 22,
            'identity_file' => $this->dir.'/identity', 'known_hosts_file' => $this->dir.'/known_hosts']];
        file_put_contents($this->dir.'/targets.json', json_encode($registry)); chmod($this->dir.'/targets.json', 0600);
        config(['mcp_admin.ssh_registry' => $this->dir.'/targets.json']);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') as $file) { unlink($file); } rmdir($this->dir);
        parent::tearDown();
    }

    public function test_ssh_uses_only_pinned_keys_and_never_executes_local_shell_interpolation(): void
    {
        $binary = $this->dir.'/fake-ssh';
        file_put_contents($binary, "#!/usr/bin/python3\nimport json,sys\nprint(json.dumps(sys.argv[1:]))\n"); chmod($binary, 0700);
        config(['mcp_admin.ssh_binary' => $binary]);
        $r = app(SshGateway::class)->run('fixture', 'echo $(touch /invalid-path); uname');
        $args = json_decode($r['output'], true);
        $this->assertTrue($r['ok']);
        $this->assertContains('StrictHostKeyChecking=yes', $args);
        $this->assertContains('BatchMode=yes', $args);
        $this->assertContains('ForwardAgent=no', $args);
        $this->assertContains('UserKnownHostsFile='.$this->dir.'/known_hosts', $args);
        $this->assertSame('echo $(touch /invalid-path); uname', end($args));
    }

    public function test_private_registry_and_missing_targets_fail_closed(): void
    {
        chmod($this->dir.'/targets.json', 0644); clearstatcache();
        $this->expectException(\RuntimeException::class);
        app(SshGateway::class)->inventory();
    }

    public function test_unknown_target_does_not_invoke_ssh(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(SshGateway::class)->run('-oProxyCommand=evil', 'uptime');
    }

    public function test_output_is_bounded_and_common_credentials_are_redacted(): void
    {
        $binary = $this->dir.'/fake-ssh';
        file_put_contents($binary, "#!/usr/bin/python3\nprint('token=private-token password=private-pass Bearer privatebearer')\nprint('x'*200000)\n"); chmod($binary, 0700);
        config(['mcp_admin.ssh_binary' => $binary, 'mcp_admin.output_limit' => 1000]);
        $r = app(SshGateway::class)->run('fixture', 'uptime');
        $this->assertTrue($r['truncated']);
        $this->assertLessThanOrEqual(1000, strlen($r['output']));
        $this->assertStringNotContainsString('private-', $r['output']);
        $this->assertStringNotContainsString('privatebearer', $r['output']);
    }
}

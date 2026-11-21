<?php

namespace App\Services\Mcp;

use Symfony\Component\Process\Process;

/** Destinations and identities are provisioned privately by the operator, never by tools. */
class SshGateway
{
    public const DIAGNOSTICS = [
        'uptime' => 'uptime',
        'disk' => 'df -hP',
        'memory' => 'free -m',
        'failed_services' => 'systemctl --failed --no-pager --plain',
    ];

    public function inventory(): array
    {
        return array_map(fn ($id, $t) => ['id' => $id, 'label' => $t['label'] ?? $id],
            array_keys($this->targets()), array_values($this->targets()));
    }

    private function targets(): array
    {
        $path = config('mcp_admin.ssh_registry');
        if (! is_file($path)) {
            return [];
        }
        if ((fileperms($path) & 0077) !== 0 || is_link($path)) {
            throw new \RuntimeException('SSH registry must be private.');
        }
        $targets = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        return is_array($targets) ? $targets : [];
    }

    public function targetHash(string $id): string
    {
        $t = $this->targets()[$id] ?? null;
        abort_unless(is_array($t), 422);
        foreach (['host', 'user', 'port', 'identity_file', 'known_hosts_file'] as $key) {
            abort_unless(isset($t[$key]) && is_scalar($t[$key]), 422);
        }
        abort_unless(preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9.:-]{0,252}\z/', $t['host'])
            && preg_match('/\A[a-zA-Z_][a-zA-Z0-9_-]{0,63}\z/', $t['user'])
            && filter_var($t['port'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]), 422);
        $dir = realpath(dirname(config('mcp_admin.ssh_registry')));
        foreach (['identity_file', 'known_hosts_file'] as $key) {
            $real = realpath($t[$key]);
            abort_unless($real && $dir && str_starts_with($real, $dir.'/')
                && ! is_link($t[$key]) && is_file($real), 422);
            abort_unless((fileperms($real) & 0077) === 0, 422);
        }
        return hash('sha256', json_encode($t, JSON_THROW_ON_ERROR)
            .hash_file('sha256', $t['identity_file']).hash_file('sha256', $t['known_hosts_file']));
    }

    public function run(string $id, string $command): array
    {
        $this->targetHash($id);
        $t = $this->targets()[$id];
        $process = new Process([
            config('mcp_admin.ssh_binary'), '-F', '/dev/null', '-T',
            '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes', '-o', 'StrictHostKeyChecking=yes',
            '-o', 'PasswordAuthentication=no', '-o', 'KbdInteractiveAuthentication=no',
            '-o', 'ConnectTimeout=5', '-o', 'ConnectionAttempts=1',
            '-o', 'ForwardAgent=no', '-o', 'ClearAllForwardings=yes',
            '-o', 'UserKnownHostsFile='.$t['known_hosts_file'],
            '-i', $t['identity_file'], '-p', (string) $t['port'],
            $t['user'].'@'.$t['host'], $command,
        ]);
        $process->setTimeout((int) config('mcp_admin.ssh_timeout', 30));
        $output = '';
        $limit = (int) config('mcp_admin.output_limit', 16384);
        $truncated = false;
        try {
            $process->run(function ($type, $chunk) use (&$output, &$truncated, $limit, $process) {
                $remaining = max(0, $limit - strlen($output));
                $output .= substr($chunk, 0, $remaining);
                $truncated = $truncated || strlen($chunk) > $remaining;
                // Symfony also retains output; clear it to keep a hostile command bounded.
                $process->clearOutput();
                $process->clearErrorOutput();
            });
            return ['ok' => $process->isSuccessful(), 'exit_code' => $process->getExitCode(),
                'output' => self::redact($output), 'truncated' => $truncated];
        } catch (\Throwable) {
            return ['ok' => false, 'code' => 'ssh_failed_or_timed_out', 'outcome' => 'unknown',
                'output' => self::redact($output), 'truncated' => $truncated];
        }
    }

    public static function redact(string $text): string
    {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = preg_replace('~-----BEGIN [^-]*PRIVATE KEY-----.*?(?:-----END [^-]*PRIVATE KEY-----|\z)~s', '[PRIVATE KEY REDACTED]', $text);
        $text = preg_replace('/(?i)(password|passwd|secret|token|api[_-]?key)(\s*[=:]\s*)[^\s]+/', '$1$2[REDACTED]', $text);
        $text = preg_replace('/(?i)Bearer\s+[A-Za-z0-9._~+\/-]+/', 'Bearer [REDACTED]', $text);
        return preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $text);
    }
}

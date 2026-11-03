<?php

declare(strict_types=1);

namespace App\Services\Cloud;

/** Fixed owned first-boot source. No customer script, provider secret or remote source download. */
final class ArvanLinuxBootstrap
{
    public const INSTALLER_SHA256 = 'ba2d8ef3c2fea7534e89ca163364c63d0161c1809c7452e3923cfbb0f90f6ab8';
    public const WRAPPER_SHA256 = '69541775c0a9ec4ac50a71c52d3c2652cba01e52cbae8c0384d4493119be7fef';

    /** Use only the resolved regional distribution's provider metadata. */
    public static function family(array $group, array $image): ?string
    {
        $families = [];
        foreach ([$group['name'] ?? null, $image['distribution_name'] ?? null, $image['name'] ?? null] as $hint) {
            if (!is_string($hint) || trim($hint) === '') {
                continue;
            }
            if (strlen($hint) > 256 || preg_match('/(?:^|[^a-z])(windows|centos|rocky|almalinux|fedora|alpine|freebsd|rhel|oracle|opensuse|arch)(?:[^a-z]|$)/i', $hint)) {
                return null;
            }
            if (preg_match('/\A\s*(ubuntu|debian)(?:\b|[_-])/i', $hint, $match)) {
                $families[] = strtolower($match[1]);
            }
        }
        $families = array_values(array_unique($families));

        return count($families) === 1 ? $families[0] : null;
    }

    private static function source(string $filename, string $expected): string
    {
        $path = dirname(__DIR__, 3).'/resources/arvan-monitoring/'.$filename;
        $stat = @lstat($path);
        if (!$stat || ($stat['mode'] & 0170000) !== 0100000 || $stat['size'] < 1 || $stat['size'] > 65536) {
            throw new \RuntimeException('bootstrap_source_unavailable');
        }
        $source = @file_get_contents($path, false, null, 0, 65537);
        if (!is_string($source) || strlen($source) > 65536 || !hash_equals($expected, hash('sha256', $source))) {
            throw new \RuntimeException('bootstrap_source_digest_mismatch');
        }

        return $source;
    }

    /** The v1 string follows official Terraform's unchanged init_script JSON passthrough. */
    public static function build(string $family): array
    {
        if (!in_array($family, ['ubuntu', 'debian'], true)) {
            throw new \InvalidArgumentException('unsupported_bootstrap_family');
        }
        $installer = self::source('install-linux-exporter.py', self::INSTALLER_SHA256);
        $wrapper = self::source('bootstrap-linux-exporter.py', self::WRAPPER_SHA256);
        $script = <<<'SCRIPT'
#!/bin/sh
set -eu
umask 077
if [ ! -x /usr/bin/python3 ]; then
    printf '%s\n' '{"status":"refused","reason":"python3_missing","central_enrollment":"not_performed","billing":"not_performed"}'
    exit 1
fi
exec /usr/bin/python3 -I -S - <<'SNET_ARVAN_BOOTSTRAP_V1'
import base64, gzip, hashlib, json, sys
try:
    def source(encoded, expected):
        decoded = gzip.decompress(base64.b64decode(encoded, validate=True))
        if hashlib.sha256(decoded).hexdigest() != expected:
            raise ValueError('source_digest_mismatch')
        return decoded
    installer = source('@INSTALLER@', '@INSTALLER_SHA256@')
    wrapper = source('@WRAPPER@', '@WRAPPER_SHA256@')
    scope = {'__name__': 'servernet_owned_bootstrap'}
    exec(compile(wrapper, '<servernet-owned-bootstrap>', 'exec'), scope)
    result = scope['run_embedded'](installer)
except Exception:
    result = {'status': 'refused', 'reason': 'bootstrap_source_invalid', 'central_enrollment': 'not_performed', 'billing': 'not_performed'}
print(json.dumps(result, sort_keys=True))
raise SystemExit(0 if result.get('status') == 'verified' else 1)
SNET_ARVAN_BOOTSTRAP_V1
SCRIPT;
        $script = str_replace(['@INSTALLER@', '@WRAPPER@', '@INSTALLER_SHA256@', '@WRAPPER_SHA256@'],
            [base64_encode(gzencode($installer, 9)), base64_encode(gzencode($wrapper, 9)), self::INSTALLER_SHA256, self::WRAPPER_SHA256], $script)."\n";
        if (strlen($script) > 32768) {
            throw new \RuntimeException('bootstrap_source_too_large');
        }

        return ['script' => $script, 'metadata' => ['version' => 1, 'state' => 'requested', 'family' => $family,
            'source_sha256' => hash('sha256', $script), 'installer_sha256' => self::INSTALLER_SHA256,
            'wrapper_sha256' => self::WRAPPER_SHA256, 'execution' => 'unknown',
            'central_enrollment' => 'not_performed', 'billing' => 'not_performed']];
    }
}

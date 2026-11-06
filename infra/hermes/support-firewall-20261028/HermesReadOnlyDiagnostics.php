<?php

namespace App\Services\Hermes;

use App\Models\CloudInstance;
use App\Models\Server;
use App\Models\Service;
use App\Services\Cloud\CloudManager;
use App\Services\Provisioning\PleskClient;
use App\Services\Provisioning\WhmClient;
use Illuminate\Support\Facades\Cache;

/** Closed set of read-only checks against an ownership-verified service. No general tool executor. */
final class HermesReadOnlyDiagnostics
{
    public function __construct(private CloudManager $cloud) {}

    public function inspect(Service $service, bool $inspectPhp = false): array
    {
        return Cache::remember('hermes:diagnostics:v2:'.$service->id.':'.$service->updated_at?->timestamp.':'.(int) $inspectPhp, 180,
            function () use ($service, $inspectPhp): array {
                $checks = [];
                $instance = CloudInstance::query()->where('service_id', $service->id)->orderByDesc('id')->first();
                if ($instance) {
                    try {
                        $driver = $this->cloud->driver((string) $instance->provider);
                        $r = $driver && $driver->isConfigured() ? $driver->serverStatus((string) $instance->provider_ref) : [];
                        $checks[] = ['check' => 'cloud_status', 'source' => 'provider_api', 'at' => time(),
                            'result' => ($r['ok'] ?? false) === true ? 'verified' : 'unavailable',
                            'status' => ($r['ok'] ?? false) === true ? self::status($r['status'] ?? '') : null,
                            'traffic_used_gb' => ($r['ok'] ?? false) === true && is_numeric($r['traffic_used_gb'] ?? null)
                                && (float) $r['traffic_used_gb'] >= 0 && is_finite((float) $r['traffic_used_gb']) ? (float) $r['traffic_used_gb'] : null,
                            'traffic_included_gb' => ($r['ok'] ?? false) === true && is_numeric($r['traffic_included_gb'] ?? null)
                                && (float) $r['traffic_included_gb'] > 0 && is_finite((float) $r['traffic_included_gb']) ? (float) $r['traffic_included_gb'] : null];
                    } catch (\Throwable) { $checks[] = self::unavailable('cloud_status'); }
                    if ($instance->provider === 'arvan') {
                        try { $checks[] = app(\App\Services\Cloud\ArvanClient::class)->inspectFirewall((string) $instance->provider_ref); }
                        catch (\Throwable) { $checks[] = self::unavailable('cloud_firewall'); }
                    }
                }
                $server = $service->server_id ? Server::query()->find($service->server_id) : null;
                if ($server) {
                    try {
                        if ($server->type === 'plesk' && filled($service->domain)) {
                            $domain = htmlspecialchars((string) $service->domain, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                            $r = (new PleskClient($server))->call('<packet><webspace><get><filter><name>'.$domain.'</name></filter><dataset><gen_info/></dataset></get></webspace></packet>');
                            // ok=true proves the API accepted this exact-domain query, not that the site is online.
                            $checks[] = ['check' => 'plesk_subscription', 'source' => 'plesk_xml_api', 'at' => time(),
                                'result' => ($r['ok'] ?? false) === true ? 'query_accepted' : 'unavailable'];
                        } elseif ($server->type === 'whm' && filled($service->username)) {
                            $client = new WhmClient($server);
                            $r = $client->accountSummary((string) $service->username);
                            $acct = $r['data']['acct'][0] ?? null;
                            $valid = ($r['ok'] ?? false) === true && is_array($acct)
                                && ($acct['user'] ?? '') === (string) $service->username;
                            $checks[] = ['check' => 'whm_account', 'source' => 'whm_api', 'at' => time(),
                                'result' => $valid ? 'verified' : 'unavailable',
                                'suspended' => $valid ? ((string) ($acct['suspended'] ?? '') === '1') : null];
                            if ($valid && $inspectPhp && filled($service->domain)) {
                                $checks[] = (new HermesWhmPhpDiagnostics())->inspect($service, $server, $client);
                            }
                        }
                    } catch (\Throwable) { $checks[] = self::unavailable('hosting_account'); }
                }
                if (filled($service->domain)) { $checks[] = $this->website((string) $service->domain); }
                return ['service_id' => (int) $service->id, 'checked_at' => time(), 'checks' => $checks,
                    'repairs_performed' => false, 'scope' => 'read_only'];
            });
    }

    /** Public IPv4 only, including explicit special-use ranges excluded by older PHP filters. */
    public static function publicAddress(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) { return false; }
        $number = (int) sprintf('%u', ip2long($ip));
        foreach ([['0.0.0.0', 8], ['100.64.0.0', 10], ['192.0.0.0', 24], ['192.0.2.0', 24], ['192.88.99.0', 24],
            ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4]] as [$base, $bits]) {
            $mask = (0xffffffff << (32 - $bits)) & 0xffffffff;
            if (($number & $mask) === (((int) sprintf('%u', ip2long($base))) & $mask)) { return false; }
        }
        return true;
    }

    public static function domain(string $domain): bool
    {
        return strlen($domain) <= 253 && ! filter_var($domain, FILTER_VALIDATE_IP)
            && preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/iD', $domain) === 1;
    }

    private function website(string $domain): array
    {
        $base = ['check' => 'website_dns_tls_http', 'source' => 'public_dns_pinned_tls', 'at' => time(), 'result' => 'unavailable'];
        $domain = strtolower(rtrim(trim($domain), '.'));
        if (! self::domain($domain)) { return $base + ['reason' => 'unsupported_domain']; }
        try {
            $records = @dns_get_record($domain, DNS_A);
            $ips = array_values(array_unique(array_column($records ?: [], 'ip')));
            if (! $ips || count(array_filter($ips, [self::class, 'publicAddress'])) !== count($ips)) {
                return $base + ['reason' => 'no_safe_public_ipv4'];
            }
            // Pin the validated address. No second DNS resolution or redirect can reach a private target.
            $context = stream_context_create(['ssl' => ['peer_name' => $domain, 'SNI_enabled' => true,
                'verify_peer' => true, 'verify_peer_name' => true, 'capture_peer_cert' => true]]);
            $socket = @stream_socket_client('tls://'.$ips[0].':443', $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $context);
            if (! $socket) { return $base + ['dns' => 'resolved', 'reason' => 'tls_or_connection_unavailable']; }
            try {
                stream_set_timeout($socket, 3);
                $parameters = stream_context_get_params($socket);
                $cert = openssl_x509_parse($parameters['options']['ssl']['peer_certificate']);
                $request = "HEAD / HTTP/1.1\r\nHost: ".$domain."\r\nConnection: close\r\nUser-Agent: ServerNet-Hermes-Health/1\r\n\r\n";
                if (fwrite($socket, $request) !== strlen($request)) { return $base + ['reason' => 'http_unavailable']; }
                $first = fgets($socket, 2048);
                $code = is_string($first) && preg_match('~^HTTP/1\.[01] ([1-5][0-9]{2})\b~', $first, $match) ? (int) $match[1] : null;
                return array_replace($base, ['result' => $code ? 'verified' : 'unavailable', 'dns' => 'resolved',
                    'tls' => 'verified', 'certificate_expires_at' => $cert['validTo_time_t'] ?? null,
                    'http_status' => $code, 'redirect_followed' => false]);
            } finally { fclose($socket); }
        } catch (\Throwable) { return $base; }
    }

    private static function status(mixed $value): string
    {
        return is_string($value) && in_array($value, ['running', 'off', 'stopped', 'building', 'deleted', 'suspended', 'error'], true) ? $value : 'unknown';
    }

    private static function unavailable(string $check): array
    {
        return ['check' => $check, 'source' => 'configured_api', 'result' => 'unavailable', 'at' => time()];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Cloud;

use Closure;
use RuntimeException;

/** HTTP over one approved account-owned Unix socket. Never opens TCP, DNS or a tunnel. */
final class UnixPrometheusTrafficTransport
{
    public const RELATIVE = '/storage/app/private/arvan-observation/prometheus-query.sock';
    public const MAX_BYTES = 262144;

    public function __construct(private string $root, private array $pin, private ?Closure $connector = null) {}

    public function path(): string { return $this->root.self::RELATIVE; }

    /** Capture is for explicit operator approval, never an automatic poll/re-pin. */
    public static function capture(string $root): array
    {
        if (!function_exists('posix_geteuid') || !function_exists('posix_getuid') || posix_getuid() !== posix_geteuid()) {
            throw new RuntimeException('unix_transport_account_unverified');
        }
        $owner = posix_geteuid();
        if (!str_starts_with($root, '/') || realpath($root) !== $root) { throw new RuntimeException('unix_transport_root_invalid'); }
        $path = $root.self::RELATIVE;
        $out = [];
        foreach (['directory' => dirname($path), 'socket' => $path] as $kind => $name) {
            clearstatcache(true, $name); $stat = @lstat($name);
            $mode = $kind === 'socket' ? 0600 : 0700;
            $type = $kind === 'socket' ? 0140000 : 0040000;
            if (!$stat || is_link($name) || realpath($name) !== $name || $stat['uid'] !== $owner
                || ($stat['mode'] & 0170000) !== $type || ($stat['mode'] & 07777) !== $mode
                || ($kind === 'socket' && $stat['nlink'] !== 1)) {
                throw new RuntimeException('unix_transport_metadata_invalid');
            }
            $out[$kind] = ['dev' => $stat['dev'], 'ino' => $stat['ino'], 'uid' => $stat['uid'], 'gid' => $stat['gid'], 'mode' => $mode];
        }
        return $out;
    }

    public function verify(): void
    {
        if (self::capture($this->root) !== $this->pin) { throw new RuntimeException('unix_transport_pin_changed'); }
    }

    private function remaining(float $end, $stream): void
    {
        $left = $end - hrtime(true) / 1_000_000_000;
        if ($left <= 0) { throw new RuntimeException('unix_transport_deadline'); }
        $seconds = (int) floor($left);
        if (!stream_set_timeout($stream, $seconds, max(1, (int) (($left - $seconds) * 1_000_000)))) {
            throw new RuntimeException('unix_transport_timeout_unavailable');
        }
    }

    private function take($stream, int $maximum, float $end): string
    {
        $this->remaining($end, $stream);
        $value = fread($stream, $maximum);
        $meta = stream_get_meta_data($stream);
        if ($value === false || ($meta['timed_out'] ?? false) || ($value === '' && !feof($stream))) {
            throw new RuntimeException('unix_transport_read_failed');
        }
        return $value;
    }

    public function request(array $query): array
    {
        if (array_keys($query) !== ['query', 'time'] || !is_string($query['query']) || !is_string($query['time'])
            || preg_match('/\A[1-9]\d{8,10}\z/D', $query['time']) !== 1) {
            throw new RuntimeException('unix_transport_query_invalid');
        }
        $target = '/api/v1/query?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        if (strlen($target) > 8192) { throw new RuntimeException('unix_transport_query_limit'); }
        $this->verify();
        $end = hrtime(true) / 1_000_000_000 + 5;
        $stream = $this->connector !== null ? ($this->connector)($this->path())
            : @stream_socket_client('unix://'.$this->path(), $errno, $error, 2, STREAM_CLIENT_CONNECT);
        if (!is_resource($stream)) { throw new RuntimeException('unix_transport_connect_failed'); }
        try {
            $request = 'GET '.$target." HTTP/1.0\r\nHost: localhost\r\nAccept: application/json\r\nConnection: close\r\n\r\n";
            for ($offset = 0; $offset < strlen($request);) {
                $this->remaining($end, $stream);
                $written = fwrite($stream, substr($request, $offset));
                if ($written === false || $written === 0) { throw new RuntimeException('unix_transport_write_failed'); }
                $offset += $written;
            }
            $header = '';
            while (!str_ends_with($header, "\r\n\r\n")) {
                if (strlen($header) >= 16384) { throw new RuntimeException('unix_transport_header_limit'); }
                $byte = $this->take($stream, 1, $end);
                if ($byte === '') { throw new RuntimeException('unix_transport_header_incomplete'); }
                $header .= $byte;
            }
            $lines = explode("\r\n", substr($header, 0, -4));
            $statusLine = array_shift($lines);
            if (preg_match('/\AHTTP\/1\.[01] ([1-5]\d\d)(?: [\x20-\x7e]*)?\z/D', $statusLine, $match) !== 1) {
                throw new RuntimeException('unix_transport_status_invalid');
            }
            $headers = [];
            foreach ($lines as $line) {
                if (preg_match('/\A([A-Za-z0-9-]+):[ \t]*([\x20-\x7e]*)\z/D', $line, $field) !== 1) { throw new RuntimeException('unix_transport_header_invalid'); }
                $key = strtolower($field[1]);
                if (array_key_exists($key, $headers)) { throw new RuntimeException('unix_transport_duplicate_header'); }
                $headers[$key] = trim($field[2]);
            }
            if (isset($headers['transfer-encoding']) || isset($headers['content-encoding']) || isset($headers['location'])) {
                throw new RuntimeException('unix_transport_response_forbidden');
            }
            $status = (int) $match[1];
            if ($status !== 200) { return ['status' => $status, 'body' => '']; }
            if (strtolower(trim(explode(';', $headers['content-type'] ?? '')[0])) !== 'application/json') {
                throw new RuntimeException('unix_transport_content_type_invalid');
            }
            $length = null;
            if (isset($headers['content-length'])) {
                if (preg_match('/\A(?:0|[1-9]\d{0,5})\z/D', $headers['content-length']) !== 1
                    || (int) $headers['content-length'] > self::MAX_BYTES) { throw new RuntimeException('unix_transport_body_limit'); }
                $length = (int) $headers['content-length'];
            }
            $body = '';
            while (!feof($stream)) {
                $body .= $this->take($stream, min(8192, self::MAX_BYTES + 1 - strlen($body)), $end);
                if (strlen($body) > self::MAX_BYTES || ($length !== null && strlen($body) > $length)) { throw new RuntimeException('unix_transport_body_limit'); }
            }
            if ($length !== null && strlen($body) !== $length) { throw new RuntimeException('unix_transport_body_incomplete'); }
            $this->verify();
            return ['status' => 200, 'body' => $body];
        } finally { fclose($stream); }
    }
}

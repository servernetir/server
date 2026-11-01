<?php

namespace App\Services\Hermes;

/**
 * Minimum-privilege client for proving the Hermes VM ↔ Growth HMAC read path.
 *
 * It intentionally exposes only curriculum and oversight. It cannot create
 * candidates, change knowledge, send customer messages or execute tools.
 */
final class HermesGrowthReadClient
{
    private const ORIGIN = 'https://console.servernet.cloud';
    private const PATH_PREFIX = '/api/internal/growth/knowledge/';
    private const ACTIONS = ['curriculum', 'oversight'];
    private const MAX_RESPONSE_BYTES = 1048576;

    private \Closure $transport;
    private \Closure $clock;
    private \Closure $nonceFactory;

    public function __construct(
        private readonly string $key,
        ?callable $transport = null,
        ?callable $clock = null,
        ?callable $nonceFactory = null,
    ) {
        if (strlen($key) < 32) {
            throw new \InvalidArgumentException('growth_key_invalid');
        }

        $this->transport = $transport
            ? \Closure::fromCallable($transport)
            : self::curlTransport();
        $this->clock = \Closure::fromCallable($clock ?? static fn (): int => time());
        $this->nonceFactory = \Closure::fromCallable($nonceFactory ?? static function (): string {
            return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        });
    }

    /** @return array<string, mixed> */
    public function curriculum(): array
    {
        return $this->request('curriculum');
    }

    /** @return array<string, mixed> */
    public function oversight(): array
    {
        return $this->request('oversight');
    }

    /** @return array<string, mixed> */
    public function request(string $action): array
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw new \DomainException('growth_action_not_read_only');
        }

        $timestamp = (string) ($this->clock)();
        $nonce = (string) ($this->nonceFactory)();
        if (preg_match('/^\d{10}$/D', $timestamp) !== 1
            || preg_match('/^[a-zA-Z0-9_-]{24,80}$/D', $nonce) !== 1) {
            throw new \RuntimeException('growth_signing_input_invalid');
        }

        $path = self::PATH_PREFIX.$action;
        $body = '{}';
        $message = $timestamp."\n".$nonce."\nPOST\n".$path."\n".hash('sha256', $body);
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-Growth-Time' => $timestamp,
            'X-Growth-Nonce' => $nonce,
            'X-Growth-Signature' => hash_hmac('sha256', $message, $this->key),
        ];

        $response = ($this->transport)(self::ORIGIN.$path, $headers, $body, 10);
        $status = (int) ($response['status'] ?? 0);
        $raw = $response['body'] ?? null;
        if (! is_string($raw) || strlen($raw) > self::MAX_RESPONSE_BYTES) {
            throw new \RuntimeException('growth_response_invalid');
        }
        if ($status !== 200) {
            // No automatic retry: 409/timeout/5xx require receipt reconciliation.
            throw new \RuntimeException('growth_http_'.$status);
        }

        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('growth_response_json_invalid');
        }

        if (! is_array($decoded) || ! array_key_exists('data', $decoded)
            || ! is_array($decoded['data']) || ($decoded['executed_actions'] ?? null) !== []) {
            throw new \RuntimeException('growth_response_contract_invalid');
        }

        return $decoded['data'];
    }

    private static function curlTransport(): \Closure
    {
        return static function (string $url, array $headers, string $body, int $timeout): array {
            $handle = curl_init($url);
            if ($handle === false) {
                throw new \RuntimeException('growth_transport_unavailable');
            }

            $responseBody = '';
            $tooLarge = false;
            $headerLines = [];
            foreach ($headers as $name => $value) {
                $headerLines[] = $name.': '.$value;
            }

            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headerLines,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$responseBody, &$tooLarge): int {
                    if (strlen($responseBody) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                        $tooLarge = true;
                        return 0;
                    }
                    $responseBody .= $chunk;
                    return strlen($chunk);
                },
            ]);

            $ok = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $errorCode = curl_errno($handle);
            curl_close($handle);

            if ($tooLarge) {
                throw new \RuntimeException('growth_response_too_large');
            }
            if ($ok === false || $errorCode !== 0) {
                throw new \RuntimeException('growth_transport_failed_'.$errorCode);
            }

            return ['status' => $status, 'body' => $responseBody];
        };
    }
}

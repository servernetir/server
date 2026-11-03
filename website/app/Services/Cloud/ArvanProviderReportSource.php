<?php

declare(strict_types=1);

namespace App\Services\Cloud;

use Closure;
use Illuminate\Support\Facades\Http;

/** Disabled by default, one fixed-origin GET. No persistence, billing, lifecycle or UI wiring. */
final class ArvanProviderReportSource
{
    /**
     * currentIdentity must independently re-read the unambiguous current local
     * service/instance/owner/reference binding, before and after transport.
     * Callers must not return a cached model or request-supplied identity.
     * tokenReader must use an approved read-only credential path; this source
     * never calls Setting::getSecret(), which can write a cache on a miss.
     * Optional transport injection is a seam for fully offline tests.
     */
    public function __construct(
        private bool $enabled = false,
        private ?Closure $currentIdentity = null,
        private ?Closure $tokenReader = null,
        private ?Closure $transport = null,
    ) {}

    public function read(array $identity, int $collectedAt): array
    {
        if (!$this->enabled) {
            return ArvanProviderReportObservation::unknown('provider_reports_disabled');
        }
        if (!ArvanProviderReportObservation::validIdentity($identity) || $collectedAt < 946684800) {
            return ArvanProviderReportObservation::unknown('expected_identity_or_collection_time_invalid');
        }
        if ($this->currentIdentity === null) {
            return ArvanProviderReportObservation::unknown('current_identity_reader_required');
        }
        if ($this->tokenReader === null) {
            return ArvanProviderReportObservation::unknown('provider_access_reader_required');
        }
        try {
            if (!ArvanProviderReportObservation::sameIdentity($identity, ($this->currentIdentity)())) {
                return ArvanProviderReportObservation::unknown('current_identity_mismatch');
            }
            $token = ($this->tokenReader)();
            if (!is_string($token) || trim($token) === '' || strlen($token) > 8192
                || str_contains($token, "\r") || str_contains($token, "\n")) {
                return ArvanProviderReportObservation::unknown('provider_access_unavailable');
            }
            [$region, $uuid] = explode(':', $identity['provider_ref'], 2);
            $url = 'https://napi.arvancloud.ir/ecc/v1/regions/'.rawurlencode($region).'/reports/'.rawurlencode($uuid);
            $query = ['period' => '1d'];
            // The stored authorization value already includes its own prefix.
            $headers = ['Authorization' => $token, 'Accept' => 'application/json'];
            $response = $this->transport !== null
                ? ($this->transport)('GET', $url, $headers, $query)
                : $this->fetch($url, $headers, $query);
            unset($token, $headers);
            if (!ArvanProviderReportObservation::sameIdentity($identity, ($this->currentIdentity)())) {
                return ArvanProviderReportObservation::unknown('current_identity_changed_during_read');
            }
            if (!is_array($response) || ($response['status'] ?? null) !== 200
                || !is_string($response['body'] ?? null)) {
                return ArvanProviderReportObservation::unknown('provider_report_unavailable');
            }

            $result = (new ArvanProviderReportObservation)->parse($response['body'], $identity, $collectedAt);
            if ($result['observation'] !== null) {
                $result['observation']['local_binding_verified_before_after'] = true;
            }

            return $result;
        } catch (\Throwable) {
            // Provider bodies, exception messages and authorization never enter diagnostics/logs.
            return ArvanProviderReportObservation::unknown('provider_report_read_failed');
        }
    }

    private function fetch(string $url, array $headers, array $query): array
    {
        $deadline = microtime(true) + 8;
        $response = Http::withHeaders($headers)
            ->withOptions(['allow_redirects' => false, 'verify' => true, 'stream' => true, 'read_timeout' => 3])
            ->connectTimeout(3)->timeout(8)->get($url, $query);
        $stream = $response->toPsrResponse()->getBody();
        $body = '';
        try {
            while (!$stream->eof() && strlen($body) <= ArvanProviderReportObservation::MAX_BODY_BYTES) {
                if (microtime(true) > $deadline) {
                    throw new \RuntimeException('read_deadline');
                }
                $chunk = $stream->read(min(8192, ArvanProviderReportObservation::MAX_BODY_BYTES + 1 - strlen($body)));
                if ($chunk === '') {
                    throw new \RuntimeException('incomplete_stream');
                }
                $body .= $chunk;
            }
        } finally {
            $stream->close();
        }

        return ['status' => $response->status(), 'body' => $body];
    }
}

<?php

declare(strict_types=1);

// No Composer, Laravel boot, database, credentials, network or filesystem mutation.
namespace Illuminate\Support\Facades {
    final class Http
    {
        public static array $calls = [];
        public static int $status = 200;
        public static string $body = '';
        public static bool $closed = false;
        public static bool $stallAfterBody = false;
        public static function withHeaders(array $headers): self { self::$calls[] = ['headers', $headers]; return new self; }
        public function withOptions(array $options): self { self::$calls[] = ['options', $options]; return $this; }
        public function connectTimeout(int $seconds): self { self::$calls[] = ['connect_timeout', $seconds]; return $this; }
        public function timeout(int $seconds): self { self::$calls[] = ['timeout', $seconds]; return $this; }
        public function get(string $url, array $query): self { self::$calls[] = ['GET', $url, $query]; return $this; }
        public function status(): int { return self::$status; }
        public function toPsrResponse(): self { return $this; }
        public function getBody(): object
        {
            return new class {
                private int $offset = 0;
                public function eof(): bool { return !Http::$stallAfterBody && $this->offset >= strlen(Http::$body); }
                public function read(int $length): string { $value = substr(Http::$body, $this->offset, $length); $this->offset += strlen($value); return $value; }
                public function close(): void { Http::$closed = true; }
            };
        }
    }
}

namespace {
    use App\Services\Cloud\ArvanProviderReportObservation as Observation;
    use App\Services\Cloud\ArvanProviderReportSource as Source;
    use Illuminate\Support\Facades\Http;

    require __DIR__.'/../../website/app/Services/Cloud/ArvanProviderReportObservation.php';
    require __DIR__.'/../../website/app/Services/Cloud/ArvanProviderReportSource.php';

    $tests = 0; $assertions = 0;
    function check(bool $condition, string $label): void {
        global $assertions; $assertions++;
        if (!$condition) { throw new RuntimeException($label); }
    }
    function test(string $label, Closure $run): void {
        global $tests; $run(); $tests++; echo 'ok '.$tests.' - '.$label."\n";
    }
    function identity(): array {
        return ['service_id' => 17, 'instance_id' => 23, 'customer_id' => 31, 'provider' => 'arvan',
            'provider_ref' => 'ir-thr-c2:11111111-2222-3333-4444-555555555555'];
    }
    function fixture(): array {
        // Synthetic shape only; labels are intentionally opaque, never a time/unit contract.
        return ['charts' => ['network' => ['title' => 'Network', 'categories' => ['11:00', '11:05'],
            'series' => [['name' => 'incoming', 'data' => [17, 23.5]], ['name' => 'outgoing', 'data' => [0, 31]]]]],
            'statistics' => ['UNRELATED_SYNTHETIC_PRIVATE_VALUE']];
    }
    function json(array $value): string { return json_encode($value, JSON_THROW_ON_ERROR); }
    function parse(?array $fixture = null, int $at = 1793674800): array {
        return (new Observation)->parse(json($fixture ?? fixture()), identity(), $at);
    }
    function unknown(array $out, string $reason): void {
        check($out['status'] === 'unknown' && $out['reason'] === $reason, $reason);
        check($out['observation'] === null && $out['traffic_used_gb'] === null, 'no invented observations or traffic');
        check($out['sample_at'] === null && $out['unit'] === null && $out['source_freshness'] === 'unknown', 'unknown semantics');
        check(!$out['financial_action_performed'] && !$out['lifecycle_action_performed'], 'no actions');
    }
    function ready(?Closure $transport = null, ?Closure $current = null): Source {
        return new Source(true, $current ?? fn () => identity(), fn () => 'Apikey SYNTHETIC_SECRET',
            $transport ?? fn () => ['status' => 200, 'body' => json(fixture())]);
    }

    test('valid raw report preserves numbers with unknown semantics', function (): void {
        $out = parse();
        check($out['status'] === 'diagnostic_only', 'diagnostic only');
        check($out['sample_at'] === null && $out['unit'] === null && $out['traffic_used_gb'] === null, 'no units/time/GB');
        check($out['source_freshness'] === 'unknown', 'freshness unknown');
        check($out['observation']['network']['series'][0]['data'] === [17, 23.5], 'values preserved');
        check($out['observation']['collected_at_is_sample_time'] === false, 'local time not sample time');
        check(!$out['observation']['provider_identity_echo_verified'] && !$out['observation']['local_binding_verified_before_after'], 'pure parser cannot assert binding');
        check(!str_contains(json($out), 'UNRELATED_SYNTHETIC_PRIVATE_VALUE'), 'unrelated data excluded');
    });
    test('data-wrapped report accepted without leaking envelope', function (): void {
        $out = (new Observation)->parse(json(['data' => fixture(), 'message' => 'SYNTHETIC_PRIVATE_MESSAGE']), identity(), 1793674800);
        check($out['status'] === 'diagnostic_only', 'wrapper');
        check(!str_contains(json($out), 'SYNTHETIC_PRIVATE_MESSAGE'), 'envelope not projected');
    });
    test('repeated stale-looking or time-looking labels never become fresh', function (): void {
        $report = fixture(); $report['charts']['network']['categories'] = ['2026-01-01T00:00:00Z', '2026-01-01T00:05:00Z'];
        $first = parse($report); $second = parse($report, 1793675800);
        check($first['observation']['chart_sha256'] === $second['observation']['chart_sha256'], 'same chart fingerprint');
        check($second['source_freshness'] === 'unknown' && $second['sample_at'] === null, 'polling not freshness');
    });
    test('unit-looking title is not interpreted as a unit', function (): void {
        $report = fixture(); $report['charts']['network']['title'] = 'Network (Mbit/s)';
        check(parse($report)['unit'] === null, 'no title-derived unit');
    });
    test('zero values are diagnostic values not zero cost or complete coverage', function (): void {
        $report = fixture(); foreach ($report['charts']['network']['series'] as &$row) { $row['data'] = [0, 0]; } unset($row);
        $out = parse($report);
        check($out['status'] === 'diagnostic_only' && $out['traffic_used_gb'] === null, 'no zero cost');
    });
    test('signed finite chart values retained without direction assumption', function (): void {
        $report = fixture(); $report['charts']['network']['series'][1]['data'] = [-17, -23.5];
        check(parse($report)['observation']['network']['series'][1]['data'] === [-17, -23.5], 'opaque signed values');
    });
    test('malformed JSON scalar list and depth fail closed', function (): void {
        foreach (['{', 'null', '[]', '17', str_repeat('{"a":', 40).'0'.str_repeat('}', 40)] as $raw) {
            $out = (new Observation)->parse($raw, identity(), 1793674800);
            check($out['status'] === 'unknown' && $out['observation'] === null, 'invalid JSON or root');
        }
    });
    test('ambiguous wrapped and raw charts rejected', function (): void {
        unknown((new Observation)->parse(json(fixture() + ['data' => fixture()]), identity(), 1793674800), 'provider_report_shape_unknown');
    });
    test('missing network is unknown rather than no use', function (): void {
        unknown(parse(['charts' => ['cpu' => fixture()['charts']['network']]]), 'provider_network_chart_invalid');
    });
    test('empty report is unknown', function (): void {
        $report = fixture(); $report['charts']['network']['categories'] = [];
        unknown(parse($report), 'provider_network_chart_invalid');
    });
    test('too many points series and long labels rejected', function (): void {
        $report = fixture(); $report['charts']['network']['categories'] = array_fill(0, 2049, 'label');
        unknown(parse($report), 'provider_network_chart_invalid');
        $report = fixture(); $report['charts']['network']['series'] = array_fill(0, 9, $report['charts']['network']['series'][0]);
        unknown(parse($report), 'provider_network_chart_invalid');
        $report = fixture(); $report['charts']['network']['title'] = str_repeat('x', 257);
        unknown(parse($report), 'provider_network_chart_invalid');
    });
    test('category types and control bytes rejected', function (): void {
        foreach ([17, null, '', "label\n"] as $label) {
            $report = fixture(); $report['charts']['network']['categories'][0] = $label;
            unknown(parse($report), 'provider_network_categories_invalid');
        }
    });
    test('duplicate series and missing points rejected', function (): void {
        $report = fixture(); $report['charts']['network']['series'][1]['name'] = 'incoming';
        unknown(parse($report), 'provider_network_series_invalid');
        $report = fixture(); $report['charts']['network']['series'][1]['data'] = [17];
        unknown(parse($report), 'provider_network_series_invalid');
    });
    test('null strings booleans and nonfinite numbers never become zero', function (): void {
        foreach ([null, '0', false] as $value) {
            $report = fixture(); $report['charts']['network']['series'][0]['data'][0] = $value;
            unknown(parse($report), 'provider_network_value_invalid');
        }
        $raw = str_replace('[17,23.5]', '[1e999,23.5]', json(fixture()));
        unknown((new Observation)->parse($raw, identity(), 1793674800), 'provider_network_value_invalid');
    });
    test('oversized body rejected before decoding', function (): void {
        unknown((new Observation)->parse(str_repeat(' ', Observation::MAX_BODY_BYTES + 1), identity(), 1793674800), 'provider_report_size_invalid');
    });
    test('identity strictly validates IDs reference and exact fields', function (): void {
        foreach ([['service_id' => '17'], ['instance_id' => 0], ['provider' => 'hetzner'], ['provider_ref' => 'ir-thr-c2:../../other'], ['extra' => 1]] as $changes) {
            check(!Observation::validIdentity(array_replace(identity(), $changes)), 'invalid identity');
        }
        check(Observation::sameIdentity(identity(), array_reverse(identity(), true)), 'key ordering irrelevant');
        check(!Observation::sameIdentity(identity(), null), 'missing binding');
        unknown((new Observation)->parse(json(fixture()), identity(), 0), 'expected_identity_or_collection_time_invalid');
    });
    test('disabled source reads no credentials binding or transport', function (): void {
        $forbidden = fn () => throw new RuntimeException('must_not_run');
        unknown((new Source(false, $forbidden, $forbidden, $forbidden))->read(identity(), 1793674800), 'provider_reports_disabled');
        unknown((new Source)->read(identity(), 1793674800), 'provider_reports_disabled');
    });
    test('current independent binding is mandatory', function (): void {
        unknown((new Source(true))->read(identity(), 1793674800), 'current_identity_reader_required');
    });
    test('explicit read-only credential reader is mandatory', function (): void {
        unknown((new Source(true, fn () => throw new RuntimeException('must_not_run')))
            ->read(identity(), 1793674800), 'provider_access_reader_required');
    });
    test('invalid expected identity makes no calls', function (): void {
        $forbidden = fn () => throw new RuntimeException('must_not_run');
        unknown((new Source(true, $forbidden, $forbidden, $forbidden))->read([], 1793674800), 'expected_identity_or_collection_time_invalid');
    });
    test('owner service instance or provider drift blocks before transport', function (): void {
        foreach (['customer_id' => 32, 'service_id' => 18, 'instance_id' => 24, 'provider' => 'hetzner',
            'provider_ref' => 'ir-thr-c2:aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'] as $key => $value) {
            $changed = array_replace(identity(), [$key => $value]);
            unknown(ready(fn () => throw new RuntimeException('must_not_run'), fn () => $changed)->read(identity(), 1793674800), 'current_identity_mismatch');
        }
    });
    test('exact fixed origin GET period and authorization are preserved', function (): void {
        $reads = 0; $calls = 0;
        $source = ready(function ($method, $url, $headers, $query) use (&$calls): array {
            $calls++;
            check($method === 'GET', 'only GET');
            check($url === 'https://napi.arvancloud.ir/ecc/v1/regions/ir-thr-c2/reports/11111111-2222-3333-4444-555555555555', 'exact origin/path');
            check($headers === ['Authorization' => 'Apikey SYNTHETIC_SECRET', 'Accept' => 'application/json'], 'stored prefix preserved');
            check($query === ['period' => '1d'], 'only verified bounded period');
            return ['status' => 200, 'body' => json(fixture())];
        }, function () use (&$reads): array { $reads++; return identity(); });
        $out = $source->read(identity(), 1793674800);
        check($reads === 2 && $calls === 1, 'bounded read and two identity checks');
        check($out['observation']['local_binding_verified_before_after'] === true, 'binding rechecked');
        check(!$out['observation']['provider_identity_echo_verified'], 'provider echo not claimed');
        check(!str_contains(json($out), 'SYNTHETIC_SECRET'), 'no secret projection');
    });
    test('identity changes while GET runs discard chart', function (): void {
        $reads = 0;
        unknown(ready(null, function () use (&$reads): array { return array_replace(identity(), ['customer_id' => ++$reads === 1 ? 31 : 32]); })
            ->read(identity(), 1793674800), 'current_identity_changed_during_read');
    });
    test('missing malformed or header-injected access never reaches transport', function (): void {
        foreach ([null, '', "Apikey\r\nInjected: true", str_repeat('x', 8193)] as $token) {
            unknown((new Source(true, fn () => identity(), fn () => $token, fn () => throw new RuntimeException('must_not_run')))
                ->read(identity(), 1793674800), 'provider_access_unavailable');
        }
    });
    test('HTTP errors redirects throttling and malformed response are unknown', function (): void {
        foreach ([['status' => 302, 'body' => 'PRIVATE'], ['status' => 401, 'body' => 'PRIVATE'], ['status' => 404, 'body' => 'PRIVATE'],
            ['status' => 429, 'body' => 'PRIVATE'], ['status' => 500, 'body' => 'PRIVATE'], ['status' => '200', 'body' => json(fixture())], null] as $response) {
            unknown(ready(fn () => $response)->read(identity(), 1793674800), 'provider_report_unavailable');
        }
    });
    test('transport and binding exceptions omit messages bodies secrets', function (): void {
        foreach ([ready(fn () => throw new RuntimeException('SYNTHETIC_SECRET')),
            ready(null, fn () => throw new RuntimeException('SYNTHETIC_SECRET'))] as $source) {
            $out = $source->read(identity(), 1793674800);
            unknown($out, 'provider_report_read_failed');
            check(!str_contains(json($out), 'SYNTHETIC_SECRET'), 'exception redacted');
        }
    });
    test('native HTTP branch disables redirects bounds time and closes stream', function (): void {
        Http::$calls = []; Http::$closed = false; Http::$body = json(fixture()); Http::$status = 200;
        $out = (new Source(true, fn () => identity(), fn () => 'Apikey SYNTHETIC_SECRET'))->read(identity(), 1793674800);
        check($out['status'] === 'diagnostic_only' && Http::$closed, 'native branch closes stream');
        check(Http::$calls[1] === ['options', ['allow_redirects' => false, 'verify' => true, 'stream' => true, 'read_timeout' => 3]], 'bounded options and TLS verification');
        check(Http::$calls[2] === ['connect_timeout', 3] && Http::$calls[3] === ['timeout', 8], 'bounded timeout');
        check(Http::$calls[4][0] === 'GET' && Http::$calls[4][2] === ['period' => '1d'], 'one fixed GET');
    });
    test('native oversized body is bounded and never parsed as observation', function (): void {
        Http::$calls = []; Http::$closed = false; Http::$body = str_repeat('x', Observation::MAX_BODY_BYTES + 10000);
        unknown((new Source(true, fn () => identity(), fn () => 'Apikey SYNTHETIC_SECRET'))->read(identity(), 1793674800), 'provider_report_size_invalid');
        check(Http::$closed && count(Http::$calls) === 5, 'one closed bounded GET');
    });
    test('valid-looking prefix followed by non-EOF empty read is rejected', function (): void {
        Http::$calls = []; Http::$closed = false; Http::$body = json(fixture()); Http::$stallAfterBody = true;
        unknown((new Source(true, fn () => identity(), fn () => 'Apikey SYNTHETIC_SECRET'))
            ->read(identity(), 1793674800), 'provider_report_read_failed');
        check(Http::$closed, 'incomplete stream closed');
        Http::$stallAfterBody = false;
    });
    echo $tests.' tests, '.$assertions." assertions passed\n";
}

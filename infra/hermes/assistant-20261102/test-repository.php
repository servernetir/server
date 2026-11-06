<?php

/** Isolated source lifecycle and real HTTP reader behavior; no app or network. */
declare(strict_types=1);

namespace Illuminate\Console {
    class Command { public const SUCCESS = 0; public const FAILURE = 1; }
}
namespace Illuminate\Support\Facades {
    final class Http {
        public static array $responses = [];
        public static array $requests = [];
        public static function withHeaders(array $headers): \RepositoryFixture\Pending { return new \RepositoryFixture\Pending($headers); }
    }
}
namespace RepositoryFixture {
    final class Stream {
        public int $offset = 0;
        public bool $closed = false;
        public function __construct(public string $body, public bool $stall = false) {}
        public function eof(): bool { return $this->offset >= strlen($this->body); }
        public function read(int $length): string {
            if ($this->stall) { return ''; }
            $value = substr($this->body, $this->offset, min($length, 31)); $this->offset += strlen($value); return $value;
        }
        public function close(): void { $this->closed = true; }
    }
    final class Response {
        public function __construct(public Stream $stream, public int $status = 200) {}
        public function successful(): bool { return $this->status >= 200 && $this->status < 300; }
        public function toPsrResponse(): self { return $this; }
        public function getBody(): Stream { return $this->stream; }
    }
    final class Pending {
        public array $options = [];
        public function __construct(public array $headers) {}
        public function connectTimeout(int $value): self { $this->options['connect_timeout'] = $value; return $this; }
        public function timeout(int $value): self { $this->options['timeout'] = $value; return $this; }
        public function withOptions(array $value): self { $this->options += $value; return $this; }
        public function get(string $url): Response {
            \Illuminate\Support\Facades\Http::$requests[] = ['url' => $url, 'headers' => $this->headers, 'options' => $this->options];
            return array_shift(\Illuminate\Support\Facades\Http::$responses);
        }
    }
}
namespace {
    use App\Services\Hermes\HermesRepositoryKnowledge as Repository;
    use App\Services\Hermes\HermesKnowledge as Knowledge;
    use App\Services\Hermes\HermesAssistantContext as Map;
    use App\Console\Commands\HermesKnowledgeRefresh as Refresh;
    use Illuminate\Support\Facades\Http;
    use RepositoryFixture\Response;
    use RepositoryFixture\Stream;

    require_once dirname(__DIR__, 3).'/website/app/Services/Hermes/HermesAssistantContext.php';
    require_once dirname(__DIR__, 3).'/website/app/Services/Hermes/HermesRepositoryKnowledge.php';
    require_once __DIR__.'/HermesConversationMemory.php';
    require_once __DIR__.'/HermesKnowledge.php';
    require_once __DIR__.'/HermesKnowledgeRefresh.php';

    $checks = 0;
    $check = static function (bool $condition, string $label) use (&$checks): void {
        $checks++; if (! $condition) { throw new RuntimeException('FAILED: '.$label); }
    };
    $reject = static function (callable $call, string $label) use ($check): void {
        try { $call(); } catch (Throwable) { $check(true, $label); return; }
        $check(false, $label);
    };
    $sha = str_repeat('a', 40); $nextSha = str_repeat('b', 40); $now = 1793613600;
    $check(Repository::commit(json_encode(['commit' => ['sha' => $sha]])) === $sha, 'branch response pins exact SHA');
    foreach (['{}', '{', json_encode(['commit' => ['sha' => 'develop']]), str_repeat('x', 65537)] as $bad) {
        $reject(fn () => Repository::commit($bad), 'invalid branch response rejected');
    }
    $index = "روند امروز: [گزارش جدید](RELEASE-2026-11-02.md)؛ فقط در Git است و هنوز نصب نشده.\n\n"
        ."[بدون تاریخ](README.md)\n\n"
        ."[External](https://other.example/2026-11-02.md)\n\n"
        ."[فرار](../../../2026-11-02.md)\n\n"
        ."[حساس](SECRET-2026-11-02.md): password=synthetic-secret\n\n"
        ."[ماه نامعتبر](REPORT-2026-13-40.md)\n\n"
        ."[آینده](REPORT-2028-01-01.md)\n\n"
        ."[گزارش ریشه](../../ROOT-2026-10-31.md): مستند شد.\n";
    $parsed = Repository::parse($index, $sha, $now);
    $check(count($parsed['documents']) === 2, 'only dated safe in-repository summaries retained');
    $check($parsed['undated_entries_skipped'] === 3, 'undated invalid and future source dates counted');
    $first = $parsed['documents'][0];
    $check($first['source_date'] === '2026-11-02', 'original report date retained');
    $check(str_contains($first['text'], 'هنوز نصب نشده'), 'Git-only limitation retained');
    $check(str_contains($first['source_ref'], '/'.$sha.'/docs/project/README.md'), 'source pinned to index actually read');
    $check($first['source_type'] === 'repository_document' && $first['status'] === 'documented', 'not falsely verified');
    $check(str_contains($parsed['documents'][1]['text'], 'ROOT-2026-10-31.md'), 'repository relative root link resolved');
    $reject(fn () => Repository::parse('[undated](README.md)', $sha, $now), 'no date means unavailable rather than invented source date');
    $reject(fn () => Repository::parse(str_repeat('x', Repository::MAX_BYTES + 1), $sha, $now), 'oversized index rejected');
    $reject(fn () => Repository::parse($index, 'develop', $now), 'mutable branch token rejected as pinned source');
    $reject(fn () => Repository::parse("\xff", $sha, $now), 'invalid UTF8 rejected');
    $many = '';
    for ($i = 0; $i < 25; $i++) { $many .= "[Report$i](REPORT$i-2026-11-02.md): documented\n\n"; }
    $check(count(Repository::parse($many, $sha, $now)['documents']) === 12, 'at most12 documents per refresh');

    $private = ['id' => 'owner-fixture', 'title' => 'Owner fixture', 'text' => 'Original owner preference.',
        'topics' => ['project:kianpet'], 'source_type' => 'owner_chat', 'source_ref' => 'synthetic private source',
        'source_date' => '2026-10-30', 'status' => 'reported'];
    $knowledge = []; Knowledge::importReferences($knowledge, [$private], $now - 100);
    $ownerBefore = $knowledge['reference_documents']['owner-fixture'];
    Repository::record($knowledge, $parsed, $now);
    $check(count($knowledge['reference_documents']) === 3, 'source summaries join owner store');
    $check($knowledge['reference_documents']['owner-fixture'] === $ownerBefore, 'owner record untouched');
    $check($knowledge['repository_source']['status'] === 'ok' && $knowledge['repository_source']['last_success_at'] === $now, 'successful dated source receipt persisted');
    $check(! $knowledge['repository_source']['current_production_verified'], 'success does not claim live production verified');
    $check(! Repository::due($knowledge, $now + 1), 'same scheduler hour does not refetch');
    $check(Repository::due($knowledge, $now + 3600), 'next scheduler hour refreshes');
    $check(Repository::due(['repository_source' => ['checked_at' => $now + 864000]], $now), 'invalid future timestamp cannot suspend refresh');
    $next = Repository::parse('[Replacement](NEW-2026-11-02.md): documented', $nextSha, $now + 3600);
    Repository::record($knowledge, $next, $now + 3600);
    $check(count($knowledge['reference_documents']) === 2, 'managed source rotates rather than accumulating forever');
    $check($knowledge['reference_documents']['owner-fixture'] === $ownerBefore, 'rotation preserves private project records');
    $lastGoodReferences = $knowledge['reference_documents'];
    $lastGoodAt = $knowledge['repository_source']['last_success_at'];
    Repository::record($knowledge, null, $now + 7200, 'connection_error');
    $check($knowledge['reference_documents'] === $lastGoodReferences, 'fetch failure retains last-good summaries');
    $check($knowledge['repository_source']['last_success_at'] === $lastGoodAt, 'failure never advances last success');
    $check($knowledge['repository_source']['status'] === 'degraded', 'source failure explicit');
    $check(Repository::context($knowledge, $now + 20000)['stale'], 'last good becomes stale from original successful observation');
    $check(! Repository::context([], $now)['current_production_verified'], 'missing source does not verify production');
    Repository::record($knowledge, $next, $now + 10800);
    $check($knowledge['repository_source']['status'] === 'ok' && ! isset($knowledge['repository_source']['failure']), 'recovery resets failure receipt');

    $full = [];
    for ($i = 0; $i < 200; $i++) { $row = $private; $row['id'] = 'owner-item-'.$i; Knowledge::importReferences($full, [$row], $now); }
    $beforeFull = $full['reference_documents']; Repository::record($full, $parsed, $now + 1);
    $check($full['reference_documents'] === $beforeFull, 'capacity failure never evicts owner references');
    $check($full['repository_source']['status'] === 'degraded', 'capacity failure visible');

    $reader = new ReflectionMethod(Refresh::class, 'repositoryBody');
    $stream = new Stream(str_repeat('x', 100)); Http::$responses = [new Response($stream)];
    $check($reader->invoke(null, Repository::BRANCH_URL, 100) === str_repeat('x', 100), 'real streaming reader accepts exact byte limit');
    $check($stream->closed, 'reader closes successful body');
    $request = Http::$requests[0];
    $check($request['options']['allow_redirects'] === false && $request['options']['stream'] === true, 'no redirects and bounded streaming configured');
    $check($request['options']['connect_timeout'] === 5 && $request['options']['timeout'] === 15, 'connect and overall timeout budgets configured');
    $check(! isset($request['headers']['Authorization']) && ! isset($request['options']['verify']), 'public fetch has no credentials and retains strict default TLS');
    foreach ([new Response(new Stream(str_repeat('x', 101))), new Response(new Stream('body'), 301), new Response(new Stream('body', true))] as $response) {
        Http::$responses = [$response];
        $reject(fn () => $reader->invoke(null, Repository::BRANCH_URL, 100), 'oversize redirect or stalled body rejected');
        $check($response->stream->closed, 'failed reader always closes body');
    }
    $context = Knowledge::context($knowledge, $now + 10800, 'ServerNet');
    $check($context['repository_source']['status'] === 'ok', 'real owner knowledge context shows source status');
    $check(count(Map::projectIndex($knowledge['reference_documents'])) === 2, 'repository refresh does not obscure other project directory');
    if (isset($argv[1])) {
        $real = Repository::parse(file_get_contents($argv[1]), $sha, $now);
        $check(count($real['documents']) > 0 && count($real['documents']) <= 12, 'current real repository index parses');
    }
    echo json_encode(['ok' => true, 'checks' => $checks, 'network_calls' => 0], JSON_THROW_ON_ERROR).PHP_EOL;
}

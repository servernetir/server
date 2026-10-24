<?php
/** Isolated immutable prompt tests. Stub transport, no API or database access. */
declare(strict_types=1);

namespace App\Services\Ticket {
    class TicketDraftWriter
    {
        protected string $purpose = 'article';
        public static array $calls = [];
        public static ?string $output = 'لطفاً متن خطا را ارسال کنید.';
        public function __construct() { $this->purpose = 'support'; }
        protected function call(string $system, string $user, int $maxTokens, int $timeout = 140, bool $stream = false): ?string
        {
            self::$calls[] = ['purpose' => $this->purpose, 'system' => $system, 'payload' => json_decode($user, true, 64, JSON_THROW_ON_ERROR), 'tokens' => $maxTokens, 'timeout' => $timeout];
            return self::$output;
        }
    }
}

namespace {
    use App\Services\Hermes\HermesSupportDraftWriter;
    use App\Services\Ticket\TicketDraftWriter;

    $source = dirname(__DIR__, 4).'/app/Services/Hermes/';
    require $source.'HermesSupportPolicy.php';
    require $source.'HermesSupportDraftWriter.php';
    function check(bool $condition, string $message = 'check failed'): void
    {
        if (! $condition) { throw new RuntimeException($message); }
    }
    $tests = [];
    $tests['exact immutable thread and existing support routing'] = function (): void {
        TicketDraftWriter::$calls = [];
        $thread = [['role' => 'customer', 'body' => 'خطای HTTP 503 می‌بینم.'], ['role' => 'support', 'body' => 'در حال بررسی است.']];
        $writer = new HermesSupportDraftWriter();
        check(is_string($writer->draftSnapshot('اشکال سرویس', 'technical', $thread)));
        $thread[] = ['role' => 'customer', 'body' => 'password later-secret'];
        $call = TicketDraftWriter::$calls[0];
        check($call['purpose'] === 'support' && $call['timeout'] === 60 && $call['tokens'] === 900);
        check(count($call['payload']['thread']) === 2 && ! str_contains(json_encode($call['payload']), 'later-secret'));
    };
    $tests['earlier prompt credentials reject transport entirely'] = function (): void {
        TicketDraftWriter::$calls = [];
        $writer = new HermesSupportDraftWriter();
        check($writer->draftSnapshot('اشکال سرویس', 'technical', [['role' => 'customer', 'body' => 'password earlier-secret'], ['role' => 'customer', 'body' => 'هنوز مشکل دارم']]) === null);
        check(TicketDraftWriter::$calls === []);
    };
    $tests['empty provider output remains a retryable failure'] = function (): void {
        TicketDraftWriter::$output = null;
        check((new HermesSupportDraftWriter())->draftSnapshot('اشکال سرویس', 'technical', [['role' => 'customer', 'body' => 'خطای HTTP 503']]) === null);
    };
    $results = [];
    foreach ($tests as $name => $test) {
        try { $test(); $results[] = ['name' => $name, 'ok' => true]; }
        catch (Throwable $e) { $results[] = ['name' => $name, 'ok' => false, 'error' => $e->getMessage()]; }
    }
    $failed = count(array_filter($results, static fn (array $r): bool => ! $r['ok']));
    echo json_encode(['tests' => count($results), 'failed' => $failed, 'results' => $results], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL;
    exit($failed === 0 ? 0 : 1);
}

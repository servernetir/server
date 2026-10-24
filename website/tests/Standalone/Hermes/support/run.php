<?php
/** Standalone PHP CLI tests. No Laravel boot, DB, provider, mail, SMS or live keys. */
declare(strict_types=1);

use App\Services\Hermes\HermesSupportPolicy;
use App\Services\Hermes\HermesSupportProcessor;
use App\Services\Hermes\HermesSupportState;

$source = dirname(__DIR__, 4).'/app/Services/Hermes/';
foreach (['HermesSupportPolicy', 'HermesSupportState', 'HermesSupportProcessor'] as $class) {
    require $source.$class.'.php';
}

function check(bool $condition, string $message = 'check failed'): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function fixture(string $path): array
{
    $state = new HermesSupportState($path);
    $policy = new HermesSupportPolicy();
    $processor = new HermesSupportProcessor($state, $policy, static fn (string $s): string => 'test:'.base64_encode($s));
    $ticket = ['id' => 21, 'customer_id' => 7, 'subject' => 'اشکال سرویس', 'department' => 'technical'];
    $message = ['id' => 42, 'body' => 'سرویس من باز نمی شود'];
    $fresh = static fn (): array => ['ticket_id' => 21, 'customer_id' => 7, 'message_id' => 42, 'status' => 'open', 'author_role' => 'customer'];
    return [$state, $policy, $processor, $ticket, $message, $fresh];
}

$safeQuestion = 'لطفاً می‌فرمایید هنگام باز کردن سرویس چه پیامی می‌بینید؟';
if (($argv[1] ?? '') === '--worker') {
    [$state, , $processor, $ticket, $message, $fresh] = fixture($argv[2]);
    $processor->process($ticket, $message, $fresh, static fn (): string => $safeQuestion,
        static fn (): int => throw new RuntimeException('Unexpected note'),
        function (string $body, callable $written) use ($state): int {
            $state->change(function (array $s): array {
                $s['dispatches'] = ($s['dispatches'] ?? 0) + 1;
                return $s;
            });
            usleep(80000);
            $written(88);
            return 88;
        }, true, 'worker');
    exit(0);
}

$root = sys_get_temp_dir().'/hermes-support-tests-'.bin2hex(random_bytes(8));
mkdir($root, 0700, true);
$results = [];
$tests = [];
$tests['risk classification and unicode controls'] = function () use ($root): void {
    [, $policy] = fixture($root.'/policy');
    check($policy->classify('کیف‌پول', 'بررسی کنید', 'technical') === 'financial');
    check($policy->classify('refund', 'please review', 'support') === 'financial');
    check($policy->classify('firewall', 'please review', 'technical') === 'security_or_shared_infrastructure');
    check($policy->classify('server', 'please reboot', 'technical') === 'state_change');
    check($policy->classify('help', 'password is abc', 'technical') === 'credentials');
    check($policy->classify('help', '-----BEGIN OPENSSH PRIVATE KEY-----', 'technical') === 'credentials');
    check($policy->classify('help', 'cannot open', 'sales') === 'unclassified_department');
    check($policy->classify('help', 'cannot open', 'technical') === 'low_risk');
};
$tests['only safe clarification questions may send'] = function () use ($root, $safeQuestion): void {
    [, $p] = fixture($root.'/approval');
    check($p->maySend('low_risk', $safeQuestion, 'سرویس باز نمی شود'));
    check(! $p->maySend('financial', $safeQuestion, 'سرویس باز نمی شود'));
    check(! $p->maySend('low_risk', 'مشکل رفع شد. آیا بررسی می‌کنید؟', 'سرویس باز نمی شود'));
    check(! $p->maySend('low_risk', 'آیا دستور sudo reboot را اجرا می‌کنید؟', 'سرویس باز نمی شود'));
    check(! $p->maySend('low_risk', 'آیا تصویر کارت بانکی را می‌فرستید؟', 'سرویس باز نمی شود'));
    check(! $p->maySend('low_risk', 'آیا از آدرس https://example.test استفاده می‌کنید؟', 'سرویس باز نمی شود'));
    check(! $p->maySend('low_risk', $safeQuestion, 'HTTP 503 error'));
    check(! $p->maySend('low_risk', 'آیا تا ۲ ساعت صبر می‌کنید؟', 'سرویس باز نمی شود'));
    check(! $p->maySend('low_risk', 'لطفاً با غیرفعال کردن فایروال دوباره تلاش می‌کنید؟', 'سرویس باز نمی شود'));
    check(! $p->maySend('low_risk', 'آیا سرور را ریبوت می‌کنید؟', 'سرویس باز نمی شود'));
};
$tests['completed reply is durable and not duplicated'] = function () use ($root, $safeQuestion): void {
    [$state, , $p, $ticket, $message, $fresh] = fixture($root.'/delivered');
    $sent = 0;
    $send = function (string $body, callable $written) use (&$sent): int { ++$sent; $written(99); return 99; };
    $notes = static fn (): int => throw new RuntimeException('Unexpected note');
    check($p->process($ticket, $message, $fresh, static fn (): string => $safeQuestion, $notes, $send, true, 'r1') === 'sent');
    check($p->process($ticket, $message, $fresh, static fn (): string => $safeQuestion, $notes, $send, true, 'r2') === 'sent');
    check($sent === 1);
    check($state->item('ticket:21:message:42')['reply_id'] === 99);
    check(! str_contains(file_get_contents($root.'/delivered/state.json'), $safeQuestion));
    check((fileperms($root.'/delivered/state.json') & 0777) === 0600);
    check((fileperms($root.'/delivered') & 0777) === 0700);
};
$tests['human reply invalidates an in-flight draft'] = function () use ($root, $safeQuestion): void {
    [, , $p, $ticket, $message, $fresh] = fixture($root.'/stale');
    $changed = false;
    $fresh = static function () use (&$changed): array { return ['ticket_id' => 21, 'customer_id' => 7, 'message_id' => $changed ? 43 : 42, 'status' => $changed ? 'answered' : 'open', 'author_role' => $changed ? 'staff' : 'customer']; };
    $draft = static function () use (&$changed, $safeQuestion): string { $changed = true; return $safeQuestion; };
    $never = static fn (): int => throw new RuntimeException('Stale draft used');
    check($p->process($ticket, $message, $fresh, $draft, $never, $never, false, 'stale') === 'stale');
};
$tests['wrong customer ownership blocks all processing'] = function () use ($root): void {
    [, , $p, $ticket, $message] = fixture($root.'/ownership');
    $fresh = static fn (): array => ['ticket_id' => 21, 'customer_id' => 8, 'message_id' => 42, 'status' => 'open', 'author_role' => 'customer'];
    $never = static fn (): int => throw new RuntimeException('Unauthorized resource used');
    check($p->process($ticket, $message, $fresh, $never, $never, $never, true, 'ownership') === 'stale');
};
$tests['financial draft stays internal and is not repeated'] = function () use ($root): void {
    [$state, , $p, $ticket, $message, $fresh] = fixture($root.'/financial');
    $ticket['subject'] = 'درخواست بازگشت وجه';
    $notes = 0;
    $note = static function () use (&$notes): int { ++$notes; return 15; };
    $never = static fn (): int => throw new RuntimeException('Financial reply sent');
    check($p->process($ticket, $message, $fresh, static fn (): string => 'برای بازبینی مالی به مسئول مربوط ارجاع شود.', $note, $never, true, 'finance') === 'review_required');
    check($p->process($ticket, $message, $fresh, $never, $note, $never, true, 'finance2') === 'review_required');
    check($notes === 1);
    check($state->item('ticket:21:message:42')['classification'] === 'financial');
};
$tests['credential text never reaches model or notes'] = function () use ($root): void {
    [, , $p, $ticket, $message, $fresh] = fixture($root.'/credentials');
    $message['body'] = 'password is do-not-forward';
    $never = static fn (): int => throw new RuntimeException('Credential text used');
    check($p->process($ticket, $message, $fresh, $never, $never, $never, true, 'secret') === 'excluded');
};
$tests['earlier credentials never reach the snapshot model'] = function () use ($root): void {
    [, , $p, $ticket, $message, $fresh] = fixture($root.'/earlier-secret');
    $message['context'] = "password is earlier-secret\n".$message['body'];
    $never = static fn (): int => throw new RuntimeException('Earlier credential used');
    check($p->process($ticket, $message, $fresh, $never, $never, $never, true, 'earlier-secret') === 'excluded');
};
$tests['earlier financial context prevents automatic clarification'] = function () use ($root, $safeQuestion): void {
    [, , $p, $ticket, $message, $fresh] = fixture($root.'/earlier-finance');
    $message['context'] = "درخواست استرداد وجه\n".$message['body'];
    $notes = 0;
    $note = static function () use (&$notes): int { ++$notes; return 17; };
    $never = static fn (): int => throw new RuntimeException('Financial clarification sent');
    check($p->process($ticket, $message, $fresh, static fn (): string => $safeQuestion, $note, $never, true, 'earlier-finance') === 'review_required');
    check($notes === 1);
};
$tests['automatic clarification is locally authored without a model call'] = function () use ($root, $safeQuestion): void {
    [, , $p, $ticket, $message, $fresh] = fixture($root.'/fixed-template');
    $never = static fn (): int => throw new RuntimeException('Unexpected model or note');
    $send = static function (string $body, callable $written) use ($safeQuestion): int { check($body === $safeQuestion); $written(118); return 118; };
    check($p->process($ticket, $message, $fresh, $never, $never, $send, true, 'template') === 'sent');
};
$tests['no-send keeps a professional draft internal'] = function () use ($root): void {
    [, , $p, $ticket, $message, $fresh] = fixture($root.'/no-send');
    $draft = 'لطفاً متن پیام خطا را برای بررسی دقیق‌تر ارسال کنید.';
    $notes = 0;
    $note = static function (string $body) use (&$notes, $draft): int { check($body === $draft); ++$notes; return 19; };
    $never = static fn (): int => throw new RuntimeException('No-send delivered a reply');
    check($p->process($ticket, $message, $fresh, static fn (): string => $draft, $note, $never, false, 'no-send') === 'review_required');
    check($notes === 1);
};
$tests['explicit owner exclusion never reaches model or delivery'] = function () use ($root): void {
    [, , $p, $ticket, $message, $fresh] = fixture($root.'/excluded');
    $ticket['excluded'] = true;
    $never = static fn (): int => throw new RuntimeException('Excluded ticket used');
    check($p->process($ticket, $message, $fresh, $never, $never, $never, true, 'excluded') === 'excluded');
};
$tests['model failures retry with a bound and no send'] = function () use ($root): void {
    [$state, , $p, $ticket, $message, $fresh] = fixture($root.'/retry');
    $calls = 0;
    $draft = static function () use (&$calls): ?string { ++$calls; return null; };
    $never = static fn (): int => throw new RuntimeException('Failed draft used');
    check($p->process($ticket, $message, $fresh, $draft, $never, $never, false, 'a') === 'retry');
    check($p->process($ticket, $message, $fresh, $draft, $never, $never, false, 'b') === 'retry_wait');
    $state->put('ticket:21:message:42', ['next_retry_at' => 0]);
    check($p->process($ticket, $message, $fresh, $draft, $never, $never, false, 'c') === 'retry');
    $state->put('ticket:21:message:42', ['next_retry_at' => 0]);
    check($p->process($ticket, $message, $fresh, $draft, $never, $never, false, 'd') === 'review_required');
    check($p->process($ticket, $message, $fresh, $draft, $never, $never, false, 'e') === 'review_required');
    check($calls === 3);
};
$tests['uncertain dispatch never repeats automatically'] = function () use ($root, $safeQuestion): void {
    [, , $p, $ticket, $message, $fresh] = fixture($root.'/uncertain');
    $calls = 0;
    $send = static function () use (&$calls): int { ++$calls; throw new RuntimeException('Network failed at unknown point'); };
    $never = static fn (): int => throw new RuntimeException('Unexpected note');
    check($p->process($ticket, $message, $fresh, static fn (): string => $safeQuestion, $never, $send, true, 'u1') === 'delivery_uncertain');
    check($p->process($ticket, $message, $fresh, static fn (): string => $safeQuestion, $never, $send, true, 'u2') === 'delivery_uncertain');
    check($calls === 1);
};
$tests['notification failure after durable message does not duplicate'] = function () use ($root, $safeQuestion): void {
    [$state, , $p, $ticket, $message, $fresh] = fixture($root.'/notification');
    $calls = 0;
    $send = static function (string $body, callable $written) use (&$calls): int { ++$calls; $written(101); throw new RuntimeException('Notifier failed'); };
    $never = static fn (): int => throw new RuntimeException('Unexpected note');
    check($p->process($ticket, $message, $fresh, static fn (): string => $safeQuestion, $never, $send, true, 'n1') === 'sent_notification_error');
    check($p->process($ticket, $message, $fresh, static fn (): string => $safeQuestion, $never, $send, true, 'n2') === 'sent_notification_error');
    check($calls === 1 && $state->item('ticket:21:message:42')['reply_id'] === 101);
};
$tests['interrupted dispatch becomes a visible incident without resend'] = function () use ($root): void {
    [$state, , $p, $ticket, $message, $fresh] = fixture($root.'/crashed');
    $state->put('ticket:21:message:42', ['status' => 'dispatching', 'ticket_id' => 21, 'customer_message_id' => 42, 'request_id' => 'crash', 'dispatch_started_at' => time() - 1000]);
    check($state->reconcileInterrupted() === 1);
    check($state->reconcileInterrupted() === 0);
    $never = static fn (): int => throw new RuntimeException('Interrupted dispatch retried');
    check($p->process($ticket, $message, $fresh, $never, $never, $never, true, 'crash-retry') === 'delivery_uncertain');
    check(count($state->status()['errors']) === 1);
};
$tests['busy discovery queues once and never overwrites a delivery claim'] = function () use ($root): void {
    [$state] = fixture($root.'/busy-queue');
    $key = 'ticket:21:message:42';
    $state->queueIfUnclaimed($key, ['ticket_id' => 21, 'customer_message_id' => 42, 'reason' => 'claim_busy']);
    check($state->item($key)['status'] === 'retry');
    $state->put($key, ['status' => 'sent', 'reply_id' => 101]);
    $state->queueIfUnclaimed($key, ['ticket_id' => 21, 'customer_message_id' => 42, 'reason' => 'claim_busy']);
    check($state->item($key)['status'] === 'sent' && $state->item($key)['reply_id'] === 101);
};
$tests['diagnostics discard secret fields and status omits drafts'] = function () use ($root): void {
    [$state] = fixture($root.'/redaction');
    $state->error('upstream_unavailable', 'request', ['status' => 502, 'Authorization' => 'Bearer secret-value', 'body' => '<!DOCTYPE secret>', 'cookie' => 'secret-cookie'], true);
    $state->put('x', ['draft_ciphertext' => 'encrypted-draft', 'status' => 'review_required']);
    $raw = file_get_contents($root.'/redaction/state.json');
    check(! str_contains($raw, 'secret-value') && ! str_contains($raw, 'secret-cookie') && ! str_contains($raw, 'DOCTYPE'));
    check(! str_contains(json_encode($state->status()), 'encrypted-draft'));
};
$tests['corrupt durable state fails closed without resetting it'] = function () use ($root): void {
    [$state] = fixture($root.'/corrupt');
    $state->read();
    file_put_contents($root.'/corrupt/state.json', '{broken');
    $caught = false;
    try { $state->read(); } catch (Throwable) { $caught = true; }
    check($caught);
    check(file_get_contents($root.'/corrupt/state.json') === '{broken');
};
$tests['concurrent independent processors deliver once'] = function () use ($root): void {
    if (! function_exists('proc_open')) {
        throw new RuntimeException('proc_open is required for the concurrency test');
    }
    $children = [];
    for ($i = 0; $i < 8; ++$i) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __FILE__, '--worker', $root.'/parallel'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        check(is_resource($process));
        fclose($pipes[0]);
        $children[] = [$process, $pipes];
    }
    foreach ($children as [$process, $pipes]) {
        $out = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        check(proc_close($process) === 0, $out.$error);
    }
    $state = new HermesSupportState($root.'/parallel');
    check(($state->read()['dispatches'] ?? 0) === 1);
    check($state->item('ticket:21:message:42')['status'] === 'sent');
};

foreach ($tests as $name => $test) {
    try { $test(); $results[] = ['name' => $name, 'ok' => true]; }
    catch (Throwable $e) { $results[] = ['name' => $name, 'ok' => false, 'error' => $e->getMessage()]; }
}
$failed = count(array_filter($results, static fn (array $r): bool => ! $r['ok']));
echo json_encode(['tests' => count($results), 'failed' => $failed, 'results' => $results], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT).PHP_EOL;
exit($failed === 0 ? 0 : 1);

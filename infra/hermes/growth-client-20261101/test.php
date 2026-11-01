<?php

require __DIR__.'/HermesGrowthReadClient.php';

use App\Services\Hermes\HermesGrowthReadClient;

$assertions = 0;
$assert = static function (bool $condition, string $message = 'assertion_failed') use (&$assertions): void {
    $assertions++;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$throws = static function (callable $callable, string $expected) use ($assert): void {
    try {
        $callable();
    } catch (Throwable $exception) {
        $assert($exception->getMessage() === $expected, 'unexpected_exception_'.$exception->getMessage());
        return;
    }
    throw new RuntimeException('missing_exception_'.$expected);
};

$key = str_repeat('k', 32);
$clock = static fn (): int => 1793520000;
$nonce = static fn (): string => 'abcdefghijklmnopqrstuvwx';
$calls = 0;
$transport = static function (string $url, array $headers, string $body, int $timeout) use ($assert, $key, &$calls): array {
    $calls++;
    $assert($url === 'https://console.servernet.cloud/api/internal/growth/knowledge/curriculum');
    $assert(parse_url($url, PHP_URL_QUERY) === null);
    $assert($body === '{}');
    $assert($timeout === 10);
    $assert($headers['Content-Type'] === 'application/json');
    $assert($headers['X-Growth-Time'] === '1793520000');
    $assert($headers['X-Growth-Nonce'] === 'abcdefghijklmnopqrstuvwx');
    $path = '/api/internal/growth/knowledge/curriculum';
    $message = $headers['X-Growth-Time']."\n".$headers['X-Growth-Nonce']."\nPOST\n".$path."\n".hash('sha256', $body);
    $assert(hash_equals(hash_hmac('sha256', $message, $key), $headers['X-Growth-Signature']));
    return ['status' => 200, 'body' => json_encode([
        'data' => ['version' => 'servernet-six-sections-v1', 'grants_execution_rights' => false],
        'executed_actions' => [],
    ], JSON_THROW_ON_ERROR)];
};

$client = new HermesGrowthReadClient($key, $transport, $clock, $nonce);
$data = $client->curriculum();
$assert($calls === 1);
$assert($data['version'] === 'servernet-six-sections-v1');
$assert($data['grants_execution_rights'] === false);

$throws(static fn () => new HermesGrowthReadClient('short'), 'growth_key_invalid');
$throws(static fn () => $client->request('candidate'), 'growth_action_not_read_only');

$invalidNonceClient = new HermesGrowthReadClient($key, $transport, $clock, static fn (): string => 'short');
$throws(static fn () => $invalidNonceClient->oversight(), 'growth_signing_input_invalid');

$failureCalls = 0;
$failureClient = new HermesGrowthReadClient($key, static function () use (&$failureCalls): array {
    $failureCalls++;
    return ['status' => 503, 'body' => '{}'];
}, $clock, $nonce);
$throws(static fn () => $failureClient->oversight(), 'growth_http_503');
$assert($failureCalls === 1, 'client_retried_unknown_result');

$actionClient = new HermesGrowthReadClient($key, static fn (): array => [
    'status' => 200,
    'body' => '{"data":{},"executed_actions":["unexpected"]}',
], $clock, $nonce);
$throws(static fn () => $actionClient->oversight(), 'growth_response_contract_invalid');

$jsonClient = new HermesGrowthReadClient($key, static fn (): array => ['status' => 200, 'body' => '{'], $clock, $nonce);
$throws(static fn () => $jsonClient->oversight(), 'growth_response_json_invalid');

$shapeClient = new HermesGrowthReadClient($key, static fn (): array => [
    'status' => 200,
    'body' => '{"data":[],"executed_actions":[]}',
], $clock, $nonce);
$assert($shapeClient->oversight() === []);

$largeClient = new HermesGrowthReadClient($key, static fn (): array => [
    'status' => 200,
    'body' => str_repeat('x', 1048577),
], $clock, $nonce);
$throws(static fn () => $largeClient->oversight(), 'growth_response_invalid');

echo "Hermes Growth read client: {$assertions} assertions passed.\n";

<?php
/**
 * Offline procedural-knowledge acceptance; no app bootstrap, .env, DB or API.
 * Usage: PHP tests/standalone/domain-supplier-knowledge.php [repository-root]
 * Fixture IDs/review times are synthetic. This is not a production import.
 */
declare(strict_types=1);

$root = $argv[1] ?? dirname(__DIR__, 3);
require_once $root.'/website/app/Services/Growth/Redactor.php';
require_once $root.'/website/app/Services/Hermes/HermesApprovedBusinessKnowledge.php';

use App\Services\Hermes\HermesApprovedBusinessKnowledge as Knowledge;

$manifest = json_decode(file_get_contents($root.'/infra/hermes/domain-suppliers-20261031/knowledge-manifest.json'), true, flags: JSON_THROW_ON_ERROR);
$checks = 0;
$assert = static function (bool $ok, string $case) use (&$checks): void {
    if (! $ok) { throw new RuntimeException('FAILED: '.$case); }
    $checks++;
};
$assert($manifest['imported_to_production'] === false, 'manifest does not claim import');
$assert($manifest['grants_execution_rights'] === false, 'procedural text gives no rights');
$assert(count($manifest['documents']) === 4, 'four separate lessons');
$now = strtotime('2026-10-31T12:00:00Z');
$rows = [];
foreach ($manifest['documents'] as $position => $item) {
    $payload = $item['payload'];
    $assert($payload['classification'] === 'internal' && $payload['scope'] === 'general' && $payload['kind'] === 'runbook', 'owner-only general runbook');
    $assert(! preg_match('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}|https?:\/\/|\b\d{1,3}(?:\.\d{1,3}){3}\b|\b[a-z0-9-]+\.com\b/iu', $payload['body']), 'no customer domain, network or personal locator');
    $assert(! preg_match('/password\s*[:=]|api[_ -]?key\s*[:=]|bearer\s+|BEGIN .*PRIVATE KEY/iu', $payload['body']), 'no credentials');
    $rows[] = $payload + ['id' => 9000 + $position, 'version' => 1, 'status' => 'verified', 'verified_at' => '2026-10-31T11:00:00Z'];
}

$queries = [
    'هزینه واقعی استعلام هم‌شرایط' => 9000,
    'حساب واقعی نگهدارنده تمدید صحیح' => 9001,
    'نتیجه نامعلوم تکرار سفارش تطبیق پرداخت' => 9002,
    'Joker آمادگی فعال‌سازی' => 9003,
];
foreach ($queries as $query => $expected) {
    $result = Knowledge::project($rows, $query, $now, true);
    $assert(in_array('knowledge:'.$expected.':v1', array_column($result['references'], 'citation'), true), 'owner retrieves '.$expected);
    $assert($result['reference_only'] === true && $result['current_state_verified'] === false && $result['grants_execution_rights'] === false, 'retrieval preserves authority boundary');
    $assert(Knowledge::project($rows, $query, $now, false)['references'] === [], 'internal guidance not exposed to customer');
}
$assert(Knowledge::project($rows, 'Joker', strtotime('2026-12-01T00:00:00Z'), true)['references'] === [], 'expired review guidance withheld');
$candidates = array_map(static fn ($row) => array_replace($row, ['status' => 'candidate', 'verified_at' => null]), $rows);
$assert(Knowledge::project($candidates, 'Joker', $now, true)['references'] === [], 'unreviewed candidates not approved by file presence');
echo json_encode(['checks' => $checks, 'passed' => true, 'synthetic_only' => true, 'production_imported' => false], JSON_THROW_ON_ERROR).PHP_EOL;

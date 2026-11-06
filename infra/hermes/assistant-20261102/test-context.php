<?php

/** Isolated behavior checks: no application bootstrap, secrets, model or network. */
declare(strict_types=1);

use App\Services\Hermes\HermesAssistantContext as Map;
use App\Services\Hermes\HermesConversationMemory as Memory;
use App\Services\Hermes\HermesKnowledge as Knowledge;

require_once dirname(__DIR__, 3).'/website/app/Services/Hermes/HermesAssistantContext.php';
require_once dirname(__DIR__, 3).'/website/app/Services/Hermes/HermesRepositoryKnowledge.php';
require_once __DIR__.'/HermesConversationMemory.php';
require_once __DIR__.'/HermesKnowledge.php';

$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    $checks++;
    if (! $condition) { throw new RuntimeException('FAILED: '.$label); }
};
$payload = static function (string $prompt): array {
    $start = strpos($prompt, '{');
    $end = strrpos($prompt, '}');
    return json_decode(substr($prompt, $start, $end - $start + 1), true, flags: JSON_THROW_ON_ERROR);
};
$now = 1793613600;
$state = ['messages' => [], 'memories' => [['text' => 'گزارش‌ها فارسی و کوتاه باشند.']]];
$runtime = ['available' => true, 'support_last_success_at' => $now - 100,
    'tracked_tasks' => [['status' => 'investigating', 'updated_at' => $now - 20]],
    'support_experience' => ['case_count' => 302],
    'inventory_checks' => [
        'hetzner' => ['at' => $now - 8000, 'complete' => true],
        'arvan' => ['at' => $now - 60, 'complete' => false],
        'ovh' => ['at' => $now + 60, 'complete' => true],
        'bad key' => ['at' => $now, 'complete' => true],
    ],
    'knowledge' => ['reference_document_count' => 151,
        'internal_observation' => ['observed_at' => $now - 30],
        'feed_health' => ['status' => 'degraded']],
    'approved_business_knowledge' => ['available' => true,
        'references' => [['citation' => 'knowledge:4:v1', 'excerpt' => 'Reviewed runbook.']]]];

$originalState = $state; $originalRuntime = $runtime;
$map = Map::project($state, $runtime, $now);
$check($map['storage']['editable_notes'] === 1 && $map['storage']['private_references'] === 151, 'notes and reference counts are independent');
$check($map['storage']['support_cases'] === 302 && $map['storage']['tasks_in_this_context'] === 1, 'separate support/work layers projected');
$check($map['storage']['runbook_reader'] === 'available', 'runbook availability projected');
$check($map['freshness']['recorded_operations']['status'] === 'recent_record', 'recent observation explicitly remains a record');
$check($map['freshness']['support_worker']['status'] === 'recent_record', 'worker uses last success');
$check($map['freshness']['inventory_scans']['hetzner']['status'] === 'stale', 'old inventory not made current by map generation');
$check($map['freshness']['inventory_scans']['arvan']['status'] === 'incomplete', 'incomplete inventory stays incomplete');
$check($map['freshness']['inventory_scans']['ovh']['status'] === 'unknown', 'future inventory observation is unknown');
$check(! isset($map['freshness']['inventory_scans']['bad key']), 'freeform scan keys excluded');
$check($map['freshness']['feeds'] === 'degraded', 'degraded source remains visible');
$check($map['audience'] === 'owner_private' && ! $map['live_state_verified'] && ! $map['grants_execution_rights'], 'map never grants rights or proves health');
$check($state === $originalState && $runtime === $originalRuntime, 'projection never mutates state');

$empty = Map::project(['messages' => [], 'memories' => []], [], $now);
$check($empty['storage']['private_references'] === null, 'missing reference count unknown rather than zero');
$check($empty['freshness']['recorded_operations']['status'] === 'unknown', 'missing operations observation unknown');
$check($empty['freshness']['support_worker']['status'] === 'unknown', 'missing worker success unknown');
$check($empty['freshness']['feeds'] === 'unknown', 'missing feed state unknown');
$check(! $empty['project_index_available'], 'missing directory is not claimed available');

$make = static function (string $id, array $topics, string $date = '2026-11-02'): array {
    return ['id' => $id, 'title' => 'Project fixture', 'text' => 'Owner requested scoped work; current installation not verified.',
        'topics' => $topics, 'source_type' => 'owner_chat', 'source_ref' => 'synthetic-owner-conversation',
        'source_date' => $date, 'status' => 'requested'];
};
$knowledge = [];
$documents = [$make('project-a', ['project:servernet', 'project:servernet']),
    $make('project-b', ['project:kianpet', 'exhibition']),
    $make('project-c', ['project:servernet'], '2026-10-31')];
$check(Knowledge::importReferences($knowledge, $documents, $now) === 3, 'import uses existing reference store');
$check(Knowledge::importReferences($knowledge, $documents, $now + 1) === 0, 'same payload import is idempotent');
$index = Map::projectIndex($knowledge['reference_documents']);
$check($index[0]['reference_count'] === 2, 'repeated topic does not inflate reference count');
$check($index[0]['source_date'] === '2026-11-02', 'directory uses original latest source date');
$check(count($index) === 2, 'only recorded project topics appear');
$badRows = $knowledge['reference_documents'];
$badRows[] = $make('project-public', ['project:lubercar']) + ['audience' => 'public'];
$badRows[] = $make('project-unknown', ['project:private_identity']) + ['audience' => 'owner_private'];
$badRows[] = ['audience' => 'owner_private', 'topics' => ['project:lubercar']];
$badIndex = Map::projectIndex($badRows);
$check(count($badIndex) === 2, 'public malformed and unknown project records excluded');
$check(! str_contains(json_encode($index), 'synthetic-owner-conversation'), 'directory contains no freeform provenance or bodies');
$view = Knowledge::context($knowledge, $now, 'Kianpet');
$check(count($view['owner_project_index']) === 2, 'real knowledge projection includes complete bounded directory');
$check($view['reference_document_count'] === 3, 'directory does not replace full reference count');
$check(($view['relevant_owner_references'][0]['audience'] ?? '') === 'owner_private', 'retrieved excerpts remain owner-private');
$mappedRuntime = $runtime;
$mappedRuntime['knowledge'] = $view + $runtime['knowledge'];
$mapped = Map::project($state, $mappedRuntime, $now);
$check($mapped['projects'][1]['name'] === 'Kianpet', 'directory uses fixed public business labels');
$check($mapped['project_index_available'], 'existing directory recognized');

$prompt = Memory::context($state, $mappedRuntime, 'current');
$data = $payload($prompt);
$check($data['assistant_knowledge']['version'] === Map::VERSION, 'real owner context includes map automatically');
$check($data['durable_owner_memory'] === [$state['memories'][0]['text']], 'normal owner notes retained');
$check($data['application_state']['tracked_tasks'] === $runtime['tracked_tasks'], 'normal task evidence retained');
$check(isset($data['application_state']['approved_business_knowledge']['references'][0]['citation']), 'optional cited runbook still fits');
$check(str_contains($prompt, 'reconcile uncertain external effects'), 'unknown actions not automatically repeated');
$check(str_contains($prompt, 'owner conversation'), 'private project boundary explicit');
$check($state === $originalState, 'context leaves stored memories and transcript unchanged');

$secretState = ['messages' => [['id' => 'old', 'role' => 'user', 'text' => 'password=synthetic-secret']],
    'memories' => [['text' => 'token=synthetic-secret']]];
$safe = Memory::context($secretState, [], 'new');
$check(! str_contains($safe, 'synthetic-secret'), 'sensitive owner content remains omitted');
$check(str_contains($safe, '[sensitive message omitted]'), 'sensitive transcript omission is visible');

for ($length = 0; $length <= 13000; $length += 250) {
    $large = $mappedRuntime + ['padding' => str_repeat('x', $length)];
    $boundedPrompt = Memory::context($state, $large, 'current');
    $bounded = $payload($boundedPrompt);
    $check(mb_strlen($boundedPrompt) + 4000 < 16000, 'relay payload budget at padding '.$length);
    $check(isset($bounded['assistant_knowledge']), 'directory survives compression at padding '.$length);
}

$answer = Memory::reply('آیا حافظه فقط همین یادداشت‌هاست؟', $state, $mappedRuntime, []);
$check(is_string($answer) && str_contains($answer, 'نه تمام دانش من'), 'memory question explains distinction');
$check(! str_contains($answer, 'در تب حافظه'), 'owner not routed into technical panels');
$missing = Memory::reply('حافظه چیست؟', $state, [], []);
$check(str_contains($missing, 'نبود آن منابع را ثابت نمی‌کند'), 'missing observation not described as absent knowledge');
if (isset($argv[1])) {
    $privateRows = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
    $privateKnowledge = [];
    $check(Knowledge::importReferences($privateKnowledge, $privateRows, $now) === count($privateRows), 'private transfer payload passes real importer');
    $check(count(Map::projectIndex($privateKnowledge['reference_documents'])) === 4, 'private payload covers four known business projects');
    $check(Knowledge::importReferences($privateKnowledge, $privateRows, $now + 1) === 0, 'private transfer payload repeat makes no duplicate');
}
echo json_encode(['ok' => true, 'checks' => $checks, 'network_calls' => 0], JSON_THROW_ON_ERROR).PHP_EOL;

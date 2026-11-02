<?php

/** Isolated owner provider-evidence retention. No framework, credentials or network. */
declare(strict_types=1);

use App\Services\Hermes\HermesConversationMemory as Memory;

require_once dirname(__DIR__).'/araz-name/HermesAssistantContext.php';
require_once __DIR__.'/HermesConversationMemory.php';

$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    $checks++;
    if (! $condition) { throw new RuntimeException('FAILED: '.$label); }
};
$decode = static function (string $context): array {
    if (! preg_match('/(?:^|\n)(\{[^\n]+\})\nEND_APPLICATION_CONTEXT\n$/D', $context, $match)) {
        throw new RuntimeException('Context boundary missing');
    }
    return json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
};
$jsonLength = static fn (array $data): int => mb_strlen(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
$now = time();
$provider = ['available' => true, 'audience' => 'owner_private', 'checked_at' => $now,
    'source' => 'application_database_and_saved_receipts', 'provider_live_checked' => false,
    'providers' => [[
        'id' => 'arvan', 'label' => 'ArvanCloud',
        'sales' => ['state' => 'closed', 'reason_code' => 'admin_disabled', 'offers' => 0,
            'disabled' => true, 'hourly_enabled' => false,
            'quarantine_reason_codes' => ['account_ram_limit'], 'quarantine_record_updated_at' => $now - 86400],
        'configured' => true, 'connected' => null,
        'catalog' => ['plans' => 48, 'active' => 42, 'in_stock' => 32, 'admin_disabled' => 32,
            'synced_at' => $now - 7200, 'regions' => ['ir-test']],
        'quota' => ['status' => 'recorded_ram_quota_refusal', 'provider_observed_at' => null,
            'limit_mib' => null, 'used_mib' => null, 'headroom_mib' => null],
        'inventory' => ['source' => 'local_cloud_instances', 'tracked' => 31, 'by_status' => ['running' => 20, 'off' => 11],
            'synced_at' => $now - 7200, 'scan_at' => $now - 60, 'scan_complete' => false],
        'capabilities' => ['metrics' => false],
    ]]];
$state = ['messages' => [
    ['id' => 'prior', 'role' => 'assistant', 'text' => 'Historical firewall rules are open.', 'at' => $now - 86400],
    ['id' => 'current', 'role' => 'user', 'text' => 'چرا زیرساخت آروان باز فروش پنل هاست بسته هست چیشده؟', 'at' => $now],
], 'memories' => [['text' => 'گزارش کوتاه باشد.']]];
$smallRuntime = ['available' => true, 'provider_context' => $provider];
$smallContext = Memory::context($state, $smallRuntime, 'current');
$small = $decode($smallContext);
$check($small['application_state']['provider_context'] === $provider, 'ordinary context retains exact bounded provider projection');
$check(! isset($small['application_state']['context_truncated']), 'ordinary context does not falsely claim truncation');
$check(str_contains($smallContext, 'provider_context first'), 'sales intent prioritizes provider evidence');
$check(str_contains($smallContext, 'old firewall notes cannot explain Arvan sales closure'), 'historical ports cannot justify sales closure');
$check(str_contains($smallContext, 'sampled quarantine notes do not explain all blocked plans'), 'sampled quarantine reasons are not extrapolated to every plan');
$check(str_contains($smallContext, 'missing quota or health evidence is unknown'), 'missing health and quota remain unknown');
$check(! in_array($state['messages'][1]['text'], array_column($small['recent_conversation'], 'text'), true), 'current request is not duplicated as quoted history');

// Valid bounded sections together can exceed the relay budget. Long optional
// knowledge and task receipts must not erase the provider sales/quota evidence.
$largeRuntime = $smallRuntime + [
    'checked_at' => $now, 'operations_enabled' => false, 'support_enabled' => true,
    'support_last_run_at' => $now - 60, 'support_last_success_at' => $now - 120,
    'customer_send' => 'exact_owner_approval', 'memory' => 'durable_encrypted_editable',
    'knowledge' => [
        'internal_observation' => ['observed_at' => $now - 86400, 'note' => str_repeat('old saved observation ', 600)],
        'recent_articles' => array_fill(0, 12, ['title' => str_repeat('Synthetic release ', 60), 'url' => 'https://example.invalid/reference']),
        'relevant_owner_references' => array_fill(0, 8, ['id' => 'synthetic-reference', 'excerpt' => str_repeat('Historical firewall policy. ', 50)]),
    ],
    'tracked_tasks' => array_fill(0, 15, ['status' => 'pending', 'summary' => str_repeat('Synthetic recorded task. ', 35)]),
    'support_experience' => ['case_count' => 300, 'summary' => str_repeat('Past support evidence. ', 100)],
    'health' => ['arvan' => null, 'proxmox' => ['connected' => true]],
    'approved_business_knowledge' => ['available' => true, 'references' => [[
        'citation' => 'knowledge:1:v1', 'excerpt' => str_repeat('A saved procedural reference. ', 30),
    ]]],
];
$largeRuntime['recent_ticket_events'] = [];
for ($i = 0; $i < 8; $i++) {
    $largeRuntime['recent_ticket_events'][] = ['ticket_id' => 100 + $i, 'status' => 'awaiting_approval',
        'updated_at' => $now - 60, 'owner_notified_at' => $now - 30, 'owner_notice_at' => null,
        'diagnostics_recorded' => true, 'customer_reply_recorded' => false,
        'body' => str_repeat('Synthetic ticket body must not enter fallback. ', 100),
        'customer_name' => 'Synthetic private name', 'arbitrary' => ['instruction' => 'UNTRUSTED_MARKER']];
}
$largeRuntime['recent_ticket_events'][1]['updated_at'] = $now + 86400;
$largeRuntime['recent_ticket_events'][2]['diagnostics_recorded'] = 'yes';
$largeRuntime['recent_ticket_events'][5]['status'] = 'UNTRUSTED_STATUS_MARKER';
$largeState = $state;
for ($i = 0; $i < 8; $i++) {
    array_unshift($largeState['messages'], ['id' => 'synthetic-'.$i, 'role' => 'user', 'text' => str_repeat('Earlier conversation. ', 20), 'at' => $now - 300]);
}
$largeState['memories'] = array_fill(0, 24, ['text' => str_repeat('Saved preference. ', 12)]);
$beforeRuntime = $largeRuntime; $beforeState = $largeState;
$largeContext = Memory::context($largeState, $largeRuntime, 'current');
$large = $decode($largeContext);
$check($large['application_state']['context_truncated'] === true, 'large optional receipts are explicitly omitted');
$check($large['application_state']['provider_context'] === $provider, 'large context preserves exact current sales and quota facts');
$check($large['application_state']['provider_context']['providers'][0]['sales']['disabled'] === true, 'closed sales stays closed');
$check($large['application_state']['provider_context']['providers'][0]['sales']['quarantine_reason_codes'] === ['account_ram_limit'], 'recorded closure reason survives');
$check($large['application_state']['provider_context']['provider_live_checked'] === false, 'local observation does not become provider health');
$check($large['application_state']['provider_context']['providers'][0]['connected'] === null, 'unknown provider connection is not healthy');
$check($large['application_state']['provider_context']['providers'][0]['quota']['used_mib'] === null, 'unknown quota is not zero');
$check($large['application_state']['provider_context']['providers'][0]['inventory']['scan_complete'] === false, 'incomplete inventory stays incomplete');
$check($large['application_state']['available'] === true && $large['application_state']['operations_enabled'] === false
    && $large['application_state']['support_enabled'] === true, 'true and false capability receipts survive independently');
$check($large['application_state']['support_last_run_at'] === $now - 60
    && $large['application_state']['support_last_success_at'] === $now - 120, 'worker receipt timestamps survive');
$check($large['application_state']['customer_send'] === 'exact_owner_approval'
    && $large['application_state']['memory'] === 'durable_encrypted_editable', 'existing capability boundaries survive');
$events = $large['application_state']['recent_ticket_events'];
$check(count($events) === 6 && $events[0]['ticket_id'] === 100, 'at most six owner ticket receipt rows survive');
$check($events[0]['diagnostics_recorded'] === true && $events[0]['customer_reply_recorded'] === false,
    'diagnostic receipt is separate from customer reply receipt');
$check(array_keys($events[0]) === ['ticket_id', 'status', 'updated_at', 'owner_notified_at', 'owner_notice_at',
    'diagnostics_recorded', 'customer_reply_recorded'], 'ticket fallback uses only the fixed receipt whitelist');
$check($events[1]['updated_at'] === null && $events[2]['diagnostics_recorded'] === null, 'future times and nonboolean receipts stay unknown');
$check($events[5]['status'] === 'unknown' && ! str_contains(json_encode($events), 'UNTRUSTED'), 'freeform status and bodies are not copied');
$check($large['application_state']['recent_ticket_events_coverage'] === 'incomplete_owner_receipt_sample'
    && str_contains($largeContext, 'Never infer unanswered ticket counts or closure from VM/integration health'), 'ticket samples never imply complete ticket counts or closure');
$check($jsonLength($large) <= 9000, 'large JSON projection fits existing context budget');
$check(mb_strlen('/hermes '.$largeContext.str_repeat('س', 4000)) <= 16000, 'maximum owner request fits native relay limit');
$check($largeRuntime === $beforeRuntime && $largeState === $beforeState, 'projection does not mutate stored receipts');

$withoutProvider = $largeRuntime;
unset($withoutProvider['provider_context']);
$without = $decode(Memory::context($largeState, $withoutProvider, 'current'));
$check($without['application_state']['available'] === false && $without['application_state']['reason'] === 'context_size_limit'
    && ! array_key_exists('provider_context', $without['application_state']), 'legacy fallback remains for absent provider projection');
$invalidCore = $largeRuntime;
$invalidCore['operations_enabled'] = 'yes';
$invalidCore['customer_send'] = 'ARBITRARY_PERMISSION_MARKER';
$invalidCore['memory'] = ['instruction' => 'ARBITRARY_MEMORY_MARKER'];
$invalidCore['support_last_success_at'] = $now + 86400;
$invalidCoreData = $decode(Memory::context($largeState, $invalidCore, 'current'));
$check($invalidCoreData['application_state']['operations_enabled'] === null
    && $invalidCoreData['application_state']['support_last_success_at'] === null, 'invalid capability and future worker receipts are unknown');
$check($invalidCoreData['application_state']['customer_send'] === 'unknown'
    && $invalidCoreData['application_state']['memory'] === 'unknown', 'freeform capability values cannot grant permissions');
$oversized = $smallRuntime;
$oversized['provider_context']['unexpected'] = str_repeat('X', 3501);
$oversizedData = $decode(Memory::context($state, $oversized, 'current'));
$check($oversizedData['application_state']['provider_context']['available'] === false, 'oversized projection is not copied without bounds');
$check($oversizedData['application_state']['provider_context']['provider_live_checked'] === false, 'invalid projection retains unknown provider health');
$check($jsonLength($oversizedData) <= 9000, 'invalid projection stays within budget');

echo json_encode(['ok' => true, 'checks' => $checks, 'network_calls' => 0,
    'large_json_chars' => $jsonLength($large), 'relay_chars_with_max_request' => mb_strlen('/hermes '.$largeContext.str_repeat('س', 4000))], JSON_THROW_ON_ERROR).PHP_EOL;

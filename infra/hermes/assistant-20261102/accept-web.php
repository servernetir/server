<?php
/** Private CLI: scoped owner-reference import and read-only acceptance. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('HOME') !== '/home/servernetcloud') { exit(1); }
umask(0077);
const HW_ROOT = '/home/servernetcloud/.hermes-assistant-20261102';
require '/home/servernetcloud/servernet_app/vendor/autoload.php';
$app = require '/home/servernetcloud/servernet_app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$lock = Illuminate\Support\Facades\Cache::lock('hermes:knowledge-refresh', 300);
if (! $lock->get()) { throw new RuntimeException('knowledge_worker_busy'); }
try {
    $operations = app(App\Services\Hermes\HermesOperations::class);
    $owner = $operations->owner();
    if (! $owner || $owner['user_id'] !== '1') { throw new RuntimeException('owner_binding_changed'); }
    $payload = HW_ROOT.'/owner-project-references.json';
    if (is_link($payload) || filesize($payload) > 102400) { throw new RuntimeException('invalid_private_payload'); }
    chmod($payload, 0600);
    $documents = json_decode(file_get_contents($payload), true, 32, JSON_THROW_ON_ERROR);
    $store = app(App\Services\Hermes\HermesOperationsStore::class);
    $changed = $store->change($owner, static function (array &$state) use ($documents): int {
        $backup = HW_ROOT.'/knowledge-before-import.enc';
        if (! is_file($backup)) {
            file_put_contents($backup, Illuminate\Support\Facades\Crypt::encryptString(json_encode($state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));
            chmod($backup, 0600);
        }
        $knowledge = $state['knowledge'] ?? [];
        $count = App\Services\Hermes\HermesKnowledge::importReferences($knowledge, $documents, time());
        $state['knowledge'] = $knowledge;
        return $count;
    });
    $runtime = $operations->chatContext($owner, 'کیان پت ارومیه لوبرکار سرورنت');
    $memory = app(App\Services\Bale\Admin\HermesMiniAppStore::class)->read($owner);
    $context = App\Services\Hermes\HermesConversationMemory::context($memory, $runtime, 'synthetic-acceptance');
    $knowledge = $store->read($owner)['knowledge'];
    $index = App\Services\Hermes\HermesKnowledge::context($knowledge, time())['owner_project_index'];
    $config = ['state_dir' => HW_ROOT.'/relay-notifier',
        'controller_state_dir' => '/home/servernetcloud/.relay-recovery/state',
        'owner_binding' => hash('sha256', $owner['user_id'].':'.$owner['chat_id']),
        'delivery_command' => [PHP_BINARY, HW_ROOT.'/relay-notify.php'],
        'adapter_sha256' => hash_file('sha256', HW_ROOT.'/relay-notify.php'),
        'probe_command' => [PHP_BINARY, HW_ROOT.'/relay-probe.php'],
        'probe_sha256' => hash_file('sha256', HW_ROOT.'/relay-probe.php')];
    $configPath = HW_ROOT.'/relay-notifier.json';
    if (file_exists($configPath)) { throw new RuntimeException('monitor_configuration_already_present'); }
    file_put_contents($configPath, json_encode($config, JSON_THROW_ON_ERROR)); chmod($configPath, 0600);
    $result = ['ok' => true, 'imported_or_updated' => $changed,
        'private_reference_count' => count($knowledge['reference_documents']),
        'projects' => $index, 'editable_notes_preserved' => count($memory['memories']),
        'context_characters' => mb_strlen($context), 'context_version_present' => str_contains($context, 'assistant-context-v1-20261102'),
        'relay_configuration_created' => true, 'owner_binding_verified' => true,
        'messages_sent' => 0, 'financial_or_service_actions' => 0];
    file_put_contents(HW_ROOT.'/web-acceptance.json', json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    chmod(HW_ROOT.'/web-acceptance.json', 0600);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
} finally { $lock->release(); }

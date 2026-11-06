<?php
/** Private CLI only. Never exposed as a web endpoint or scheduled job. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
umask(0077);
$base = '/home/servernetcloud/servernet_app';
$source = '/home/servernetcloud/hermes-business-source-20261030';
$mode = $argv[1] ?? '';
if (!in_array($mode, ['deploy', 'deploy-v2', 'accept'], true) || getenv('HOME') !== '/home/servernetcloud') { exit(1); }
try {
    require $base.'/vendor/autoload.php';
    $app = require $base.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (in_array($mode, ['deploy', 'deploy-v2'], true)) {
        $lock = Illuminate\Support\Facades\Cache::lock('hermes:support-review', 2700);
        if (!$lock->get()) { throw new RuntimeException('review_busy'); }
        try {
            foreach (['prepare', 'apply', 'verify'] as $phase) {
                $process = new Symfony\Component\Process\Process(
                    ['/usr/bin/python3', $source.'/infra/hermes/business-20261030/'.($mode === 'deploy-v2' ? 'deploy-v2.py' : 'deploy.py'), $phase, $source],
                    $source, ['HERMES_REVIEW_LOCK_HELD' => '1'], null, 90
                );
                $process->run();
                $receipt = json_decode(trim($process->getOutput()), true, 16, JSON_THROW_ON_ERROR);
                if (!$process->isSuccessful()) { throw new RuntimeException('release_'.$phase.'_stopped'); }
                echo json_encode(['phase'=>$receipt['phase'], 'release'=>$receipt['release'],
                    'files'=>count($receipt['files']), 'cron_unchanged'=>true], JSON_THROW_ON_ERROR).PHP_EOL;
            }
        } finally { $lock->release(); }
        exit(0);
    }
    // Fresh CLI process loads the installed classes. No model, sends, or worker runs.
    $reader = app(App\Services\Hermes\HermesApprovedBusinessKnowledge::class);
    $queries = ['بررسی اقدام یادگیری', 'SMTP PTR', 'GPU ذخیره', 'مشتری سازمانی'];
    $checks = [];
    foreach ($queries as $query) {
        $row = $reader->read($query);
        if (!$row['available'] || !$row['references']) { throw new RuntimeException('live_public_retrieval_missing'); }
        $checks[] = array_column($row['references'], 'citation');
    }
    $ops = app(App\Services\Hermes\HermesOperations::class);
    $owner = $ops->owner();
    if (!$owner) { throw new RuntimeException('owner_binding_unavailable'); }
    $context = $ops->chatContext($owner, 'بررسی اقدام یادگیری');
    $modelContext = App\Services\Hermes\HermesConversationMemory::context(['messages'=>[], 'memories'=>[]], $context, 'acceptance-fixture');
    $state = app(App\Services\Hermes\HermesOperationsStore::class)->read($owner);
    $relayContainsBridge=str_contains($modelContext, '"approved_business_knowledge"') && str_contains($modelContext, '"citation"');
    $complete=isset($context['approved_business_knowledge']) && $relayContainsBridge;
    $receipt = ['status'=>$complete ? 'accepted' : 'partial', 'checked_at'=>gmdate('c'), 'public_query_checks'=>$checks,
        'owner_context_contains_bridge'=>isset($context['approved_business_knowledge']),
        'owner_relay_contains_bridge'=>$relayContainsBridge,
        'native_operations_enabled'=>$ops->enabled(),
        'native_support_enabled'=>$context['support_enabled'] ?? null,
        'support_last_run_at'=>$context['support_last_run_at'] ?? null,
        'support_last_success_at'=>$context['support_last_success_at'] ?? null,
        'private_reference_count'=>count($state['knowledge']['reference_documents'] ?? []),
        'approved_rule_count'=>count(App\Services\Hermes\HermesOperationsPolicy::approvedRules($state)),
        'tracked_task_count'=>count($state['tasks'] ?? []),
        'model_response_tested'=>false, 'external_messages_sent'=>0];
    $dir=$base.'/storage/app/private/hermes-business-20261030';
    if (is_link($dir) || (!is_dir($dir) && !mkdir($dir,0700,true))) { throw new RuntimeException('receipt_directory'); }
    $receiptPath=$dir.'/acceptance.json';
    if (is_link($receiptPath)) { throw new RuntimeException('receipt_directory'); }
    $bytes=json_encode($receipt,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
    if (file_put_contents($receiptPath,$bytes,LOCK_EX)!==strlen($bytes)) { throw new RuntimeException('receipt_directory'); }
    echo json_encode($receipt,JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $e) {
    echo json_encode(['status'=>'stopped','exception_class'=>$e::class,
        'reason'=>$e instanceof RuntimeException && preg_match('/^(review_busy|release_.*_stopped|live_public_retrieval_missing|owner_binding_unavailable|receipt_directory)$/D',$e->getMessage()) ? $e->getMessage() : 'inspect_private_runtime'],JSON_THROW_ON_ERROR).PHP_EOL;
    exit(1);
}

<?php
/** Explicit owner-authorized reference import; never changes permissions or starts a model. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
umask(0077);
$home = '/home/servernetcloud';
$base = $home.'/servernet_app';
$release = $home.'/deploy-backups/hermes-knowledge-transfer-20261029';
$result = ['release' => 'hermes-knowledge-transfer-20261029', 'at' => gmdate('c')];
$locks = [];
final class KnowledgeTransferError extends RuntimeException {}
function ensure(bool $ok, string $reason): void { if (!$ok) { throw new KnowledgeTransferError($reason); } }
function jsonHash(mixed $value): string { return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR)); }
function writeReceipt(string $dir, array $receipt): void {
    $tmp = tempnam($dir, '.receipt-');
    ensure(is_string($tmp), 'receipt_temp');
    chmod($tmp, 0600);
    $bytes = json_encode($receipt, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    ensure(file_put_contents($tmp, $bytes) === strlen($bytes) && rename($tmp, $dir.'/receipt.json'), 'receipt_write');
}
function checkRetrieval(array $knowledge, array $cases): int {
    foreach ($cases as $case) {
        $found = array_column(App\Services\Hermes\HermesKnowledge::references($knowledge, $case['query']), 'id');
        ensure(in_array($case['expected_id'], $found, true), 'retrieval:'.$case['expected_id']);
    }
    return count($cases);
}
try {
    ensure(getenv('HOME') === $home && is_dir($base) && !is_link($base), 'account_or_app');
    foreach (['.servernet-web-release.lock', 'servernet-release.lock'] as $name) {
        ensure(!is_link($home.'/'.$name), 'release_lock_symlink');
        $lock = fopen($home.'/'.$name, 'c+b');
        ensure($lock !== false && flock($lock, LOCK_EX | LOCK_NB), 'release_busy');
        $locks[] = $lock;
    }
    require $base.'/vendor/autoload.php';
    $app = require $base.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    config(['cache.default' => 'array', 'mail.default' => 'array', 'session.driver' => 'array']);
    ensure(hash_file('sha256', $base.'/app/Services/Hermes/HermesKnowledge.php') === '76e708c5b5671ade71b868eb18e1c0d2f3c6f8c5513310c483a47eae5769905a', 'knowledge_runtime_drift');
    $raw = Illuminate\Support\Facades\DB::table('settings')->where('key', 'bale_admin_bind')->value('value');
    $owner = json_decode(Illuminate\Support\Facades\Crypt::decryptString((string)$raw), true, 16, JSON_THROW_ON_ERROR);
    ensure(ctype_digit((string)($owner['user_id'] ?? '')) && ctype_digit((string)($owner['chat_id'] ?? '')), 'owner_binding');
    $statePath = $base.'/storage/app/private/hermes-operations/'.hash('sha256', $owner['user_id'].':'.$owner['chat_id']);
    ensure(is_file($statePath) && !is_link($statePath), 'existing_state_required');
    $documents = json_decode(file_get_contents(__DIR__.'/references.json'), true, 32, JSON_THROW_ON_ERROR);
    $cases = json_decode(file_get_contents(__DIR__.'/retrieval-cases.json'), true, 16, JSON_THROW_ON_ERROR);
    $packageHash = jsonHash([$documents, $cases]);
    ensure(!is_link($release) && (is_dir($release) || mkdir($release, 0700, true)), 'private_release');
    chmod($release, 0700);
    $receiptPath = $release.'/receipt.json';
    $receipt = is_file($receiptPath) ? json_decode(file_get_contents($receiptPath), true, 32, JSON_THROW_ON_ERROR) : null;
    if ($receipt !== null) { ensure(hash_equals($receipt['package_sha256'], $packageHash), 'package_drift'); }
    $store = app(App\Services\Hermes\HermesOperationsStore::class);
    if ($receipt === null) {
        $state = $store->read($owner);
        $candidate = $state['knowledge'] ?? [];
        $changed = App\Services\Hermes\HermesKnowledge::importReferences($candidate, $documents, time());
        $idempotent = $candidate;
        ensure(App\Services\Hermes\HermesKnowledge::importReferences($idempotent, $documents, time()+1) === 0 && $candidate === $idempotent, 'idempotency');
        $checks = checkRetrieval($candidate, $cases);
        $receipt = $result + ['phase'=>'prepared', 'package_sha256'=>$packageHash,
            'previous_references_sha256'=>jsonHash($state['knowledge']['reference_documents'] ?? []),
            'references_before'=>count($state['knowledge']['reference_documents'] ?? []),
            'references_after'=>count($candidate['reference_documents']), 'changed'=>$changed,
            'retrieval_checks'=>$checks, 'idempotency_passed'=>true];
        writeReceipt($release, $receipt);
    } elseif ($receipt['phase'] === 'prepared') {
        $changed = $store->change($owner, function (array &$state) use ($documents, $cases, $receipt, $release) {
            ensure(jsonHash($state['knowledge']['reference_documents'] ?? []) === $receipt['previous_references_sha256'], 'references_changed_since_prepare');
            $before = $state;
            $encrypted = Illuminate\Support\Facades\Crypt::encryptString(json_encode($before, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $backup = $release.'/state-before.encrypted';
            ensure(!file_exists($backup), 'backup_already_exists_reconcile');
            ensure(file_put_contents($backup, $encrypted, LOCK_EX) === strlen($encrypted), 'backup_write'); chmod($backup, 0600);
            $state['knowledge'] ??= [];
            $count = App\Services\Hermes\HermesKnowledge::importReferences($state['knowledge'], $documents, time());
            checkRetrieval($state['knowledge'], $cases);
            foreach ($before as $key=>$value) {
                if ($key !== 'knowledge') { ensure(($state[$key] ?? null) === $value, 'unrelated_state_changed'); }
            }
            foreach ($before['knowledge'] ?? [] as $key=>$value) {
                if (!in_array($key, ['reference_documents','reference_imported_at'], true)) { ensure($state['knowledge'][$key] === $value, 'unrelated_knowledge_changed'); }
            }
            foreach ($before['knowledge']['reference_documents'] ?? [] as $id=>$row) {
                ensure(($state['knowledge']['reference_documents'][$id] ?? null) === $row, 'prior_reference_changed');
            }
            return $count;
        });
        $receipt['phase']='applied'; $receipt['applied_at']=gmdate('c'); $receipt['changed']=$changed;
        $receipt['previous_references_preserved']=true; $receipt['unrelated_state_preserved']=true;
        writeReceipt($release, $receipt);
    } else {
        $state = $store->read($owner);
        $knowledge = $state['knowledge'] ?? [];
        foreach ($documents as $row) {
            ensure(isset($knowledge['reference_documents'][$row['id']]), 'missing_saved_reference');
            $saved = $knowledge['reference_documents'][$row['id']];
            foreach ($row as $key=>$value) { ensure(($saved[$key] ?? null) === $value, 'saved_content_mismatch'); }
            ensure($saved['reference_only'] && !$saved['current_state_verified'] && $saved['audience']==='owner_private', 'scope_mismatch');
        }
        $receipt['retrieval_checks']=checkRetrieval($knowledge, $cases);
        $contextChecks=0;
        foreach ($cases as $case) {
            $runtime = app(App\Services\Hermes\HermesOperations::class)->chatContext($owner, $case['query']);
            ensure(in_array($case['expected_id'], array_column($runtime['knowledge']['relevant_owner_references'] ?? [], 'id'), true), 'chat_context_missing:'.$case['expected_id']);
            $contextChecks++;
        }
        $receipt['phase']='verified'; $receipt['verified_at']=gmdate('c');
        $receipt['chat_context_checks']=$contextChecks; $receipt['reference_count']=count($knowledge['reference_documents']);
        $receipt['model_response_tested']=false;
        writeReceipt($release, $receipt);
    }
    echo json_encode($receipt, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    echo json_encode($result + ['phase'=>'stopped','exception_class'=>$error::class,
        'reason'=>$error instanceof KnowledgeTransferError ? $error->getMessage() : 'inspect_private_runtime'], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(1);
} finally {
    foreach (array_reverse($locks) as $lock) { flock($lock, LOCK_UN); fclose($lock); }
}

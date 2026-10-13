<?php
// Check by default. Apply only after explicitly choosing to roll back this release.
$task = __DIR__;
$root = '/home/servernetcloud/servernet_app';
$apply = ($argv[1] ?? 'check') === 'apply';
$manifest = json_decode(file_get_contents($task.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($manifest as $row) {
    $rel = $row['path'];
    if (!preg_match('~^[A-Za-z0-9_./-]+$~', $rel) || str_contains($rel, '..') || str_starts_with($rel, '/')) throw new RuntimeException('Unsafe path.');
    if (!is_file($root.'/'.$rel) || hash_file('sha256', $root.'/'.$rel) !== $row['after']) throw new RuntimeException('Live drift: '.$rel.'; merge this file manually.');
    if ($row['before'] !== null && (!is_file($task.'/backup/'.$rel) || hash_file('sha256',$task.'/backup/'.$rel) !== $row['before'])) throw new RuntimeException('Backup mismatch: '.$rel);
}
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$open=App\Models\AiUsage::whereIn('status',App\Models\AiUsage::OPEN)->count();
if ($open) throw new RuntimeException('Open usage exists; disable admissions and reconcile with the new engine first.');
echo json_encode(['files'=>count($manifest),'hashes'=>'matched','open_usage'=>$open,'mode'=>$apply?'apply':'check']).PHP_EOL;
if (!$apply) exit(0);
if (file_exists($task.'/rollback-done.json') || is_dir($task.'/rollback-quarantine')) throw new RuntimeException('Rollback already started; inspect before resuming.');
// Stop all paid admissions before restoring the earlier engine. Keep settings and keys intact.
App\Models\Setting::put('ai_sales_open','0');
App\Models\AiProvider::where('slug','deepinfra')->update(['live_calls_enabled'=>false]);
mkdir($task.'/rollback-quarantine',0700);
foreach ($manifest as $row) {
    $rel=$row['path']; $dest=$root.'/'.$rel;
    if (hash_file('sha256',$dest)!==$row['after']) throw new RuntimeException('Changed during rollback: '.$rel);
    if ($row['before'] === null) {
        $quarantine=$task.'/rollback-quarantine/'.$rel;
        if (!is_dir(dirname($quarantine))) mkdir(dirname($quarantine),0700,true);
        if (!rename($dest,$quarantine)) throw new RuntimeException('Quarantine failed.');
    } else {
        $tmp=$dest.'.ai-rollback-tmp';
        if (file_exists($tmp) || !copy($task.'/backup/'.$rel,$tmp)) throw new RuntimeException('Restore staging failed.');
        chmod($tmp,0644);
        if (!rename($tmp,$dest)) throw new RuntimeException('Restore failed.');
    }
}
file_put_contents($task.'/rollback-done.json',json_encode(['at'=>gmdate('c'),'files'=>count($manifest)],JSON_PRETTY_PRINT));
echo 'Code restored. Preserve additive schema, price history and financial ledger. Clear caches and reset web OPcache before serving requests.'.PHP_EOL;

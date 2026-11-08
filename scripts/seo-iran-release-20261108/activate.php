<?php
declare(strict_types=1);

/* Private CLI helper. It never acquires release locks or clears a whole cache.
 * argv: phase stage preview-sha payload payload-sha coordinator-pid release.py
 * The caller must keep the inspected release.py session alive until acceptance.
 */
const SA_RELEASE = 'seo-iran-release-20261108';
const SA_HOME = '/home/servernetcloud';
const SA_APP = SA_HOME.'/servernet_app';
const SA_LOCKS = ['.servernet-web-release.lock', 'servernet-release.lock', '.codex-web-deploy.lock'];

function saCheck(bool $ok, string $reason): void { if (!$ok) throw new RuntimeException($reason); }
function saUid(): int { return function_exists('posix_geteuid') ? posix_geteuid() : (int)fileowner('/proc/'.getmypid()); }
function saSha(string $bytes): string { return hash('sha256', $bytes); }
function saSafe(string $path, bool $directory = false): void {
    saCheck(str_starts_with($path, SA_HOME.'/') && !str_contains($path, '/../') && !str_contains($path, '/./'), 'path_scope');
    for ($p=$path; $p!==SA_HOME; $p=dirname($p)) saCheck(!is_link($p), 'symlink_path');
    if (file_exists($path)) saCheck($directory ? is_dir($path) : is_file($path), 'path_type');
}
function saSig(string $path): ?array {
    clearstatcache(true, $path); saSafe($path);
    if (!file_exists($path)) return null;
    $s=stat($path); saCheck(is_array($s), 'stat_failed');
    return ['sha256'=>hash_file('sha256',$path), 'mode'=>$s['mode'] & 07777,
        'uid'=>$s['uid'],'gid'=>$s['gid'],'bytes'=>$s['size']];
}
function saPrivate(string $path): string {
    saSafe($path); $s=stat($path);
    saCheck(is_array($s) && $s['uid']===saUid() && ($s['mode'] & 07777)===0600, 'private_file_identity');
    $d=file_get_contents($path); saCheck(is_string($d) && strlen($d)<8*1024*1024, 'private_read'); return $d;
}
function saWritePrivate(string $path, string $bytes): void {
    saSafe($path); $f=fopen($path,'x+b'); saCheck(is_resource($f),'private_already_exists');
    try { saCheck(chmod($path,0600) && fwrite($f,$bytes)===strlen($bytes) && fflush($f) && fsync($f),'private_write'); }
    finally { fclose($f); }
}
function saRecord(string $stage,string $name,array $data): void {
    saWritePrivate($stage.'/'.$name,json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n");
}
function saReadJson(string $path): array {
    $d=json_decode(saPrivate($path),true,64,JSON_THROW_ON_ERROR); saCheck(is_array($d),'private_json'); return $d;
}
function saStart(int $pid): string {
    $s=file_get_contents('/proc/'.$pid.'/stat'); saCheck(is_string($s),'coordinator_missing');
    $i=strrpos($s,') '); saCheck($i!==false,'coordinator_stat');
    $parts=preg_split('/\s+/',trim(substr($s,$i+2))); saCheck(isset($parts[19]),'coordinator_start'); return $parts[19];
}
function saArgument(array $argv,string $name): ?string {
    foreach ($argv as $i=>$v) { if ($v===$name) return $argv[$i+1]??null; if (str_starts_with($v,$name.'=')) return substr($v,strlen($name)+1); } return null;
}
function saCoordinator(int $pid,string $start,string $stage,string $payload,string $payloadSha,string $helper): void {
    saCheck($pid>1 && is_dir('/proc/'.$pid) && fileowner('/proc/'.$pid)===saUid() && saStart($pid)===$start,'coordinator_identity');
    $cmd=explode("\0",rtrim((string)file_get_contents('/proc/'.$pid.'/cmdline'),"\0"));
    $i=array_search($helper,$cmd,true);
    saCheck($i!==false && ($cmd[$i+1]??null)==='session'
        && saArgument($cmd,'--stage')===$stage && saArgument($cmd,'--payload')===$payload
        && saArgument($cmd,'--payload-sha256')===$payloadSha,'coordinator_command');
    $lines=file('/proc/locks',FILE_IGNORE_NEW_LINES); saCheck(is_array($lines),'kernel_locks_unavailable');
    foreach (SA_LOCKS as $name) {
        $path=SA_HOME.'/'.$name; saSafe($path); $s=stat($path);
        saCheck(is_array($s) && $s['uid']===saUid() && ($s['mode'] & 0022)===0,'lock_identity');
        $major=(($s['dev']>>8)&0xfff)|(($s['dev']>>32)&0xfffff000);
        $minor=($s['dev']&0xff)|(($s['dev']>>12)&0xffffff00); $found=false;
        foreach ($lines as $line) {
            $p=preg_split('/\s+/',trim($line));
            if (count($p)<8 || $p[1]!=='FLOCK' || $p[2]!=='ADVISORY' || $p[3]!=='WRITE' || (int)$p[4]!==$pid) continue;
            $dev=explode(':',$p[5]); if (count($dev)===3 && hexdec($dev[0])===$major && hexdec($dev[1])===$minor && (int)$dev[2]===$s['ino']) $found=true;
        }
        saCheck($found,'coordinator_does_not_own_all_locks');
    }
}
function saSource(array $preview,bool $restored): void {
    foreach ($preview['files'] as $row) {
        saCheck(is_string($row['destination']) && str_starts_with($row['destination'],'servernet_app/'),'source_scope');
        $path=SA_HOME.'/'.$row['destination']; saCheck(saSig($path)==($restored?$row['before']:$row['after']),'source_identity_drift');
    }
    saCheck(saSig(SA_APP.'/resources/views/layouts/site.blade.php')==$preview['layout_dependency']['signature'],'layout_identity_drift');
}
function saCompiledPath(string $path): void {
    saSafe($path); $dir=SA_APP.'/storage/framework/views';
    saCheck(dirname($path)===$dir && realpath($dir)===$dir && preg_match('/\A[a-f0-9]+\.php\z/D',basename($path))===1,'compiled_path');
}
function saPlan(array $plan,array $preview,object $compiler): void {
    $expected=[];
    foreach ($preview['files'] as $index=>$r) if ($r['change'] && str_ends_with($r['source'],'.blade.php')) $expected[$r['source']]=['row'=>$r,'index'=>$index];
    saCheck($plan['release']===SA_RELEASE && count($plan['compiled'])===count($expected),'activation_plan_scope');
    foreach ($plan['compiled'] as $r) {
        $e=$expected[$r['source']]??null; saCheck($e!==null,'activation_plan_source'); unset($expected[$r['source']]);
        $source=SA_HOME.'/'.$e['row']['destination']; saCompiledPath($r['path']);
        saCheck($r['source_path']===$source && $r['path']===$compiler->getCompiledPath($source)
            && $r['backup']==='activation-before-'.$e['index'].'.php' && $r['candidate']==='activation-candidate-'.$e['index'].'.php','activation_plan_path');
    }
    saCheck($expected===[],'activation_plan_incomplete');
}
function saAtomic(string $path,string $bytes,array $after,?array $before,int $mtime): void {
    saCheck(saSha($bytes)===$after['sha256'] && strlen($bytes)===$after['bytes'],'compiled_candidate_drift');
    saCompiledPath($path); $temporary=dirname($path).'/.seo-activation-'.bin2hex(random_bytes(12));
    $f=fopen($temporary,'x+b'); saCheck(is_resource($f),'compiled_stage_open');
    try {
        saCheck(chmod($temporary,$after['mode']),'compiled_mode');
        if (filegroup($temporary)!==$after['gid']) saCheck(chgrp($temporary,$after['gid']),'compiled_group');
        saCheck(fileowner($temporary)===$after['uid'] && fwrite($f,$bytes)===strlen($bytes) && fflush($f) && fsync($f),'compiled_stage_write');
        saCheck(saSig($path)==$before,'compiled_before_drift');
        saCheck(rename($temporary,$path) && touch($path,$mtime,$mtime),'compiled_replace');
        saCheck(saSig($path)==$after,'compiled_after_drift');
    } finally { fclose($f); if (file_exists($temporary)) unlink($temporary); }
}
function saRestore(string $stage,array $plan,callable $guard): int {
    foreach ($plan['compiled'] as $r) {
        $current=saSig($r['path']); saCheck($current==$r['before'] || $current==$r['after'],'compiled_newer_work');
        if ($r['before']!==null) saCheck(saSha(saPrivate($stage.'/'.$r['backup']))===$r['before']['sha256'],'compiled_backup_drift');
    }
    $count=0;
    foreach (array_reverse($plan['compiled']) as $r) {
        $guard(); $current=saSig($r['path']); saCheck($current==$r['before'] || $current==$r['after'],'compiled_restore_race');
        if ($current==$r['after']) {
            if ($r['before']===null) { saCheck(unlink($r['path']),'compiled_remove'); }
            else {
                // Restored source was atomically replaced later than the backup.
                // Backdate the cached preimage so Laravel must recompile its source.
                $sourceTime=is_file($r['source_path'])?filemtime($r['source_path']):time();
                saAtomic($r['path'],saPrivate($stage.'/'.$r['backup']),$r['before'],$r['after'],min($r['before_mtime'],$sourceTime-2));
            }
            $count++;
        }
    }
    return $count;
}
function saMain(array $argv): int {
    saCheck(PHP_SAPI==='cli' && count($argv)===8 && saUid()===1020,'cli_account');
    [, $phase,$stage,$previewSha,$payloadPath,$payloadSha,$pidText,$helper]=$argv;
    saCheck(in_array($phase,['activate','rollback'],true) && ctype_digit($pidText),'arguments');
    foreach ([$previewSha,$payloadSha] as $hash) saCheck(preg_match('/\A[a-f0-9]{64}\z/D',$hash)===1,'hash_argument');
    saSafe($stage,true); $st=stat($stage);
    saCheck(dirname($stage)===SA_HOME && preg_match('/\A\.seo-iran-release-20261108-[a-z0-9-]+\z/D',basename($stage))===1
        && is_array($st) && $st['uid']===1020 && ($st['mode']&07777)===0700,'stage_identity');
    $payloadRaw=saPrivate($payloadPath); saCheck(saSha($payloadRaw)===$payloadSha,'payload_hash');
    $payload=json_decode($payloadRaw,true,64,JSON_THROW_ON_ERROR);
    saCheck($payload['release']===SA_RELEASE && $payload['home']===SA_HOME && $payload['account_uid']===1020
        && saSha(saPrivate($helper))===$payload['helpers']['release.py'],'payload_identity');
    $raw=saPrivate($stage.'/preview.json'); saCheck(saSha($raw)===$previewSha,'preview_hash');
    $preview=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
    saCheck($preview['release']===SA_RELEASE && $preview['payload_sha256']===$payloadSha && count($preview['files'])===9,'preview_identity');
    $expected=[]; foreach ($payload['files'] as $r) $expected[$r['source']]=$r['destination'];
    foreach ($preview['files'] as $r) saCheck(($expected[$r['source']]??null)===$r['destination'],'preview_scope');
    saCheck(count(array_unique(array_column($preview['files'],'source')))===9,'preview_duplicate_source');
    $intent=saReadJson($stage.'/intent.json');
    saCheck($intent['preview_sha256']===$previewSha && $intent['payload_sha256']===$payloadSha,'install_intent');
    $restored=$phase==='rollback';
    if ($restored) {
        $rollback=saReadJson($stage.'/rollback.json'); saCheck($rollback['release']===SA_RELEASE && $rollback['preview_sha256']===$previewSha,'rollback_receipt');
        saCheck(!file_exists($stage.'/activation-rollback.json'),'rollback_activation_already_attempted');
    } else {
        $applied=saReadJson($stage.'/applied.json'); saCheck($applied['release']===SA_RELEASE && $applied['target_commit']===$payload['target_commit']
            && !file_exists($stage.'/rollback.json') && !file_exists($stage.'/activation-intent.json'),'apply_receipt');
    }
    $pid=(int)$pidText; $start=saStart($pid);
    $guard=static function()use($pid,$start,$stage,$payloadPath,$payloadSha,$helper,$preview,$restored):void {
        saCoordinator($pid,$start,$stage,$payloadPath,$payloadSha,$helper); saSource($preview,$restored);
    };
    $guard();
    if (!$restored) saRecord($stage,'activation-intent.json',['release'=>SA_RELEASE,'preview_sha256'=>$previewSha,'payload_sha256'=>$payloadSha,'at'=>gmdate('c')]);
    // CLI flags supplied by the coordinator additionally disable network transports.
    require SA_APP.'/vendor/autoload.php'; $app=require SA_APP.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    Illuminate\Support\Facades\Http::preventStrayRequests();
    saCheck(config('cache.default')==='file','unexpected_cache_store');
    $compiler=$app->make('blade.compiler'); $compiled=[]; $plan=null;
    try {
        if ($restored) {
            if (file_exists($stage.'/activation-plan.json')) {
                $plan=saReadJson($stage.'/activation-plan.json'); saCheck($plan['preview_sha256']===$previewSha && $plan['payload_sha256']===$payloadSha,'activation_plan_identity');
                saPlan($plan,$preview,$compiler);
                $count=saRestore($stage,$plan,$guard);
            } else $count=0;
        } else {
            foreach ($preview['files'] as $index=>$row) {
                if (!$row['change'] || !str_ends_with($row['source'],'.blade.php')) continue;
                $guard(); $source=SA_HOME.'/'.$row['destination']; $path=$compiler->getCompiledPath($source); saCompiledPath($path);
                $before=saSig($path); saCheck($before===null || $before['uid']===1020,'compiled_owner');
                $beforeMtime=$before===null?null:filemtime($path); $backup='activation-before-'.$index.'.php'; $candidate='activation-candidate-'.$index.'.php';
                if ($before!==null) { $data=file_get_contents($path); saCheck(is_string($data)&&strlen($data)<8*1024*1024,'compiled_backup_read'); saWritePrivate($stage.'/'.$backup,$data); saCheck(saSig($path)==$before,'compiled_backup_race'); }
                $previous=$compiler->getPath(); try {
                    $compiler->setPath($source); $data=$compiler->compileString(file_get_contents($source));
                    $data=(new ReflectionMethod($compiler,'appendFilePath'))->invoke($compiler,$data); token_get_all($data,TOKEN_PARSE);
                } finally { $compiler->setPath($previous); }
                saWritePrivate($stage.'/'.$candidate,$data);
                $after=['sha256'=>saSha($data),'mode'=>$before['mode']??0644,'uid'=>1020,'gid'=>$before['gid']??filegroup(dirname($path)),'bytes'=>strlen($data)];
                $compiled[]=['source'=>$row['source'],'source_path'=>$source,'path'=>$path,'before'=>$before,'before_mtime'=>$beforeMtime,'after'=>$after,'backup'=>$backup,'candidate'=>$candidate];
            }
            $plan=['release'=>SA_RELEASE,'preview_sha256'=>$previewSha,'payload_sha256'=>$payloadSha,'compiled'=>$compiled];
            saRecord($stage,'activation-plan.json',$plan);
            foreach ($compiled as $r) {
                $guard(); $current=saSig($r['path']);
                if ($current==$r['after']) continue;
                saCheck($current==$r['before'],'compiled_precondition_drift');
                saAtomic($r['path'],saPrivate($stage.'/'.$r['candidate']),$r['after'],$r['before'],max(time(),filemtime($r['source_path'])+1));
            }
            $count=count($compiled);
        }
        $guard(); $store=Illuminate\Support\Facades\Cache::store(); $generationBefore=(int)$store->get('page:gen',0);
        App\Http\Middleware\PageCache::purge(); $generationAfter=(int)$store->get('page:gen',0);
        saCheck($generationAfter>$generationBefore,'page_generation_not_increased'); $guard();
        $result=['release'=>SA_RELEASE,'phase'=>$phase,'status'=>$restored?'rollback_activation_complete_pending_http':'activation_complete_pending_http',
            'preview_sha256'=>$previewSha,'payload_sha256'=>$payloadSha,'compiled_views'=>$count,'cache_store'=>'file',
            'generation_before'=>$generationBefore,'generation_after'=>$generationAfter,'opcache_activation_claimed'=>false,
            'framework_bootstraps'=>1,'broad_cache_flush'=>false,'at'=>gmdate('c')];
        saRecord($stage,$restored?'activation-rollback.json':'activation.json',$result);
        echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n"; return 0;
    } catch (Throwable $e) {
        if (!$restored && $plan!==null) {
            try { $count=saRestore($stage,$plan,$guard); saRecord($stage,'activation-failure.json',['release'=>SA_RELEASE,'status'=>'cache_preimages_restored_pending_review','count'=>$count,'at'=>gmdate('c')]); }
            catch (Throwable) { if (!file_exists($stage.'/activation-failure.json')) saRecord($stage,'activation-failure.json',['release'=>SA_RELEASE,'status'=>'stopped_preserve_unknown_cache_work','at'=>gmdate('c')]); }
        }
        throw $e;
    }
}
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    try { exit(saMain($argv)); }
    catch (Throwable $e) { echo json_encode(['release'=>SA_RELEASE,'status'=>'stopped','reason'=>$e instanceof RuntimeException && preg_match('/\A[a-z_]+\z/D',$e->getMessage())?$e->getMessage():'inspect_private_activation_state'],JSON_THROW_ON_ERROR)."\n"; exit(1); }
}

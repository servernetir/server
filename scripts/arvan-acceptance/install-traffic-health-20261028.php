<?php
declare(strict_types=1);

/** Narrow UI-only release. No billing, provider, notifier, scheduler or global cache operation. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0'); umask(0077);
$root='/home/servernetcloud/servernet_app';
$private=$root.'/storage/app/private';
$backup=$private.'/arvan-traffic-health-release-20261028';
$view=$root.'/resources/views/admin/errors.blade.php';
$class=$root.'/app/Services/Finance/ArvanTrafficHealth.php';
$php='/opt/cpanel/ea-php84/root/usr/bin/php';
$mode=$argv[1]??'--preview'; $stage='arguments'; $lock=null; $temps=[]; $app=null;
$block=<<<'BLADE'
    {{-- [arvan-traffic-health:start] --}}
    @php
      if (!collect($health ?? [])->contains('key', 'arvan_traffic_meter')) {
        $health[] = app(\App\Services\Finance\ArvanTrafficHealth::class)->check();
      }
    @endphp
    {{-- [arvan-traffic-health:end] --}}
BLADE;
$block.="\n";
function thGuard(bool $ok,string $code): void { if(!$ok) throw new RuntimeException('guard:'.$code); }
function thHash(string $file): string { $v=hash_file('sha256',$file); thGuard(is_string($v),'hash'); return $v; }
function thBlob(string $bytes): string { return sha1('blob '.strlen($bytes)."\0".$bytes); }
function thWriteNew(string $file,string $bytes,int $mode=0600): void {
    thGuard(!is_link($file),'new_symlink');
    $h=@fopen($file,'x'); thGuard(is_resource($h),'exclusive_create');
    try {
        thGuard(fwrite($h,$bytes)===strlen($bytes),'write');
        thGuard(fflush($h),'flush');
        thGuard(chmod($file,$mode),'file_mode');
    } finally { fclose($h); }
}
function thRun(array $command,string $cwd): void {
    $pipes=[];
    $p=proc_open($command,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$cwd);
    thGuard(is_resource($p),'child_start');
    stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); thGuard(proc_close($p)===0,'child_check_failed');
}
function thReadManifest(string $backup): array {
    $file=$backup.'/manifest.json';
    thGuard(is_file($file)&&!is_link($file)&&(fileperms($file)&0777)===0600&&filesize($file)<32768,'manifest');
    $m=json_decode(file_get_contents($file),true,32,JSON_THROW_ON_ERROR);
    thGuard(is_array($m)&&($m['version']??null)===1,'manifest_version');
    foreach(['before_sha256','after_sha256','class_sha256'] as $k) thGuard(is_string($m[$k]??null)&&preg_match('/^[a-f0-9]{64}$/D',$m[$k])===1,'manifest_hash');
    return $m;
}
function thAtomicView(string $view,string $bytes,string $before,array &$temps): void {
    thGuard(is_file($view)&&!is_link($view)&&thHash($view)===$before,'view_drift');
    $tmp=tempnam(dirname($view),'.arvan-traffic-view-'); thGuard(is_string($tmp),'view_temp'); $temps[]=$tmp;
    thGuard(file_put_contents($tmp,$bytes,LOCK_EX)===strlen($bytes),'candidate_write');
    thGuard(chmod($tmp,fileperms($view)&0777),'candidate_mode');
    thGuard(!is_link($view)&&thHash($view)===$before,'view_drift');
    thGuard(rename($tmp,$view),'atomic_view_replace');
    thGuard(thHash($view)===hash('sha256',$bytes),'view_verify');
}
function thBoot(string $root): object {
    require_once $root.'/vendor/autoload.php';
    $app=require $root.'/bootstrap/app.php';
    $level=ob_get_level(); ob_start();
    try { $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); }
    finally { while(ob_get_level()>$level) ob_end_clean(); }
    return $app;
}
function thInvalidateCompiled(object $app,string $view,string $root): bool {
    $compiled=$app->make('blade.compiler')->getCompiledPath($view);
    $expected=$root.'/storage/framework/views';
    if(!is_string($compiled)||realpath(dirname($compiled))!==$expected
        ||preg_match('/^[a-f0-9]{16,128}\\.php$/D',basename($compiled))!==1) return false;
    thGuard(!is_link($compiled),'compiled_symlink');
    return !file_exists($compiled)||unlink($compiled);
}
try {
    thGuard(count($argv)<=2&&in_array($mode,['--preview','--apply','--rollback'],true),'mode');
    $stage='paths';
    thGuard(realpath($root)===$root&&realpath($private)===$private,'root_path');
    thGuard(realpath(dirname($view))===$root.'/resources/views/admin'&&is_file($view)&&!is_link($view),'view_path');
    thGuard(realpath(dirname($class))===$root.'/app/Services/Finance'&&!is_link($class),'class_path');
    thGuard(is_executable($php)&&is_callable('proc_open'),'php_process');
    if(file_exists($backup)) thGuard(is_dir($backup)&&!is_link($backup)&&realpath($backup)===$backup&&(fileperms($backup)&0777)===0700,'backup_directory');
    if($mode==='--rollback') {
        $stage='rollback';
        thGuard(is_dir($backup),'backup_missing');
        thGuard(!is_link($backup.'/release.lock'),'lock_symlink');
        $lock=fopen($backup.'/release.lock','c'); thGuard(is_resource($lock)&&flock($lock,LOCK_EX|LOCK_NB),'release_lock'); chmod($backup.'/release.lock',0600);
        $m=thReadManifest($backup); $beforeFile=$backup.'/errors.before.blade.php';
        thGuard(is_file($beforeFile)&&!is_link($beforeFile)&&(fileperms($beforeFile)&0777)===0600&&thHash($beforeFile)===$m['before_sha256'],'backup_view');
        $liveHash=thHash($view);
        if($liveHash!==$m['before_sha256']) {
            thGuard($liveHash===$m['after_sha256'],'rollback_view_drift');
            thAtomicView($view,file_get_contents($beforeFile),$liveHash,$temps);
        }
        $app=thBoot($root); $invalidated=thInvalidateCompiled($app,$view,$root);
        echo json_encode(['ok'=>true,'mode'=>'rollback','view_sha256'=>thHash($view),'class_left_in_place'=>true,
            'compiled_view_invalidated'=>$invalidated,'backup'=>'storage/app/private/arvan-traffic-health-release-20261028'],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
    } else {
        $stage='staged_assets';
        $stageDir=realpath(__DIR__); thGuard(is_string($stageDir),'stage_directory');
        $source=$stageDir.'/ArvanTrafficHealth.php'; $test=$stageDir.'/traffic-health.test.php';
        foreach([$source,$test] as $file) thGuard(is_file($file)&&!is_link($file)&&realpath($file)===$file,'staged_asset');
        $classBytes=file_get_contents($source); $testBytes=file_get_contents($test);
        thGuard(thBlob($classBytes)==='4a09ce097c1b523f880f351073c412ea4bdb342e','class_blob');
        thGuard(thBlob($testBytes)==='1c4ef49415a69f6607542701792baafe6bfc2f77','test_blob');
        $classHash=hash('sha256',$classBytes);
        if(file_exists($class)) thGuard(is_file($class)&&thHash($class)===$classHash,'existing_class_differs');
        $stage='lint_and_pure_tests';
        thRun([$php,'-l',$source],$root); thRun([$php,'-l',$test],$root); thRun([$php,$test,$source],$root);
        $stage='live_view_candidate';
        $before=file_get_contents($view); $beforeHash=hash('sha256',$before);
        $start='[arvan-traffic-health:start]'; $end='[arvan-traffic-health:end]'; $anchor='    @foreach($health as $c)';
        $installed=str_contains($before,$start)||str_contains($before,$end);
        if($installed) {
            thGuard(substr_count($before,$start)===1&&substr_count($before,$end)===1&&substr_count($before,$block.$anchor)===1,'view_marker_conflict');
            $candidate=$before;
        } else {
            thGuard(substr_count($before,$anchor)===1,'view_anchor');
            $candidate=str_replace($anchor,$block.$anchor,$before);
        }
        $afterHash=hash('sha256',$candidate);
        $stage='blade_compile';
        $app=thBoot($root);
        $compiled=$app->make('blade.compiler')->compileString($candidate);
        $lint=tempnam(sys_get_temp_dir(),'arvan-traffic-lint-'); thGuard(is_string($lint),'lint_temp'); $temps[]=$lint;
        thGuard(file_put_contents($lint,$compiled,LOCK_EX)===strlen($compiled)&&chmod($lint,0600),'lint_write');
        thRun([$php,'-l',$lint],$root);
        $status='preview'; $invalidated=false;
        if($mode==='--apply') {
            $stage='release_backup';
            if(!file_exists($backup)) thGuard(mkdir($backup,0700),'backup_create');
            thGuard(realpath($backup)===$backup&&!is_link($backup)&&(fileperms($backup)&0777)===0700,'backup_directory');
            thGuard(!is_link($backup.'/release.lock'),'lock_symlink');
            $lock=fopen($backup.'/release.lock','c'); thGuard(is_resource($lock)&&flock($lock,LOCK_EX|LOCK_NB),'release_lock'); chmod($backup.'/release.lock',0600);
            thGuard(thHash($view)===$beforeHash,'view_drift');
            if(!$installed) {
                $m=['version'=>1,'before_sha256'=>$beforeHash,'after_sha256'=>$afterHash,'class_sha256'=>$classHash,'created_at_utc'=>gmdate('c')];
                $beforeFile=$backup.'/errors.before.blade.php';
                if(file_exists($beforeFile)) thGuard(is_file($beforeFile)&&!is_link($beforeFile)&&(fileperms($beforeFile)&0777)===0600&&thHash($beforeFile)===$beforeHash,'existing_backup_differs');
                else thWriteNew($beforeFile,$before);
                if(file_exists($backup.'/manifest.json')) {
                    $saved=thReadManifest($backup);
                    foreach(['before_sha256','after_sha256','class_sha256'] as $key) thGuard($saved[$key]===$m[$key],'existing_manifest_differs');
                } else thWriteNew($backup.'/manifest.json',json_encode($m,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
            }
            $stage='class_install';
            if(!file_exists($class)) {
                $tmp=tempnam(dirname($class),'.arvan-traffic-class-'); thGuard(is_string($tmp),'class_temp'); $temps[]=$tmp;
                thGuard(file_put_contents($tmp,$classBytes,LOCK_EX)===strlen($classBytes)&&chmod($tmp,0644),'class_write');
                thGuard(!is_link($class)&&!file_exists($class),'class_appeared');
                thGuard(link($tmp,$class),'class_exclusive_publish');
            }
            thGuard(!is_link($class)&&thHash($class)===$classHash,'class_verify');
            $stage='fresh_composer_autoload';
            $autoloadCode='require '.var_export($root.'/vendor/autoload.php',true).'; $r=new ReflectionClass("App\\\\Services\\\\Finance\\\\ArvanTrafficHealth"); if(realpath($r->getFileName())!=='.var_export($class,true).'||hash_file("sha256",$r->getFileName())!=='.var_export($classHash,true).') exit(3);';
            thRun([$php,'-r',$autoloadCode],$root);
            $stage='view_install';
            if(!$installed) thAtomicView($view,$candidate,$beforeHash,$temps);
            else thGuard(thHash($view)===$afterHash,'view_drift');
            $stage='compiled_view';
            $invalidated=thInvalidateCompiled($app,$view,$root);
            $status=$installed?'already_installed':'installed';
        }
        $stage='safe_report';
        if(!class_exists(App\Services\Finance\ArvanTrafficHealth::class)) require $source;
        $report=(new App\Services\Finance\ArvanTrafficHealth())->report();
        echo json_encode(['ok'=>true,'mode'=>$status,'view_before_sha256'=>$beforeHash,'view_sha256'=>thHash($view),
            'candidate_view_sha256'=>$afterHash,'class_sha256'=>$classHash,'compiled_view_invalidated'=>$invalidated,
            'backup'=>$mode==='--apply'?'storage/app/private/arvan-traffic-health-release-20261028':null,'report'=>$report],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
    }
} catch(Throwable $e) {
    $reason=preg_match('/^guard:[a-z0-9_]+$/D',$e->getMessage())===1?$e->getMessage():'operation_failed';
    echo json_encode(['ok'=>false,'stage'=>$stage,'reason'=>$reason,'financial_or_schedule_operation'=>false],JSON_THROW_ON_ERROR),PHP_EOL;
    exit(1);
} finally {
    foreach($temps as $file) if(is_string($file)&&is_file($file)&&!is_link($file)) @unlink($file);
    if(is_resource($lock)) { flock($lock,LOCK_UN); fclose($lock); }
}

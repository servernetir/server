<?php
declare(strict_types=1);
// Private one-file CLI release. Web OPcache activation is a separate guarded step.
if (PHP_SAPI !== 'cli') { exit(1); }
umask(0077);
const BLOG_HOME = '/home/servernetcloud';
const BLOG_ROOT = BLOG_HOME.'/servernet_app';
const BLOG_DIR = BLOG_HOME.'/.seo-blog-authors-20261101';
const BLOG_REL = 'app/Services/BlogRepository.php';
const BLOG_BEFORE = '78d4310b9262476e25fb7d77df174c0c38a6fc0872d5bbad9aaa03ac0d2e0117';
const BLOG_AFTER = '8769aff87efe64a7871a0f399d6e806d6d1027337fdf973c71df2c5a67a765df';
const BLOG_PROBE = 'a084cf7c11f453fd208a3dc6201ca6b50586ae867371db2a38bff4c8e750d324';
function blogInsist(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function blogHash(string $path): ?string {
    clearstatcache(true,$path);
    return !is_link($path) && is_file($path) ? hash_file('sha256',$path) : null;
}
function blogPut(string $path, string $bytes, int $mode): void {
    blogInsist(!is_link($path),'write_symlink_refused');
    $tmp = $path.'.seo-blog-'.bin2hex(random_bytes(6));
    try {
        blogInsist(file_put_contents($tmp,$bytes,LOCK_EX) === strlen($bytes),'short_write');
        blogInsist(chmod($tmp,$mode),'chmod_failed');
        blogInsist(rename($tmp,$path),'atomic_rename_failed');
        blogInsist(blogHash($path) === hash('sha256',$bytes),'write_hash_mismatch');
    } finally { if (is_file($tmp)) unlink($tmp); }
}
function blogRecord(string $path, array $data): void {
    blogPut($path,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n",0600);
}
function blogProcess(array $command): string {
    $p = new \Symfony\Component\Process\Process($command);
    $p->setTimeout(45);
    $p->run();
    blogInsist($p->isSuccessful(),'private_subprocess_failed');
    return $p->getOutput();
}
function blogProbe(string $hash): array {
    $data = json_decode(blogProcess([PHP_BINARY,'-d','opcache.enable_cli=0',BLOG_DIR.'/blog-probe.php',BLOG_ROOT,$hash]),true,32,JSON_THROW_ON_ERROR);
    blogInsist(($data['status']??null)==='ok' && ($data['probe']['source_sha256']??null)===$hash,'probe_result_invalid');
    return $data['probe'];
}
function blogCompare(array $before, array $after): array {
    $counts = [];
    foreach (['fa','en','tr'] as $locale) {
        $b = $before['locales'][$locale]??null; $a = $after['locales'][$locale]??null;
        blogInsist(is_array($b)&&is_array($a),'probe_locale_missing');
        blogInsist($b['cold']['rows']===$a['cold']['rows']
            && $b['cold']['metadata_sha256']===$a['cold']['metadata_sha256'],'metadata_changed');
        blogInsist($a['warm']['sql_queries']===0 && $a['cold']['metadata_sha256']===$a['warm']['metadata_sha256'],'warm_cache_changed');
        blogInsist($a['cold']['author_queries']<=1
            && $a['cold']['author_queries']<=$b['cold']['author_queries']
            && $a['cold']['sql_queries']<=$b['cold']['sql_queries'],'query_count_regressed');
        $counts[$locale] = ['rows'=>$a['cold']['rows'],'author_queries_before'=>$b['cold']['author_queries'],
            'author_queries_after'=>$a['cold']['author_queries'],'metadata_unchanged'=>true];
    }
    return $counts;
}
$locks=[];
try {
    $mode=$argv[1]??'preview';
    blogInsist(in_array($mode,['preview','apply','rollback'],true),'mode_invalid');
    blogInsist(is_dir(BLOG_DIR)&&!is_link(BLOG_DIR)&&str_replace('\\','/',(string)realpath(BLOG_DIR))===BLOG_DIR,'private_directory_invalid');
    if (PHP_OS_FAMILY!=='Windows') {
        blogInsist((fileperms(BLOG_DIR)&0777)===0700&&fileowner(BLOG_DIR)===getmyuid(),'private_directory_permissions_invalid');
    }
    foreach (['.servernet-web-release.lock','servernet-release.lock','.codex-web-deploy.lock'] as $name) {
        $path=BLOG_HOME.'/'.$name;
        blogInsist(!is_link($path),'lock_symlink_refused');
        $lock=fopen($path,'c+b');
        blogInsist($lock!==false&&flock($lock,LOCK_EX|LOCK_NB),'release_lock_busy');
        $locks[]=$lock;
    }
    $target=BLOG_ROOT.'/'.BLOG_REL;
    blogInsist(str_replace('\\','/',(string)realpath($target))===$target&&!is_link($target),'target_path_invalid');
    blogInsist(blogHash(BLOG_DIR.'/candidate.php')===BLOG_AFTER,'candidate_drift');
    blogInsist(blogHash(BLOG_DIR.'/blog-probe.php')===BLOG_PROBE,'probe_script_drift');
    require BLOG_ROOT.'/vendor/autoload.php';
    blogProcess([PHP_BINARY,'-l',BLOG_DIR.'/candidate.php']);
    if ($mode==='preview') {
        blogInsist(!file_exists(BLOG_DIR.'/preview.json')&&!file_exists(BLOG_DIR.'/before.php')
            &&!file_exists(BLOG_DIR.'/intent.json'),'preview_already_attempted');
        blogInsist(blogHash($target)===BLOG_BEFORE,'live_source_drift');
        $permissions=fileperms($target)&0777;
        blogPut(BLOG_DIR.'/before.php',file_get_contents($target),0600);
        blogInsist(blogHash(BLOG_DIR.'/before.php')===BLOG_BEFORE,'backup_hash_mismatch');
        $baseline=blogProbe(BLOG_BEFORE);
        blogInsist(blogHash($target)===BLOG_BEFORE,'source_changed_during_preview');
        $receipt=['status'=>'preview','at'=>gmdate('c'),'target'=>BLOG_REL,'before'=>BLOG_BEFORE,
            'after'=>BLOG_AFTER,'source_mode'=>$permissions,'baseline'=>$baseline,'candidate_lint'=>true];
        blogRecord(BLOG_DIR.'/preview.json',$receipt);
        echo json_encode($receipt,JSON_THROW_ON_ERROR),"\n";
        exit;
    }
    $preview=json_decode(file_get_contents(BLOG_DIR.'/preview.json'),true,32,JSON_THROW_ON_ERROR);
    blogInsist(($preview['target']??null)===BLOG_REL&&($preview['before']??null)===BLOG_BEFORE
        &&($preview['after']??null)===BLOG_AFTER,'preview_scope_drift');
    blogInsist(blogHash(BLOG_DIR.'/before.php')===BLOG_BEFORE,'backup_drift');
    blogInsist(is_int($preview['source_mode'])&&$preview['source_mode']>=0&&$preview['source_mode']<=0777,'backup_mode_invalid');
    if ($mode==='rollback') {
        blogInsist(file_exists(BLOG_DIR.'/intent.json')&&!file_exists(BLOG_DIR.'/rollback.json'),'rollback_not_available');
        $intent=json_decode(file_get_contents(BLOG_DIR.'/intent.json'),true,16,JSON_THROW_ON_ERROR);
        blogInsist(($intent['preview_sha256']??null)===blogHash(BLOG_DIR.'/preview.json'),'intent_preview_drift');
        blogInsist(in_array(blogHash($target),[BLOG_BEFORE,BLOG_AFTER],true)
            &&(fileperms($target)&0777)===$preview['source_mode'],'newer_source_prevents_rollback');
        blogPut($target,file_get_contents(BLOG_DIR.'/before.php'),$preview['source_mode']);
        blogRecord(BLOG_DIR.'/rollback.json',['status'=>'rolled_back_pending_web_activation','at'=>gmdate('c'),
            'source_sha256'=>blogHash($target),'web_opcache_activated'=>false]);
        echo "{\"status\":\"rolled_back_pending_web_activation\"}\n";
        exit;
    }
    blogInsist(!file_exists(BLOG_DIR.'/intent.json'),'install_already_attempted');
    blogInsist(blogHash($target)===BLOG_BEFORE&&(fileperms($target)&0777)===$preview['source_mode'],'state_changed_after_preview');
    blogRecord(BLOG_DIR.'/intent.json',['status'=>'installing','at'=>gmdate('c'),'target'=>BLOG_REL,
        'before'=>BLOG_BEFORE,'after'=>BLOG_AFTER,'preview_sha256'=>blogHash(BLOG_DIR.'/preview.json')]);
    blogPut($target,file_get_contents(BLOG_DIR.'/candidate.php'),$preview['source_mode']);
    blogInsist(blogHash($target)===BLOG_AFTER,'installed_source_drift');
    $probe=blogProbe(BLOG_AFTER);
    $comparison=blogCompare($preview['baseline'],$probe);
    $receipt=['status'=>'installed_cli_verified_pending_web_acceptance','at'=>gmdate('c'),
        'source_sha256'=>blogHash($target),'comparison'=>$comparison,'after_probe'=>$probe,
        'web_opcache_activated'=>false,'shared_cache_flushed'=>false,'database_mutation'=>false];
    blogRecord(BLOG_DIR.'/applied.json',$receipt);
    echo json_encode($receipt,JSON_THROW_ON_ERROR),"\n";
} catch(Throwable $e) {
    $reason=$e instanceof RuntimeException&&preg_match('/^[a-z_]+$/D',$e->getMessage())?$e->getMessage():'inspect_private_release_status';
    echo json_encode(['status'=>'stopped','reason'=>$reason,'recovery'=>'Inspect intent and guarded rollback; do not rerun apply.']),"\n";
    exit(1);
} finally {
    foreach (array_reverse($locks) as $lock) { flock($lock,LOCK_UN); fclose($lock); }
}

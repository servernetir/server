<?php
declare(strict_types=1);
// Temporary primary: HTTP denied; only same-account local FastCGI may enter.
const BF_RELEASE='seo-blog-authors-20261101';
const BF_HOME='/home/servernetcloud';
const BF_ROOT=BF_HOME.'/servernet_app';
const BF_PRIVATE=BF_HOME.'/.seo-blog-authors-20261101';
const BF_PRIMARY=BF_HOME.'/public_html/_seo_blog_authors_20261101/activate.php';
const BF_REL='app/Services/BlogRepository.php';
const BF_BEFORE='78d4310b9262476e25fb7d77df174c0c38a6fc0872d5bbad9aaa03ac0d2e0117';
const BF_AFTER='8769aff87efe64a7871a0f399d6e806d6d1027337fdf973c71df2c5a67a765df';
const BF_PROBE='a084cf7c11f453fd208a3dc6201ca6b50586ae867371db2a38bff4c8e750d324';
$action=$_SERVER['SERVERNET_RELEASE_ACTION']??'';
$phase=$_SERVER['SERVERNET_RELEASE_PHASE']??'';
if (PHP_SAPI!=='fpm-fcgi'||realpath(__FILE__)!==BF_PRIMARY
    ||($_SERVER['SERVERNET_LOCAL_RELEASE']??'')!==BF_RELEASE||($_SERVER['REQUEST_METHOD']??'')!=='POST'
    ||!in_array($action,['observe','activate','verify'],true)||!in_array($phase,['before','after'],true)
    ||(function_exists('posix_geteuid')&&posix_geteuid()!==getmyuid())) {
    http_response_code(404); exit;
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
$locks=[];
try {
    foreach (['.servernet-web-release.lock','servernet-release.lock','.codex-web-deploy.lock'] as $name) {
        $path=BF_HOME.'/'.$name;
        if (is_link($path)||!is_file($path)||fileowner($path)!==getmyuid()) throw new RuntimeException('lock_invalid');
        $lock=fopen($path,'r+b');
        if (!$lock||!flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('lock_busy');
        $locks[]=$lock;
    }
    $target=BF_ROOT.'/'.BF_REL;
    $expected=$phase==='after'?BF_AFTER:BF_BEFORE;
    clearstatcache(true,$target);
    if (is_link($target)||realpath($target)!==$target||hash_file('sha256',$target)!==$expected) throw new RuntimeException('source_drift');
    if (hash_file('sha256',BF_PRIVATE.'/blog-probe.php')!==BF_PROBE) throw new RuntimeException('probe_drift');
    if (!function_exists('opcache_is_script_cached')||!function_exists('opcache_invalidate')||!function_exists('opcache_compile_file')) {
        throw new RuntimeException('opcache_functions_missing');
    }
    $before=opcache_is_script_cached($target);
    $invalidated=false; $compiled=false;
    if ($action==='activate') {
        if ($before&&!opcache_invalidate($target,true)) throw new RuntimeException('target_invalidation_failed');
        $invalidated=$before;
        if (!opcache_compile_file($target)) throw new RuntimeException('target_compile_failed');
        $compiled=true;
    }
    $probe=null; $comparison=[];
    if ($action==='verify') {
        require BF_PRIVATE.'/blog-probe.php';
        $probe=snetBlogMetadataProbe(BF_ROOT,$expected);
        $preview=json_decode(file_get_contents(BF_PRIVATE.'/preview.json'),true,32,JSON_THROW_ON_ERROR);
        if (($preview['before']??null)!==BF_BEFORE||($preview['after']??null)!==BF_AFTER) throw new RuntimeException('preview_drift');
        foreach (['fa','en','tr'] as $locale) {
            $old=$preview['baseline']['locales'][$locale]['cold'];
            $new=$probe['locales'][$locale]['cold'];
            if ($old['rows']!==$new['rows']||$old['metadata_sha256']!==$new['metadata_sha256']) throw new RuntimeException('metadata_drift');
            if ($phase==='after'&&($new['author_queries']>1||$new['author_queries']>$old['author_queries']
                ||$new['sql_queries']>$old['sql_queries'])) throw new RuntimeException('query_count_regressed');
            $comparison[$locale]=['rows'=>$new['rows'],'author_queries'=>$new['author_queries'],'metadata_unchanged'=>true];
        }
    }
    clearstatcache(true,$target);
    if (hash_file('sha256',$target)!==$expected) throw new RuntimeException('source_changed_during_activation');
    echo json_encode(['release'=>BF_RELEASE,'status'=>'ok','action'=>$action,'phase'=>$phase,'at'=>gmdate('c'),
        'target'=>BF_REL,'source_sha256'=>$expected,'php'=>PHP_VERSION,'sapi'=>PHP_SAPI,
        'opcache'=>['enable'=>(string)ini_get('opcache.enable'),'validate_timestamps'=>(string)ini_get('opcache.validate_timestamps'),
            'revalidate_freq'=>(string)ini_get('opcache.revalidate_freq'),'cached_before'=>$before,
            'invalidated'=>$invalidated,'compiled'=>$compiled,'cached_after'=>opcache_is_script_cached($target)],
        'comparison'=>$comparison,'probe'=>$probe,'shared_cache_flushed'=>false,'database_mutation'=>false],JSON_THROW_ON_ERROR),"\n";
} catch(Throwable $e) {
    http_response_code(503);
    echo json_encode(['release'=>BF_RELEASE,'status'=>'stopped','action'=>$action,'phase'=>$phase,
        'reason'=>$e instanceof RuntimeException&&preg_match('/^[a-z_]+$/D',$e->getMessage())?$e->getMessage():'inspect_private_activation_status']),"\n";
} finally { foreach(array_reverse($locks) as $lock) { flock($lock,LOCK_UN); fclose($lock); } }

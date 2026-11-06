<?php
/** HTTP-denied same-account activation of the single, approved reward config. */
declare(strict_types=1);
const MR_RELEASE='madar-rewards-20261031';
const MR_HOME='/home/servernetcloud';
const MR_CONFIG=MR_HOME.'/servernet_app/config/madar.php';
const MR_PRIMARY=MR_HOME.'/public_html/_madar_rewards_release_20261031/activate.php';
const MR_HASH='38651acbd0d51a7bb1691bd1215f6bfba1024868498b1e37b1c6b45d9cead3da';
const MR_AMOUNTS=['gpu-video'=>350000,'gpu-code'=>280000,'gpu-guide'=>210000,'referral'=>140000,
    'migration-story'=>180000,'gpu-template'=>560000,'gpu-benchmark'=>320000,'gpu-workshop'=>530000];
if(PHP_SAPI!=='fpm-fcgi'||realpath(__FILE__)!==MR_PRIMARY||($_SERVER['SERVERNET_LOCAL_RELEASE']??'')!==MR_RELEASE
    ||($_SERVER['REQUEST_METHOD']??'')!=='POST'||!in_array($_SERVER['SERVERNET_RELEASE_ACTION']??'',['observe','activate','verify'],true)
    ||(function_exists('posix_geteuid')&&posix_geteuid()!==getmyuid())){http_response_code(404);exit;}
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');
$action=$_SERVER['SERVERNET_RELEASE_ACTION'];$locks=[];
try{
    foreach(['.servernet-web-release.lock','servernet-release.lock'] as $name){
        $p=MR_HOME.'/'.$name;if(is_link($p)||!is_file($p)||fileowner($p)!==getmyuid()){throw new RuntimeException('release_lock_invalid');}
        $h=fopen($p,'r+b');if(!$h||!flock($h,LOCK_EX|LOCK_NB)){throw new RuntimeException('release_lock_busy');}$locks[]=$h;
    }
    if(is_link(MR_CONFIG)||realpath(MR_CONFIG)!==MR_CONFIG||!hash_equals(MR_HASH,(string)hash_file('sha256',MR_CONFIG))){throw new RuntimeException('config_hash_drift');}
    $invalidated=0;
    if($action==='activate'&&function_exists('opcache_is_script_cached')&&opcache_is_script_cached(MR_CONFIG)){
        if(!opcache_invalidate(MR_CONFIG,true)){throw new RuntimeException('config_invalidation_failed');}$invalidated=1;
    }
    $config=require MR_CONFIG;$amounts=[];foreach($config['campaigns'] as $key=>$value){$amounts[$key]=$value['reward_irt'];}
    if($amounts!==MR_AMOUNTS){throw new RuntimeException('reward_readback_mismatch');}
    echo json_encode(['release'=>MR_RELEASE,'action'=>$action,'status'=>'ok','amounts_irt'=>$amounts,'config_sha256'=>MR_HASH,
        'invalidated'=>$invalidated,'php'=>PHP_VERSION,'framework_bootstrapped'=>false,'database_writes'=>0,'utc'=>gmdate('c')],JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $e){http_response_code(503);echo json_encode(['release'=>MR_RELEASE,'action'=>$action,'status'=>'stopped',
    'reason'=>$e instanceof RuntimeException?$e->getMessage():'private_activation_error'],JSON_THROW_ON_ERROR).PHP_EOL;}
finally{foreach(array_reverse($locks) as $h){flock($h,LOCK_UN);fclose($h);}}

<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
$root='/home/servernetcloud/servernet_app'; $db=null; $stage='bootstrap';
function trafficTime(mixed $v): ?string {
    if (is_int($v) && $v>=946684800 && $v<=time()+86400) { return gmdate('c',$v); }
    return is_string($v) && strlen($v)<=40 && preg_match('/^\\d{4}-\\d{2}-\\d{2}[T ]\\d{2}:\\d{2}:\\d{2}(?:\\.\\d+)?(?:Z|[+-]\\d{2}:\\d{2})?$/D',$v)===1 ? $v : null;
}
function trafficSummary(array $a,int $depth=0): array {
    $o=[];
    foreach(['enabled','applied','ok','success','dry_run'] as $k) { if(is_bool($a[$k]??null)) $o[$k]=$a[$k]; }
    foreach(['sequence','total_irt','total_rial','amount_irt','charged_irt','posted_irt','margin_bp','tax_bp','lines','invoices','held','unallocated','charged','posted','skipped','failures','errors','lines_count','invoice_count','held_count','unallocated_count'] as $k) {
        if(!array_key_exists($k,$a)) continue; $v=$a[$k];
        if(is_int($v) && $v>=0) $o[$k]=$v;
        elseif(is_array($v)) $o[$k.'_array_count']=count($v);
    }
    foreach(['last_success','last_success_at','last_run_at','last_attempt_at','last_apply_at','last_error_at','usage_to','usageTo','fetched_at','last_fetch_at','baseline_at','generated_at','observed_at'] as $k) {
        if(array_key_exists($k,$a)) $o[$k]=trafficTime($a[$k]);
    }
    foreach(['mode','action'] as $k) {
        if(isset($a[$k]) && in_array($a[$k],['apply','preview','dry-run','dry_run','report','baseline'],true)) $o[$k]=$a[$k];
    }
    if($depth<3) foreach(['last_result','last_plan','summary','counts','stats','source'] as $k) {
        if(is_array($a[$k]??null)) $o[$k]=trafficSummary($a[$k],$depth+1);
    }
    return $o;
}
try {
    require $root.'/vendor/autoload.php'; $app=require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db=Illuminate\Support\Facades\DB::connection();
    if(!in_array($db->getDriverName(),['mysql','mariadb'],true)) throw new RuntimeException('driver');
    $stage='read_state';
    $db->statement('SET TRANSACTION READ ONLY'); $db->beginTransaction();
    $raw=$db->table('settings')->where('key','arvan_traffic_meter_v1')->value('value');
    $state=json_decode(Illuminate\Support\Facades\Crypt::decryptString((string)$raw),true,512,JSON_THROW_ON_ERROR);
    if(!is_array($state)) throw new RuntimeException('state');
    $out=['utc'=>gmdate('c'),'mode'=>'traffic_control_read_only','meter'=>trafficSummary($state)];
    $excluded=$state['excluded_customers']??null;
    $out['excluded_customer_count']=is_array($excluded)?count($excluded):null;
    $exclusionListValid=is_array($excluded)&&array_is_list($excluded)
        && count(array_filter($excluded,static fn($v)=>is_int($v)&&$v>=0
            || is_string($v)&&preg_match('/^(?:0|[1-9]\\d{0,9})$/D',$v)===1))===count($excluded);
    $out['disputed_account_exclusion_present']=$exclusionListValid
        ? in_array(545,array_map('intval',$excluded),true):null;
    $db->rollBack(); unset($raw,$state,$excluded);
    $stage='source_metadata';
    $files=['app/Services/Finance/ArvanTrafficMeter.php','app/Services/Finance/ArvanTrafficSource.php','app/Console/Commands/MeterArvanTraffic.php'];
    $names=[];
    foreach($files as $relative) {
        $path=$root.'/'.$relative; $entry=['file'=>basename($relative),'present'=>is_file($path)];
        if(is_file($path)) {
            $text=file_get_contents($path); $entry['sha256']=hash('sha256',$text);
            $entry['public_methods']=[];
            if(preg_match_all('/public\\s+(?:static\\s+)?function\\s+([A-Za-z_][A-Za-z0-9_]*)\\s*\\(/',$text,$m)) $entry['public_methods']=array_values(array_unique($m[1]));
            if(str_ends_with($relative,'MeterArvanTraffic.php')
                && preg_match('/\\$signature\\s*=\\s*([\'"])([a-z][a-z0-9_-]*:[a-z][a-z0-9_-]*)(.*?)\\1\\s*;/s',$text,$m)) {
                $names[]=$m[2]; $entry['command_name']=$m[2];
                foreach(['--apply','--dry-run','--notify','--report'] as $option) $entry['option_declared'][substr($option,2)]=str_contains($m[3],$option);
            }
            unset($text,$m);
        }
        $out['code'][]=$entry;
    }
    $stage='registered_schedule';
    $out['registered_traffic_events']=[];
    $schedule=$app->make(Illuminate\Console\Scheduling\Schedule::class);
    foreach($schedule->events() as $event) {
        $command=(string)($event->command??'');
        $name=null;
        foreach($names as $n) if(preg_match('/(?<![a-z0-9_:-])'.preg_quote($n,'/').'(?![a-z0-9_:-])/i',$command)) { $name=$n; break; }
        if($name===null && str_contains(strtolower($command),'arvan')
            && (str_contains(strtolower($command),'traffic')||str_contains(strtolower($command),'meter'))) $name='unrecognized_arvan_traffic_command';
        if($name===null) continue;
        $expression=method_exists($event,'getExpression')?(string)$event->getExpression():(string)($event->expression??'');
        $out['registered_traffic_events'][]=['command_name'=>$name,
            'cron_expression'=>preg_match('/^[0-9*\\/, \\-]{5,100}$/D',$expression)?$expression:null,
            'apply_argument'=>preg_match('/(?:^|\\s)--apply(?:\\s|$)/',$command)===1,
            'without_overlapping'=>(bool)($event->withoutOverlapping??false),
            'background'=>(bool)($event->runInBackground??false)];
    }
    $stage='host_cron';
    $out['host_cron']=['readable'=>false,'laravel_schedule_rows'=>0,'direct_arvan_traffic_rows'=>0,'matching_expressions'=>[]];
    if(is_callable('proc_open')) {
        $pipes=[]; $p=@proc_open(['/usr/bin/crontab','-l'],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(is_resource($p)) {
            $crontab=stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]); $exit=proc_close($p);
            $out['host_cron']['readable']=$exit===0;
            if($exit===0) foreach(preg_split('/\\r?\\n/',$crontab) as $line) {
                if(trim($line)===''||str_starts_with(ltrim($line),'#')) continue;
                $master=str_contains($line,$root)&&str_contains($line,'artisan')&&preg_match('/\\bschedule:run\\b/',$line);
                $direct=str_contains(strtolower($line),'arvan')&&(str_contains(strtolower($line),'traffic')||str_contains(strtolower($line),'meter'));
                if($master) $out['host_cron']['laravel_schedule_rows']++;
                if($direct) $out['host_cron']['direct_arvan_traffic_rows']++;
                if($master||$direct) {
                    $parts=preg_split('/\\s+/',trim($line),6); $expr=implode(' ',array_slice($parts,0,5));
                    if(preg_match('/^[0-9*\\/, \\-]{5,100}$/D',$expr)) $out['host_cron']['matching_expressions'][]=$expr;
                }
            }
            unset($crontab,$line,$pipes);
        }
    }
    $out['scope_notes']=['No meter apply, charge, report notification or schedule change was executed.',
        'Configured schedules do not prove recent execution; compare meter and supplier freshness.',
        'Node Exporter telemetry and provider credit/network enforcement are separate checks.'];
    echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
} catch(Throwable $e) {
    if($db && $db->transactionLevel()>0) { try{$db->rollBack();}catch(Throwable){} }
    echo json_encode(['error'=>'traffic_control_probe_failed','stage'=>$stage,'exception_class'=>get_class($e),
        'source_file'=>basename($e->getFile()),'source_line'=>$e->getLine()],JSON_UNESCAPED_SLASHES),PHP_EOL;
    exit(1);
}

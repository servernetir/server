<?php
declare(strict_types=1);

namespace App\Services\Cloud {
    // Only this disposable test process reports a regular fixture file as a socket.
    // Real-socket CI sets this to null and uses the actual kernel lstat unchanged.
    $udsSyntheticSocket = null;
    function lstat(string $path): array|false {
        $value = \lstat($path);
        if ($value !== false && $path === ($GLOBALS['udsSyntheticSocket'] ?? null)) {
            $value['mode'] = ($value['mode'] & 07777) | 0140000;
        }
        return $value;
    }
}
namespace {
    require __DIR__.'/../../website/app/Services/Cloud/UnixPrometheusTrafficTransport.php';
    require __DIR__.'/../../website/app/Services/Cloud/ArvanTrafficObservation.php';
    require __DIR__.'/../../website/app/Services/Cloud/PrivatePrometheusTrafficSource.php';
    use App\Services\Cloud\UnixPrometheusTrafficTransport as Transport;
    use App\Services\Cloud\PrivatePrometheusTrafficSource as Source;

    $assertions = 0; $realCases = 0;
    function utCheck(bool $ok,string $message): void {
        global $assertions; $assertions++;
        if (!$ok) throw new RuntimeException('test_failed:'.$message);
    }
    function utRefuses(Closure $call,string $reason): void {
        try { $call(); } catch (RuntimeException $error) {
            utCheck($error->getMessage() === $reason,'refusal_'.$reason); return;
        }
        throw new RuntimeException('test_failed:missing_refusal_'.$reason);
    }
    function utFixture(bool $synthetic = true): array {
        $root = sys_get_temp_dir().'/servernet-uds-test-'.bin2hex(random_bytes(8));
        mkdir($root,0700); $directory = $root.'/storage/app/private/arvan-observation';
        mkdir($directory,0700,true); chmod($directory,0700);
        $path = $root.Transport::RELATIVE;
        if ($synthetic) { file_put_contents($path,'metadata fixture only'); chmod($path,0600); }
        $GLOBALS['udsSyntheticSocket'] = $synthetic ? $path : null;
        return compact('root','directory','path');
    }
    function utCleanup(array $fixture): void {
        // Remove only this test's random private subtree, never an application path.
        if (!str_starts_with($fixture['root'],sys_get_temp_dir().'/servernet-uds-test-')) throw new RuntimeException('fixture_scope');
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture['root'],FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname()); else unlink($entry->getPathname());
        }
        rmdir($fixture['root']); $GLOBALS['udsSyntheticSocket'] = null;
    }

    final class UdsMemoryStream {
        public $context;
        public static string $response = '';
        public static string $written = '';
        public static bool $closed = false;
        private int $position = 0;
        public function stream_open(string $path,string $mode,int $options,?string &$opened): bool { self::$written=''; self::$closed=false; return true; }
        public function stream_read(int $count): string { $chunk=substr(self::$response,$this->position,min(3,$count)); $this->position+=strlen($chunk); return $chunk; }
        public function stream_write(string $bytes): int { $count=min(7,strlen($bytes)); self::$written.=substr($bytes,0,$count); return $count; }
        public function stream_eof(): bool { return $this->position>=strlen(self::$response); }
        public function stream_set_option(int $option,int $arg1,?int $arg2): bool { return $option===STREAM_OPTION_READ_TIMEOUT; }
        public function stream_stat(): array { return []; }
        public function stream_close(): void { self::$closed=true; }
    }
    stream_wrapper_register('udsfixture',UdsMemoryStream::class);
    function utHttp(string $body,string $headers='',int $status=200): string {
        return 'HTTP/1.0 '.$status." Result\r\nContent-Type: application/json\r\n".$headers."\r\n".$body;
    }
    function utTransport(array $fixture,string $response,?Closure $afterVerify = null): Transport {
        $pin=Transport::capture($fixture['root']); UdsMemoryStream::$response=$response;
        return new Transport($fixture['root'],$pin,static function(string $path)use($fixture,$afterVerify) {
            utCheck($path===$fixture['path'],'exact_unix_path_to_connector');
            if ($afterVerify) $afterVerify();
            return fopen('udsfixture://response','r+');
        });
    }
    $query=['query'=>'up{snet_service_id="613"}','time'=>'1793638800'];
    $fixture=utFixture();
    try {
        $body='{"status":"success","data":{"resultType":"vector","result":[]}}';
        $transport=utTransport($fixture,utHttp($body,'Content-Length: '.strlen($body)."\r\n"));
        $result=$transport->request($query);
        utCheck($result===['status'=>200,'body'=>$body],'fragmented_response_reassembled');
        utCheck(str_starts_with(UdsMemoryStream::$written,'GET /api/v1/query?'),'only_query_get_request');
        utCheck(str_contains(UdsMemoryStream::$written,'Host: localhost') && !str_contains(UdsMemoryStream::$written,'Authorization:'),'fixed_host_no_auth');
        utCheck(UdsMemoryStream::$closed,'stream_closed_on_success');
        $cases=[
            [utHttp('x',"Location: https://unapproved.invalid/\r\n",302),'unix_transport_response_forbidden'],
            [utHttp('x',"Transfer-Encoding: chunked\r\n"),'unix_transport_response_forbidden'],
            [utHttp('x',"Content-Encoding: gzip\r\n"),'unix_transport_response_forbidden'],
            [utHttp('x',"Content-Length: 1\r\ncontent-length: 1\r\n"),'unix_transport_duplicate_header'],
            ["HTTP/1.0 200 OK\r\nContent-Type: text/plain\r\n\r\nx",'unix_transport_content_type_invalid'],
            [utHttp('x',"Content-Length: 3\r\n"),'unix_transport_body_incomplete'],
            [utHttp('xx',"Content-Length: 1\r\n"),'unix_transport_body_limit'],
            [utHttp('',"Content-Length: 262145\r\n"),'unix_transport_body_limit'],
            [utHttp(str_repeat('x',Transport::MAX_BYTES+1)),'unix_transport_body_limit'],
            ["HTTP/1.0 200 OK\r\nX-Fill: ".str_repeat('x',16384),'unix_transport_header_limit'],
            ["HTTP/1.0 200 OK\r\nContent-Type: application/json\r\n",'unix_transport_header_incomplete'],
        ];
        foreach ($cases as [$response,$reason]) {
            $transport=utTransport($fixture,$response); utRefuses(static fn()=>$transport->request($query),$reason);
            utCheck(UdsMemoryStream::$closed,'stream_closed_on_refusal');
        }
        $transport=utTransport($fixture,utHttp('',"Content-Length: 0\r\n",503));
        utCheck($transport->request($query)===['status'=>503,'body'=>''],'non200_is_unavailable_not_sample');
        $transport=utTransport($fixture,utHttp('{}'));
        utRefuses(static fn()=>$transport->request(['time'=>'1793638800','query'=>'up']),'unix_transport_query_invalid');
        utRefuses(static fn()=>$transport->request(['query'=>str_repeat('x',9000),'time'=>'1793638800']),'unix_transport_query_limit');
        $method=new ReflectionMethod(Transport::class,'remaining');
        $stream=fopen('udsfixture://deadline','r+');
        try { utRefuses(static fn()=>$method->invoke($transport,hrtime(true)/1_000_000_000-1,$stream),'unix_transport_deadline'); }
        finally { fclose($stream); }
        foreach (['socket','directory'] as $kind) {
            $path=$fixture[$kind==='socket'?'path':'directory']; chmod($path,$kind==='socket'?0644:0750);
            utRefuses(static fn()=>Transport::capture($fixture['root']),'unix_transport_metadata_invalid');
            chmod($path,$kind==='socket'?0600:0700);
        }
        $pin=Transport::capture($fixture['root']); $pin['socket']['gid']++;
        utRefuses(static fn()=>(new Transport($fixture['root'],$pin))->verify(),'unix_transport_pin_changed');
        $transport=utTransport($fixture,utHttp('{}'),static function()use($fixture) {
            $next=$fixture['path'].'.next'; file_put_contents($next,'replacement'); chmod($next,0600); rename($next,$fixture['path']);
        });
        utRefuses(static fn()=>$transport->request($query),'unix_transport_pin_changed');
        $other=$fixture['path'].'.target'; rename($fixture['path'],$other); symlink($other,$fixture['path']);
        utRefuses(static fn()=>Transport::capture($fixture['root']),'unix_transport_metadata_invalid');
        unlink($fixture['path']); rename($other,$fixture['path']);
        link($fixture['path'],$fixture['path'].'.hardlink');
        utRefuses(static fn()=>Transport::capture($fixture['root']),'unix_transport_metadata_invalid');
        unlink($fixture['path'].'.hardlink');

        // The optional UDS constructor path never silently retries a TCP transport.
        $transport=utTransport($fixture,utHttp('',"Location: https://unapproved.invalid/\r\n",302));
        $tcpCalls=0;
        $source=new Source('http://127.0.0.1:19090',static function()use(&$tcpCalls){$tcpCalls++; throw new RuntimeException('must_not_fallback');},$transport);
        $identity=['service_id'=>613,'instance_id'=>77,'customer_id'=>42,'provider_ref'=>'ir-tbz-sh1:00000000-0000-0000-0000-000000000001','enrollment_id'=>str_repeat('a',32),'device'=>'ens3'];
        $read=$source->read($identity,1793638800);
        utCheck($read['status']==='unknown' && $read['reason']==='private_transport_protocol_invalid' && $read['sample']===null && $tcpCalls===0,'uds_failure_no_tcp_fallback_or_sample');
        $labels=['job'=>'servernet-arvan']; $rows=[]; $now=1793638800;
        foreach (['service_id','instance_id','customer_id','provider_ref','enrollment_id'] as $key) $labels['snet_'.$key]=(string)$identity[$key];
        foreach (['up'=>'1','node_scrape_collector_success'=>'1','node_network_receive_bytes_total'=>'1000','node_network_transmit_bytes_total'=>'2000','node_boot_time_seconds'=>(string)($now-86400)] as $name=>$value) {
            $metric=$labels+['__name__'=>$name];
            if ($name==='node_scrape_collector_success') $metric['collector']='netdev';
            if (str_starts_with($name,'node_network_')) $metric['device']='ens3';
            $rows[]=['metric'=>$metric,'value'=>[$now,$value]]; unset($metric['__name__']);
            $metric['snet_observed_metric']=$name; $metric['snet_observation_kind']='scrape_timestamp';
            $rows[]=['metric'=>$metric,'value'=>[$now,(string)($now-5)]];
        }
        $body=json_encode(['status'=>'success','data'=>['resultType'=>'vector','result'=>$rows]],JSON_THROW_ON_ERROR);
        $transport=utTransport($fixture,utHttp($body,'Content-Length: '.strlen($body)."\r\n"));
        $source=new Source('https://unapproved.invalid',static function()use(&$tcpCalls){$tcpCalls++; throw new RuntimeException('must_not_fallback');},$transport);
        $read=$source->read($identity,$now);
        utCheck($read['status']==='observed' && $read['sample']['sample_at']===$now-5 && $tcpCalls===0,'uds_success_constructor_uses_source_timestamp_without_tcp');
    } finally { utCleanup($fixture); }

    // CI must explicitly enable real AF_UNIX tests. This managed worker forbids
    // socket creation; the pure suite above never attempts another socket API.
    if (getenv('ARVAN_UDS_REAL_TESTS')==='1') {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) throw new RuntimeException('real_unix_test_requires_pcntl');
        foreach (['success','deadline','redirect','oversize'] as $case) {
            $fixture=utFixture(false); $server=null; $pid=null;
            try {
                $server=@stream_socket_server('unix://'.$fixture['path'],$errno,$error,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN);
                if (!is_resource($server)) throw new RuntimeException('real_unix_fixture_unavailable');
                chmod($fixture['path'],0600); $pin=Transport::capture($fixture['root']);
                $pid=pcntl_fork(); if ($pid<0) throw new RuntimeException('real_unix_fork_failed');
                if ($pid===0) {
                    $client=stream_socket_accept($server,3);
                    if (!is_resource($client)) exit(31);
                    $request=''; stream_set_timeout($client,3);
                    while (!str_contains($request,"\r\n\r\n") && strlen($request)<16384) { $chunk=fread($client,1024); if ($chunk===false||$chunk==='') exit(32); $request.=$chunk; }
                    if (!str_starts_with($request,'GET /api/v1/query?') || !str_contains($request,'time=1793638800')) exit(33);
                    if ($case==='deadline') { sleep(7); fclose($client); exit(0); }
                    $response=match($case) {
                        'redirect'=>utHttp('',"Location: https://unapproved.invalid/\r\n",302),
                        'oversize'=>utHttp(str_repeat('x',Transport::MAX_BYTES+1)),
                        default=>utHttp('{"real_unix":true}',"Content-Length: 18\r\n"),
                    };
                    for ($offset=0;$offset<strlen($response);) { $written=@fwrite($client,substr($response,$offset,4096)); if (!$written) break; $offset+=$written; }
                    fclose($client); fclose($server); exit(0);
                }
                $transport=new Transport($fixture['root'],$pin); $started=microtime(true);
                if ($case==='success') utCheck($transport->request($query)['body']==='{"real_unix":true}','real_unix_exchange');
                elseif ($case==='deadline') {
                    try { $transport->request($query); throw new RuntimeException('deadline_not_enforced'); }
                    catch (RuntimeException $failure) { utCheck(in_array($failure->getMessage(),['unix_transport_deadline','unix_transport_read_failed'],true),'real_unix_deadline_refusal'); }
                    utCheck(microtime(true)-$started<5.8,'real_unix_total_deadline');
                } else utRefuses(static fn()=>$transport->request($query),$case==='redirect'?'unix_transport_response_forbidden':'unix_transport_body_limit');
                $realCases++;
            } finally {
                if ($pid>0) { if ($case==='deadline') posix_kill($pid,SIGTERM); pcntl_waitpid($pid,$status); }
                if (is_resource($server)) fclose($server);
                utCleanup($fixture);
            }
        }
    }
    echo json_encode(['status'=>'pass','assertions'=>$assertions,'real_unix_cases'=>$realCases,'real_unix_requested'=>getenv('ARVAN_UDS_REAL_TESTS')==='1','app_bootstrap'=>false,'production_calls'=>false],JSON_THROW_ON_ERROR)."\n";
}

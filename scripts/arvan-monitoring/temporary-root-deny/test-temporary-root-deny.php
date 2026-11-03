<?php
declare(strict_types=1);

// Synthetic private filesystem only. The copied helper has fixture paths/account/PHP gates.
if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || !function_exists('proc_open')) { throw new RuntimeException('safe_cli_fixture_required'); }
$helper = file_get_contents(__DIR__.'/temporary-root-deny.php');
$library = file_get_contents(dirname(__DIR__).'/arvan-release-v3/web-release/deploy.php');
if (!is_string($helper) || !is_string($library) || hash('sha256',$library) !== '02c952a7ba3ac370e88081898c43be2b2b91ff200e894ce927c5e8bc7da8c93e') { throw new RuntimeException('reviewed_fixture_sources_required'); }
$base = sys_get_temp_dir().'/temporary-deny-test-'.bin2hex(random_bytes(8));
mkdir($base,0700); $count = 0; $cases = 0;
function ok(bool $v, string $name): void { global $count; if (!$v) { throw new RuntimeException('test_failed:'.$name); } $count++; }
function swapOnce(string $s, string $from, string $to): string {
    if (substr_count($s,$from) !== 1) { throw new RuntimeException('fixture_anchor_not_unique'); }
    return str_replace($from,$to,$s);
}
function fixture(string $source, ?callable $instrument = null): array {
    global $base,$helper,$library,$cases; $cases++;
    $home = $base.'/case-'.$cases; $app = $home.'/servernet_app';
    $stage = $app.'/storage/app/private/arvan-delivery-20261103-v3q4m7dz';
    mkdir($stage,0700,true); mkdir($app.'/storage/framework',0700,true); mkdir($home.'/public_html',0755,true);
    $lib = str_replace('/home/servernetcloud',$home,$library);
    file_put_contents($stage.'/deploy.php',$lib); chmod($stage.'/deploy.php',0600);
    $code = str_replace('/home/servernetcloud',$home,$helper);
    $code = str_replace('02c952a7ba3ac370e88081898c43be2b2b91ff200e894ce927c5e8bc7da8c93e',hash('sha256',$lib),$code);
    $code = str_replace("const TD_PHP = '/opt/cpanel/ea-php84/root/usr/bin/php';",'const TD_PHP = '.var_export(PHP_BINARY,true).';',$code);
    $code = str_replace("version_compare(PHP_VERSION, '8.4.0', '<')","version_compare(PHP_VERSION, '8.0.0', '<')",$code);
    $code = str_replace("!== 'servernetcloud'","!== ".var_export(posix_getpwuid(posix_geteuid())['name'],true),$code);
    if ($instrument) { $code = $instrument($code,$home,$stage); }
    file_put_contents($stage.'/temporary-root-deny.php',$code); chmod($stage.'/temporary-root-deny.php',0600);
    file_put_contents($home.'/public_html/.htaccess',$source); chmod($home.'/public_html/.htaccess',0644);
    return ['home'=>$home,'app'=>$app,'stage'=>$stage,'source'=>$home.'/public_html/.htaccess','before'=>$source];
}
function run(array $f, string $mode, ?string $hash = null): array {
    $args = [PHP_BINARY,$f['stage'].'/temporary-root-deny.php',$mode]; if ($hash !== null) { $args[] = $hash; }
    $p = proc_open($args,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$f['app']);
    if (!is_resource($p)) { throw new RuntimeException('fixture_process_failed'); }
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($p); $data = json_decode($out,true);
    if (!is_array($data)) { throw new RuntimeException('fixture_json_failed:'.$out.':'.$err); }
    return [$exit,$data,$err];
}
function plan(array $f): string {
    [$exit,$out] = run($f,'--plan',hash_file('sha256',$f['source']));
    ok($exit === 0 && $out['status'] === 'prepared_not_installed','plan'); return $out['plan_sha256'];
}
function apply(array $f, string $hash): void {
    [$exit,$out] = run($f,'--apply',$hash); ok($exit === 0 && $out['status'] === 'deny_installed','apply');
}
$source = "<IfModule mod_rewrite.c>\n    <IfModule mod_negotiation.c>\n        Options -MultiViews -Indexes\n    </IfModule>\n\n    RewriteEngine On\n\n    RewriteCond %{HTTP:Authorization} .\n    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]\n    RewriteCond %{REQUEST_FILENAME} !-d\n    RewriteCond %{REQUEST_FILENAME} !-f\n    RewriteRule ^ index.php [L]\n</IfModule>\n";
$f = fixture($source); $hash = plan($f);
ok(file_get_contents($f['source']) === $source,'plan_no_source_write');
$candidate = file_get_contents($f['stage'].'/temp-deny-candidate.txt');
ok(strlen($candidate)-strlen($source) === 198,'exact_198_byte_block');
ok(str_contains($candidate,"    RewriteEngine On\n    # BEGIN ServerNet private Arvan release deny"),'immediately_after_engine');
[$exit,$out] = run($f,'--plan',hash('sha256',$source)); ok($exit === 0 && $out['plan_sha256'] === $hash,'plan_replay_read_only');
apply($f,$hash); clearstatcache(true,$f['source']);
ok((fileperms($f['source']) & 0777) === 0644,'mode_preserved');
ok(fileowner($f['source']) === posix_geteuid(),'owner_preserved');
[$exit,$out] = run($f,'--apply',$hash); ok($exit !== 0 && $out['error_code'] === 'historical_attempt_no_automatic_retry','apply_never_retries_mutation');
$foreign = "# concurrent hosting change\n".file_get_contents($f['source'])."# later setting\n";
file_put_contents($f['source'],$foreign);
[$exit,$out] = run($f,'--cleanup',hash('sha256',$foreign));
ok($exit === 0 && $out['status'] === 'owned_block_removed','cleanup_success');
ok(file_get_contents($f['source']) === "# concurrent hosting change\n".$source."# later setting\n",'cleanup_preserves_foreign_edits');

$f = fixture(str_replace("\n","\r\n",$source)); $hash = plan($f); apply($f,$hash);
ok(!str_contains(str_replace("\r\n",'',file_get_contents($f['source'])),"\n"),'crlf_preserved');

foreach (['drift','mode','symlink','identical_replacement'] as $kind) {
    $f = fixture($source); $hash = plan($f); $old = file_get_contents($f['source']);
    if ($kind === 'drift') { file_put_contents($f['source'],$old."# concurrent\n"); }
    if ($kind === 'mode') { chmod($f['source'],0600); }
    if ($kind === 'symlink') { rename($f['source'],$f['source'].'.foreign'); symlink($f['source'].'.foreign',$f['source']); }
    if ($kind === 'identical_replacement') { file_put_contents($f['source'].'.new',$old); chmod($f['source'].'.new',0644); rename($f['source'].'.new',$f['source']); }
    [$exit] = run($f,'--apply',$hash); ok($exit !== 0,'source_cas_refuses_'.$kind);
    ok(!file_exists($f['stage'].'/temp-deny-attempt.json'),'no_attempt_after_'.$kind);
}
foreach (['write','link','postpublication','foreignreceipt','lock_after_rename'] as $fault) {
    $f = fixture($source,function(string $code,string $home,string $stage) use ($fault): string {
        if ($fault === 'write') { return swapOnce($code,'writeNew($tmp, $bytes);',"if (\$name === 'temp-deny-receipt.json') { tdFail('injected_receipt_write_failure'); }\n    writeNew(\$tmp, \$bytes);"); }
        if ($fault === 'link') { return swapOnce($code,'if (!@link($tmp, TD_STAGE.\'/\'.$name))',"if (\$name === 'temp-deny-receipt.json') { tdFail('injected_receipt_link_failure'); }\n    if (!@link(\$tmp, TD_STAGE.'/'.\$name))"); }
        if ($fault === 'postpublication') { return swapOnce($code,'$saved = tdRead(TD_STAGE.\'/\'.$name, $owner, true);',"if (\$name === 'temp-deny-receipt.json') { tdFail('injected_after_publication'); }\n    \$saved = tdRead(TD_STAGE.'/'.\$name, \$owner, true);"); }
        if ($fault === 'foreignreceipt') { return swapOnce($code,'if (!@link($tmp, TD_STAGE.\'/\'.$name))',"if (\$name === 'temp-deny-receipt.json') { file_put_contents(TD_STAGE.'/'.\$name, 'foreign receipt'); chmod(TD_STAGE.'/'.\$name,0600); }\n    if (!@link(\$tmp, TD_STAGE.'/'.\$name))"); }
        return swapOnce($code,"if (!rename(\$tmp, TD_SOURCE)) { tdFail('atomic_rename_failed'); }","if (!rename(\$tmp, TD_SOURCE)) { tdFail('atomic_rename_failed'); }\n    rename(TD_ROOT.'/storage/framework/arvan-delivery-release.lock', TD_ROOT.'/storage/framework/old.lock');\n    file_put_contents(TD_ROOT.'/storage/framework/arvan-delivery-release.lock','replacement'); chmod(TD_ROOT.'/storage/framework/arvan-delivery-release.lock',0600);");
    });
    $hash = plan($f); [$exit,$out] = run($f,'--apply',$hash);
    ok($exit !== 0 && $out['status'] === 'unknown_requires_reconciliation','fault_unknown_'.$fault);
    $after = file_get_contents($f['source']); ok(str_contains($after,'[F,L]'),'fault_leaves_deny_'.$fault);
    [$exit] = run($f,'--apply',$hash); ok($exit !== 0 && file_get_contents($f['source']) === $after,'fault_no_retry_'.$fault);
    if ($fault === 'foreignreceipt') { ok(file_get_contents($f['stage'].'/temp-deny-receipt.json') === 'foreign receipt','foreign_receipt_preserved'); }
    if ($fault === 'write') {
        [$exit,$out] = run($f,'--cleanup',hash('sha256',$after));
        ok($exit === 0 && file_get_contents($f['source']) === $source,'cleanup_proven_ownership_without_receipt');
    }
}
foreach (['primary_exists','primary_symlink','changed_block','foreign_marker'] as $kind) {
    $f = fixture($source); $hash = plan($f); apply($f,$hash);
    $primary = $f['home'].'/public_html/_arvan_delivery_20261103_v3q4m7dz';
    if ($kind === 'primary_exists') { mkdir($primary,0700); }
    if ($kind === 'primary_symlink') { symlink($f['stage'],$primary); }
    if ($kind === 'changed_block') { file_put_contents($f['source'],str_replace('[F,L]','[L]',file_get_contents($f['source']))); }
    if ($kind === 'foreign_marker') { file_put_contents($f['source'],file_get_contents($f['source'])."# ServerNet private Arvan release deny 20261103 v3q4m7dz\n"); }
    $before = file_get_contents($f['source']); [$exit] = run($f,'--cleanup',hash('sha256',$before));
    ok($exit !== 0 && file_get_contents($f['source']) === $before,'cleanup_refuses_'.$kind);
}
foreach (["RewriteCond %{HTTP_HOST} x\n", "RewriteRule ^ x [L]\n", "Redirect /old /new\n", "ProxyPass /x http://localhost/\n"] as $prefix) {
    $f = fixture(str_replace('    RewriteEngine On',$prefix.'    RewriteEngine On',$source));
    [$exit] = run($f,'--plan',hash_file('sha256',$f['source'])); ok($exit !== 0,'earlier_routing_refused');
}
$f = fixture(str_replace('    RewriteEngine On',"    RewriteEngine On\n    RewriteEngine Off",$source));
[$exit] = run($f,'--plan',hash_file('sha256',$f['source'])); ok($exit !== 0,'engine_off_refused');
$f = fixture($source,fn(string $code): string => swapOnce($code,'$tmp = TD_STAGE.\'/temp-deny-rename-\'.bin2hex(random_bytes(12));',"rename(TD_ROOT.'/storage/framework/arvan-delivery-release.lock',TD_ROOT.'/storage/framework/old.lock');\n    file_put_contents(TD_ROOT.'/storage/framework/arvan-delivery-release.lock','new'); chmod(TD_ROOT.'/storage/framework/arvan-delivery-release.lock',0600);\n    \$tmp = TD_STAGE.'/temp-deny-rename-'.bin2hex(random_bytes(12));"));
$hash = plan($f); [$exit] = run($f,'--apply',$hash); ok($exit !== 0 && file_get_contents($f['source']) === $source,'lock_loss_before_rename_no_source_write');
foreach (['mode','nlink','size'] as $change) {
    $f = fixture($source,function(string $code) use ($change): string {
        $fault = match ($change) {
            'mode' => 'chmod($path,0664);',
            'nlink' => 'link($path,$path.".foreign-link");',
            'size' => 'file_put_contents($path,str_repeat("x",524289));',
        };
        return swapOnce($code,'$r = checkedRead($path, $owner);','if ($path === TD_SOURCE) { '.$fault.' }' ."\n    ".'$r = checkedRead($path, $owner);');
    });
    [$exit,$out] = run($f,'--inspect');
    ok($exit !== 0 && $out['error_code'] === 'file_metadata_changed_during_read','same_inode_read_race_'.$change);
}
echo json_encode(['status'=>'pass','assertions'=>$count,'isolated_fixtures'=>$cases,'fixture_root'=>$base],JSON_UNESCAPED_SLASHES),"\n";

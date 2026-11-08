<?php
declare(strict_types=1);
require __DIR__.'/activate.php';
function t(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
function rejects(callable $call,string $reason): void { try { $call(); } catch (RuntimeException $e) { t($e->getMessage()===$reason,'wrong rejection'); return; } throw new RuntimeException('not rejected: '.$reason); }
t(saArgument(['python3','x','--stage','/x'],'--stage')==='/x','spaced argument');
t(saArgument(['python3','x','--stage=/y'],'--stage')==='/y','equal argument');
t(saArgument(['--other=x'],'--stage')===null,'missing argument');
t(ctype_digit(saStart(getmypid())),'proc start time');
t(saUid()===(int)fileowner('/proc/'.getmypid()),'effective uid');
rejects(fn()=>saSafe('/tmp/unrelated'),'path_scope');
rejects(fn()=>saSafe(SA_HOME.'/../outside'),'path_scope');
rejects(fn()=>saMain(['script']),'cli_account');
require dirname(__DIR__,2).'/website/vendor/autoload.php';
$temporary=sys_get_temp_dir().'/seo-activation-tests-'.bin2hex(random_bytes(8)); mkdir($temporary,0700);
$files=new Illuminate\Filesystem\Filesystem();
$compiler=new Illuminate\View\Compilers\BladeCompiler($files,$temporary);
$source=$temporary.'/fixture.blade.php';
file_put_contents($source,"@if(\$enabled)\n<p>{{ \$title }}</p>\n@endif\n");
$compiler->setPath($source); $data=$compiler->compileString(file_get_contents($source));
$data=(new ReflectionMethod($compiler,'appendFilePath'))->invoke($compiler,$data);
token_get_all($data,TOKEN_PARSE); $compiler->compile($source);
t($data===file_get_contents($compiler->getCompiledPath($source)),'compiled byte parity');
rejects(fn()=>saPlan(['release'=>SA_RELEASE,'compiled'=>[]],['files'=>[['change'=>true,'source'=>'view.blade.php']]],$compiler),'activation_plan_scope');
unlink($compiler->getCompiledPath($source)); unlink($source); rmdir($temporary);
t(SA_RELEASE==='seo-iran-release-20261108','IR release identity');
$variant=file_get_contents(__DIR__.'/activate.php');
$restored=str_replace('seo-iran-release-20261108','seo-release-20261108',$variant);
$restored=str_replace("count(\$preview['files'])===9","count(\$preview['files'])===34",$restored);
$restored=str_replace("count(array_unique(array_column(\$preview['files'],'source')))===9","count(array_unique(array_column(\$preview['files'],'source')))===34",$restored);
$restored=str_replace("saCheck(is_string(\$row['destination']) && str_starts_with(\$row['destination'],'servernet_app/'),'source_scope');", "saCheck(is_string(\$row['destination']) && (str_starts_with(\$row['destination'],'servernet_app/')\n            || \$row['destination']==='public_html/assets/css/tool-next-step.css'),'source_scope');",$restored);
t(hash('sha256',$restored)==='bbf6616dadf6b0806919d9dac060d63e8ea2d948d7bead9d276e66e7adca311e','unchanged main activation guards');
echo "PASS 12 IR activation guard/compiler/parity checks\n";

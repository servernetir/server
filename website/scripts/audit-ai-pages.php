<?php
if (PHP_SAPI !== 'cli' || empty($argv[1]) || empty($argv[2])) {
    fwrite(STDERR, "Usage: php audit-ai-pages.php /path/to/app /private/path/report.json\n"); exit(2);
}
$appRoot=rtrim($argv[1], '/\\');$output=$argv[2];
require $appRoot.'/vendor/autoload.php';
$app=require $appRoot.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['session.driver'=>'array']);
$kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);
$models=App\Models\AiModel::orderBy('id')->get();
$started=gmdate('c');$rows=[];$examples=[];$done=0;
$attr=function($html,$tag,$name,$value,$result){preg_match_all('~<'.$tag.'\b[^>]*>~i',$html,$tags);foreach($tags[0]as$t){preg_match_all('~([\w:-]+)\s*=\s*"([^"]*)"~',$t,$matches,PREG_SET_ORDER);$a=[];foreach($matches as$m)$a[strtolower($m[1])]=html_entity_decode($m[2],ENT_QUOTES|ENT_HTML5,'UTF-8');if(($a[$name]??null)===$value)return $a[$result]??null;}return null;};
$registry=$models->map(fn($m)=>['slug'=>$m->slug,'name'=>$m->name,'vendor'=>$m->vendor,'category'=>$m->category,'status'=>$m->status,'context_tokens'=>$m->context_tokens,'max_output_tokens'=>$m->max_output_tokens,'description_characters'=>mb_strlen(strip_tags((string)$m->description)),'indexable'=>App\Services\Ai\AiModelPage::indexable($m)])->all();
foreach($models as$model)foreach([''=>'fa','/en'=>'en','/tr'=>'tr']as$prefix=>$locale){
 $path=$prefix.'/ai/models/'.$model->slug;$url=rtrim(config('app.url'),'/').$path;$start=hrtime(true);
 $request=Illuminate\Http\Request::create($url);
 $response=$kernel->handle($request);$html=$response->getContent();$renderMs=(hrtime(true)-$start)/1e6;
 preg_match('~<title>(.*?)</title>~s',$html,$title);
 $jsonErrors=0;preg_match_all('~<script[^>]*type="application/ld\+json"[^>]*>(.*?)</script>~s',$html,$scripts);
 foreach($scripts[1]as$json){json_decode($json,true);if(json_last_error()!==JSON_ERROR_NONE)$jsonErrors++;}
 $row=['path'=>$path,'slug'=>$model->slug,'locale'=>$locale,'status'=>$response->getStatusCode(),'render_ms'=>round($renderMs,2),'bytes'=>strlen($html),'title'=>html_entity_decode($title[1]??'',ENT_QUOTES|ENT_HTML5,'UTF-8'),'description'=>$attr($html,'meta','name','description','content'),'canonical'=>$attr($html,'link','rel','canonical','href'),'robots'=>$attr($html,'meta','name','robots','content'),'h1_count'=>preg_match_all('~<h1(?:\s|>)~i',$html),'hreflang_count'=>preg_match_all('~<link[^>]*hreflang=~i',$html),'json_errors'=>$jsonErrors,'expected_indexable'=>App\Services\Ai\AiModelPage::indexable($model)];
 $row['has_estimator']=str_contains($html,'data-ai-estimator');
 $row['has_code_tabs']=str_contains($html,'data-ai-code-tabs');
 if($locale==='en'){
  preg_match_all('~<pre[^>]*id="ai-example-(curl|python|javascript)"[^>]*><code>(.*?)</code></pre>~s',$html,$codes,PREG_SET_ORDER);
  foreach($codes as$code)$examples[$model->slug][$code[1]]=html_entity_decode($code[2],ENT_QUOTES|ENT_HTML5,'UTF-8');
 }
 $rows[]=$row;$kernel->terminate($request,$response);$done++;
 if($done%30===0)file_put_contents($output,json_encode(['started'=>$started,'complete'=>false,'model_count'=>$models->count(),'done'=>$done,'registry'=>$registry,'rows'=>$rows,'examples'=>$examples],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
 usleep(10000);
}
file_put_contents($output,json_encode(['started'=>$started,'finished'=>gmdate('c'),'complete'=>true,'method'=>'production Laravel HTTP kernel, private CLI process; not external HTTP TTFB','model_count'=>$models->count(),'done'=>$done,'registry'=>$registry,'rows'=>$rows,'examples'=>$examples],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
echo json_encode(['complete'=>true,'models'=>$models->count(),'pages'=>$done,'bad_status'=>count(array_filter($rows,fn($r)=>$r['status']!==200))]).PHP_EOL;

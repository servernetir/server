<?php
require '/home/servernetcloud/servernet_app/vendor/autoload.php';
$app = require '/home/servernetcloud/servernet_app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$dir=__DIR__;
$db=Illuminate\Support\Facades\DB::class;
if (($argv[1]??'')==='--prepare') {
    if(is_file($dir.'/CMS-MANIFEST.json'))throw new RuntimeException('Existing CMS packet: inspect first');
    $ops=json_decode(file_get_contents($dir.'/cms-operations.json'),true,512,JSON_THROW_ON_ERROR);
    $manifest=[];
    foreach($ops as $op){
        $post=App\Models\Post::published()->where('slug',$op['slug'])->where('type',$op['type'])->firstOrFail();
        $row=$post->translations()->where('locale',$op['locale'])->firstOrFail();
        $before=$row->getRawOriginal('content');$after=$before;
        foreach($op['replacements'] as [$old,$new]){
            if(substr_count($after,$old)!==1)throw new RuntimeException('Content mismatch '.$op['slug'].'/'.$op['locale']);
            $after=str_replace($old,$new,$after);
        }
        $manifest[]=['id'=>$row->id,'post_id'=>$post->id,'slug'=>$op['slug'],'locale'=>$op['locale'],
            'before'=>$before,'after'=>$after,'updated_at'=>$row->getRawOriginal('updated_at'),
            'post_updated_at'=>$post->getRawOriginal('updated_at'),'before_sha'=>hash('sha256',$before),'after_sha'=>hash('sha256',$after)];
    }
    file_put_contents($dir.'/CMS-MANIFEST.json',json_encode($manifest,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
    chmod($dir.'/CMS-MANIFEST.json',0600);
    echo 'CMS_PREPARED '.count($manifest).' translations; database unchanged'.PHP_EOL;
    exit;
}
if(($argv[1]??'')!=='--apply'||is_file($dir.'/CMS-APPLIED.json'))throw new RuntimeException('Invalid state: inspect receipts');
$manifest=json_decode(file_get_contents($dir.'/CMS-MANIFEST.json'),true,512,JSON_THROW_ON_ERROR);
$db::transaction(function()use($db,$manifest){
    foreach($manifest as $item){
        $row=$db::table('post_translations')->where('id',$item['id'])->lockForUpdate()->first();
        if(!$row||hash('sha256',$row->content)!==$item['before_sha']||$row->updated_at!==$item['updated_at'])throw new RuntimeException('Concurrent translation edit');
    }
    foreach($manifest as $item){
        $db::table('post_translations')->where('id',$item['id'])->update(['content'=>$item['after'],'updated_at'=>now()]);
    }
    foreach(array_unique(array_column($manifest,'post_id'))as$id)$db::table('posts')->where('id',$id)->update(['updated_at'=>now()]);
});
$receipt=[];
foreach($manifest as $item){
    $content=$db::table('post_translations')->where('id',$item['id'])->value('content');
    if(hash('sha256',$content)!==$item['after_sha'])throw new RuntimeException('CMS readback mismatch');
    $receipt[]=['id'=>$item['id'],'slug'=>$item['slug'],'locale'=>$item['locale'],'sha256'=>$item['after_sha']];
}
file_put_contents($dir.'/CMS-APPLIED.json',json_encode($receipt,JSON_PRETTY_PRINT));chmod($dir.'/CMS-APPLIED.json',0600);
echo 'CMS_APPLIED '.count($receipt).' translations; readback hashes matched'.PHP_EOL;

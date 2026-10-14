<?php
require '/home/servernetcloud/servernet_app/vendor/autoload.php';
$app=require '/home/servernetcloud/servernet_app/bootstrap/app.php';
$app->usePublicPath('/home/servernetcloud/public_html');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$z=new ZipArchive; $z->open('/home/servernetcloud/shop-growth-20261014-preflight-v2/snapshot.zip');
$before=json_decode($z->getFromName('catalog.json'),true,512,JSON_THROW_ON_ERROR); $z->close();
$result=['servers'=>0,'parts'=>0,'catalog'=>0,'originals_checked'=>0,'protected_fields_changed'=>[],'missing_images'=>0,'source_mismatches'=>[]];
foreach(['servers'=>App\Models\PhysicalServer::class,'parts'=>App\Models\ServerPart::class] as $kind=>$model){
 $rows=$model::orderBy('id')->get(); $result[$kind]=$rows->count();
 foreach($rows as $row){
  if($row->condition==='catalog')$result['catalog']++;
  foreach(($row->gallery??[]) as $path)if(!is_file(public_path(ltrim($path,'/'))))$result['missing_images']++;
 }
 foreach($before[$kind] as $old){
  $now=$rows->firstWhere('id',$old['id'])->toArray(); $result['originals_checked']++;
  foreach(['slug','brand','condition','active','popular','sort','price_contact','price_irt','price_eur','in_stock','compat_gens'] as $field)
   if(array_key_exists($field,$old)&&$old[$field]!==$now[$field])$result['protected_fields_changed'][]=$kind.'/'.$old['id'].'/'.$field;
 }
}
$expected=[];
foreach(['release-v2','meta-v3','admin-v4'] as $release){
 $folder='/home/servernetcloud/shop-growth-20261014-'.$release;
 foreach(json_decode(file_get_contents($folder.'/manifest.json'),true) as $item)$expected[$item['path']]=$item['after'];
}
foreach($expected as $path=>$sha){
 $actual=str_starts_with($path,'public/')?public_path(substr($path,7)):base_path($path);
 if(!is_file($actual)||hash_file('sha256',$actual)!==$sha)$result['source_mismatches'][]=$path;
}
$result['verified_at_utc']=gmdate('c');
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;

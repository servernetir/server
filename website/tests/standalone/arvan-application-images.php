<?php
// Pure contract checks. Run with PHP 8.4; no framework bootstrap or database.
require $argv[1] ?? __DIR__.'/../../app/Services/Cloud/ArvanApplicationImages.php';
use App\Services\Cloud\ArvanApplicationImages as Images;
function check(bool $ok, string $message): void {
    if (! $ok) { throw new RuntimeException($message); }
}
$id = '11111111-2222-3333-4444-555555555555';
$base = ['id'=>$id, 'name'=>'Docker-Ubuntu', 'image_version'=>'26.1.1',
    'disk'=>0, 'ram'=>0, 'ssh_password'=>true, 'price'=>['pph'=>0,'ppd'=>0,'ppm'=>0]];
$rows = Images::normalize('ir-thr-si1', [$base]);
check(count($rows) === 1, 'Native free image accepted');
check($rows[0]['min_ram_mb'] === 1024 && $rows[0]['min_disk_gb'] === 10, 'Sales floors enforced');
check(Images::imageId('ir-thr-si1',$rows[0]['provider_ref']) === $id, 'Regional reference resolves');
check(Images::imageId('ir-tbz-sh1',$rows[0]['provider_ref']) === null, 'Wrong region rejected');
check(Images::imageId('ir-thr-si1','app:ir-thr-si1:not-a-uuid') === null, 'Malformed ID rejected');
foreach (['Ubuntu-Plesk','Ubuntu-Cpanel','Rocky-Directadmin','Unknown-app'] as $name) {
    check(Images::normalize('ir-thr-si1',[array_replace($base,['name'=>$name])]) === [], 'Unapproved app rejected');
}
foreach ([['price'=>['pph'=>1,'ppd'=>0,'ppm'=>0]],['price'=>[]],['ssh_password'=>false],['image_version'=>''],['id'=>'invalid']] as $change) {
    check(Images::normalize('ir-thr-si1',[array_replace($base,$change)]) === [], 'Incomplete/paid image rejected');
}
$other = Images::normalize('ir-tbz-sh1', [array_replace($base,['id'=>'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'])]);
check($rows[0]['key'] === $other[0]['key'] && $rows[0]['provider_ref'] !== $other[0]['provider_ref'], 'Same app groups across regions without reusing ID');
$version = Images::normalize('ir-thr-si1',[array_replace($base,['image_version'=>'27.0'])]);
check($rows[0]['key'] !== $version[0]['key'], 'Versions retain distinct order keys');
$heavy = Images::normalize('ir-thr-si1',[array_replace($base,['name'=>'Gitlab','disk'=>100])])[0];
check($heavy['min_disk_gb'] === 100 && $heavy['min_ram_mb'] === 16384 && Images::minCpu('gitlab') === 8, 'Provider and CPU requirements preserved');
echo "APPLICATION_CONTRACT_PASS\n";

@php
  // Lightweight first paint for mobile, using the same geographic projection as the poster.
  $posterYaw = $atlasNodes[$atlasDefault]['position'][1];
  $posterProject = static function($lat, $lon) use ($posterYaw) {
    $p=deg2rad($lat); $q=deg2rad($lon-$posterYaw); $t=deg2rad(22);
    return [340+cos($p)*sin($q)*255,330-(sin($p)*cos($t)-cos($p)*cos($q)*sin($t))*255,sin($p)*sin($t)+cos($p)*cos($q)*cos($t)];
  };
  $posterHub=$atlasNodes[$atlasDefault]['position'];
  $posterPaths=[];
  foreach($atlasNodes as $iso=>$node) {
    if($iso===$atlasDefault) continue;
    [$lat,$lon]=$node['position'];
    $delta=fmod($lon-$posterHub[1]+540,360)-180;
    $path='';$connected=false;
    for($i=0;$i<=20;$i++) {
      $progress=$i/20;
      [$x,$y,$z]=$posterProject($posterHub[0]+($lat-$posterHub[0])*$progress+sin($progress*M_PI)*7,$posterHub[1]+$delta*$progress);
      if($z>.05) {$path.=($connected?'L':'M').round($x,1).' '.round($y,1).' ';$connected=true;} else {$connected=false;}
    }
    if($path!=='') $posterPaths[]=$path;
  }
@endphp
<svg class="sn-globe-routes" viewBox="0 0 680 680" aria-hidden="true">
  @foreach($posterPaths as $path)<path d="{{ $path }}"/>@endforeach
  @foreach($atlasNodes as $iso=>$node)
    @php [$x,$y,$z]=$posterProject(...$node['position']); @endphp
    @if($z>.12)<circle cx="{{ round($x,1) }}" cy="{{ round($y,1) }}" r="{{ $iso===$atlasDefault ? 4 : 2.2 }}"/>@endif
  @endforeach
</svg>

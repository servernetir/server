<div class="sn-product-stage sn-stage-{{ $atlasStage ?? 'hosting' }}" aria-hidden="true">
  <div class="sn-stage-caption"><span>SERVERNET / {{ strtoupper($atlasStage ?? 'hosting') }}</span><span>BUILT TO GROW</span></div>
  @if(($atlasStage ?? '') === 'gpu')
  <div class="sn-stage-chip"><span>GPU</span><b>COMPUTE</b><i></i><i></i><i></i><i></i></div>
  <div class="sn-stage-workloads"><span>AI MODELS</span><span>RENDERING</span><span>DATA SCIENCE</span></div>
  @elseif(in_array($atlasStage ?? '', ['vps','cloud','dedicated']))
  <div class="sn-stage-racks">@for($rack=0;$rack<3;$rack++)<div>@for($slot=0;$slot<4;$slot++)<span><i></i><b></b><b></b><em></em></span>@endfor</div>@endfor</div>
  <div class="sn-stage-workloads"><span>NVMe</span><span>CLOUD</span><span>YOUR NEXT PROJECT</span></div>
  @elseif(($atlasStage ?? '') === 'storage')
  <div class="sn-stage-storage sn-storage-art-{{ $storageKind ?? 'object-storage' }}">
    <div class="sn-storage-layer"><i></i><span></span><span></span><b></b></div>
    <div class="sn-storage-layer"><i></i><span></span><span></span><b></b></div>
    <div class="sn-storage-layer"><i></i><span></span><span></span><b></b></div>
    <div class="sn-storage-core"><svg viewBox="0 0 48 48" fill="none"><path d="M8 14 24 5l16 9v20l-16 9-16-9V14Z M8 14l16 9 16-9 M24 23v20" stroke="currentColor" stroke-width="1.4"/></svg><span>{{ ($storageKind ?? '') === 'object-storage' ? 'S3' : (($storageKind ?? '') === 'block-storage' ? 'BLOCK' : 'BOX') }}</span></div>
    <i class="sn-storage-signal"></i><i class="sn-storage-signal"></i><i class="sn-storage-signal"></i>
  </div>
  <div class="sn-stage-workloads"><span>{{ strtoupper(str_replace('-', ' ', $storageKind ?? 'storage')) }}</span><span>SERVERNET</span></div>
  @else
  <div class="sn-stage-browser"><header><i></i><i></i><i></i><span>your-next-website</span></header><div><span></span><b></b><b></b><section><i></i><i></i><i></i></section><footer><i></i><i></i><i></i></footer></div></div>
  <div class="sn-stage-workloads"><span>WEB</span><span>WORDPRESS</span><span>APPLICATIONS</span></div>
  @endif
  <div class="sn-stage-orbit"></div>
</div>

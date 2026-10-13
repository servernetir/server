<div class="sn-product-art sn-art-{{ $type }}" aria-hidden="true">
  @if(in_array($type, ['globe', 'zap']))
  <div class="sn-mini-browser"><div><i></i><i></i><i></i><span>servernet.cloud</span></div><div class="sn-browser-content"><b></b><span></span><span></span><section><i></i><i></i><i></i></section></div></div>
  @elseif($type === 'cpu')
  <div class="sn-mini-rack"><span><i></i><b></b><b></b><b></b></span><span><i></i><b></b><b></b><b></b></span><span><i></i><b></b><b></b><b></b></span></div>
  @elseif($type === 'server')
  <div class="sn-metal-server"><div></div><div></div><div></div><i></i><b>SERVERNET</b></div>
  @elseif($type === 'cloud')
  <div class="sn-cloud-layers"><span></span><span></span><span><svg class="icon"><use href="#i-cloud"/></svg></span></div>
  @else
  <div class="sn-domain-art"><span>www.</span><b>yourbrand<span>.com</span></b><i></i></div>
  @endif
</div>

<div class="sn-service-art sn-service-art-{{ $icon }}" aria-hidden="true">
  <span class="sn-service-node"><svg class="icon"><use href="#i-{{ $icon }}"/></svg></span>
  <i class="sn-service-line"></i>
  <span class="sn-service-hub">SN</span>
  <i class="sn-service-line"></i>
  <span class="sn-service-node"><svg class="icon"><use href="#i-{{ $icon === 'factory' ? 'server' : ($icon === 'bot' ? 'message' : 'check') }}"/></svg></span>
</div>

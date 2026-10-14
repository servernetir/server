{{-- Decorative, original illustrations. No external requests, vendor logos or status indicators. --}}
<span class="gpu-art gpu-art-{{ $art }}" aria-hidden="true">
<svg viewBox="0 0 128 104" fill="none" focusable="false">
  <circle cx="64" cy="52" r="42" fill="currentColor" opacity=".055"/>
  <circle cx="64" cy="52" r="41" stroke="currentColor" stroke-dasharray="2 6" opacity=".2"/>
  @if($art === 'ollama')
    <path d="M21 31h61a9 9 0 0 1 9 9v27a9 9 0 0 1-9 9H48L33 87V76H21a9 9 0 0 1-9-9V40a9 9 0 0 1 9-9Z" fill="var(--surface)" stroke="currentColor" stroke-width="1.6"/>
    <path d="M29 47h44M29 56h31" stroke="currentColor" stroke-width="3" stroke-linecap="round" opacity=".45"/>
    <g class="gpu-art-float"><rect x="72" y="13" width="44" height="34" rx="11" fill="var(--surface)" stroke="currentColor" stroke-width="1.6"/><circle class="gpu-art-dot" cx="84" cy="30" r="2.5" fill="currentColor"/><circle class="gpu-art-dot" cx="94" cy="30" r="2.5" fill="currentColor" opacity=".65"/><circle class="gpu-art-dot" cx="104" cy="30" r="2.5" fill="currentColor" opacity=".3"/></g>
    <path class="gpu-art-flow" d="M93 61h15v25H62" stroke="currentColor" stroke-width="1.5" stroke-dasharray="4 7" opacity=".65"/><circle cx="60" cy="86" r="4" fill="currentColor"/>
  @elseif($art === 'comfyui')
    <rect x="33" y="14" width="77" height="61" rx="10" transform="rotate(9 33 14)" fill="currentColor" opacity=".12"/>
    <rect x="19" y="23" width="81" height="63" rx="10" fill="var(--surface)" stroke="currentColor" stroke-width="1.6"/>
    <path d="m26 73 21-23 17 15 12-11 17 19" fill="currentColor" opacity=".2"/><path d="m26 73 21-23 17 15 12-11 17 19" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
    <circle cx="76" cy="40" r="6" fill="currentColor" opacity=".6"/>
    <g class="gpu-art-float" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M104 12v15m-7-8h14M111 39v8m-4-4h8"/></g>
    <path class="gpu-art-flow" d="M12 47V18h42" stroke="currentColor" stroke-width="1.5" stroke-dasharray="4 7" opacity=".6"/>
  @else
    <rect x="14" y="20" width="100" height="67" rx="11" fill="var(--surface)" stroke="currentColor" stroke-width="1.6"/>
    <path d="M14 37h100" stroke="currentColor" opacity=".25"/><circle cx="25" cy="28" r="2" fill="currentColor"/><circle cx="33" cy="28" r="2" fill="currentColor" opacity=".55"/><circle cx="41" cy="28" r="2" fill="currentColor" opacity=".25"/>
    <path d="m32 48-7 7 7 7m13-14 7 7-7 7m-4-17-5 20" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
    <path d="M65 48v27h37" stroke="currentColor" opacity=".3"/>
    <path class="gpu-art-flow" d="m69 68 8-6 7 2 8-14 8 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" stroke-dasharray="45 8"/>
    <circle class="gpu-art-float" cx="99" cy="19" r="8" fill="var(--surface)" stroke="currentColor" stroke-width="1.5"/><path d="m96 19 2 2 4-4" stroke="currentColor" stroke-width="1.5"/>
  @endif
</svg>
</span>

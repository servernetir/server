<summary class="set-infra-summary" aria-label="زیرساختِ {{ fa_num($infraStatus['number']) }} — {{ $infraStatus['name'] }}">
  <span class="set-infra-name"><span class="set-infra-number" aria-hidden="true">{{ fa_num($infraStatus['number']) }}</span><b>{{ $infraStatus['name'] }}</b></span>
  <span class="set-infra-status" data-state="{{ $infraStatus['state'] }}">{{ $infraStatus['label'] }}</span>
</summary>

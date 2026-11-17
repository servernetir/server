<span class="ga-status" data-gpu-live-capacity data-plan="{{ $capacitySlug }}"
      data-capacity-url="{{ $capacityUrl }}" data-state="unknown"
      data-checking="{{ __('gpu-alternatives.checking') }}"
      data-available="{{ __('gpu-alternatives.available', ['count' => '{count}']) }}"
      data-waiting="{{ __('gpu-alternatives.waiting') }}"
      data-unknown="{{ __('gpu-alternatives.unknown') }}"
      data-checked="{{ __('gpu-alternatives.checked') }}">
  <span data-gpu-capacity-label>{{ __('gpu-alternatives.checking') }}</span>
  <time data-gpu-capacity-time></time>
</span>

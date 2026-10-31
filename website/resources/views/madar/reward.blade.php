@if($participation->reward_snapshot)
@php
  $reward = $participation->reward_snapshot;
  $familyLabels = collect($reward['rule']['families'] ?? [])->map(fn ($family) => \Illuminate\Support\Facades\Lang::has('madar.families.'.$family) ? __('madar.families.'.$family) : __('madar.scope'))->unique();
@endphp
<section class="mdr-reward">
  <span class="mdr-eyebrow">{{ __('madar.reward') }}</span>
  <h2>{{ __('madar.up_to', ['amount' => invoice_money($reward['amount_irt'], 'IRT')]) }}</h2>
  <p>{{ __('madar.reward_type') }}</p>
  <dl>
    <div><dt>{{ __('madar.deadline') }}</dt><dd>{{ blog_date($participation->work_due_at) }} <bdi>{{ $participation->work_due_at?->timezone('Asia/Tehran')->format('H:i') }} · Asia/Tehran</bdi></dd></div>
    <div><dt>{{ __('madar.reward_expiry') }}</dt><dd>{{ blog_date(\Illuminate\Support\Carbon::parse($reward['expires_at'])) }}</dd></div>
    <div><dt>{{ __('madar.minimum') }}</dt><dd>{{ invoice_money($reward['rule']['min_purchase'], 'IRT') }}</dd></div>
    <div><dt>{{ __('madar.scope') }}</dt><dd>{{ $familyLabels->join(' / ') }}</dd></div>
    <div><dt>{{ __('madar.operations') }}</dt><dd>{{ collect($reward['rule']['operations'])->map(fn ($op) => __('madar.op.'.$op))->join(' / ') }}</dd></div>
  </dl>
  <p class="mdr-small">{{ __('madar.margin') }}</p>
  <p class="mdr-small">{{ __('madar.hourly_notice') }}</p>
</section>

@endif

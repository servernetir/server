@extends('admin.layout')
@section('title', __('madar.admin_title'))
@section('nav_madar', 'on')
@section('head')<link rel="stylesheet" href="{{ asset_ver('assets/css/madar.css') }}">@endsection
@section('content')
<div class="mdr mdr-admin">
  <div class="mdr-section-head"><h1>{{ __('madar.admin_title') }}</h1><a class="ad-btn" href="/admin/marketing/campaigns">{{ __('madar.marketing_link') }}</a></div>
  @include('madar.feedback')
  @unless($ready)<p class="mdr-notice">{{ __('madar.not_ready') }}</p>
@endunless

  <div class="mdr-kpis">@foreach(['requested','offered','submitted','rewarded'] as $state)<a href="?status={{ $state }}"><span>{{ __('madar.statuses.'.$state) }}</span><b>{{ fa_num($counts[$state] ?? 0) }}</b></a>
@endforeach
</div>
  <section class="mdr-section"><h2>{{ __('madar.queue') }}</h2>
    <form method="get" class="mdr-filter"><label>{{ __('madar.status') }}<select name="status"><option value="">{{ __('madar.all') }}</option>@foreach(\App\Models\MadarParticipation::STATUSES as $state)<option value="{{ $state }}" @selected(request('status') === $state)>{{ __('madar.statuses.'.$state) }}</option>
@endforeach
</select></label><label>{{ __('madar.campaign') }}<select name="campaign"><option value="">{{ __('madar.all') }}</option>@foreach($campaigns as $key => $campaign)<option value="{{ $key }}" @selected(request('campaign') === $key)>{{ __('madar.campaigns.'.$key.'.title') }}</option>
@endforeach
</select></label><button class="ad-btn" type="submit">{{ __('madar.filter') }}</button></form>
    @forelse($participations as $p)<a class="mdr-case-row" href="{{ route('admin.madar.show', $p) }}"><div><strong>{{ $p->title }}</strong><small><bdi>{{ $p->customer?->code }}</bdi> · {{ __('madar.campaigns.'.$p->campaign_key.'.title') }}</small></div><div class="mdr-row-end">@if($p->staff_read_at === null)<span class="mdr-badge is-open">{{ __('madar.unread') }}</span>
@endif
<span class="mdr-badge">{{ __('madar.statuses.'.$p->status) }}</span></div></a>@empty<div class="mdr-empty">{{ __('madar.empty') }}</div>
@endforelse

    @if($ready){{ $participations->links() }}
@endif

  </section>
  @if($ready)
  <section class="mdr-section"><h2>{{ __('madar.settings') }}</h2><p>{{ __('madar.settings_hint') }}</p><div class="mdr-campaigns">
    @foreach($campaigns as $key => $campaign)<form class="mdr-action-card" method="post" action="{{ route('admin.madar.settings') }}">
@csrf
<input type="hidden" name="campaign" value="{{ $key }}"><h3>{{ __('madar.campaigns.'.$key.'.title') }}</h3><label>{{ __('madar.open') }}<select name="open"><option value="1" @selected($campaign['accepting'])>{{ __('madar.open') }}</option><option value="0" @selected(! $campaign['accepting'])>{{ __('madar.closed') }}</option></select></label><label>{{ __('madar.reward_amount') }}<input name="reward_irt" type="number" min="1" max="10000000" required value="{{ $campaign['amount_irt'] }}"></label><label>{{ __('madar.reward_program') }}<select name="reward_campaign_id"><option value="">{{ __('madar.no_program') }}</option>@foreach($rewardCampaigns as $program)<option value="{{ $program->id }}" @selected((int) $campaign['reward_campaign_id'] === $program->id)>{{ $program->name }}</option>
@endforeach
</select></label><button class="ad-btn" type="submit">{{ __('madar.publish') }}</button></form>
@endforeach

  </div></section>

@endif

</div>
@endsection

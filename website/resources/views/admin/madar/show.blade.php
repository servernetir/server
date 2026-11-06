@extends('admin.layout')
@section('title', $participation->title)
@section('nav_madar', 'on')
@section('head')<link rel="stylesheet" href="{{ asset_ver('assets/css/madar.css') }}">@endsection
@section('content')
<div class="mdr mdr-admin">
  <a href="{{ route('admin.madar') }}">{{ __('madar.back') }}</a>
  <header class="mdr-case-head"><span class="mdr-eyebrow">{{ __('madar.campaigns.'.$participation->campaign_key.'.title') }}</span><h1>{{ $participation->title }}</h1><span class="mdr-badge">{{ __('madar.statuses.'.$participation->status) }}</span><p><a href="/admin/customers/{{ $participation->customer_id }}"><bdi>{{ $participation->customer?->code }}</bdi></a></p></header>
  @include('madar.feedback')
  <div class="mdr-workspace"><div>
    @include('madar.mission')
    @include('madar.thread', ['admin' => true])
    @if($participation->status === 'requested')
    <section class="mdr-action-card"><h2>{{ __('madar.offer') }}</h2><p>{{ __('madar.offer_body') }}</p><form method="post" action="{{ route('admin.madar.offer', $participation) }}">
@csrf
<label>{{ __('madar.work_days') }}<input type="number" name="work_days" value="7" min="1" max="14" required></label><label>{{ __('madar.body') }}<textarea name="body" rows="5" required minlength="10" maxlength="5000">{{ old('body') }}</textarea></label><button type="submit" class="ad-btn">{{ __('madar.send_offer') }}</button></form></section>

@endif

    @if($participation->status === 'submitted')
    <section class="mdr-action-card"><h2>{{ __('madar.review') }}</h2><form method="post" action="{{ route('admin.madar.review', $participation) }}">
@csrf
<label>{{ __('madar.review_note') }}<textarea name="body" rows="4" required minlength="10" maxlength="5000">{{ old('body') }}</textarea></label><label class="mdr-check"><input type="checkbox" name="human_reviewed" value="1" required><span>{{ __('madar.human_review') }}</span></label><div class="mdr-actions"><button class="ad-btn" type="submit" name="action" value="approve">{{ __('madar.approve') }}</button><button class="ad-btn" type="submit" name="action" value="changes">{{ __('madar.changes') }}</button></div></form></section>

@endif

  </div><aside class="mdr-case-aside">
    @include('madar.reward')
    @if(in_array($participation->status, ['offered', 'working', 'submitted', 'changes_requested']))
    <details class="mdr-action-card"><summary>{{ __('madar.extend') }}</summary><form method="post" action="{{ route('admin.madar.extend', $participation) }}">
      @csrf
      <label>{{ __('madar.work_days') }}<input type="number" name="work_days" min="1" max="14" value="7" required></label>
      <label>{{ __('madar.body') }}<textarea name="body" minlength="10" maxlength="1000" required rows="3"></textarea></label>
      <button class="ad-btn" type="submit">{{ __('madar.extend') }}</button></form></details>
    @endif
    <section class="mdr-action-card"><h2>{{ __('madar.assistant') }}</h2><p class="mdr-small">{{ __('madar.assistant_note') }}</p><ul class="mdr-checklist">@foreach($checklist as $key => $value)@unless($key === 'human_verification_required')<li><span>{{ __('madar.checks.'.$key) }}</span><b class="{{ $value ? 'mdr-ok' : '' }}">{{ __('madar.'.($value ? 'present' : 'missing')) }}</b></li>
@endunless

@endforeach
</ul></section>
    @if(in_array($participation->status, \App\Models\MadarParticipation::OPEN))<details class="mdr-action-card"><summary>{{ __('madar.reject') }}</summary><form method="post" action="{{ route('admin.madar.reject', $participation) }}">
@csrf
<label>{{ __('madar.review_note') }}<textarea name="body" rows="3" required minlength="10" maxlength="1000"></textarea></label><button class="ad-btn" type="submit">{{ __('madar.reject') }}</button></form></details>
@endif

  </aside></div>
</div>
@endsection

@extends('panel.layout')
@section('title', __('dns.import.title'))

@push('head')
<link rel="stylesheet" href="{{ asset_ver('assets/css/dns.css') }}">
@endpush

@section('panel')
@php $url = lroute('account.dns.import', $zone->id); @endphp
<div class="dns">
  <div>
    <a class="dns-back" href="{{ lroute('account.dns.zone', $zone->id) }}">{{ __('dns.ui.back_zone') }}</a>
    <div class="pnl-head" style="margin-top:8px">
      <div>
        <h1>{{ __('dns.import.title') }}</h1>
        <p dir="ltr" class="dns-ltr">{{ \App\Services\Dns\DomainName::toUnicode($zone->domain) }}</p>
      </div>
    </div>
  </div>

  @if(session('ok'))<div class="dns-note ok" role="status">{{ session('ok') }}</div>@endif
  @if($errors->has('import'))<div class="dns-note danger" role="alert">{{ $errors->first('import') }}</div>@endif

  @if($preview === null)
    <div class="dns-note">{{ __('dns.import.why') }}</div>

    <section class="pnl-sec">
      <div class="pnl-sec-h"><h2>{{ __('dns.import.file_title') }}</h2></div>
      <div class="pnl-sec-b">
        <p class="dns-t-dim dns-small">{{ __('dns.import.file_hint') }}</p>
        <form method="POST" action="{{ $url }}/preview" enctype="multipart/form-data" class="dns-form">
          @csrf
          <input type="hidden" name="source" value="file">
          <div class="dns-f w12">
            <label for="dns-zf">{{ __('dns.import.upload') }}</label>
            <input id="dns-zf" type="file" name="zonefile" accept=".zone,.txt,.db,text/plain">
          </div>
          <div class="dns-f w12">
            <label for="dns-zt">{{ __('dns.import.paste') }}</label>
            <textarea id="dns-zt" name="text" dir="ltr" class="dns-ltr dns-zonefile" spellcheck="false" placeholder="$ORIGIN {{ $zone->domain }}.&#10;@   3600 IN A   185.10.20.30&#10;www 3600 IN CNAME @"></textarea>
          </div>
          <div class="dns-actions"><button class="pnl-btn primary" type="submit">{{ __('dns.import.preview_btn') }}</button></div>
        </form>
      </div>
    </section>

    <section class="pnl-sec">
      <div class="pnl-sec-h"><h2>{{ __('dns.import.scan_title') }}</h2></div>
      <div class="pnl-sec-b">
        <div class="dns-note warn">{{ __('dns.import.scan_limit') }}</div>
        <form method="POST" action="{{ $url }}/preview">@csrf
          <input type="hidden" name="source" value="scan">
          <button class="pnl-btn" type="submit"><svg class="icon"><use href="#i-search"/></svg>{{ __('dns.import.scan_btn') }}</button>
        </form>
      </div>
    </section>
  @else
    @if($preview['fatal'])
      <div class="dns-note danger">{{ __('dns.import.'.$preview['fatal']) }}</div>
    @endif
    @if($preview['truncated'])
      <div class="dns-note warn">{{ __('dns.import.truncated') }}</div>
    @endif
    @if($source === 'scan')
      <div class="dns-note warn">{{ __('dns.import.scan_limit') }}</div>
      @if($partial ?? false)<div class="dns-note warn" role="status">{{ __('dns.import.scan_partial') }}</div>@endif
    @endif

    <div class="dns-stats">
      @foreach(['new', 'duplicate', 'conflict', 'invalid', 'skipped', 'unsupported'] as $k)
        <div class="dns-stat"><span>{{ __('dns.import.status_'.$k) }}</span><b>{{ $preview['counts'][$k] ?? 0 }}</b></div>
      @endforeach
    </div>

    <form method="POST" action="{{ $url }}/apply">
      @csrf
      <input type="hidden" name="source" value="{{ $source }}">
      @if($source === 'file')<textarea name="text" hidden>{{ $text }}</textarea>@endif

      <section class="pnl-sec">
        <div class="pnl-sec-h"><h2>{{ __('dns.import.preview_title') }}</h2></div>
        <div class="dns-tbl-wrap">
          <table class="dns-tbl dns-imp">
            <thead><tr><th><input type="checkbox" checked data-dns-all aria-label="{{ __('dns.import.select_all') }}"></th><th>#</th><th>{{ __('dns.form.type') }}</th><th>{{ __('dns.form.name') }}</th><th>{{ __('dns.form.value') }}</th><th>{{ __('dns.import.status') }}</th></tr></thead>
            <tbody>
              @foreach($preview['rows'] as $row)
                @php
                  $d = $row['data'];
                  $val = $d ? ($d['type'] === 'TXT' ? \App\Services\Dns\TxtCodec::display($d['content']) : (($d['prio'] !== null ? $d['prio'].' ' : '').$d['content'])) : '';
                  $pill = ['new' => 'on', 'duplicate' => 'info', 'conflict' => 'wait', 'invalid' => 'off', 'skipped' => 'info', 'unsupported' => 'off'][$row['status']] ?? 'info';
                  $codeKey = $row['code'] ? (\Illuminate\Support\Facades\Lang::has('dns.import.'.$row['code']) ? 'dns.import.'.$row['code'] : 'dns.err.'.$row['code']) : null;
                @endphp
                <tr>
                  <td>@if($row['status'] === 'new')<input type="checkbox" name="lines[]" value="{{ $row['line'] }}" checked aria-label="{{ __('dns.import.select') }}">@endif</td>
                  <td class="dns-t-dim">{{ $row['line'] }}</td>
                  <td>@if($row['type'])<span class="dns-type t-{{ $row['type'] }}">{{ $row['type'] }}</span>@endif</td>
                  <td class="c-name" dir="ltr">{{ $row['name'] ? \App\Services\Dns\DomainName::relative($row['name'], $zone->domain) : '' }}</td>
                  <td class="c-val" dir="ltr">{{ $val }}</td>
                  <td><span class="dns-pill {{ $pill }}">{{ __('dns.import.status_'.$row['status']) }}</span>
                    @if($codeKey)<span class="c-note" dir="auto">{{ __($codeKey, $row['params'] ?? []) }}</span>@endif
                    @if($row['ttl_from'] ?? null)<span class="c-note" dir="auto">{{ __('dns.import.ttl_aligned', ['from' => $row['ttl_from'], 'ttl' => $d['ttl']]) }}</span>@endif</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </section>

      <div class="dns-note">{{ __('dns.import.email_reminder') }}</div>
      <div class="dns-row-actions">
        <button class="pnl-btn primary" type="submit" @disabled(($preview['counts']['new'] ?? 0) === 0)><svg class="icon"><use href="#i-check"/></svg>{{ __('dns.import.apply_btn') }}</button>
        <a class="pnl-btn" href="{{ $url }}">{{ __('dns.form.cancel') }}</a>
      </div>
    </form>
  @endif
</div>
<script src="{{ asset_ver('assets/js/dns.js') }}" defer></script>
@endsection

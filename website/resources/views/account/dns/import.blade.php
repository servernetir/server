@extends('panel.layout')
@section('title', __('dns.import.title'))

@push('head')
<link rel="stylesheet" href="{{ asset_ver('assets/css/dns.css') }}">
@endpush

@section('panel')
@php
  $url = lroute('account.dns.import', $zone->id);
  $back = preg_replace('/^[\x{2190}-\x{2192}\s]+/u', '', __('dns.ui.back_zone'));
@endphp
<div class="dns">
  <a class="dns-back" href="{{ lroute('account.dns.zone', $zone->id) }}"><svg class="icon" aria-hidden="true"><use href="#i-arrow"/></svg>{{ $back }}</a>

  <header class="dns-hero">
    <div>
      <p class="dns-eyebrow"><svg class="icon" aria-hidden="true"><use href="#i-restore"/></svg>{{ __('dns.ux.import_eyebrow') }}</p>
      <h1>{{ __('dns.import.title') }}</h1>
      <p class="dns-lead dns-ltr" dir="ltr">{{ \App\Services\Dns\DomainName::toUnicode($zone->domain) }}</p>
    </div>
    <div class="dns-orb" aria-hidden="true">
      <span class="dns-orb-track"></span>
      <span class="dns-orb-core"><svg class="icon"><use href="#i-restore"/></svg></span>
    </div>
  </header>

  @if(session('ok'))<div class="dns-note ok" role="status">{{ session('ok') }}</div>@endif
  @if($errors->has('import'))<div class="dns-note danger" role="alert">{{ $errors->first('import') }}</div>@endif

  @if($preview === null)
    <div class="dns-note">{{ __('dns.import.why') }}</div>

    <section class="dns-card" data-dns-tabs>
      <div class="dns-card-h">
        <div class="dns-tabs" role="tablist">
          <button type="button" class="dns-tab" role="tab" aria-selected="true" aria-controls="dns-src-file" data-dns-tab="dns-src-file">{{ __('dns.ux.tab_file') }}</button>
          <button type="button" class="dns-tab" role="tab" aria-selected="false" aria-controls="dns-src-scan" data-dns-tab="dns-src-scan">{{ __('dns.ux.tab_scan') }}</button>
        </div>
      </div>

      <div class="dns-card-b" id="dns-src-file" role="tabpanel">
        <div><b class="dns-small">{{ __('dns.import.file_title') }}</b><p class="dns-t-dim dns-small">{{ __('dns.import.file_hint') }}</p></div>
        <form method="POST" action="{{ $url }}/preview" enctype="multipart/form-data" class="dns-form" data-busy>
          @csrf
          <input type="hidden" name="source" value="file">
          <div class="dns-f w12">
            <label class="dns-drop" for="dns-zf" data-dns-drop>
              <svg class="icon" aria-hidden="true"><use href="#i-file"/></svg>
              <b data-dns-drop-name>{{ __('dns.ux.drop_title') }}</b>
              <span>{{ __('dns.ux.drop_hint') }}</span>
              <input id="dns-zf" type="file" name="zonefile" accept=".zone,.txt,.db,text/plain" aria-label="{{ __('dns.import.upload') }}">
            </label>
          </div>
          <div class="dns-f w12">
            <label for="dns-zt">{{ __('dns.import.paste') }}</label>
            <textarea id="dns-zt" name="text" dir="ltr" class="dns-ltr dns-zonefile" spellcheck="false" placeholder="$ORIGIN {{ $zone->domain }}.&#10;@   3600 IN A   185.10.20.30&#10;www 3600 IN CNAME @"></textarea>
          </div>
          <div class="dns-actions"><button class="pnl-btn primary" type="submit"><svg class="icon" aria-hidden="true"><use href="#i-eye"/></svg>{{ __('dns.import.preview_btn') }}</button></div>
        </form>
      </div>

      <div class="dns-card-b" id="dns-src-scan" role="tabpanel">
        <div><b class="dns-small">{{ __('dns.import.scan_title') }}</b></div>
        <div class="dns-note warn">{{ __('dns.import.scan_limit') }}</div>
        <form method="POST" action="{{ $url }}/preview" data-busy>@csrf
          <input type="hidden" name="source" value="scan">
          <button class="pnl-btn primary" type="submit"><svg class="icon" aria-hidden="true"><use href="#i-search"/></svg>{{ __('dns.import.scan_btn') }}</button>
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

    <div class="dns-sum">
      @foreach(['new', 'duplicate', 'conflict', 'invalid', 'skipped', 'unsupported'] as $k)
        <div class="s-{{ $k }}"><span>{{ __('dns.import.status_'.$k) }}</span><b>{{ fa_num($preview['counts'][$k] ?? 0) }}</b></div>
      @endforeach
    </div>

    <form method="POST" action="{{ $url }}/apply" data-busy>
      @csrf
      <input type="hidden" name="source" value="{{ $source }}">
      @if($source === 'file')<textarea name="text" hidden>{{ $text }}</textarea>@endif

      <section class="dns-card">
        <div class="dns-card-h">
          <div class="dns-card-t">
            <span class="dns-card-ic" aria-hidden="true"><svg class="icon"><use href="#i-list"/></svg></span>
            <h2>{{ __('dns.import.preview_title') }}</h2>
          </div>
        </div>
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
                <tr class="dns-row">
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

      <div class="dns-note" style="margin-block:18px">{{ __('dns.import.email_reminder') }}</div>
      <div class="dns-sticky">
        <span>{{ __('dns.ux.selected') }} <b data-dns-selected>{{ fa_num($preview['counts']['new'] ?? 0) }}</b></span>
        <div class="dns-row-actions">
          <a class="pnl-btn" href="{{ $url }}">{{ __('dns.form.cancel') }}</a>
          <button class="pnl-btn primary" type="submit" @disabled(($preview['counts']['new'] ?? 0) === 0)><svg class="icon" aria-hidden="true"><use href="#i-check"/></svg>{{ __('dns.import.apply_btn') }}</button>
        </div>
      </div>
    </form>
  @endif
</div>
<script src="{{ asset_ver('assets/js/dns.js') }}" defer></script>
@endsection

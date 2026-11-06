@extends('panel.layout')
@section('title', __('dns.zone.title', ['domain' => $zone->domain]))

@push('head')
<link rel="stylesheet" href="{{ asset_ver('assets/css/dns.css') }}">
@endpush

@section('panel')
@php
  $url = lroute('account.dns.zone', $zone->id);
  $unicode = \App\Services\Dns\DomainName::toUnicode($zone->domain);
  $statusPill = match(true) {
      $zone->status === 'suspended' => ['off', __('dns.status.suspended')],
      $zone->status === 'active' && in_array($zone->delegation_state, ['not_delegated', 'partial'], true) => ['wait', __('dns.delegation.state_lost')],
      $zone->status === 'active'    => ['on', __('dns.status.active')],
      default                       => ['wait', __('dns.status.pending')],
  };
  // «منتشر شد» فقط وقتی هر دو نیم‌سرور serialِ تازه را با AA داده‌اند (NodeVerifier)
  $pub = match(true) {
      $zone->status === 'suspended'                      => null,
      $zone->publish_state === 'failed'                  => ['off', __('dns.publish.failed')],
      $zone->publish_state === 'pending'                 => ['wait', __('dns.publish.pending')],
      $published                                         => ['on', __('dns.publish.published')],
      default                                            => ['wait', __('dns.publish.propagating')],
  };
  $steps = [
      ['key' => 'ownership', 'done' => (bool) $zone->ownership],
      ['key' => 'records',   'done' => (bool) $zone->records_reviewed_at],
      ['key' => 'ready',     'done' => $published],
      ['key' => 'ns',        'done' => $zone->delegation_state === 'delegated'],
      ['key' => 'active',    'done' => $zone->status === 'active'],
  ];
  $doneCount = collect($steps)->where('done', true)->count();
  $nowIndex = collect($steps)->search(fn ($s) => ! $s['done']);
  $editOpen = str_starts_with((string) session('dns_form'), 'edit-') ? (int) substr((string) session('dns_form'), 5) : null;
  $newOpen = session('dns_form') === 'new';
  $typeCounts = $records->groupBy('type')->map->count();
  $ds = (array) ($zone->parent_ds ?? []);
  $back = preg_replace('/^[\x{2190}-\x{2192}\s]+/u', '', __('dns.ui.back'));
  $ttlLabel = fn (int $t) => $t % 3600 === 0 ? __('dns.ui.hours', ['n' => fa_num(intdiv($t, 3600))]) : ($t % 60 === 0 ? __('dns.ui.minutes', ['n' => fa_num(intdiv($t, 60))]) : __('dns.ui.seconds', ['n' => fa_num($t)]));
  $nodeDot = ['in_sync' => 'on', 'lagging' => 'wait', 'missing' => 'wait', 'unreachable' => 'off'];
@endphp

<div class="dns">
  <a class="dns-back" href="{{ lroute('account.dns') }}"><svg class="icon" aria-hidden="true"><use href="#i-arrow"/></svg>{{ $back }}</a>

  {{-- ══ هیرو ══ --}}
  <header class="dns-hero">
    <div>
      <p class="dns-eyebrow"><svg class="icon" aria-hidden="true"><use href="#i-globe"/></svg>{{ __('dns.ux.zone_eyebrow') }}</p>
      <h1 class="dns-ltr" dir="ltr">{{ $unicode }}</h1>
      <div class="dns-badges">
        <span class="dns-pill {{ $statusPill[0] }}">{{ $statusPill[1] }}</span>
        @if($pub)<span class="dns-pill {{ $pub[0] }}">{{ $pub[1] }}</span>@endif
        <span class="dns-pill info">{{ trans_choice('dns.list.records', $records->count(), ['n' => fa_num($records->count())]) }}</span>
      </div>
      <div class="dns-actions-bar" style="margin-top:20px">
        <a class="pnl-btn" href="{{ lroute('account.dns.import', $zone->id) }}"><svg class="icon" aria-hidden="true"><use href="#i-restore"/></svg>{{ __('dns.zone.import') }}</a>
        <a class="pnl-btn" href="{{ $url }}/export"><svg class="icon" aria-hidden="true"><use href="#i-file"/></svg>{{ __('dns.zone.export') }}</a>
        <form method="POST" action="{{ $url }}/check" data-busy>@csrf
          <button class="pnl-btn" type="submit"><svg class="icon" aria-hidden="true"><use href="#i-search"/></svg>{{ __('dns.zone.check_now') }}</button></form>
      </div>
    </div>
    <div class="dns-orb" aria-hidden="true">
      <span class="dns-orb-track"></span>
      <span class="dns-orb-core"><svg class="icon"><use href="#i-server"/></svg></span>
    </div>
  </header>

  @if(session('ok'))<div class="dns-note ok" role="status">{{ session('ok') }}</div>@endif
  @foreach(['record', 'delete', 'zone'] as $k)
    @if($errors->has($k) && session('dns_form') === null)<div class="dns-note danger" role="alert">{{ $errors->first($k) }}</div>@endif
  @endforeach

  @if($zone->status === 'suspended')
    <div class="dns-note danger"><div><b>{{ __('dns.zone.suspended_title') }}</b> {{ __('dns.zone.suspended_body') }}</div></div>
  @endif
  @if($ds !== [] && $zone->delegation_state === 'delegated')
    <div class="dns-note danger"><div><b>{{ __('dns.check.dnssec_live_title') }}</b> {{ __('dns.check.dnssec_live_body') }}</div></div>
  @endif
  @if($zone->status === 'active' && in_array($zone->delegation_state, ['not_delegated', 'partial'], true))
    <div class="dns-note warn"><div><b>{{ __('dns.delegation.state_lost') }}</b> {{ __('dns.delegation.lost_body') }}</div></div>
  @endif

  {{-- ══ مسیرِ راه‌اندازی ══ --}}
  @if($zone->status !== 'active')
  <section class="dns-card">
    <div class="dns-card-h">
      <div class="dns-card-t">
        <span class="dns-card-ic" aria-hidden="true"><svg class="icon"><use href="#i-rocket"/></svg></span>
        <div><h2>{{ __('dns.zone.setup_title') }}</h2><p>{{ __('dns.ux.setup_sub') }}</p></div>
      </div>
    </div>
    <div class="dns-card-b">
      <div class="dns-progress">
        <div class="dns-progress-top"><span>{{ __('dns.ux.progress') }}</span><b>{{ __('dns.ux.progress_n', ['done' => fa_num($doneCount), 'total' => fa_num(count($steps))]) }}</b></div>
        <div class="dns-bar" role="progressbar" aria-valuemin="0" aria-valuemax="{{ count($steps) }}" aria-valuenow="{{ $doneCount }}" aria-label="{{ __('dns.ux.progress') }}"><i style="width:{{ round($doneCount / count($steps) * 100) }}%"></i></div>
      </div>

      <ol class="dns-stepper">
        @foreach($steps as $i => $s)
          <li class="{{ $s['done'] ? 'done' : ($i === $nowIndex ? 'now' : '') }}"><i>{{ $s['done'] ? '✓' : fa_num($i + 1) }}</i><span>{{ __('dns.step.'.$s['key']) }}</span></li>
        @endforeach
      </ol>

      <ul class="dns-check">
        @foreach($checklist['items'] as $item)
          <li class="is-{{ $item['state'] }}">
            <b>{{ __('dns.check.'.$item['key']) }}</b>
            <span>{{ __('dns.check.'.$item['key'].'_'.$item['state'], $item['params']) }}</span>
          </li>
        @endforeach
      </ul>

      @if(! $zone->records_reviewed_at)
        <form method="POST" action="{{ $url }}/reviewed" class="dns-row-actions">@csrf
          <a class="pnl-btn" href="{{ lroute('account.dns.import', $zone->id) }}"><svg class="icon" aria-hidden="true"><use href="#i-restore"/></svg>{{ __('dns.zone.import') }}</a>
          <button class="pnl-btn primary" type="submit"><svg class="icon" aria-hidden="true"><use href="#i-check"/></svg>{{ __('dns.zone.mark_reviewed') }}</button>
        </form>
      @endif

      @if($checklist['blocking'])
        <div class="dns-note danger"><div><b>{{ __('dns.check.dnssec_block_title') }}</b> {{ __('dns.check.dnssec_block_body') }}</div></div>
      @elseif($published && $zone->records_reviewed_at)
        <div class="dns-ns-guide">
          <h3>{{ __('dns.zone.ns_title') }}</h3>
          <p class="dns-t-dim dns-small">{{ __('dns.zone.ns_body') }}</p>
          <div class="dns-ns">
            @foreach($nameservers as $ns)
              <div class="dns-copy"><code>{{ $ns }}</code>
                <button type="button" data-copy="{{ $ns }}" data-copied="{{ __('dns.ui.copied') }}"><svg class="icon" aria-hidden="true"><use href="#i-copy"/></svg>{{ __('dns.ui.copy') }}</button></div>
            @endforeach
          </div>
          <p class="dns-t-dim dns-small">{{ __('dns.zone.ns_wait') }}</p>
        </div>
      @else
        <p class="dns-hint">{{ __('dns.zone.ns_not_yet') }}</p>
      @endif

      @if($zone->delegation_checked_at)
        <p class="dns-small dns-t-dim">{{ __('dns.delegation.checked_at', ['time' => sdate($zone->delegation_checked_at, true)]) }}
          — <b class="{{ $zone->delegation_state === 'delegated' ? 'dns-t-ok' : 'dns-t-warn' }}">{{ __('dns.delegation.state_'.$zone->delegation_state) }}</b></p>
      @endif
    </div>
  </section>
  @endif

  @if($hosting !== [])
    <div class="dns-note">{{ __('dns.zone.hosting_note', ['ip' => $hosting[0]['ip'] ?? '—']) }}</div>
  @endif

  {{-- ══ رکوردها ══ --}}
  <section class="dns-card" id="records">
    <div class="dns-card-h">
      <div class="dns-card-t">
        <span class="dns-card-ic" aria-hidden="true"><svg class="icon"><use href="#i-list"/></svg></span>
        <div><h2>{{ __('dns.zone.records_title') }}</h2><p>{{ __('dns.ux.records_sub') }}</p></div>
      </div>
      <button class="pnl-btn primary" type="button" data-dns-open="new" aria-expanded="{{ $newOpen ? 'true' : 'false' }}"><svg class="icon" aria-hidden="true"><use href="#i-plus"/></svg>{{ __('dns.zone.add_record') }}</button>
    </div>

    <div class="dns-form-wrap" data-dns-panel="new" @unless($newOpen) hidden @endunless>
      @include('account.dns.record-form', ['rec' => null, 'formKey' => 'new', 'action' => $url.'/records'])
    </div>

    @if($records->count())
      <div class="dns-tools">
        <label class="dns-search">
          <svg class="icon" aria-hidden="true"><use href="#i-search"/></svg>
          <input class="dns-input" type="search" placeholder="{{ __('dns.zone.search') }}" data-dns-search aria-label="{{ __('dns.zone.search') }}">
        </label>
        <div class="dns-chips" role="group" aria-label="{{ __('dns.form.type') }}">
          <button type="button" class="dns-chip" data-dns-chip="" aria-pressed="true">{{ __('dns.zone.all') }} · {{ fa_num($records->count()) }}</button>
          @foreach($typeCounts as $t => $n)
            <button type="button" class="dns-chip" data-dns-chip="{{ $t }}" aria-pressed="false">{{ $t }} · {{ fa_num($n) }}</button>
          @endforeach
        </div>
      </div>

      <div class="dns-tbl-wrap">
        <table class="dns-tbl dns-rec">
          <thead><tr><th>{{ __('dns.form.type') }}</th><th>{{ __('dns.form.name') }}</th><th>{{ __('dns.form.value') }}</th><th>TTL</th><th class="c-act"><span class="sr-only">{{ __('dns.ui.actions') }}</span></th></tr></thead>
          <tbody>
            @foreach($records as $r)
              @php
                $short = \App\Services\Dns\DomainName::relative($r->name, $zone->domain);
                $shown = $r->type === 'TXT' ? \App\Services\Dns\TxtCodec::display($r->content) : $r->content;
              @endphp
              <tr class="dns-row {{ $r->disabled ? 'is-off' : '' }} {{ session('dns_highlight') === $r->id ? 'is-new' : '' }}" data-type="{{ $r->type }}"
                  data-q="{{ mb_strtolower($r->name.' '.$shown.' '.$r->comment) }}">
                <td><span class="dns-type t-{{ $r->type }}">{{ $r->type }}</span></td>
                <td class="c-name" dir="ltr">{{ $short }}</td>
                <td class="c-val" dir="ltr">
                  @if($r->prio !== null)<span class="dns-t-dim">{{ $r->prio }}</span> @endif{{ $shown }}
                  @if($r->comment)<span class="c-note" dir="auto">{{ $r->comment }}</span>@endif
                  @if($r->disabled)<span class="c-note dns-t-warn" dir="auto">{{ __('dns.zone.disabled_note') }}</span>@endif
                </td>
                <td class="c-ttl">{{ $ttlLabel((int) $r->ttl) }}</td>
                <td class="c-act">
                  <button class="dns-ic-btn" type="button" data-dns-open="edit-{{ $r->id }}" title="{{ __('dns.ui.edit') }}" aria-label="{{ __('dns.ui.edit') }}"
                          aria-expanded="{{ $editOpen === $r->id ? 'true' : 'false' }}"><svg class="icon" aria-hidden="true"><use href="#i-wrench"/></svg></button>
                  <form method="POST" action="{{ $url }}/records/{{ $r->id }}/toggle" class="dns-inline">@csrf
                    <button class="dns-ic-btn" type="submit" title="{{ $r->disabled ? __('dns.ui.enable') : __('dns.ui.disable') }}" aria-label="{{ $r->disabled ? __('dns.ui.enable') : __('dns.ui.disable') }}">
                      <svg class="icon" aria-hidden="true"><use href="#{{ $r->disabled ? 'i-eye' : 'i-eye-off' }}"/></svg></button>
                  </form>
                  <form method="POST" action="{{ $url }}/records/{{ $r->id }}/delete" class="dns-inline" data-confirm="{{ __('dns.ui.delete_record_confirm', ['type' => $r->type, 'name' => $short]) }}">@csrf
                    <button class="dns-ic-btn danger" type="submit" title="{{ __('dns.ui.delete') }}" aria-label="{{ __('dns.ui.delete') }}"><svg class="icon" aria-hidden="true"><use href="#i-x"/></svg></button>
                  </form>
                </td>
              </tr>
              <tr class="dns-edit" data-dns-panel="edit-{{ $r->id }}" data-type="{{ $r->type }}" @unless($editOpen === $r->id) hidden @endunless>
                <td colspan="5">
                  @include('account.dns.record-form', ['rec' => $r, 'formKey' => 'edit-'.$r->id, 'action' => $url.'/records/'.$r->id])
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
        <p class="dns-no-match" data-dns-nomatch hidden>{{ __('dns.ux.no_match') }}</p>
      </div>
    @else
      <div class="dns-card-b">
        <div class="dns-empty">
          <span class="dns-empty-ic"><svg class="icon" aria-hidden="true"><use href="#i-list"/></svg></span>
          <b>{{ __('dns.zone.no_records') }}</b>
          <p>{{ __('dns.zone.no_records_body') }}</p>
          <div class="dns-row-actions">
            <a class="pnl-btn" href="{{ lroute('account.dns.import', $zone->id) }}"><svg class="icon" aria-hidden="true"><use href="#i-restore"/></svg>{{ __('dns.zone.import') }}</a>
            <button class="pnl-btn primary" type="button" data-dns-open="new"><svg class="icon" aria-hidden="true"><use href="#i-plus"/></svg>{{ __('dns.zone.add_record') }}</button>
          </div>
        </div>
      </div>
    @endif
  </section>

  <div class="dns-grid">
    {{-- ══ نیم‌سرورها ══ --}}
    <section class="dns-card">
      <div class="dns-card-h">
        <div class="dns-card-t">
          <span class="dns-card-ic" aria-hidden="true"><svg class="icon"><use href="#i-server"/></svg></span>
          <div><h2>{{ __('dns.zone.nodes_title') }}</h2><p>{{ __('dns.ux.nodes_sub') }}</p></div>
        </div>
      </div>
      <div class="dns-card-b">
        <div class="dns-nodes">
          @foreach($nodes as $host => $n)
            <div class="dns-node">
              <span class="dns-node-dot {{ $nodeDot[$n['state']] ?? '' }}" aria-hidden="true"></span>
              <code dir="ltr">{{ $host }}</code>
              <small>{{ __('dns.node.'.$n['state']) }} · {{ $n['checked_at'] ? sdate($n['checked_at'], true) : __('dns.ui.never') }}</small>
            </div>
          @endforeach
        </div>
        <p class="dns-hint">{{ __('dns.zone.cache_note') }}</p>
      </div>
    </section>

    {{-- ══ تاریخچه ══ --}}
    <section class="dns-card">
      <div class="dns-card-h">
        <div class="dns-card-t">
          <span class="dns-card-ic" aria-hidden="true"><svg class="icon"><use href="#i-clock"/></svg></span>
          <div><h2>{{ __('dns.zone.activity') }}</h2><p>{{ __('dns.ux.activity_sub') }}</p></div>
        </div>
      </div>
      <div class="dns-card-b">
        @if($events->count())
          <ul class="dns-log">
            @foreach($events as $e)
              <li><span>{{ \Illuminate\Support\Facades\Lang::has('dns.audit.'.$e->message) ? __('dns.audit.'.$e->message, collect((array) $e->payload)->filter(fn ($v) => is_scalar($v))->all()) : __('dns.audit.other') }}</span>
                <time>{{ $e->created_at ? sdate($e->created_at, true) : '' }}</time></li>
            @endforeach
          </ul>
        @else
          <p class="dns-t-dim dns-small">—</p>
        @endif
      </div>
    </section>
  </div>

  {{-- ══ ناحیهٔ خطر — بسته تا کسی ناخواسته به آن نرسد ══ --}}
  <details class="dns-danger" @if($errors->has('delete')) open @endif>
    <summary><svg class="icon" aria-hidden="true"><use href="#i-x"/></svg>{{ __('dns.zone.delete_title') }}</summary>
    <div class="dns-danger-b">
      <p class="dns-t-dim dns-small">{{ __('dns.zone.delete_body') }}</p>
      <form method="POST" action="{{ $url }}/delete">@csrf
        <input class="dns-input dns-ltr" name="confirm" dir="ltr" autocomplete="off" spellcheck="false" placeholder="{{ $zone->domain }}" aria-label="{{ __('dns.zone.delete_confirm_label') }}">
        <button class="pnl-btn danger" type="submit"><svg class="icon" aria-hidden="true"><use href="#i-x"/></svg>{{ __('dns.zone.delete_btn') }}</button>
      </form>
    </div>
  </details>
</div>
<script src="{{ asset_ver('assets/js/dns.js') }}" defer></script>
@endsection

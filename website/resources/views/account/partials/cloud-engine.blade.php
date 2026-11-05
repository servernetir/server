@php
  $operation = $engine['operation'];
  $traffic = $engine['traffic'];
  $formatBytes = fn ($value) => fa_num(preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', (string) $value));
  $labels = [
    'statuses' => collect(['pending','running','succeeded','failed','pending_review'])->mapWithKeys(fn ($status) => [$status => __('cloud_engine.status_'.$status)])->all(),
    'actions' => collect(['create','power','delete','rebuild','reset_password','hostname'])->mapWithKeys(fn ($action) => [$action => __('cloud_engine.action_'.$action)])->all(),
    'coverage' => collect(['unknown','incomplete','observed_intervals_only'])->mapWithKeys(fn ($status) => [$status => __('cloud_engine.coverage_'.$status)])->all(),
    'none' => __('cloud_engine.customer_no_operation'),
  ];
@endphp
<section class="pnl-sec" data-engine-panel
         data-status-url="{{ lroute('account.cloud.engine.status', [$service, 'month' => $traffic['month']]) }}"
         data-metrics-url="{{ lroute('account.cloud.metrics', $service) }}"
         data-time-zone="{{ $engine['timezone'] }}"
         data-labels="{{ json_encode($labels, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) }}">
  <div class="pnl-sec-h"><h2>{{ __('cloud_engine.customer_title') }}</h2></div>
  <div class="pnl-sec-b">
    <div class="cs-grid" aria-live="polite">
      <div class="cs-kv"><small>{{ __('cloud_engine.customer_operation') }}</small>
        <b data-engine-operation-action>{{ $operation ? ($labels['actions'][$operation['action']] ?? $labels['none']) : $labels['none'] }}</b>
        <span data-engine-operation-status>{{ $operation ? ($labels['statuses'][$operation['status']] ?? $labels['statuses']['pending_review']) : '' }}</span>
      </div>
      <div class="cs-kv"><small>{{ __('cloud_engine.customer_coverage') }}</small>
        <b data-engine-coverage>{{ $labels['coverage'][$traffic['coverage']] ?? $labels['coverage']['unknown'] }}</b>
      </div>
      <div class="cs-kv"><small>{{ __('cloud_engine.customer_inbound') }}</small>
        <b dir="ltr"><span data-engine-in>{{ $formatBytes($traffic['in_bytes']) }}</span> {{ __('cloud_engine.customer_bytes') }}</b>
      </div>
      <div class="cs-kv"><small>{{ __('cloud_engine.customer_outbound') }}</small>
        <b dir="ltr"><span data-engine-out>{{ $formatBytes($traffic['out_bytes']) }}</span> {{ __('cloud_engine.customer_bytes') }}</b>
      </div>
      <div class="cs-kv"><small>{{ __('cloud_engine.customer_uptime') }}</small><b dir="ltr" data-engine-uptime>—</b></div>
      <div class="cs-kv"><small>{{ __('cloud_engine.customer_last_boot') }}</small><b dir="ltr" data-engine-boot>—</b></div>
      <div class="cs-kv"><small>{{ __('cloud_engine.customer_cpu_usage') }}</small><b dir="ltr" data-engine-cpu>—</b></div>
      <div class="cs-kv"><small>{{ __('cloud_engine.customer_memory_used') }}</small><b dir="ltr" data-engine-memory>—</b></div>
      <div class="cs-kv"><small>{{ __('cloud_engine.customer_allocated_disk') }}</small><b dir="ltr" data-engine-disk>—</b></div>
      <div class="cs-kv"><small>{{ __('cloud_engine.customer_guest_disk') }}</small><b data-engine-guest-disk>{{ __('cloud_engine.customer_not_measured') }}</b></div>
      <div class="cs-kv"><small>{{ __('cloud_engine.customer_network_in') }}</small><b dir="ltr" data-engine-net-in>—</b></div>
      <div class="cs-kv"><small>{{ __('cloud_engine.customer_network_out') }}</small><b dir="ltr" data-engine-net-out>—</b></div>
    </div>
    <p class="dm-note">{{ __('cloud_engine.customer_boot_estimate') }}</p>
    <p class="dm-note warn" data-engine-stale @if(! ($traffic['stale'] ?? true)) hidden @endif>{{ __('cloud_engine.customer_traffic_stale') }}</p>
    <p class="dm-note">{{ __('cloud_engine.customer_traffic_note') }}</p>
    <form method="get" action="{{ lroute('account.cloud.show', $service) }}" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin:16px 0">
      <label>{{ __('cloud_engine.customer_month') }}<input type="month" name="engine_month" value="{{ $traffic['month'] }}" required class="pnl-input" dir="ltr"></label>
      <button class="pnl-btn" type="submit">{{ __('cloud_engine.customer_refresh') }}</button>
    </form>

    @if($engine['capabilities']['power'])
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin:16px 0">
        @foreach(['on' => 'cs_power_on', 'off' => 'cs_power_off', 'reboot' => 'cs_reboot'] as $action => $label)
          <form method="post" action="{{ lroute('account.cloud.engine.power', $service) }}">
            @csrf
            <input type="hidden" name="request_key" value="{{ bin2hex(random_bytes(16)) }}">
            <input type="hidden" name="action" value="{{ $action }}">
            <button class="pnl-btn" type="submit" data-engine-mutation>{{ __('ui.'.$label) }}</button>
          </form>
        @endforeach
      </div>
    @endif
    @if($engine['capabilities']['console'])
      <form method="post" action="{{ lroute('account.cloud.engine.console', $service) }}" target="_blank" rel="noopener noreferrer" style="margin:16px 0">
        @csrf<button class="pnl-btn" type="submit">{{ __('cloud_engine.customer_console') }}</button>
      </form>
    @endif
    @if($engine['capabilities']['hostname'])
      <form method="post" action="{{ lroute('account.cloud.engine.hostname', $service) }}" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin:16px 0">
        @csrf
        <input type="hidden" name="request_key" value="{{ bin2hex(random_bytes(16)) }}">
        <label>{{ __('cloud_engine.customer_hostname') }}<input class="pnl-input" type="text" name="hostname" value="{{ $engine['hostname'] }}" maxlength="253" required dir="ltr" autocomplete="off"></label>
        <button class="pnl-btn" type="submit" data-engine-mutation>{{ __('cloud_engine.customer_change_hostname') }}</button>
      </form>
    @endif
    @if($engine['capabilities']['reset_password'])
      <form method="post" action="{{ lroute('account.cloud.engine.password', $service) }}" style="display:grid;gap:10px;margin:16px 0">
        @csrf
        <input type="hidden" name="request_key" value="{{ bin2hex(random_bytes(16)) }}">
        <label><input type="checkbox" name="confirm" value="1" required> {{ __('cloud_engine.customer_confirm') }}</label>
        <button class="pnl-btn" type="submit" data-engine-mutation>{{ __('cloud_engine.customer_password') }}</button>
      </form>
    @endif
    @if($engine['capabilities']['rebuild'])
      <details class="cs-danger" style="margin-top:20px">
        <summary>{{ __('cloud_engine.customer_rebuild') }}</summary>
        <p>{{ __('cloud_engine.customer_rebuild_warning') }}</p>
        <form method="post" action="{{ lroute('account.cloud.engine.rebuild', $service) }}" style="display:grid;gap:10px">
          @csrf
          <input type="hidden" name="request_key" value="{{ bin2hex(random_bytes(16)) }}">
          <label>{{ __('cloud_engine.image') }}<select name="image" required class="pnl-input">
            @foreach($engine['images'] as $image)<option value="{{ $image['key'] }}">{{ $image['label'] }}</option>@endforeach
          </select></label>
          <label>{{ __('cloud_engine.customer_type_delete') }}<input type="text" class="pnl-input" name="confirm" required pattern="DELETE" autocomplete="off" dir="ltr"></label>
          <button class="pnl-btn danger" type="submit" data-engine-mutation>{{ __('cloud_engine.customer_rebuild') }}</button>
        </form>
      </details>
    @endif
  </div>
</section>

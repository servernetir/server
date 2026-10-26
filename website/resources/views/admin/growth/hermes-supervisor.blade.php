<section class="gc-card" aria-label="{{ __('growth.supervisor_title') }}">
  <h3>{{ __('growth.supervisor_title') }}</h3>
  <p>{{ __('growth.supervisor_scope') }}</p>
  <p><strong>{{ __('growth.supervisor_state') }}:</strong> {{ __('growth.supervisor_state_'.$supervisor['state']) }}</p>
  @if($supervisor['latest_run'])
    <p><strong>{{ __('growth.supervisor_last') }}:</strong> <time>{{ $supervisor['latest_run']['observed_at'] ?? '' }}</time> · <code>{{ $supervisor['latest_run']['status'] ?? '' }}</code></p>
    <p>{{ __('growth.supervisor_findings') }}: {{ count($supervisor['latest_run']['findings'] ?? []) }}</p>
    @foreach($supervisor['latest_run']['findings'] ?? [] as $finding)
      <p><code>{{ $finding['code'] }}</code> · {{ $finding['count'] }} · <code>{{ $finding['proposed_action'] }}</code></p>
    @endforeach
  @else
    <p class="gc-muted">{{ __('growth.supervisor_no_run') }}</p>
  @endif
  <form method="post" action="/admin/ai-growth/supervisor">@csrf
    <input type="hidden" name="enabled" value="{{ $supervisor['enabled'] ? 0 : 1 }}">
    <button>{{ __('growth.'.($supervisor['enabled'] ? 'supervisor_pause' : 'supervisor_enable')) }}</button>
  </form>
  <p class="gc-muted">{{ __('growth.supervisor_limits') }}</p>
</section>

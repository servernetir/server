@if(\App\Models\Setting::get('bale_manager_policy_v2_enabled') === '1')
<div class="ad-panel">
  <div class="ad-panel-h"><h3>{{ __('bale-evidence.title') }}</h3><a href="{{ route('admin.customer.evidence', $c) }}">{{ __('bale-evidence.all_history') }}</a></div>
  <p style="padding:0 16px;color:var(--muted)">{{ __('bale-evidence.retention_hint') }}</p>
  @forelse($managerEvidenceProfiles ?? collect() as $ep)
    <div style="padding:12px 16px;border-top:1px solid var(--line)">
      <b>{{ $ep->displayName() ?: __('bale-evidence.profile_number', ['id' => $ep->id]) }}</b> · {{ trans()->has('bale-evidence.status_'.$ep->status) ? __('bale-evidence.status_'.$ep->status) : $ep->status }}
      <a href="{{ url('/admin/verifications?q='.$c->code) }}">{{ __('bale-evidence.review_profile') }}</a>
      @foreach($ep->dossierDocuments as $ed)
        <div style="padding-top:6px">
          <a href="{{ url('/admin/verifications/'.$ep->id.'/doc/'.$ed->id) }}">{{ $ed->original_name ?: $ed->kind }}</a>
          <span style="color:var(--muted)"> · {{ trans()->has('bale-evidence.status_'.$ed->status) ? __('bale-evidence.status_'.$ed->status) : $ed->status }} · {{ stime($ed->uploaded_at) }}</span>
        </div>
      @endforeach
    </div>
  @empty
    <p style="padding:0 16px;color:var(--muted)">{{ __('bale-evidence.no_documents') }}</p>
  @endforelse
  @foreach($managerEvidenceReceipts ?? collect() as $er)
    <div style="padding:10px 16px;border-top:1px solid var(--line)">
      <a href="{{ route('admin.bank-transfers.evidence', $er) }}">{{ __('bale-evidence.receipt_number', ['id' => $er->id, 'name' => $er->evidence_name]) }}</a>
      <span style="color:var(--muted)"> · {{ trans()->has('bale-evidence.status_'.$er->status) ? __('bale-evidence.status_'.$er->status) : $er->status }} · {{ stime($er->created_at) }}</span>
    </div>
  @endforeach
</div>
@endif

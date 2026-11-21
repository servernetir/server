@extends('admin.layout')
@section('title', __('email-evidence-review.detail_title'))
@section('nav_email_marketing', 'on')
@section('content')
<style>
  .erd{--erd-accent:var(--cyan);display:grid;gap:18px;max-width:1120px;margin-inline:auto;color:var(--text)}
  html[data-theme="light"] .erd{--erd-accent:#0e7490}
  .erd *{box-sizing:border-box}.erd h2,.erd h3,.erd p,.erd dl,.erd dd{margin:0}.erd h2,.erd h3{line-height:1.65;color:var(--text)}.erd p{line-height:1.8;color:var(--muted);font-size:13px}
  .erd a:focus-visible,.erd button:focus-visible,.erd input:focus-visible,.erd select:focus-visible{outline:2px solid var(--erd-accent);outline-offset:3px}
  .erd-back{align-self:flex-start;text-decoration:none;justify-self:start}.erd-hero{padding:24px;border:1px solid var(--line2);border-radius:20px;background:radial-gradient(ellipse at 100% 0%,rgba(34,211,238,.1),transparent 58%),var(--surface)}
  .erd-hero h2{font-size:23px;margin-bottom:8px}.erd-hero p{max-width:820px}
  .erd-meta{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:20px!important}.erd-meta div{background:var(--surface2);border:1px solid var(--line);border-radius:12px;padding:14px;min-width:0}
  .erd-meta dt{font-size:12px;color:var(--muted);margin-bottom:6px}.erd-meta dd{font-size:15px;font-weight:650;overflow-wrap:anywhere}
  .erd-notice,.erd-message{padding:13px 17px;border:1px solid rgba(251,191,36,.28);background:rgba(251,191,36,.06);border-radius:12px;line-height:1.8;font-size:13px}.erd-message.error{border-color:rgba(255,107,107,.4);background:rgba(255,107,107,.08)}.erd-message.ok{border-color:rgba(34,211,238,.28);background:rgba(34,211,238,.07)}
  .erd-card{padding:23px;border:1px solid var(--line);border-radius:16px;background:var(--surface);min-width:0}.erd-card h3{font-size:18px;margin-bottom:7px}.erd-card p{margin-bottom:17px}
  .erd-form{display:grid;gap:16px}.erd-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.erd-field{display:grid;gap:6px;min-width:0;align-content:start}.erd-field label{font-size:13px;font-weight:650}.erd-field input,.erd-field select{width:100%;min-width:0;min-height:43px;font:inherit;border-radius:9px;padding:9px 11px;color:var(--text);background:var(--surface2);border:1px solid var(--line2)}.erd-field small{font-size:11.5px;line-height:1.7;color:var(--muted)}
  .erd-field.wide{grid-column:1/-1}.erd-ack{display:grid;grid-template-columns:19px minmax(0,1fr);gap:10px;padding:15px;border:1px solid var(--line);border-radius:12px;background:var(--surface2);font-size:12.5px;line-height:1.75;cursor:pointer}.erd-ack input{width:17px;height:17px;accent-color:var(--erd-accent);margin:3px 0 0}
  .erd-actions{display:flex;justify-content:flex-end}.erd-actions button{min-height:44px;white-space:normal}
  .erd-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;align-items:start}.erd-table-wrap{overflow-x:auto}.erd-table-wrap table{min-width:650px}.erd-table-wrap .ad-table th,.erd-table-wrap .ad-table td{padding:13px 15px;font-size:12px}
  @media(max-width:850px){.erd-grid,.erd-meta{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:580px){.erd-fields,.erd-grid,.erd-meta{grid-template-columns:minmax(0,1fr)}.erd-card,.erd-hero{padding:17px}.erd-actions button{width:100%}}
</style>
<div class="erd" data-evidence-state="{{ $state }}">
  <a class="btn btn-glass erd-back" href="{{ route('admin.email-marketing.index').'#evidence' }}">{{ __('email-evidence-review.back') }}</a>
  <section class="erd-hero" aria-labelledby="erd-title">
    <h2 id="erd-title">{{ __('email-evidence-review.detail_title') }}</h2>
    <p>{{ __('email-evidence-review.detail_intro') }}</p>
    <dl class="erd-meta">
      <div><dt>{{ __('email-evidence-review.contact_id') }}</dt><dd dir="ltr">{{ $record->contact_id }}</dd></div>
      <div><dt>{{ __('email-evidence-review.consent_date') }}</dt><dd dir="ltr">{{ $record->consent_date->format('Y-m-d') }}</dd></div>
      <div><dt>{{ __('email-evidence-review.imported_at') }}</dt><dd>{{ sdate($record->created_at) }}</dd></div>
      <div><dt>{{ __('email-evidence-review.state') }}</dt><dd>{{ $state === 'pending' ? __('email-evidence-review.review_only') : __('email-evidence-review.state_'.$state) }}</dd></div>
    </dl>
  </section>
  @if(!empty($sharedDocument))
    <section class="erd-card" aria-labelledby="erd-document-title">
      <h3 id="erd-document-title">{{ __('email-evidence-review.document_detail_title') }}</h3>
      <p>{{ __('email-evidence-review.document_detail_notice') }}</p>
      <dl class="erd-meta">
        <div><dt>{{ __('email-evidence-review.document_group') }}</dt><dd dir="ltr" data-evidence-document-batch="{{ $sharedDocument['batch_id'] }}">{{ $sharedDocument['batch_id'] }}</dd></div>
        <div><dt>{{ __('email-evidence-review.document_reference') }}</dt><dd dir="auto">{{ $sharedDocument['reference'] ?? '—' }}</dd></div>
        <div><dt>{{ __('email-evidence-review.document_linked_at') }}</dt><dd dir="ltr">{{ ($sharedDocument['created_at'] ?? null)?->format('Y-m-d H:i:s T') ?? '—' }}</dd></div>
      </dl>
    </section>
  @endif
  <p class="erd-notice">{{ __('email-evidence-review.review_workflow_notice') }}</p>
  @if(session('review_error'))
    <div role="alert" class="erd-message error">{{ session('review_error') }}</div>
  @endif
  @if(session('review_success'))
    <div role="status" class="erd-message ok">{{ session('review_success') }}</div>
  @endif

  @if($state === 'pending')
    <section class="erd-card" aria-labelledby="erd-verify-title">
      <h3 id="erd-verify-title">{{ __('email-evidence-review.verify_title') }}</h3>
      <p>{{ __('email-evidence-review.verify_help') }}</p>
      <form id="evidence-review-verify" class="erd-form" method="post" action="{{ route('admin.email-marketing.evidence.decide', ['review' => $record->id]) }}" autocomplete="off">
        @csrf
        <input type="hidden" name="decision" value="verified">
        <input type="hidden" name="expected_event" value="{{ $expectedEvent }}">
        <div class="erd-fields">
          <div class="erd-field">
            <label for="erd-source-email">{{ __('email-evidence-review.source_email') }}</label>
            <input id="erd-source-email" type="email" name="source_email" dir="ltr" maxlength="190" autocomplete="off" autocapitalize="none" spellcheck="false" required>
          </div>
          <div class="erd-field">
            <label for="erd-source-date">{{ __('email-evidence-review.source_date') }}</label>
            <input id="erd-source-date" type="date" name="source_date" dir="ltr" max="{{ now()->toDateString() }}" autocomplete="off" required>
          </div>
          <div class="erd-field">
            <label for="erd-source-reference">{{ __('email-evidence-review.source_reference') }}</label>
            <input id="erd-source-reference" type="text" name="source_reference" dir="auto" maxlength="160" autocomplete="off" spellcheck="false" required>
          </div>
          <div class="erd-field">
            <label for="erd-source-kind">{{ __('email-evidence-review.source_kind') }}</label>
            <select id="erd-source-kind" name="source_kind" required>
              <option value="" selected disabled>{{ __('email-evidence-review.select_option') }}</option>
              @foreach(['web_form','written_consent','account_opt_in','other_documented'] as $kind)
                <option value="{{ $kind }}">{{ __('email-evidence-review.source_'.$kind) }}</option>
              @endforeach
            </select>
          </div>
          <div class="erd-field wide">
            <label for="erd-review-reference">{{ __('email-evidence-review.review_reference') }}</label>
            <input id="erd-review-reference" type="text" name="review_reference" maxlength="160" dir="auto" autocomplete="off" spellcheck="false" aria-describedby="erd-review-reference-help" required>
            <small id="erd-review-reference-help">{{ __('email-evidence-review.review_reference_help') }}</small>
          </div>
        </div>
        <label class="erd-ack" for="erd-verify-ack"><input type="checkbox" id="erd-verify-ack" name="acknowledge" value="1" required><span>{{ __('email-evidence-review.verify_ack') }}</span></label>
        <div class="erd-actions"><button class="btn btn-primary" type="submit">{{ __('email-evidence-review.verify_button') }}</button></div>
      </form>
    </section>
  @endif

  @if(in_array($state, ['pending','verified','rejected'], true))
    <div class="erd-grid">
      @if(in_array($state, ['pending','verified'], true))
        <section class="erd-card" aria-labelledby="erd-reject-title">
          <h3 id="erd-reject-title">{{ __('email-evidence-review.reject_title') }}</h3>
          <p>{{ __('email-evidence-review.reject_help') }}</p>
          <form id="evidence-review-reject" class="erd-form" method="post" action="{{ route('admin.email-marketing.evidence.decide', ['review' => $record->id]) }}" autocomplete="off">
            @csrf
            <input type="hidden" name="decision" value="rejected">
            <input type="hidden" name="expected_event" value="{{ $expectedEvent }}">
            <div class="erd-field">
              <label for="erd-reject-reason">{{ __('email-evidence-review.reason') }}</label>
              <select name="reason" id="erd-reject-reason" required>
                <option value="" selected disabled>{{ __('email-evidence-review.select_option') }}</option>
                @foreach(['missing_evidence','identity_mismatch','not_marketing_consent','ambiguous_evidence','invalidated'] as $reason)
                  <option value="{{ $reason }}">{{ __('email-evidence-review.reason_'.$reason) }}</option>
                @endforeach
              </select>
            </div>
            <label class="erd-ack" for="erd-reject-ack"><input type="checkbox" id="erd-reject-ack" name="acknowledge" value="1" required><span>{{ __('email-evidence-review.reject_ack') }}</span></label>
            <div class="erd-actions"><button class="btn btn-glass" type="submit">{{ __('email-evidence-review.reject_button') }}</button></div>
          </form>
        </section>
      @endif
      <section class="erd-card" aria-labelledby="erd-revoke-title">
        <h3 id="erd-revoke-title">{{ __('email-evidence-review.revoke_title') }}</h3>
        <p>{{ __('email-evidence-review.revoke_help') }}</p>
        <form id="evidence-review-revoke" class="erd-form" method="post" action="{{ route('admin.email-marketing.evidence.decide', ['review' => $record->id]) }}" autocomplete="off">
          @csrf
          <input type="hidden" name="decision" value="revoked">
          <input type="hidden" name="expected_event" value="{{ $expectedEvent }}">
          <div class="erd-field">
            <label for="erd-revoke-reason">{{ __('email-evidence-review.reason') }}</label>
            <select name="reason" id="erd-revoke-reason" required>
              <option value="" selected disabled>{{ __('email-evidence-review.select_option') }}</option>
              @foreach(['recipient_withdrawal','administrative_prevent'] as $reason)
                <option value="{{ $reason }}">{{ __('email-evidence-review.reason_'.$reason) }}</option>
              @endforeach
            </select>
          </div>
          <div class="erd-field">
            <label for="erd-revoke-reference">{{ __('email-evidence-review.withdrawal_reference') }}</label>
            <input id="erd-revoke-reference" type="text" name="review_reference" maxlength="160" dir="auto" autocomplete="off" spellcheck="false" aria-describedby="erd-revoke-reference-help">
            <small id="erd-revoke-reference-help">{{ __('email-evidence-review.withdrawal_reference_help') }}</small>
          </div>
          <label class="erd-ack" for="erd-revoke-ack"><input type="checkbox" id="erd-revoke-ack" name="acknowledge" value="1" required><span>{{ __('email-evidence-review.revoke_ack') }}</span></label>
          <div class="erd-actions"><button class="btn btn-glass" type="submit">{{ __('email-evidence-review.revoke_button') }}</button></div>
        </form>
      </section>
    </div>
  @else
    <p class="erd-notice">{{ __('email-evidence-review.review_closed') }}</p>
  @endif

  <section class="erd-card" aria-labelledby="erd-audit-title">
    <h3 id="erd-audit-title">{{ __('email-evidence-review.audit_title') }}</h3>
    <p>{{ __('email-evidence-review.audit_help') }}</p>
    @if($events->count() > 0)
      <div class="erd-table-wrap"><table class="ad-table">
        <thead><tr>
          <th scope="col">{{ __('email-evidence-review.state') }}</th><th scope="col">{{ __('email-evidence-review.reason') }}</th><th scope="col">{{ __('email-evidence-review.source_kind') }}</th><th scope="col">{{ __('email-evidence-review.reviewer') }}</th><th scope="col">{{ __('email-evidence-review.reviewed_at') }}</th>
        </tr></thead>
        <tbody>
          @foreach($events as $event)
            <tr data-evidence-decision="{{ $event->decision }}">
              <td>{{ __('email-evidence-review.state_'.$event->decision) }}</td>
              <td>{{ $event->reason ? __('email-evidence-review.reason_'.$event->reason) : '—' }}</td>
              <td>{{ $event->source_kind ? __('email-evidence-review.source_'.$event->source_kind) : '—' }}</td>
              <td>{{ $event->reviewer?->name ?? __('email-evidence-review.reviewer_id', ['id' => $event->reviewed_by]) }}</td>
              <td dir="ltr">{{ $event->created_at->format('Y-m-d H:i:s T') }}</td>
            </tr>
          @endforeach
        </tbody>
      </table></div>
    @else
      <p data-evidence-audit-empty>{{ __('email-evidence-review.audit_empty') }}</p>
    @endif
  </section>
</div>
@endsection

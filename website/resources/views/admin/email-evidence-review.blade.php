<style>
  .er{--er-accent:var(--cyan);--er-review:var(--amber);display:grid;gap:22px;max-width:1440px;margin-inline:auto}
  html[data-theme="light"] .er{--er-accent:#0e7490;--er-review:#92400e}
  .er *{box-sizing:border-box}.er h2,.er h3,.er p,.er dl,.er dd{margin:0}
  .er h2,.er h3{color:var(--text);line-height:1.65}.er p{line-height:1.85;color:var(--muted)}
  .er-icon{width:23px;height:23px;display:block;flex-shrink:0;stroke:currentColor;fill:none;stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round}
  .er a:focus-visible,.er button:focus-visible,.er input:focus-visible{outline:2px solid var(--er-accent);outline-offset:4px}
  .er-hero{display:flex;align-items:center;justify-content:space-between;gap:30px;padding:30px clamp(20px,3vw,36px);border:1px solid var(--line2);border-radius:22px;background:radial-gradient(ellipse at 95% 0%,rgba(34,211,238,.12),transparent 55%),radial-gradient(ellipse at 0% 100%,rgba(139,92,246,.11),transparent 55%),var(--surface)}
  .er-hero-copy{display:grid;gap:10px;max-width:820px}.er-eyebrow{display:flex;align-items:center;gap:9px;font-weight:700;font-size:12px;color:var(--er-accent);letter-spacing:.035em}
  .er-eyebrow .er-icon{width:18px;height:18px}.er-hero h2{font-size:clamp(19px,2.2vw,25px);font-weight:800}.er-hero p{font-size:13.5px}
  .er-hero-mark{display:grid;place-items:center;flex:0 0 104px;height:104px;border-radius:26px;border:1px solid var(--line2);background:linear-gradient(145deg,rgba(34,211,238,.13),rgba(139,92,246,.14));color:var(--er-accent)}
  .er-hero-mark .er-icon{width:48px;height:48px}
  .er-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
  .er-stat{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:19px;border:1px solid var(--line);border-radius:16px;background:var(--surface);min-width:0}
  .er-stat dt{color:var(--muted);font-size:12px;font-weight:600}.er-stat dd{font-size:29px;line-height:1.45;font-weight:800;color:var(--text);font-variant-numeric:tabular-nums;margin:2px 0}
  .er-stat small{color:var(--muted);font-size:11.5px;line-height:1.6;display:block}
  .er-stat-icon{display:grid;place-items:center;width:42px;height:42px;border-radius:12px;color:var(--er-accent);background:rgba(34,211,238,.09);flex:0 0 auto}
  .er-stat-icon.review{color:var(--er-review);background:rgba(251,191,36,.11)}.er-stat-icon.neutral{color:var(--muted);background:var(--surface2)}
  .er-card{background:var(--surface);border:1px solid var(--line);border-radius:18px;padding:24px;min-width:0}
  .er-grid{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(0,1fr);gap:16px;align-items:start}
  .er-head{display:flex;align-items:center;gap:12px;margin-bottom:19px}.er-head .er-icon{width:22px;height:22px;color:var(--er-accent)}
  .er-head h3{font-size:17px;font-weight:750}.er-section-copy{font-size:13px;margin-bottom:18px!important}
  .er-steps{list-style:none;padding:0;margin:0;display:grid;gap:20px}.er-steps li{display:grid;grid-template-columns:30px minmax(0,1fr);gap:12px;align-items:start}
  .er-step-no{display:grid;place-items:center;background:var(--surface2);border:1px solid var(--line2);color:var(--er-accent);font-size:13px;font-weight:800;border-radius:10px;width:30px;height:30px;font-variant-numeric:tabular-nums}
  .er-steps strong{font-weight:650;color:var(--text);display:block;line-height:1.6;margin:3px 0 5px}.er-steps p{font-size:12.5px}
  .er-columns{margin-top:10px;display:block;max-width:100%;overflow-wrap:anywhere;direction:ltr;text-align:left;padding:10px 13px;border:1px solid var(--line2);border-radius:10px;background:var(--surface2);font-size:12px;line-height:1.8;color:var(--er-accent)}
  .er-download{display:flex;align-items:center;justify-content:space-between;gap:10px;border:1px solid var(--line2);border-radius:12px;background:var(--surface2);padding:13px;margin-top:20px}
  .er-download-copy{min-width:0}.er-download-copy strong{display:block;font-weight:650;color:var(--text)}.er-download-copy small{color:var(--muted);display:block;font-size:12px;margin-top:3px}
  .er-download .btn{white-space:normal;justify-content:center;font-size:12.5px;padding:9px 12px;text-decoration:none}.er-download .er-icon{width:17px;height:17px}
  .er-form{display:grid;gap:17px}.er-file-area{padding:18px;border:1.5px dashed var(--line2);border-radius:14px;background:var(--surface2)}
  .er-file-label{font-weight:700;display:flex;align-items:center;gap:10px;color:var(--text);margin-bottom:12px}.er-file-label .er-icon{color:var(--er-accent);width:22px;height:22px}
  .er-file{width:100%;max-width:100%;font:inherit;font-size:13px;background:var(--surface);border:1px solid var(--line2);border-radius:10px;padding:8px;color:var(--muted);cursor:pointer}
  .er-file::file-selector-button{font:inherit;font-weight:650;background:var(--surface2);border:1px solid var(--line2);border-radius:7px;color:var(--text);padding:7px 11px;margin-inline-end:10px;cursor:pointer}.er-file-area p{font-size:12px;margin-top:10px}
  .er-confirm{display:grid;grid-template-columns:19px minmax(0,1fr);gap:10px;align-items:start;padding:16px;border:1px solid var(--line);background:var(--surface2);border-radius:13px;color:var(--text);font-size:13px;line-height:1.8;cursor:pointer}
  .er-confirm input{width:17px;height:17px;margin:4px 0 0;accent-color:var(--er-accent);cursor:pointer}
  .er-note{display:flex;align-items:flex-start;gap:10px;padding:13px 16px;border-radius:12px;border:1px solid rgba(251,191,36,.26);background:rgba(251,191,36,.07)}
  .er-note .er-icon{width:19px;height:19px;color:var(--er-review);margin-top:2px}.er-note p{font-size:12px;color:var(--text)}
  .er-submit{display:flex;align-items:center;justify-content:flex-end}.er-submit button{justify-content:center;min-height:46px}.er-submit .er-icon{width:18px;height:18px}
  .er-alert{display:flex;align-items:flex-start;gap:12px;border:1px solid var(--line2);border-radius:14px;padding:15px 18px;background:var(--surface)}
  .er-alert.is-error{border-color:rgba(255,107,107,.45);background:rgba(255,107,107,.10)}.er-alert.is-error .er-icon{color:var(--red)}
  .er-alert strong{display:block;color:var(--text);font-size:14px;font-weight:700;margin-bottom:3px}.er-alert p{font-size:13px}
  .er-import-result{border-color:rgba(52,211,153,.28)}.er-import-result .er-head .er-icon{color:var(--green)}
  .er-result-note{margin-top:16px!important;font-size:12px}.er-result-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
  .er-result-stats div{padding:14px;border-radius:12px;background:var(--surface2);border:1px solid var(--line)}
  .er-result-stats dt{color:var(--muted);font-size:12px}.er-result-stats dd{font-weight:750;font-size:24px;line-height:1.5;font-variant-numeric:tabular-nums;color:var(--text)}
  .er-history{padding:0;overflow:hidden}.er-history-head{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:23px 24px 18px}
  .er-history-head h3{font-size:17px;font-weight:750}.er-history-head p{margin-top:5px;font-size:12.5px}
  .er-pill{display:inline-flex;align-items:center;gap:7px;line-height:1.55;padding:5px 11px;border:1px solid rgba(251,191,36,.24);background:rgba(251,191,36,.09);color:var(--er-review);font-weight:650;font-size:11px;border-radius:999px;max-width:100%;white-space:normal}
  .er-pill::before{content:"";width:6px;height:6px;border-radius:50%;background:currentColor;flex-shrink:0}
  .er-count-pill{font-weight:650;padding:5px 12px;border-radius:99px;border:1px solid var(--line2);background:var(--surface2);font-size:12px;color:var(--muted);white-space:nowrap}
  .er-table-scroll{overflow-x:auto;border-top:1px solid var(--line)}.er .ad-table{min-width:660px}
  .er .ad-table th{background:var(--surface2);font-size:11.5px;padding:14px 20px;text-transform:none;color:var(--muted)}
  .er .ad-table td{font-size:13px;padding:15px 20px}.er .ad-table tr:hover td{background:rgba(34,211,238,.025)}
  .er-contact-id{font-weight:750;font-variant-numeric:tabular-nums;color:var(--text)}
  .er-empty{padding:52px 24px 48px;display:grid;gap:10px;text-align:center;justify-items:center;border-top:1px solid var(--line)}
  .er-empty .er-stat-icon{width:56px;height:56px;border-radius:17px}.er-empty strong{color:var(--text);font-size:15px;font-weight:700}.er-empty p{font-size:13px;max-width:430px}
  .er-pagination{padding:14px 24px;border-top:1px solid var(--line)}
  @media(max-width:1100px){.er-grid{grid-template-columns:minmax(0,1fr)}}
  @media(max-width:780px){.er-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.er-hero-mark{width:76px;height:76px;flex-basis:76px;border-radius:19px}.er-hero-mark .er-icon{width:36px;height:36px}.er-stat{padding:14px}}
  @media(max-width:520px){.er{gap:15px}.er-hero{padding:19px;align-items:flex-start;gap:12px}.er-hero-mark{display:none}.er-stats,.er-result-stats{grid-template-columns:minmax(0,1fr)}.er-stat{padding:14px 17px}.er-stat dd{font-size:25px}.er-card{padding:18px}.er-history{padding:0}.er-history-head{padding:17px 18px;align-items:flex-start;flex-wrap:wrap}.er-download{align-items:stretch;flex-direction:column}.er-file-area{padding:12px}.er-submit button{width:100%}.er-empty{padding:34px 16px}.er-pagination{padding:14px}}
  @media(prefers-reduced-motion:reduce){.er a,.er button,.er input{transition:none}}
</style>

<div class="er" id="evidence" data-evidence-overview>
  <section class="er-hero" aria-labelledby="er-title">
    <div class="er-hero-copy">
      <div class="er-eyebrow">
        <svg class="er-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l8 3v6c0 5-3.5 9-8 11-4.5-2-8-6-8-11V5l8-3z"/><path d="m9 12 2 2 4-4"/></svg>
        {{ __('email-evidence-review.hero_label') }}
      </div>
      <h2 id="er-title">{{ __('email-evidence-review.hero_title') }}</h2>
      <p>{{ __('email-evidence-review.intro') }}</p>
    </div>
    <div class="er-hero-mark" aria-hidden="true">
      <svg class="er-icon" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="15" rx="3"/><path d="m3 7 9 6 9-6"/><path d="m9 19 3-3 3 3"/></svg>
    </div>
  </section>

  <dl class="er-stats" aria-label="{{ __('email-evidence-review.overview') }}">
    <div class="er-stat">
      <div><dt>{{ __('email-evidence-review.total_records') }}</dt><dd data-evidence-total="{{ $records->total() }}">{{ number_format($records->total()) }}</dd><small>{{ __('email-evidence-review.total_note') }}</small></div>
      <span class="er-stat-icon"><svg class="er-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="3" width="14" height="18" rx="3"/><path d="M9 8h6M9 12h6M9 16h4"/></svg></span>
    </div>
    <div class="er-stat">
      <div><dt>{{ __('email-evidence-review.pending_records') }}</dt><dd data-evidence-pending="{{ $records->total() }}">{{ number_format($records->total()) }}</dd><small>{{ __('email-evidence-review.pending_note') }}</small></div>
      <span class="er-stat-icon review"><svg class="er-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span>
    </div>
    <div class="er-stat">
      <div><dt>{{ __('email-evidence-review.eligible_records') }}</dt><dd data-evidence-eligible="0">0</dd><small>{{ __('email-evidence-review.eligible_note') }}</small></div>
      <span class="er-stat-icon neutral"><svg class="er-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="5"/><path d="m8 8 8 8m0-8-8 8"/></svg></span>
    </div>
  </dl>

  @if(is_array(session('evidence_result')))
    <section class="er-card er-import-result" role="status" aria-labelledby="er-result-title">
      <div class="er-head">
        <svg class="er-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/></svg>
        <h3 id="er-result-title">{{ __('email-evidence-review.stage_result') }}</h3>
      </div>
      <dl class="er-result-stats">
        @foreach(['rows','added','duplicates'] as $metric)
          <div><dt>{{ __('email-evidence-review.count_'.$metric) }}</dt><dd data-evidence-metric="{{ $metric }}">{{ number_format((int) (session('evidence_result')[$metric] ?? 0)) }}</dd></div>
        @endforeach
      </dl>
      <p class="er-result-note">{{ __('email-evidence-review.no_message') }}</p>
    </section>
  @endif

  <div class="er-grid">
    <section class="er-card" aria-labelledby="er-upload-title">
      <div class="er-head"><svg class="er-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V3m-5 5 5-5 5 5M4 16v3a2 2 0 002 2h12a2 2 0 002-2v-3"/></svg><h3 id="er-upload-title">{{ __('email-evidence-review.stage_title') }}</h3></div>
      <p class="er-section-copy">{{ __('email-evidence-review.upload_intro') }}</p>
      <form id="evidence-review-upload" class="er-form" method="post" action="{{ route('admin.email-marketing.evidence.stage') }}" enctype="multipart/form-data">
        @csrf
        <div class="er-file-area">
          <label for="evidence-review-file" class="er-file-label"><svg class="er-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M13 3H6a2 2 0 00-2 2v14a2 2 0 002 2h12a2 2 0 002-2V10zM13 3v7h7M8 14h8m-8 3h5"/></svg>{{ __('email-evidence-review.file') }}</label>
          <input id="evidence-review-file" class="er-file" type="file" name="file" accept=".csv,text/csv" aria-describedby="er-file-help" required>
          <p id="er-file-help">{{ __('email-evidence-review.file_limits') }}</p>
        </div>
        <label for="evidence-review-acknowledge" class="er-confirm">
          <input id="evidence-review-acknowledge" type="checkbox" name="acknowledge" value="1" required>
          <span>{{ __('email-evidence-review.acknowledge') }}</span>
        </label>
        <div class="er-note">
          <svg class="er-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5m0-8h.01"/></svg>
          <p>{{ __('email-evidence-review.review_notice') }}</p>
        </div>
        <div class="er-submit">
          <button class="btn btn-primary" type="submit"><svg class="er-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12l4 4L19 6"/></svg>{{ __('email-evidence-review.stage_button') }}</button>
        </div>
      </form>
    </section>

    <section class="er-card" aria-labelledby="er-guide-title">
      <div class="er-head"><svg class="er-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h6a2 2 0 012 2v14a3 3 0 00-3-3H4zm16 0h-6a2 2 0 00-2 2v14a3 3 0 013-3h5z"/></svg><h3 id="er-guide-title">{{ __('email-evidence-review.guide_title') }}</h3></div>
      <ol class="er-steps">
        <li><span class="er-step-no" aria-hidden="true">01</span><div><strong>{{ __('email-evidence-review.step_prepare') }}</strong><p>{{ __('email-evidence-review.stage_help') }}</p><code class="er-columns">email,consent_date,consent_reference</code></div></li>
        <li><span class="er-step-no" aria-hidden="true">02</span><div><strong>{{ __('email-evidence-review.step_record') }}</strong><p>{{ __('email-evidence-review.identifier_note') }}</p></div></li>
        <li><span class="er-step-no" aria-hidden="true">03</span><div><strong>{{ __('email-evidence-review.step_review') }}</strong><p>{{ __('email-evidence-review.step_review_help') }}</p></div></li>
      </ol>
      <div class="er-download">
        <div class="er-download-copy"><strong>{{ __('email-evidence-review.sample_label') }}</strong><small>{{ __('email-evidence-review.sample_note') }}</small></div>
        <a class="btn btn-glass" href="{{ route('admin.email-marketing.evidence.sample') }}"><svg class="er-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12m-5-5 5 5 5-5M4 16v3a2 2 0 002 2h12a2 2 0 002-2v-3"/></svg>{{ __('email-evidence-review.sample') }}</a>
      </div>
    </section>
  </div>

  <section class="er-card er-history" aria-labelledby="er-history-title">
    <div class="er-history-head">
      <div><h3 id="er-history-title">{{ __('email-evidence-review.history_title') }}</h3><p>{{ __('email-evidence-review.history_note') }}</p></div>
      <span class="er-count-pill">{{ number_format($records->total()) }} {{ __('email-evidence-review.records_label') }}</span>
    </div>
    @if($records->count() > 0)
      <div class="er-table-scroll">
        <table class="ad-table">
          <thead><tr>
            <th scope="col">{{ __('email-evidence-review.contact_id') }}</th>
            <th scope="col">{{ __('email-evidence-review.consent_date') }}</th>
            <th scope="col">{{ __('email-evidence-review.imported_at') }}</th>
            <th scope="col">{{ __('email-evidence-review.state') }}</th>
          </tr></thead>
          <tbody>
            @foreach($records as $record)
              <tr data-evidence-row="{{ $record->contact_id }}">
                <td><span class="er-contact-id" dir="ltr">{{ $record->contact_id }}</span></td>
                <td><span dir="ltr">{{ $record->consent_date->format('Y-m-d') }}</span></td>
                <td>{{ sdate($record->created_at) }}</td>
                <td><span class="er-pill">{{ __('email-evidence-review.review_only') }}</span></td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @else
      <div class="er-empty" data-evidence-empty>
        <span class="er-stat-icon"><svg class="er-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9l3-5h10l3 5v9a2 2 0 01-2 2H6a2 2 0 01-2-2z"/><path d="M4 10h5l1 2h4l1-2h5"/></svg></span>
        <strong>{{ __('email-evidence-review.empty') }}</strong>
        <p>{{ __('email-evidence-review.empty_help') }}</p>
      </div>
    @endif
    @if($records->hasPages())
      <div class="er-pagination">{{ $records->links() }}</div>
    @endif
  </section>
</div>

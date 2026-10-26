@if(\Illuminate\Support\Facades\Schema::hasTable('crm_leads') && \Illuminate\Support\Facades\Schema::hasTable('crm_messages'))
<section class="section" id="consultation" style="padding-top:20px">
  <div class="container" style="max-width:860px">
    <div class="report-consultation-card">
      <h2>{{ __('conversion.report_title') }}</h2>
      <p>{{ __('conversion.report_intro', ['host' => $report->host]) }}</p>
      @if(session('report_consultation_success'))
        <p class="report-consultation-result" role="status">{{ __('conversion.report_success') }}</p>
      @elseif($report->isStale())
        <p class="report-consultation-result">{{ __('conversion.report_stale') }}</p>
        <a class="btn btn-primary" href="{{ lroute('tools', 'seo') }}">{{ __('conversion.report_refresh') }}</a>
      @else
        @if($errors->reportConsultation->any())
          <div class="report-consultation-result" role="alert"><ul>@foreach($errors->reportConsultation->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        <form method="post" action="{{ lroute('report.consultation', ['token' => $report->token]) }}" class="report-consultation-form">
          @csrf
          <div class="report-consultation-pair">
            <label>{{ __('conversion.report_name') }}<input type="text" name="consultation_name" value="{{ old('consultation_name') }}" maxlength="120" autocomplete="name" required></label>
            <label>{{ __('conversion.report_email') }}<input type="email" name="consultation_email" value="{{ old('consultation_email') }}" maxlength="190" autocomplete="email" dir="ltr" required></label>
          </div>
          <label>{{ __('conversion.report_phone') }}<input type="tel" name="consultation_phone" value="{{ old('consultation_phone') }}" maxlength="40" autocomplete="tel" dir="ltr"></label>
          <label>{{ __('conversion.report_message') }}<textarea name="consultation_message" rows="4" maxlength="1500" required placeholder="{{ __('conversion.report_message_hint') }}">{{ old('consultation_message') }}</textarea></label>
          <div hidden aria-hidden="true"><label>{{ __('conversion.report_website_leave_empty') }}<input type="text" name="consultation_website" tabindex="-1" autocomplete="off"></label></div>
          <label class="report-consultation-consent"><input type="checkbox" name="consultation_consent" value="1" required><span>{{ __('conversion.report_consent') }}</span></label>
          <button class="btn btn-primary" type="submit">{{ __('conversion.report_submit') }}</button>
          <small>{{ __('conversion.report_privacy') }}</small>
        </form>
      @endif
    </div>
  </div>
</section>
<style>
.report-consultation-card{border:1px solid var(--line,rgba(128,128,128,.25));border-radius:22px;padding:clamp(20px,4vw,36px);background:var(--card,transparent)}
.report-consultation-card h2{margin:0 0 12px;font-size:clamp(22px,4vw,30px)}
.report-consultation-card p,.report-consultation-card small{line-height:1.9}
.report-consultation-form{display:grid;gap:16px;margin-top:22px}
.report-consultation-pair{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
.report-consultation-form label{display:grid;gap:7px;min-width:0}
.report-consultation-form input:not([type=checkbox]),.report-consultation-form textarea{box-sizing:border-box;width:100%;min-width:0;border:1px solid var(--line,rgba(128,128,128,.35));border-radius:12px;padding:12px;font:inherit;color:inherit;background:var(--bg,transparent)}
.report-consultation-form .report-consultation-consent{display:flex;align-items:flex-start;gap:10px;line-height:1.8}
.report-consultation-consent input{width:18px;height:18px;flex:0 0 18px;margin-top:5px}
.report-consultation-form button{justify-self:start}
.report-consultation-result{padding:14px;border-radius:12px;background:rgba(128,128,128,.1)}
@media(max-width:600px){.report-consultation-pair{grid-template-columns:1fr}.report-consultation-form button{width:100%}}
</style>
@endif

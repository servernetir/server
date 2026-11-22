@if(session('campaign_error'))<div class="em-dashboard-alert" role="alert">{{ session('campaign_error') }}</div>@endif
@if(session('campaign_success'))<div role="status" style="padding:16px;border:1px solid var(--line2);border-radius:12px">{{ session('campaign_success') }}</div>@endif
<section id="campaigns" class="ad-panel" style="padding:24px;display:grid;gap:20px" aria-labelledby="campaigns-title">
  <div><h2 id="campaigns-title" style="margin:0 0 8px">{{ __('email-campaigns.compose') }}</h2><p style="color:var(--muted);line-height:1.9;margin:0">{{ __('email-campaigns.registration_note') }}</p></div>
  @if(!$campaignReady)
    <p role="status">{{ __('email-campaigns.unavailable') }}</p>
  @else
    @if(!($campaignSummary['mailer_ready'] ?? false))<p role="status" style="color:var(--amber)">{{ __('email-campaigns.mailer_unavailable') }}</p>@endif
    <dl style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin:0">
      @foreach(['registered', 'external_ready', 'external_missing', 'external_missing_names', 'blocked'] as $metric)
        <div style="padding:16px;border:1px solid var(--line);border-radius:12px"><dt style="font-size:13px;color:var(--muted)">{{ __('email-campaigns.metric_'.$metric) }}</dt><dd style="margin:8px 0 0;font-size:25px;font-weight:750" data-campaign-metric="{{ $metric }}">{{ number_format($campaignSummary[$metric] ?? 0) }}</dd></div>
      @endforeach
    </dl>
    @if(($campaignSummary['external_missing_names'] ?? 0) > 0)
      <p role="status" style="padding:14px 16px;border:1px solid var(--line2);border-radius:12px;color:var(--muted);line-height:1.9">{{ __('email-campaigns.missing_names_note', ['count' => number_format($campaignSummary['external_missing_names'])]) }}</p>
    @endif
    @if(($campaignSummary['external_missing'] ?? 0) > 0 || ($campaignSummary['external_missing_names'] ?? 0) > 0)
      <div style="padding:16px;border:1px solid var(--line2);border-radius:12px;background:var(--surface2)">
        <h3 style="margin:0 0 8px">{{ __('email-campaigns.restore_title') }}</h3>
        <p style="line-height:1.9;color:var(--muted)">{{ __('email-campaigns.restore_help') }}</p>
        <form method="post" action="{{ route('admin.email-marketing.recipients.import') }}" enctype="multipart/form-data" style="display:grid;gap:12px">
          @csrf
          <label>{{ __('email-campaigns.restore_file') }}<input class="ad-input" type="file" name="file" accept=".csv,text/csv" required></label>
          <label style="display:flex;align-items:start;gap:8px;line-height:1.8"><input type="checkbox" name="acknowledge" value="1" required><span>{{ __('email-campaigns.restore_ack') }}</span></label>
          <button class="btn btn-glass" type="submit" style="justify-self:start">{{ __('email-campaigns.restore_button') }}</button>
        </form>
      </div>
    @endif
    <form method="post" action="{{ route('admin.email-marketing.campaigns.store') }}" style="display:grid;gap:16px">
      @csrf
      <label>{{ __('email-campaigns.sender') }}<input class="ad-input" type="email" value="info@servernet.cloud" readonly dir="ltr"></label>
      <p style="color:var(--muted);font-size:13px;margin:0">{{ __('email-campaigns.individual_note') }}</p>
      <label>{{ __('email-campaigns.audience') }}<select class="ad-input" name="audience" required>
        @foreach(['all','customers','external'] as $audience)<option value="{{ $audience }}">{{ __('email-campaigns.audience_'.$audience) }}</option>@endforeach
      </select></label>
      <label>{{ __('email-campaigns.subject') }}<input class="ad-input" type="text" name="subject" maxlength="180" required></label>
      <label>{{ __('email-campaigns.body') }}<textarea class="ad-input" name="body" rows="9" maxlength="20000" required></textarea></label>
      <p style="color:var(--muted);font-size:13px;line-height:1.9;margin:0">{{ __('email-campaigns.template_help') }}</p>
      <p style="color:var(--muted);font-size:13px;margin:0">{{ __('email-campaigns.draft_note') }}</p>
      <button class="btn btn-primary" type="submit" style="justify-self:start">{{ __('email-campaigns.save_draft') }}</button>
    </form>
    <div style="border-top:1px solid var(--line);padding-top:18px;display:grid;gap:14px">
      <h3 style="margin:0">{{ __('email-campaigns.history') }}</h3>
      @forelse($campaigns as $campaign)
        <article style="padding:18px;border:1px solid var(--line2);border-radius:12px;background:var(--surface2)">
          <div style="display:flex;flex-wrap:wrap;gap:12px;justify-content:space-between;align-items:center"><strong>{{ $campaign->subject }}</strong><span>{{ __('email-campaigns.status_'.$campaign->status) }}</span></div>
          <p style="color:var(--muted);font-size:12px">{{ __('email-campaigns.audience_'.$campaign->audience) }} · {{ sdate($campaign->created_at) }}</p>
          <p style="color:var(--muted);font-size:12px;overflow-wrap:anywhere">{{ __('email-campaigns.sender') }}: <span dir="ltr">{{ $campaign->sender_email ?? config('crm.from.email', config('mail.from.address')) }}</span></p>
          <details @if($campaign->status === 'draft') open @endif><summary style="cursor:pointer">{{ __('email-campaigns.preview') }}</summary><div style="white-space:pre-wrap;overflow-wrap:anywhere;line-height:1.9;margin-top:12px" dir="auto">{{ $campaign->body }}</div><p style="font-size:12px;color:var(--muted)">{{ __('email-campaigns.footer_note') }}</p></details>
          @if($campaign->status === 'draft')
            <form method="post" action="{{ route('admin.email-marketing.campaigns.send', ['campaign' => $campaign->id]) }}" style="display:grid;gap:12px;margin-top:15px">
              @csrf
              <label style="display:flex;align-items:start;gap:8px;line-height:1.8"><input type="checkbox" name="acknowledge" value="1" required><span>{{ __('email-campaigns.send_ack') }}</span></label>
              <button class="btn btn-primary" type="submit" style="justify-self:start" @disabled(!($campaignSummary['mailer_ready'] ?? false))>{{ __('email-campaigns.queue_button') }}</button>
            </form>
          @else
            <p style="font-size:13px;color:var(--muted);margin-bottom:0">{{ __('email-campaigns.delivery_counts', ['total' => number_format($campaign->deliveries_count ?? 0), 'sent' => number_format($campaign->sent_count ?? 0), 'blocked' => number_format($campaign->skipped_count ?? 0), 'unknown' => number_format($campaign->unknown_count ?? 0)]) }}</p>
          @endif
        </article>
      @empty
        <p style="color:var(--muted)">{{ __('email-campaigns.empty') }}</p>
      @endforelse
    </div>
  @endif
</section>

@extends('panel.layout')
@section('title', __('ui.svc_page_title'))

@section('panel')

<div class="pnl-head">
  <div>
    <h1>{{ __('ui.svc_heading') }}</h1>
    <p>{{ __('ui.svc_subtitle') }}</p>
  </div>
</div>

<section class="pnl-sec">
  <div class="pnl-sec-h"><h2>{{ __('ui.svc_section_title') }}</h2></div>

  @if($services->isEmpty())
    <div class="pnl-sec-b">
      <p style="font-size:13.5px;color:var(--muted);line-height:2;margin:0">
        {{ __('ui.svc_empty') }}
      </p>
    </div>
  @else
    <div class="pnl-sec-b flush">
      <div class="pnl-tw">
        <table class="pnl-table">
          <thead>
            <tr><th>{{ __('ui.svc_th_service') }}</th><th>{{ __('ui.svc_th_cycle') }}</th><th>{{ __('ui.svc_th_status') }}</th><th>{{ __('ui.svc_th_due') }}</th><th class="num">{{ __('ui.svc_th_amount') }}</th><th></th></tr>
          </thead>
          <tbody>
            @foreach($services as $s)
              @php
                $badge = $s->statusBadge();
                $unpaid = $s->invoices->firstWhere('status', 'unpaid');
              @endphp
              <tr>
                <td>
                  <b>{{ $s->name }}</b>
                  @if($s->description)<div style="font-size:12px;color:var(--muted);margin-top:3px">{{ \Illuminate\Support\Str::limit($s->description, 70) }}</div>@endif
                </td>
                <td>{{ $s->cycleLabel() }}</td>
                <td><span class="pnl-pill" style="background:{{ $badge[1] }}22;color:{{ $badge[1] }}">{{ $badge[0] }}</span></td>
                <td>{{ sdate($s->next_due_at) }}</td>
                <td class="num pnl-num">{{ invoice_money($s->total(), $s->currency_code) }}</td>
                <td>
                  @if($unpaid)
                    <a class="pnl-btn primary" href="{{ lroute('account.invoice', $unpaid) }}">{{ __('ui.svc_pay') }}</a>
                  @endif
                </td>
              </tr>
              @if($s->server_id)
                <tr class="svc-detail-row"><td colspan="6" style="padding:0;border:0">
                  <div class="svc-detail">
                    @if($s->provision_status === 'done')
                      {{-- دسترسیِ سریع — همه از پنل، بدونِ لاگین دستی --}}
                      <div class="svc-quick">
                        <a class="svc-qbtn primary" href="{{ lroute('account.services.cpanel', $s) }}" target="_blank" rel="noopener"><svg class="icon"><use href="#i-key"/></svg><span>{{ __('ui.svc_cpanel_login') }}</span></a>
                        <a class="svc-qbtn" href="{{ lroute('account.services.cpanel', $s) }}?app=files" target="_blank" rel="noopener"><svg class="icon"><use href="#i-file"/></svg><span>{{ __('ui.svc_file_manager') }}</span></a>
                        <a class="svc-qbtn" href="{{ lroute('account.services.cpanel', $s) }}?app=db" target="_blank" rel="noopener"><svg class="icon"><use href="#i-db"/></svg><span>{{ __('ui.svc_databases') }}</span></a>
                        <a class="svc-qbtn" href="{{ lroute('account.services.cpanel', $s) }}?app=email" target="_blank" rel="noopener"><svg class="icon"><use href="#i-mail"/></svg><span>{{ __('ui.svc_emails') }}</span></a>
                      </div>

                      {{-- آمارِ زندهٔ سرویس (از پنل، بدونِ ورود به cPanel) --}}
                      <div class="svc-usage" data-stats="{{ lroute('account.services.stats', $s) }}">
                        <div class="svc-usage-load">{{ __('ui.svc_loading_stats') }}</div>
                      </div>

                      <div class="svc-cred">
                        <div><span>{{ __('ui.svc_cred_panel_url') }}</span>
                          @if($s->panel_url)<a href="{{ $s->panel_url }}" target="_blank" rel="noopener" dir="ltr">{{ $s->panel_url }}</a>
                          @elseif($s->server?->hostname)<b dir="ltr">{{ $s->server->hostname }}</b>@else<b>—</b>@endif</div>
                        @if($s->username)<div><span>{{ __('ui.svc_cred_username') }}</span><b dir="ltr" class="copyable" title="{{ __('ui.svc_copy_title') }}">{{ $s->username }}</b></div>@endif
                        @if($s->password)<div><span>{{ __('ui.svc_cred_password') }}</span>
                          <b dir="ltr" class="svc-pw"><span class="pw-mask">••••••••••</span><span class="pw-val" hidden>{{ $s->password }}</span>
                            <button type="button" class="pw-eye" data-show="{{ __('ui.svc_show') }}" data-hide="{{ __('ui.svc_hide') }}">{{ __('ui.svc_show') }}</button></b></div>@endif
                        @if($s->domain)<div><span>{{ __('ui.svc_cred_domain') }}</span><b dir="ltr">{{ $s->domain }}</b></div>@endif
                      </div>
                    @elseif($s->provision_status === 'failed')
                      <p class="svc-note warn">{{ __('ui.svc_provision_failed') }}</p>
                    @else
                      <p class="svc-note">{{ __('ui.svc_provision_pending') }}</p>
                    @endif
                  </div>
                </td></tr>
              @endif
            @endforeach
          </tbody>
        </table>
      </div>
    </div>
  @endif
</section>

<style>
.svc-detail{ padding:14px 16px; background:var(--surface-2); border-top:1px solid var(--line); }
.svc-login{ display:inline-flex; margin-bottom:12px; }
.svc-login .icon{ width:16px; height:16px; }
.svc-quick{ display:flex; gap:10px; flex-wrap:wrap; margin-bottom:14px; }
.svc-qbtn{ display:flex; flex-direction:column; align-items:center; gap:6px; text-decoration:none; padding:12px 14px; min-width:86px; border:1px solid var(--line); border-radius:13px; background:var(--surface); color:var(--text); font-size:12px; transition:transform .14s, border-color .16s; }
.svc-qbtn:hover{ transform:translateY(-2px); border-color:var(--brand,#22D3EE); }
.svc-qbtn.primary{ background:linear-gradient(135deg,#22D3EE,#3b82f6); color:#04121a; border-color:transparent; font-weight:700; }
.svc-qbtn .icon{ width:20px; height:20px; }
.svc-usage{ margin-bottom:14px; }
.svc-usage-load{ font-size:12px; color:var(--muted); padding:6px 0; }
.svc-usage-grid{ display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; }
.svc-stat{ border:1px solid var(--line); border-radius:12px; padding:12px 14px; background:var(--surface); }
.svc-stat .lbl{ font-size:11px; color:var(--muted); }
.svc-stat .val{ font-size:15px; color:var(--text); font-weight:700; margin-top:3px; font-variant-numeric:tabular-nums; }
.svc-bar{ height:7px; border-radius:20px; background:var(--line); overflow:hidden; margin-top:9px; }
.svc-bar > i{ display:block; height:100%; border-radius:20px; background:linear-gradient(90deg,#22D3EE,#3b82f6); transition:width .5s ease; }
.svc-bar > i.hot{ background:linear-gradient(90deg,#fbbf24,#ff6b6b); }
.svc-cred{ display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:10px 22px; }
.svc-cred > div{ display:flex; flex-direction:column; gap:3px; font-size:13px; }
.svc-cred span{ font-size:11px; color:var(--muted); }
.svc-cred b, .svc-cred a{ color:var(--text); word-break:break-all; }
.svc-cred a{ color:var(--info); text-decoration:none; }
.svc-pw{ display:inline-flex; align-items:center; gap:8px; }
.pw-eye{ background:var(--surface); border:1px solid var(--line); color:var(--info); border-radius:7px; padding:3px 9px; font:inherit; font-size:11px; cursor:pointer; }
.svc-note{ font-size:12.5px; color:var(--muted); line-height:2; margin:0; }
.svc-note.warn{ color:var(--warn); }
.copyable{ cursor:copy; }
</style>
<script>
document.querySelectorAll('.pw-eye').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var box = this.closest('.svc-pw'), mask = box.querySelector('.pw-mask'), val = box.querySelector('.pw-val');
    var show = val.hidden;
    val.hidden = !show; mask.hidden = show; this.textContent = this.getAttribute(show ? 'data-hide' : 'data-show');
  });
});
document.querySelectorAll('.svc-cred .copyable').forEach(function (el) {
  el.addEventListener('click', function () {
    var t = (this.textContent || '').trim();
    if (navigator.clipboard) navigator.clipboard.writeText(t);
    if (window.snToast) snToast(@json(__('ui.svc_copied')) + ' ✓', 'ok');
  });
});

// آمارِ زندهٔ هر سرویس را از پنل بگیر و نشان بده (بدونِ ورود به cPanel)
(function () {
  var faN = function (x) { return String(x).replace(/[0-9]/g, function (g) { return '۰۱۲۳۴۵۶۷۸۹'[g]; }); };
  var fmt = function (mb) { return mb >= 1024 ? faN((mb / 1024).toFixed(mb >= 10240 ? 0 : 1)) + ' گیگ' : faN(mb) + ' مگ'; };
  document.querySelectorAll('.svc-usage').forEach(function (box) {
    var url = box.getAttribute('data-stats');
    if (!url) return;
    fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok) { box.innerHTML = '<div class="svc-usage-load">آمار فعلاً در دسترس نیست.</div>'; return; }
        var used = d.disk_used || 0, lim = d.disk_limit;
        var pct = lim ? Math.min(100, Math.round(used / lim * 100)) : null;
        var bar = pct !== null ? '<div class="svc-bar"><i class="' + (pct >= 85 ? 'hot' : '') + '" style="width:' + pct + '%"></i></div>' : '';
        var disk = fmt(used) + (lim ? ' / ' + fmt(lim) : ' (نامحدود)') + (pct !== null ? ' · ' + faN(pct) + '٪' : '');
        var h = '<div class="svc-usage-grid">'
          + '<div class="svc-stat"><div class="lbl">فضای مصرفی</div><div class="val">' + disk + '</div>' + bar + '</div>'
          + '<div class="svc-stat"><div class="lbl">وضعیت</div><div class="val" style="color:' + (d.suspended ? '#ff6b6b' : '#34d399') + '">' + (d.suspended ? 'معلق' : 'فعال') + '</div></div>'
          + (d.ip ? '<div class="svc-stat"><div class="lbl">IP سرور</div><div class="val" dir="ltr" style="font-size:13px">' + d.ip + '</div></div>' : '')
          + '</div>';
        box.innerHTML = h;
      })
      .catch(function () { box.innerHTML = '<div class="svc-usage-load">آمار فعلاً در دسترس نیست.</div>'; });
  });
})();
</script>
@endsection

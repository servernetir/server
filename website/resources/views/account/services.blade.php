@extends('panel.layout')
@section('title', 'سرویس‌ها — سرورنت')

@section('panel')

<div class="pnl-head">
  <div>
    <h1>سرویس‌های من</h1>
    <p>سرویس‌ها و خدماتی که تهیه کرده‌اید.</p>
  </div>
</div>

<section class="pnl-sec">
  <div class="pnl-sec-h"><h2>سرویس‌ها</h2></div>

  @if($services->isEmpty())
    <div class="pnl-sec-b">
      <p style="font-size:13.5px;color:var(--muted);line-height:2;margin:0">
        هنوز سرویسی ندارید. وقتی سرویسی برایتان صادر شود، اینجا نمایش داده می‌شود.
      </p>
    </div>
  @else
    <div class="pnl-sec-b flush">
      <div class="pnl-tw">
        <table class="pnl-table">
          <thead>
            <tr><th>سرویس</th><th>دوره</th><th>وضعیت</th><th>سررسید بعدی</th><th class="num">مبلغ دوره</th><th></th></tr>
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
                <td class="num pnl-num">{{ fa_num(number_format($s->total())) }}</td>
                <td>
                  @if($unpaid)
                    <a class="pnl-btn primary" href="{{ lroute('account.invoice', $unpaid) }}">پرداخت</a>
                  @endif
                </td>
              </tr>
              @if($s->server_id)
                <tr class="svc-detail-row"><td colspan="6" style="padding:0;border:0">
                  <div class="svc-detail">
                    @if($s->provision_status === 'done')
                      <div class="svc-cred">
                        <div><span>آدرس ورود کنترل‌پنل</span>
                          @if($s->panel_url)<a href="{{ $s->panel_url }}" target="_blank" rel="noopener" dir="ltr">{{ $s->panel_url }}</a>
                          @elseif($s->server?->hostname)<b dir="ltr">{{ $s->server->hostname }}</b>@else<b>—</b>@endif</div>
                        @if($s->username)<div><span>نام‌کاربری</span><b dir="ltr" class="copyable" title="کپی">{{ $s->username }}</b></div>@endif
                        @if($s->password)<div><span>رمز عبور</span>
                          <b dir="ltr" class="svc-pw"><span class="pw-mask">••••••••••</span><span class="pw-val" hidden>{{ $s->password }}</span>
                            <button type="button" class="pw-eye">نمایش</button></b></div>@endif
                        @if($s->domain)<div><span>دامنه</span><b dir="ltr">{{ $s->domain }}</b></div>@endif
                      </div>
                    @elseif($s->provision_status === 'failed')
                      <p class="svc-note warn">در آماده‌سازیِ سرویس مشکلی پیش آمد؛ پشتیبانی در حالِ بررسی است. به‌زودی حل می‌شود.</p>
                    @else
                      <p class="svc-note">🔧 سرویسِ شما در حالِ آماده‌سازیِ خودکار است و اطلاعاتِ ورود تا لحظاتی دیگر همین‌جا نمایش داده می‌شود.</p>
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
    val.hidden = !show; mask.hidden = show; this.textContent = show ? 'پنهان' : 'نمایش';
  });
});
document.querySelectorAll('.svc-cred .copyable').forEach(function (el) {
  el.addEventListener('click', function () {
    var t = (this.textContent || '').trim();
    if (navigator.clipboard) navigator.clipboard.writeText(t);
    if (window.snToast) snToast('کپی شد ✓', 'ok');
  });
});
</script>
@endsection

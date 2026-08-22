@extends('admin.layout')
@section('title', 'زیرساختِ اکسیت')
@section('nav_exit_infra', 'on')
@section('content')

{{-- نوارِ ابزارِ اکسیت: وارد کردنِ VM + مدیریتِ رله/نودها --}}
<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">
  <a href="{{ route('admin.exit-infra.import') }}" class="ad-badge"
     style="background:rgba(34,211,238,.18);color:var(--text);padding:8px 14px;text-decoration:none">
    + وارد کردنِ VM (اسکنِ Proxmox یا دستی)
  </a>
  <a href="{{ route('admin.exit-upstreams') }}" class="ad-badge"
     style="background:rgba(148,163,184,.14);color:var(--text);padding:8px 14px;text-decoration:none">
    <svg class="icon" style="width:14px;height:14px"><use href="#i-server"/></svg>
    رله و نودهای اکسیت (SSH-VPN / VLESS)
  </a>
</div>

{{-- ── نوارِ سلامت: ضربانِ ایجنت‌ها + وضعیتِ توکن‌ها ── --}}
<div class="ad-panel">
  <div class="ad-panel-h"><h2>سلامتِ زیرساختِ اکسیت</h2></div>
  <p style="padding:0 18px 14px;color:var(--muted);font-size:13px;line-height:1.9">
    میزبانِ ایران هر چند دقیقه «حالتِ مطلوب» را از سرورِ اصلی می‌کشد. اگر ضربانِ
    زیر کهنه شود (بیش از ۵ دقیقه یا هرگز)، یعنی pull-agent نمی‌دود و مسیریابیِ
    خروجِ کشوری و port-forwardها به‌روز نمی‌شوند.
  </p>

  <div style="display:flex;flex-wrap:wrap;gap:10px;padding:0 18px 14px">
    @foreach([['ایجنتِ مسیرِ کشوری', $agents['countryroutes']], ['ایجنتِ port-forward', $agents['portforwards']]] as [$label, $a])
      @php $col = $a['stale'] ? '#ff6b6b' : '#34d399'; @endphp
      <span class="ad-badge" style="background:{{ $col }}22;color:{{ $col }};font-size:12.5px;padding:7px 12px;display:inline-flex;align-items:center;gap:6px">
        <svg class="icon" style="width:14px;height:14px"><use href="#i-{{ $a['stale'] ? 'x' : 'check' }}"/></svg>
        {{ $label }} —
        @if($a['seen'] === null)
          هرگز دیده نشده
        @else
          {{ fa_num($a['minutes']) }} دقیقه پیش
        @endif
      </span>
    @endforeach
  </div>

  {{-- توکن‌ها، فهرستِ کشورها و آی‌پیِ عمومی --}}
  <div style="display:flex;flex-wrap:wrap;gap:10px;padding:0 18px 18px">
    @foreach([['توکنِ pull-agent', $config['agent_token']], ['توکنِ Proxmox', $config['proxmox_token']]] as [$label, $set])
      @php $col = $set ? '#34d399' : '#fbbf24'; @endphp
      <span class="ad-badge" style="background:{{ $col }}22;color:{{ $col }};font-size:12.5px;padding:7px 12px">
        {{ $label }}: {{ $set ? 'تنظیم‌شده' : 'تنظیم‌نشده' }}
      </span>
    @endforeach
    <span class="ad-badge" dir="ltr" style="background:rgba(148,163,184,.14);color:var(--muted);font-size:12.5px;padding:7px 12px">
      exit countries: {{ $config['exit_countries'] }}
    </span>
    @if($publicIp !== '')
      <span class="ad-badge" dir="ltr" style="background:rgba(148,163,184,.14);color:var(--muted);font-size:12.5px;padding:7px 12px">
        public IP: {{ $publicIp }}
      </span>
    @endif
  </div>
</div>

{{-- ── شمارشِ Exit VPS به تفکیکِ کشورِ خروج ── --}}
@if($total > 0)
<div class="ad-panel" style="margin-top:16px">
  <div class="ad-panel-h"><h3>به تفکیکِ کشورِ خروج ({{ fa_num($total) }})</h3></div>
  <div style="display:flex;flex-wrap:wrap;gap:10px;padding:0 18px 18px">
    @foreach($countrySummary as $c)
      <span class="ad-badge" style="background:rgba(34,211,238,.14);color:var(--text);font-size:13px;padding:8px 13px">
        {{ $c['flag'] }} {{ $c['name'] }} — {{ fa_num($c['count']) }}
      </span>
    @endforeach
  </div>
</div>
@endif

{{-- ── جدولِ Exit VPSها ── --}}
<div class="ad-panel" style="margin-top:16px">
  <div class="ad-panel-h"><h2>Exit VPSها</h2></div>

  @if($total === 0)
    <p style="padding:16px 18px;color:var(--dim);font-size:13.5px">هنوز Exit VPSی نیست.</p>
  @else
    <div style="padding:0 4px 8px;overflow-x:auto">
      <table class="ad-table">
        <thead><tr>
          <th>کشورِ خروج</th><th>آی‌پیِ داخلی</th><th>دسترسیِ عمومی</th><th>پورت</th>
          <th>وضعیت</th><th>مشتری</th><th>سوییچِ کشور</th><th></th>
        </tr></thead>
        <tbody>
          @foreach($rows as $r)
            <tr>
              <td style="white-space:nowrap">{{ $r['flag'] }} {{ $r['country_name'] }}@if($r['protected'])<span title="خطِ‌قرمز" style="color:#ff6b6b"> 🔴</span>@endif</td>
              <td dir="ltr" style="font-size:12.5px">{{ $r['ipv4'] !== '' ? $r['ipv4'] : '—' }}</td>
              <td dir="ltr" style="font-size:12.5px;color:var(--muted)">{{ $r['public_host'] !== '' ? $r['public_host'] : '—' }}</td>
              <td>
                @if($r['protected'])
                  <span style="font-size:12px;color:var(--dim)">{{ $r['port'] > 0 ? fa_num($r['port']) : '—' }}</span>
                @else
                  <form method="post" action="{{ route('admin.exit-infra.port', $r['id']) }}" style="display:flex;gap:5px;align-items:center">
                    @csrf
                    <input type="number" name="port" min="1" max="65535" value="{{ $r['port'] > 0 ? $r['port'] : '' }}" placeholder="خودکار" dir="ltr"
                           style="width:74px;background:rgba(148,163,184,.10);color:var(--text);border:1px solid rgba(148,163,184,.3);border-radius:7px;padding:5px 7px;font-size:12px">
                    <button type="submit" title="ثبتِ پورت" class="ad-badge"
                            style="background:rgba(148,163,184,.16);color:var(--text);border:0;cursor:pointer;font-size:12px;padding:5px 8px">↵</button>
                  </form>
                @endif
              </td>
              <td><span class="ad-badge" style="background:{{ $r['status_color'] }}22;color:{{ $r['status_color'] }}">{{ $r['status_label'] }}</span></td>
              <td style="font-size:12.5px">
                @if($r['customer_name'])
                  {{ $r['customer_name'] }}
                  <div dir="ltr" style="font-size:11.5px;color:var(--dim)">{{ $r['customer_code'] }}</div>
                @else
                  <span style="color:var(--dim)">— بی‌مشتری</span>
                @endif
              </td>
              <td>
                @if($r['protected'])
                  <span title="خطِ‌قرمز — از پنل تغییر نمی‌کند" style="font-size:12px;color:#ff6b6b">🔴 قفل</span>
                @else
                  <form method="post" action="{{ route('admin.exit-infra.country', $r['id']) }}"
                        style="display:flex;gap:6px;align-items:center;flex-wrap:nowrap">
                    @csrf
                    <select name="country" dir="ltr"
                            style="background:rgba(148,163,184,.10);color:var(--text);border:1px solid rgba(148,163,184,.3);border-radius:8px;padding:5px 8px;font-size:12.5px">
                      @foreach($exitOptions as $opt)
                        <option value="{{ $opt['code'] }}" @selected($r['exit_cc'] === $opt['code'])>{{ $opt['flag'] }} {{ $opt['name'] }}</option>
                      @endforeach
                    </select>
                    <button type="submit" class="ad-badge"
                            style="background:rgba(34,211,238,.16);color:var(--text);border:0;cursor:pointer;font-size:12.5px;padding:6px 12px">اعمال</button>
                    @if($r['exit_override'])
                      <span title="کشور با override دستی تعیین شده" style="font-size:11px;color:var(--dim)">دستی</span>
                    @endif
                  </form>
                @endif
              </td>
              <td>
                @if($r['is_orphan'] && ! $r['protected'])
                  <form method="post" action="{{ route('admin.exit-infra.detach', $r['id']) }}" style="display:inline"
                        data-confirm="«{{ $r['ipv4'] ?: $r['id'] }}» از فهرستِ اکسیت حذف شود؟ (خودِ VM دست‌نخورده می‌ماند)" data-confirm-danger data-confirm-ok="حذف">
                    @csrf
                    <button type="submit" class="ad-badge" title="حذف از فهرست (نه از Proxmox)"
                            style="background:rgba(255,107,107,.13);color:#ff6b6b;border:0;cursor:pointer;font-size:11.5px;padding:5px 9px">حذف از فهرست</button>
                  </form>
                @endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  @endif
</div>
@endsection

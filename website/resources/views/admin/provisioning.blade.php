@extends('admin.layout')
@section('title', 'تحویل‌ها')
@section('nav_provisioning', 'active')
@section('content')

@php
  /** یک ردیفِ سفارش با تشخیص و دکمه‌های درست — بینِ چهار جدول مشترک */
  $row = function ($s) use ($diagnose) {
    [$what, $todo] = $diagnose($s);
    $isCloud = $s->isCloud();
    return compact('s', 'what', 'todo', 'isCloud');
  };
@endphp

<div class="ad-panel">
  <div class="ad-panel-h"><h2>تحویل‌ها</h2></div>

  <p style="padding:0 18px 8px;color:var(--muted);font-size:13px;line-height:1.9">
    هر سفارشی که پولش آمده و سرویسش هنوز دستِ مشتری نیست — با علت و کارِ بعدی.
    قاعده: <b>یا حتماً تحویل شود، یا اصلاً برای فروش موجود نباشد.</b>
  </p>

  {{-- ضربانِ کرون: اگر زمان‌بند نمی‌دود، هیچ صفی خالی نمی‌شود و همهٔ این
       صفحه علامتِ همان یک خرابی است — پس اول همین را بگو. --}}
  @php $stale = $heartbeat === null || $heartbeat->lt(now()->subMinutes(10)); @endphp
  <div style="margin:0 18px 14px;padding:9px 13px;border-radius:9px;font-size:12.5px;
              background:{{ $stale ? 'rgba(255,107,107,.09)' : 'rgba(52,211,153,.07)' }};
              border:1px solid {{ $stale ? 'rgba(255,107,107,.35)' : 'rgba(52,211,153,.25)' }}">
    {{ $stale ? '🔴' : '🟢' }} آخرین ضربانِ کرون:
    <b>{{ $heartbeat ? $heartbeat->diffForHumans() : 'هرگز' }}</b>
    @if($stale) — زمان‌بندِ سرور نمی‌دود؛ تا برنگردد هیچ تحویلِ خودکاری انجام نمی‌شود. @endif
    @if($lateQueue > 0)
      · ⚠️ {{ fa_num($lateQueue) }} سفارش بیش از ۳۰ دقیقه در صف مانده است.
    @endif
  </div>

  @if(session('ok'))<div class="ad-flash" style="margin:0 18px 12px">{{ session('ok') }}</div>@endif
  @if(session('err'))<div class="ad-flash err" style="margin:0 18px 12px">{{ session('err') }}</div>@endif
  @if($errors->any())<div class="ad-flash err" style="margin:0 18px 12px">{{ $errors->first() }}</div>@endif

  @foreach([
    ['failed', '🔴 خطا در تحویل', $failed,
      'زیرساخت «نه» گفته و کرون دیگر خودکار تلاش نمی‌کند — بدونِ اقدامِ شما همین‌جا می‌مانَد.'],
    ['manual', '🟣 منتظرِ تصمیمِ مدیر', $manual,
      'یا محافظِ سوءاستفاده نگهش داشته، یا این نوع سرویس تحویلِ دستی دارد.'],
    ['stuck', '🟠 وسطِ ساخت رها شده', $stuck,
      'پروسهٔ ساخت مرده (دیپلوی/ری‌استارت). فقط «تلاش دوباره» بیرونش می‌آورد.'],
    ['queued', '🔵 در صفِ تحویل', $queued,
      'کرونِ بعدی برمی‌دارد؛ ردیفِ کهنه یعنی هر بار به مانعی می‌خورد — علتش در ستونِ تشخیص است.'],
  ] as [$key, $title, $rows, $hint])
    <div style="padding:0 18px 4px">
      <h3 style="font-size:14px;margin:10px 0 4px">{{ $title }}
        <span style="color:var(--muted);font-weight:normal">({{ fa_num($rows->count()) }})</span>
      </h3>
      <p style="color:var(--muted);font-size:12px;margin:0 0 8px">{{ $hint }}</p>
    </div>

    @if($rows->isEmpty())
      <p style="padding:0 18px 14px;color:#34d399;font-size:12.5px">— خالی است.</p>
    @else
    <div style="padding:0 18px 16px">
      <table class="ad-table">
        <thead><tr><th>مشتری</th><th>سرویس</th><th>سن</th><th>تشخیص</th><th>اقدام</th></tr></thead>
        <tbody>
          @foreach($rows as $s)
            @php extract($row($s)); @endphp
            <tr>
              <td style="white-space:nowrap">
                <a href="/admin/customers/{{ $s->customer_id }}">{{ $s->customer?->code ?? '#'.$s->customer_id }}</a>
                <br><small style="color:var(--muted)">{{ $s->customer?->email }}</small>
              </td>
              <td>
                <a href="/admin/services/{{ $s->id }}/history"><b>{{ $s->name }}</b></a>
                <small style="color:var(--dim)" dir="ltr">#{{ $s->id }}</small>
                <br><small style="color:var(--muted)">
                  {{ $isCloud ? 'سرورِ ابری · '.($s->cloudPlan?->public_name ?? $s->cloud_plan_id) : ($s->server?->name ? 'هاست · '.$s->server->name : 'بدونِ سرورِ تحویل') }}
                </small>
              </td>
              <td style="white-space:nowrap;font-size:12px">{{ $s->created_at?->diffForHumans() ?? '—' }}</td>
              <td style="max-width:340px">
                <b style="font-size:12.5px">{{ $what }}</b>
                <div style="color:var(--muted);font-size:12px;line-height:1.8">{{ $todo }}</div>
                @if($s->provision_error)
                  <div style="color:#fbbf24;font-size:11px;margin-top:3px">{{ mb_substr($s->provision_error, 0, 160) }}</div>
                @endif
              </td>
              <td style="white-space:nowrap">
                @if($s->provision_status !== 'done' && $s->provision_status !== \App\Models\Service::PROVISION_RELEASING)
                  <form method="post" action="/admin/services/{{ $s->id }}/provision"
                        style="display:flex;gap:5px;align-items:center;flex-wrap:wrap"
                        @if($isCloud) data-confirm="تلاشِ دوبارهٔ سرورِ ابری = خریدِ واقعی از زیرساخت. ادامه؟" data-confirm-danger @endif>@csrf
                    @unless($isCloud)
                      @unless($s->server_id)
                        <select name="server_id" required style="background:var(--surface2);border:1px solid var(--line);border-radius:7px;color:var(--text);padding:4px 6px;font:inherit;font-size:12px">
                          <option value="">سرور…</option>@foreach($servers as $srv)<option value="{{ $srv->id }}">{{ $srv->name }}</option>@endforeach
                        </select>
                      @endunless
                    @endunless
                    <button class="del" style="color:#34d399" type="submit">تلاش دوباره</button>
                  </form>
                  @if($isCloud && $s->provision_status === 'manual')
                    <form method="post" action="/admin/services/{{ $s->id }}/provision-override" style="display:inline"
                          data-confirm="محافظِ سوءاستفاده برای همین یک سفارش کنار گذاشته شود و سرور ساخته شود؟ این کار ثبت می‌شود." data-confirm-danger>@csrf
                      <button class="del" style="color:#fbbf24" type="submit">تأیید و ساخت</button>
                    </form>
                  @endif
                @endif
                <a href="/admin/customers/{{ $s->customer_id }}" style="font-size:12px;display:inline-block;margin-top:4px">پروفایل (لغو/بازگشتِ وجه) ←</a>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    @endif
  @endforeach
</div>

{{-- ══ سلامتِ کاتالوگ: نفروش اگر تحویل‌دادنی نیست ══ --}}
<div class="ad-panel" style="margin-top:16px">
  <div class="ad-panel-h"><h2>سلامتِ کاتالوگ</h2></div>
  <p style="padding:0 18px 12px;color:var(--muted);font-size:13px;line-height:1.9">
    چیزی که الان می‌فروشیم ولی زیرساخت نمی‌سازد، این‌جا بالا می‌آید — قبل از
    اینکه مشتریِ بعدی همان شکست را بخرد.
  </p>

  {{-- قرنطینه‌ها --}}
  <div style="padding:0 18px 14px">
    <h3 style="font-size:14px;margin:0 0 8px">پلن‌های از فروش برداشته‌شده</h3>
    @if($catalog['quarantined']->isEmpty())
      <p style="color:#34d399;font-size:12.5px;margin:0">— هیچ پلنی بسته نیست.</p>
    @else
      <table class="ad-table">
        <thead><tr><th>زیرساخت</th><th>تعداد</th><th>علت</th><th></th></tr></thead>
        <tbody>
          @foreach($catalog['quarantined'] as $q)
          <tr>
            <td>{{ $q['provider'] }}</td>
            <td>{{ fa_num($q['count']) }}</td>
            <td style="font-size:12px;color:var(--muted);max-width:360px">
              {{ $q['auto'] ? '🤖 ' : '' }}{{ mb_substr($q['note'], 0, 140) ?: '—' }}
            </td>
            <td><a href="/admin/cloud?state=off" style="font-size:12px">بازبینی در زیرساختِ ابری ←</a></td>
          </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>

  {{-- پلن‌های پرخطا --}}
  <div style="padding:0 18px 14px">
    <h3 style="font-size:14px;margin:0 0 8px">پلن‌های پرخطا (۲+ شکست در ۱۴ روز)</h3>
    @if($catalog['failing']->isEmpty())
      <p style="color:#34d399;font-size:12.5px;margin:0">— پلنِ پرخطایی نیست.</p>
    @else
      <table class="ad-table">
        <thead><tr><th>پلن</th><th>زیرساخت / مکان</th><th>شکست</th><th>وضعیتِ فروش</th><th></th></tr></thead>
        <tbody>
          @foreach($catalog['failing'] as $f)
          <tr>
            <td><b>{{ $f['plan']->public_name }}</b> <small dir="ltr" style="color:var(--dim)">{{ $f['plan']->slug }}</small></td>
            <td>{{ $f['plan']->provider }} / {{ $f['plan']->location_code }}</td>
            <td>{{ fa_num($f['fails']) }}</td>
            <td>{{ $f['plan']->admin_disabled ? 'بسته' : ($f['plan']->blockedReason() !== null ? 'موقتاً ناموجود' : '⚠️ هنوز در فروش') }}</td>
            <td>
              @unless($f['plan']->admin_disabled)
                <form method="post" action="/admin/cloud/plans/{{ $f['plan']->id }}/toggle" style="display:inline"
                      data-confirm="فروشِ این پلن بسته شود؟ (مشتریِ بعدی دیگر نمی‌تواند بخردش)" data-confirm-danger>@csrf
                  <input type="hidden" name="note" value="بسته از مرکزِ تحویل‌ها: {{ $f['fails'] }} شکست در ۱۴ روز">
                  <button class="del" style="color:#ff6b6b" type="submit">توقفِ فروش</button>
                </form>
              @endunless
            </td>
          </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>

  {{-- مکانِ بی‌سیستم‌عامل + ظرفیتِ WHM --}}
  <div style="padding:0 18px 18px;font-size:12.5px;line-height:2">
    @if($catalog['noImage']->isNotEmpty())
      <div style="color:#ff6b6b">⚠️ مکان‌های با پلنِ فروختنی ولی <b>بدونِ سیستم‌عاملِ قابلِ انتخاب</b>
        (خرید عملاً ناممکن): <span dir="ltr">{{ $catalog['noImage']->implode('، ') }}</span></div>
    @else
      <div style="color:#34d399">✓ هر مکانِ در فروش دست‌کم یک سیستم‌عاملِ قابلِ انتخاب دارد.</div>
    @endif
    @if($catalog['whmOpen'] !== null)
      <div style="color:{{ $catalog['whmOpen']['open'] > 0 ? '#34d399' : '#ff6b6b' }}">
        {{ $catalog['whmOpen']['open'] > 0 ? '✓' : '⚠️' }}
        سرورهای هاست: {{ fa_num($catalog['whmOpen']['open']) }} از {{ fa_num($catalog['whmOpen']['total']) }}
        پذیرندهٔ حسابِ تازه{{ $catalog['whmOpen']['open'] === 0 && $catalog['whmOpen']['total'] > 0 ? ' — فروشِ هاست الان تحویل‌ناپذیر است!' : '' }}
      </div>
    @endif
  </div>
</div>

@endsection

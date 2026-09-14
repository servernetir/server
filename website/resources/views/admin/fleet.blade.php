@extends('admin.layout')
@section('title', 'ناوگان زیرساخت')
@section('nav_fleet', 'on')
@section('content')

@php
  /*
  | نمایشِ پول — یک تعریف برای کلِ صفحه.
  |
  | ⚠️ نرخِ ارز که نبود، **فقط یورو** نشان داده می‌شود. عددِ تومانیِ ساخته‌شده با
  | نرخِ صفر یعنی «۰ تومان ضرر می‌دهیم» — بدترین دروغِ ممکن در صفحه‌ای که کلِ
  | کارش نشان‌دادنِ ضرر است.
  */
  $eur = function (?int $cents) use ($eurRate) {
      $cents = (int) $cents;
      $out = '€'.number_format($cents / 100, 2);

      if ($eurRate > 0 && $cents > 0) {
          $out .= ' <span style="color:var(--dim);font-size:11px">('.fa_num(number_format((int) round($cents / 100 * $eurRate))).' ت)</span>';
      }

      return $out;
  };

  $inp = 'background:var(--surface2);border:1px solid var(--line);border-radius:8px;color:var(--text);padding:7px 10px;font:inherit;font-size:12.5px';
  $s   = $summary;
@endphp

@if($needsMigration)
  <div class="ad-panel">
    <div class="ad-panel-h"><h2>ناوگان زیرساخت</h2></div>
    <p style="color:var(--muted);font-size:13.5px;line-height:2">
      جدولِ <code dir="ltr">infra_assets</code> هنوز روی این نصب ساخته نشده.
      این صفحه تا اجرای مهاجرت‌ها خالی می‌مانَد — ولی هیچ‌جای دیگرِ پنل آسیب نمی‌بیند.
      پس از اجرای مهاجرت، دکمهٔ «اسکن زنده» را بزنید تا اولین عکسِ ناوگان گرفته شود.
    </p>
  </div>
@else

{{-- ══════════ سرصفحه: تازگیِ داده + کنش‌ها ══════════ --}}
<div class="ad-panel">
  <div class="ad-panel-h" style="flex-wrap:wrap;gap:10px">
    <div>
      <h2 style="margin:0">ناوگان زیرساخت</h2>
      <p style="margin:6px 0 0;color:var(--muted);font-size:12.5px;line-height:1.9;max-width:70ch">
        هر ماشینی که پولش را ما به یک زیرساخت می‌دهیم — و اینکه کدامشان درآمد دارند.
        داده از آخرین اسکن خوانده می‌شود، نه زنده؛ برای همین سریع است و تاریخش همیشه این‌جاست.
      </p>
    </div>

    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-inline-start:auto">
      @php
        $scanLabel = $lastScan === null
            ? 'هرگز اسکن نشده'
            : 'آخرین اسکن: '.sdate($lastScan['at'], true);
        $scanCol = $stale ? '#ff6b6b' : '#34d399';
      @endphp
      <span class="ad-badge" style="background:{{ $scanCol }}22;color:{{ $scanCol }};font-size:12px;padding:7px 12px">
        {{ $scanLabel }}
      </span>

      <form method="post" action="/admin/fleet/scan" style="display:inline">
        @csrf
        <button class="btn btn-glass" style="font-size:12.5px;padding:8px 14px">اسکن زنده</button>
      </form>

      <a class="btn btn-ghost" style="font-size:12.5px;padding:8px 14px"
         href="/admin/fleet/export?{{ http_build_query(array_filter($filters)) }}">خروجی CSV</a>
    </div>
  </div>

  @if($lastScan !== null && ! empty($lastScan['errors']))
    <div class="ad-flash err" style="margin:0 0 14px">
      در آخرین اسکن این زیرساخت‌ها پاسخ ندادند:
      <b>{{ implode('، ', array_map($realLabel, array_keys($lastScan['errors']))) }}</b>.
      ردیف‌هایشان عمداً دست‌نخورده ماند — تا رفعِ خطا، «بی‌صاحب ندارید» را باور نکنید.
    </div>
  @endif

  @if($stale)
    <div class="ad-flash" style="background:rgba(251,191,36,.1);border:1px solid rgba(251,191,36,.3);color:#fbbf24;margin:0 0 14px">
      این عکس کهنه است. پیش از هر تصمیمِ حذف، یک «اسکن زنده» بزنید.
    </div>
  @endif

  {{-- ══ کاشی‌های کلیدی — روی کلِ ناوگان، نه روی فیلتر ══ --}}
  <div class="ad-stats" style="margin-bottom:14px">
    <div class="ad-stat">
      <b>{{ fa_num($s['live'] ?? 0) }}</b>
      <span>ماشینِ زنده نزد زیرساخت‌ها</span>
    </div>
    <div class="ad-stat">
      <b style="color:var(--green)">{{ fa_num($s['attached'] ?? 0) }}</b>
      <span>متصل به مشتریِ فعال</span>
    </div>
    <div class="ad-stat">
      <b style="color:var(--amber)">{{ fa_num($s['orphan'] ?? 0) }}</b>
      <span>بی‌صاحب — هیچ سرویسی نمی‌شناسدشان</span>
    </div>
    <div class="ad-stat">
      <b style="color:var(--red)">{{ fa_num($s['zombie'] ?? 0) }}</b>
      <span>سرویس بسته شد، ماشین باز ماند</span>
    </div>
    <div class="ad-stat">
      <b style="color:#a78bfa">{{ fa_num($s['ghost'] ?? 0) }}</b>
      <span>سرویس هست، ماشین نیست</span>
    </div>
    <div class="ad-stat" style="border-color:rgba(255,107,107,.4)">
      <b style="color:var(--red);font-size:22px">{!! $eur($s['leak_cents'] ?? 0) !!}</b>
      <span>نشتیِ ماهانه — پولی که بی‌درآمد می‌رود</span>
    </div>
  </div>

  <div style="display:flex;flex-wrap:wrap;gap:9px;font-size:12.5px">
    <span class="ad-badge" style="background:rgba(148,163,184,.14);color:var(--muted);padding:7px 12px">
      کلِ هزینهٔ ماهانهٔ ناوگان: {!! $eur($s['monthly_cents'] ?? 0) !!}
    </span>
    <span class="ad-badge" style="background:rgba(255,107,107,.12);color:var(--red);padding:7px 12px">
      تا امروز بابتِ ماشین‌های رهاشده داده‌ایم: {!! $eur($s['wasted_cents'] ?? 0) !!}
    </span>
    @if(($s['owned_cents'] ?? 0) > 0)
      <span class="ad-badge" style="background:rgba(34,211,238,.12);color:var(--cyan);padding:7px 12px">
        زیرساختِ داخلیِ خودمان: {!! $eur($s['owned_cents']) !!} در ماه
      </span>
    @endif
    @if(($s['todo'] ?? 0) > 0)
      <a href="/admin/fleet?todo=1" class="ad-badge"
         style="background:rgba(251,191,36,.16);color:var(--amber);padding:7px 12px;text-decoration:none">
        {{ fa_num($s['todo']) }} ماشین منتظرِ تصمیمِ شماست ←
      </a>
    @endif
    @if(($s['unpriced'] ?? 0) > 0)
      <span class="ad-badge" style="background:rgba(148,163,184,.14);color:var(--dim);padding:7px 12px">
        بهایِ {{ fa_num($s['unpriced']) }} ماشین را نمی‌دانیم — اعدادِ بالا از واقع کمترند
      </span>
    @endif
  </div>
</div>

{{-- ══════════ شکست به تفکیک زیرساخت ══════════ --}}
@if($byProvider)
<div class="ad-panel">
  <div class="ad-panel-h"><h3>به تفکیکِ زیرساخت</h3></div>
  <div style="display:flex;flex-wrap:wrap;gap:10px">
    @foreach($byProvider as $p)
      <a href="/admin/fleet?provider={{ $p['provider'] }}"
         style="text-decoration:none;background:var(--surface2);border:1px solid var(--line2);
                border-radius:12px;padding:12px 15px;min-width:190px;display:block">
        <div style="font-size:13px;font-weight:700;color:var(--text)">{{ $p['label'] }}</div>
        <div style="font-size:12px;color:var(--muted);margin-top:5px">
          {{ fa_num($p['n']) }} ماشین · {!! $eur($p['cost']) !!} در ماه
        </div>
        @if($p['leaking'] > 0)
          <div style="font-size:11.5px;color:var(--red);margin-top:4px">
            {{ fa_num($p['leaking']) }} تای آن بی‌درآمد
          </div>
        @endif
      </a>
    @endforeach
  </div>
</div>
@endif

{{-- ══════════ جست‌وجو و فیلتر ══════════ --}}
<div class="ad-panel">
  <form method="get" action="/admin/fleet" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
    <input type="search" name="q" value="{{ $filters['q'] }}" dir="auto"
           placeholder="آی‌پی، نام ماشین، شناسه، کد یا ایمیل مشتری، شمارهٔ سرویس…"
           style="{{ $inp }};flex:1;min-width:280px">

    <select name="state" style="{{ $inp }}">
      <option value="">اتصال: همه</option>
      <option value="attached" @selected($filters['state']==='attached')>متصل به مشتری</option>
      <option value="orphan"   @selected($filters['state']==='orphan')>بی‌صاحب</option>
      <option value="zombie"   @selected($filters['state']==='zombie')>سرویس بسته، ماشین باز</option>
      <option value="ghost"    @selected($filters['state']==='ghost')>ماشین ناپدید</option>
    </select>

    <select name="provider" style="{{ $inp }}">
      <option value="">زیرساخت: همه</option>
      @foreach($providers as $slug => $label)
        <option value="{{ $slug }}" @selected($filters['provider']===$slug)>{{ $label }}</option>
      @endforeach
    </select>

    <select name="status" style="{{ $inp }}">
      <option value="">وضعیت ماشین: همه</option>
      @foreach(['running'=>'روشن','off'=>'خاموش','building'=>'در حال ساخت','error'=>'خطا','deleted'=>'حذف‌شده','unknown'=>'نامشخص'] as $k => $v)
        <option value="{{ $k }}" @selected($filters['status']===$k)>{{ $v }}</option>
      @endforeach
    </select>

    <select name="role" style="{{ $inp }}">
      <option value="">نقش: همه</option>
      @foreach(\App\Models\InfraAsset::ROLES as $k => $v)
        <option value="{{ $k }}" @selected($filters['role']===$k)>{{ $v }}</option>
      @endforeach
    </select>

    <select name="sort" style="{{ $inp }}">
      <option value="">مرتب‌سازی: قدیمی‌ترین رهاشده</option>
      <option value="cost"    @selected($filters['sort']==='cost')>گران‌ترین</option>
      <option value="created" @selected($filters['sort']==='created')>تاریخ ساخت</option>
      <option value="seen"    @selected($filters['sort']==='seen')>آخرین رؤیت</option>
      <option value="name"    @selected($filters['sort']==='name')>نام ماشین</option>
      <option value="ip"      @selected($filters['sort']==='ip')>آی‌پی</option>
      <option value="state"   @selected($filters['sort']==='state')>وضعیت اتصال</option>
    </select>

    <label style="display:flex;align-items:center;gap:6px;color:var(--muted);font-size:12.5px;cursor:pointer">
      <input type="checkbox" name="todo" value="1" @checked($filters['todo']==='1')>
      فقط نیازمندِ تصمیم
    </label>

    <button class="btn btn-glass" style="font-size:12.5px;padding:8px 16px">اعمال</button>
    @if(array_filter($filters))
      <a href="/admin/fleet" class="btn btn-ghost" style="font-size:12.5px;padding:8px 14px">پاک‌کردن</a>
    @endif
  </form>
</div>

{{-- ══════════ جدول ══════════ --}}
<div class="ad-panel">
  <div class="ad-panel-h">
    <h3>{{ fa_num($assets->total()) }} ماشین</h3>
    @if($assets->total() > 0)
      <span style="color:var(--dim);font-size:12px">صفحهٔ {{ fa_num($assets->currentPage()) }} از {{ fa_num($assets->lastPage()) }}</span>
    @endif
  </div>

  @if($assets->total() === 0)
    <p style="color:var(--dim);font-size:13.5px;padding:10px 0">
      @if($lastScan === null)
        هنوز اسکنی انجام نشده. دکمهٔ «اسکن زنده» را بزنید.
      @else
        با این فیلترها چیزی پیدا نشد.
      @endif
    </p>
  @else
  <div style="overflow-x:auto">
    {{-- 🔴 `data-no-enhance`: فیلترِ عمومیِ جدول‌ها فقط ردیف‌های **همین صفحه** را
         می‌بیند، ولی جست‌وجوی این صفحه سمتِ سرور و روی کلِ ناوگان است. دو کادرِ
         جست‌وجو با دو دامنهٔ متفاوت روی یک صفحه یعنی مدیر نتیجهٔ ناقص را کامل
         فرض می‌کند — دقیقاً همان دروغی که آن فایل خودش علیه‌اش هشدار می‌دهد. --}}
    <table class="ad-table" data-no-enhance>
      <thead>
        <tr>
          <th>ماشین</th>
          <th>آی‌پی</th>
          <th>مشخصات</th>
          <th>وضعیت</th>
          <th>اتصال</th>
          <th>مشتری / سرویس</th>
          <th>هزینهٔ ماهانه</th>
          <th>بی‌صاحب از</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        @foreach($assets as $a)
          @php
            $svc  = $a->service_id ? ($services[$a->service_id] ?? null) : null;
            $cust = $a->customer_id ? ($customers[$a->customer_id] ?? null) : null;
            $idle = $a->idleDays();
          @endphp
          <tr @class(['fl-todo' => $a->needsDecision()])>
            <td>
              <b style="font-size:13px">{{ $a->name ?: '—' }}</b>
              <div dir="ltr" style="font-size:11px;color:var(--dim)">{{ $a->provider_ref }}</div>
              <div style="font-size:11px;color:var(--dim)">{{ $realLabel($a->provider) }}</div>
            </td>

            <td dir="ltr" style="font-size:12.5px">
              {{ $a->ipv4 ?: '—' }}
              @if($a->ip_mismatch)
                <div style="font-size:11px;color:var(--amber)" dir="rtl">با آی‌پیِ پنلِ مشتری نمی‌خوانَد</div>
              @endif
            </td>

            <td style="font-size:12px;color:var(--muted)">
              {{ $a->plan_ref ?: '—' }}
              <div style="font-size:11px;color:var(--dim)">{{ $a->location_ref ?: '—' }}</div>
            </td>

            <td>
              <span class="ad-badge" style="background:{{ $a->statusColor() }}22;color:{{ $a->statusColor() }}">
                {{ $a->provider_status }}
              </span>
              @if($a->provider_created_at)
                <div style="font-size:11px;color:var(--dim);margin-top:4px">{{ sdate($a->provider_created_at) }}</div>
              @endif
            </td>

            <td>
              <span class="ad-badge" style="background:{{ $a->stateColor() }}22;color:{{ $a->stateColor() }}">
                {{ $a->stateLabel() }}
              </span>
              @if($a->role !== 'unknown')
                <div style="font-size:11px;color:var(--cyan);margin-top:4px">{{ \App\Models\InfraAsset::ROLES[$a->role] }}</div>
              @endif
              @if($a->acknowledged_at)
                <div style="font-size:11px;color:var(--dim);margin-top:2px">تأییدشده</div>
              @endif
            </td>

            <td style="font-size:12px">
              @if($cust)
                <a class="t" href="/admin/customers/{{ $cust->id }}" dir="ltr">{{ $cust->code ?: $cust->email }}</a>
              @else
                <span style="color:var(--dim)">—</span>
              @endif
              @if($svc)
                <div style="font-size:11.5px;color:var(--muted);margin-top:3px">
                  <a class="t" href="/admin/services/{{ $svc->id }}/history">#{{ fa_num($svc->id) }} {{ $svc->name }}</a>
                  <span style="color:var(--dim)">({{ $svc->status }})</span>
                </div>
              @elseif($a->service_id)
                <div style="font-size:11.5px;color:var(--dim);margin-top:3px">سرویس #{{ fa_num($a->service_id) }} پاک شده</div>
              @endif
            </td>

            <td style="font-size:12.5px;white-space:nowrap">
              {!! $eur($a->cost_eur_cents) !!}
              @if($a->cost_eur_cents === 0)
                <div style="font-size:11px;color:var(--dim)">نامعلوم</div>
              @endif
            </td>

            <td style="font-size:12.5px;white-space:nowrap">
              @if($idle === null)
                <span style="color:var(--dim)">—</span>
              @else
                <b style="color:{{ $idle >= 7 ? 'var(--red)' : 'var(--amber)' }}">{{ fa_num($idle) }} روز</b>
                @if($a->wastedEurCents() > 0)
                  <div style="font-size:11px;color:var(--red)">{!! $eur($a->wastedEurCents()) !!} سوخته</div>
                @endif
              @endif
            </td>

            <td class="ad-row-act">
              <button type="button" class="btn btn-ghost fl-toggle" data-row="fl-{{ $a->id }}"
                      style="font-size:12px;padding:6px 12px">مدیریت</button>
            </td>
          </tr>

          {{-- ردیفِ کشویی: طبقه‌بندی، یادداشت و — فقط برای بی‌درآمدها — حذفِ واقعی --}}
          <tr class="fl-edit" id="fl-{{ $a->id }}">
            <td colspan="9" style="background:var(--surface2)">
              <div style="display:flex;flex-wrap:wrap;gap:22px;align-items:flex-start">

                <form method="post" action="/admin/fleet/{{ $a->id }}/annotate"
                      style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;flex:1;min-width:320px">
                  @csrf
                  <select name="role" style="{{ $inp }}">
                    @foreach(\App\Models\InfraAsset::ROLES as $k => $v)
                      <option value="{{ $k }}" @selected($a->role === $k)>{{ $v }}</option>
                    @endforeach
                  </select>
                  <input type="text" name="note" value="{{ $a->note }}" maxlength="500"
                         placeholder="یادداشت — این ماشین برای چیست؟"
                         style="{{ $inp }};flex:1;min-width:220px">
                  <label style="display:flex;align-items:center;gap:6px;color:var(--muted);font-size:12.5px;cursor:pointer">
                    <input type="checkbox" name="ack" value="1" @checked($a->acknowledged_at !== null)>
                    دیدم و لازم نیست دوباره هشدار بدهد
                  </label>
                  <button class="btn btn-glass" style="font-size:12px;padding:7px 14px">ذخیره</button>
                </form>

                @if($a->link_state === \App\Models\InfraAsset::STATE_ORPHAN)
                  <a class="btn btn-ghost" style="font-size:12px;padding:7px 14px;align-self:center"
                     href="/admin/cloud/attach?ref={{ urlencode($a->provider_ref) }}&sname={{ urlencode((string) $a->name) }}">
                    وصل به مشتری
                  </a>
                @endif

                @if(in_array($a->link_state, \App\Models\InfraAsset::LEAKING_STATES, true))
                  {{-- 🔴 حذفِ واقعی نزدِ زیرساخت. نامِ دقیق باید تایپ شود؛
                       روی صفحه‌ای با ده‌ها ردیف، «تأیید می‌کنید؟» دیر یا زود
                       روی ردیفِ اشتباه زده می‌شود. --}}
                  <form method="post" action="/admin/fleet/{{ $a->id }}/release"
                        style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"
                        data-confirm="ماشینِ «{{ $a->name }}» برای همیشه نزدِ زیرساخت حذف می‌شود و برگشتی ندارد. مطمئنید؟"
                        data-confirm-title="حذفِ ماشین نزدِ زیرساخت"
                        data-confirm-ok="بله، حذف کن"
                        data-confirm-danger>
                    @csrf
                    <input type="text" name="confirm" dir="ltr" autocomplete="off"
                           placeholder="برای حذف، «{{ $a->name }}» را تایپ کنید"
                           style="{{ $inp }};min-width:240px;border-color:rgba(255,107,107,.4)">
                    <button class="btn" style="font-size:12px;padding:7px 14px;background:rgba(255,107,107,.14);
                            border:1px solid rgba(255,107,107,.45);color:#ff6b6b">حذف نزد زیرساخت</button>
                  </form>
                @endif
              </div>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>

  <div style="margin-top:16px">{{ $assets->onEachSide(1)->links() }}</div>
  @endif
</div>

<style>
  /* ⚠️ کلاس و نه صفتِ `hidden`: هر قاعدهٔ `display` از سمتِ نویسنده بر `[hidden]`
     غلبه می‌کند و ردیف بی‌آنکه کسی بفهمد باز می‌مانَد. */
  .fl-edit{display:none}
  .fl-edit.on{display:table-row}
  .fl-todo>td{box-shadow:inset 3px 0 0 var(--amber)}
  html[dir=ltr] .fl-todo>td{box-shadow:inset -3px 0 0 var(--amber)}
</style>

<script>
(function () {
  document.querySelectorAll('.fl-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var row = document.getElementById(btn.dataset.row);
      if (row) row.classList.toggle('on');
    });
  });
})();
</script>

@endif
@endsection

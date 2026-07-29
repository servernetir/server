@extends('admin.layout')
@section('title', 'مشتری ' . $c->code)
@section('nav_customers', 'on')
@section('content')

@php
  $iv = $c->identityVerification;
  $stMap = ['active'=>['فعال','#34d399'],'pending'=>['در انتظار','#fbbf24'],'suspended'=>['معلق','#ff6b6b'],'closed'=>['بسته','var(--dim)']];
  $st = $stMap[$c->status] ?? [$c->status,'var(--muted)'];
  $money = fn($v) => fa_num(number_format((int)$v)).' ت';
@endphp

<div style="margin-bottom:14px"><a href="/admin/customers" style="color:var(--muted);font-size:13px">→ بازگشت به مشتریان</a></div>


{{-- ══ سربرگ پرونده ══ --}}
<div class="cust-head">
  <div>
    <h2 style="margin:0;font-size:22px">{{ $c->displayName() }}</h2>
    <div style="color:var(--dim);margin-top:4px" dir="ltr">{{ $c->code }} · عضویت {{ sdate($c->created_at) }}</div>
  </div>
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <span class="ad-badge" style="background:{{ $st[1] }}22;color:{{ $st[1] }};font-size:13px;padding:6px 14px">{{ $st[0] }}</span>
    <a class="btn btn-glass" href="/admin/broadcasts?customer={{ $c->id }}"><svg class="icon"><use href="#i-message"/></svg>ارسال اعلان</a>
  </div>
</div>

{{-- ══ آمار سریع ══ --}}
<div class="cust-kpis">
  <div class="cust-kpi"><b style="color:#34d399">{{ $money($creditBalance) }}</b><span>موجودی اعتبار</span></div>
  <div class="cust-kpi"><b>{{ fa_num($invoiceTotals['count']) }}</b><span>فاکتور ({{ fa_num($invoiceTotals['unpaid']) }} پرداخت‌نشده)</span></div>
  <div class="cust-kpi"><b>{{ $money($invoiceTotals['paid']) }}</b><span>مجموع پرداخت‌شده</span></div>
  <div class="cust-kpi"><b>{{ fa_num($c->tickets->count()) }}</b><span>تیکت</span></div>
</div>

<div class="ad-grid2">
  {{-- ══ هویت و احراز ══ --}}
  <div class="ad-panel" style="margin:0">
    <div class="ad-panel-h"><h3>هویت و احراز</h3>
      @if($iv && $iv->status === 'verified')<span class="ad-badge" style="background:rgba(52,211,153,.15);color:#34d399">احرازشده</span>
      @elseif($iv)<span class="ad-badge" style="background:rgba(251,191,36,.15);color:#fbbf24">{{ $iv->status }}</span>
      @else<span class="ad-badge" style="background:rgba(95,108,130,.15);color:var(--muted)">انجام نشده</span>@endif
    </div>
    <div class="kv">
      @if($iv)
        <div><span>نام رسمی</span><b>{{ trim($iv->first_name.' '.$iv->last_name) ?: '—' }}</b></div>
        <div><span>نام پدر</span><b>{{ $iv->father_name ?: '—' }}</b></div>
        <div><span>کد ملی</span><b dir="ltr">••••• رمزنگاری‌شده</b></div>
        <div><span>تاریخ تولد</span><b dir="ltr">{{ $iv->birth_date ?: '—' }}</b></div>
        <div><span>شاهکار</span><b>{{ $iv->shahkar_matched ? 'تطابق موبایل ✓' : 'تطابق نشد' }}</b></div>
        @if($iv->fail_reason)<div><span>دلیل رد</span><b style="color:#ff6b6b">{{ $iv->fail_reason }}</b></div>@endif
      @else
        <div style="color:var(--dim);padding:8px 0">این مشتری هنوز احراز هویت نکرده است.</div>
      @endif
    </div>
  </div>

  {{-- ══ حساب و تماس ══ --}}
  <div class="ad-panel" style="margin:0">
    <div class="ad-panel-h"><h3>حساب و تماس</h3></div>
    <div class="kv">
      <div><span>موبایل</span><b dir="ltr">{{ $c->phone ?: '—' }} @if($c->phone_verified_at)<i style="color:#34d399;font-style:normal">✓</i>@endif</b></div>
      <div><span>ایمیل</span><b dir="ltr">{{ $c->email ?: '—' }} @if($c->email_verified_at)<i style="color:#34d399;font-style:normal">✓</i>@endif</b></div>
      <div><span>زبان</span><b>{{ ['fa'=>'فارسی','en'=>'انگلیسی','tr'=>'ترکی'][$c->locale] ?? $c->locale }}</b></div>
      <div><span>آخرین ورود</span><b dir="ltr">{{ stime($c->last_login_at) ?: '—' }}</b></div>
      <div><span>آخرین IP</span><b dir="ltr">{{ $c->last_login_ip ?: '—' }}</b></div>
      @if($c->locked_until && $c->locked_until->isFuture())<div><span>قفل تا</span><b style="color:#ff6b6b" dir="ltr">{{ stime($c->locked_until) }}</b></div>@endif
    </div>
  </div>
</div>

{{-- ══ حساب‌های بانکی ══ --}}
@if($c->bankAccounts->isNotEmpty())
<div class="ad-panel">
  <div class="ad-panel-h"><h3>حساب‌های بانکی</h3></div>
  <table class="ad-table">
    <thead><tr><th>بانک</th><th>شبا</th><th>صاحب حساب</th><th>وضعیت</th></tr></thead>
    <tbody>
      @foreach($c->bankAccounts as $b)
      <tr>
        <td>{{ $b->bank_name ?: '—' }} <small style="color:var(--dim)" dir="ltr">{{ $b->card_bin }}••••</small></td>
        <td dir="ltr" style="color:var(--muted)">{{ $b->iban ?: '—' }}</td>
        <td>{{ $b->owner_name ?: '—' }} @if($b->name_matched)<i style="color:#34d399">✓</i>@endif</td>
        <td><span class="ad-badge {{ $b->status === 'verified' ? 'pub' : 'draft' }}">{{ $b->status === 'verified' ? 'تأییدشده' : $b->status }}</span></td>
      </tr>
      @endforeach
    </tbody>
  </table>
</div>
@endif

{{-- ══ سرویس‌ها ══ --}}
<div class="ad-panel">
  <div class="ad-panel-h"><h3>سرویس‌ها و خدمات</h3></div>
  @if($services->isEmpty())
    <p style="padding:16px;color:var(--dim)">سرویسی برای این مشتری ثبت نشده. از فرم زیر می‌توانید یک سرویس بفروشید.</p>
  @else
    <table class="ad-table">
      <thead><tr><th>سرویس</th><th>دوره</th><th>مبلغ</th><th>وضعیت</th><th>سررسید</th><th></th></tr></thead>
      <tbody>
        @foreach($services as $s)
        @php $sb = $s->statusBadge(); @endphp
        <tr>
          <td><b>{{ $s->name }}</b>@if($s->description)<div style="font-size:12px;color:var(--dim);margin-top:2px">{{ \Illuminate\Support\Str::limit($s->description, 60) }}</div>@endif
            @if($s->server_id)
              @php $pb = $s->provisionBadge(); @endphp
              <div style="margin-top:5px;display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                <span class="ad-badge" style="background:{{ $pb[1] }}22;color:{{ $pb[1] }}">{{ $pb[0] }}</span>
                @if($s->server)<small dir="ltr" style="color:var(--dim)">{{ $s->server->name }}@if($s->username) · {{ $s->username }}@endif</small>@endif
              </div>
              @if($s->provision_status === 'failed' && $s->provision_error)<div style="font-size:11px;color:#ff6b6b;margin-top:3px">{{ $s->provision_error }}</div>@endif
            @endif
          </td>
          <td>{{ $s->cycleLabel() }}</td>
          <td>{{ $money($s->total()) }}</td>
          <td><span class="ad-badge" style="background:{{ $sb[1] }}22;color:{{ $sb[1] }}">{{ $sb[0] }}</span></td>
          <td dir="ltr" style="color:var(--muted)">{{ $s->next_due_at ? sdate($s->next_due_at) : '—' }}</td>
          <td class="ad-row-act" style="white-space:nowrap">
            <form method="post" action="/admin/services/{{ $s->id }}/status" style="display:inline">@csrf
              <select name="status" onchange="this.form.submit()" style="background:var(--surface2);border:1px solid var(--line);border-radius:7px;color:var(--text);padding:5px 8px;font:inherit;font-size:12px">
                <option value="">تغییر…</option>
                <option value="active">فعال</option>
                <option value="suspended">تعلیق</option>
                <option value="cancelled">لغو</option>
              </select>
            </form>
            @if($s->isRecurring() && $s->status === 'active')
              <form method="post" action="/admin/services/{{ $s->id }}/renew" style="display:inline">@csrf<button class="del" style="color:#22d3ee" type="submit">فاکتور تمدید</button></form>
            @endif
            @if($s->server_id || $s->domain)
              @if($s->provision_status !== 'done')
                {{-- نیاز به ساخت — اگر سروری نخورده، همین‌جا سرور/پلن را تعیین کن و بساز --}}
                <form method="post" action="/admin/services/{{ $s->id }}/provision" style="display:flex;gap:5px;align-items:center;flex-wrap:wrap;margin-top:5px">@csrf
                  @unless($s->server_id)
                    <select name="server_id" required style="background:var(--surface2);border:1px solid var(--line);border-radius:7px;color:var(--text);padding:5px 7px;font:inherit;font-size:12px">
                      <option value="">سرور…</option>@foreach($servers as $srv)<option value="{{ $srv->id }}">{{ $srv->name }}</option>@endforeach
                    </select>
                    <input type="text" name="plan" value="{{ $s->plan }}" placeholder="plan (WHM)" style="width:100px;background:var(--surface2);border:1px solid var(--line);border-radius:7px;color:var(--text);padding:5px 7px;font:inherit;font-size:12px">
                  @endunless
                  @unless($s->domain)<input type="text" name="domain" dir="ltr" placeholder="domain" style="width:130px;background:var(--surface2);border:1px solid var(--line);border-radius:7px;color:var(--text);padding:5px 7px;font:inherit;font-size:12px">@endunless
                  <button class="del" style="color:#34d399" type="submit">{{ $s->provision_status === 'failed' ? 'تلاش دوباره' : 'ساخت روی سرور' }}</button>
                </form>
              @else
                @if($s->status === 'suspended')
                  <form method="post" action="/admin/services/{{ $s->id }}/unsuspend" style="display:inline">@csrf<button class="del" style="color:#34d399" type="submit">رفع تعلیق</button></form>
                @else
                  <form method="post" action="/admin/services/{{ $s->id }}/suspend" style="display:inline">@csrf<button class="del" style="color:#fbbf24" type="submit">تعلیق سرور</button></form>
                @endif
                <form method="post" action="/admin/services/{{ $s->id }}/terminate" style="display:inline"
                      data-confirm="حساب «{{ $s->username }}» از سرور حذف شود؟ برگشت‌ناپذیر است." data-confirm-danger>@csrf<button class="del" style="color:#ff6b6b" type="submit">حذف از سرور</button></form>
              @endif
            @endif
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>
  @endif

  {{-- فروش سرویس جدید --}}
  <div style="border-top:1px solid var(--line);padding:16px">
    <h4 style="margin:0 0 12px;font-size:14px;color:var(--text)">فروش سرویس جدید به این مشتری</h4>
    <form method="post" action="/admin/customers/{{ $c->id }}/services" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
      @csrf
      <label style="display:flex;flex-direction:column;gap:5px;font-size:12.5px;color:var(--muted);grid-column:1/3">نام سرویس
        <input type="text" name="name" required maxlength="150" placeholder="مثلاً پشتیبانی ویژه ماهانه" style="background:var(--surface2);border:1px solid var(--line);border-radius:8px;color:var(--text);padding:8px 12px;font:inherit"></label>
      <label style="display:flex;flex-direction:column;gap:5px;font-size:12.5px;color:var(--muted);grid-column:1/3">توضیحات (اختیاری)
        <textarea name="description" rows="2" maxlength="2000" style="background:var(--surface2);border:1px solid var(--line);border-radius:8px;color:var(--text);padding:8px 12px;font:inherit;resize:vertical"></textarea></label>
      <label style="display:flex;flex-direction:column;gap:5px;font-size:12.5px;color:var(--muted)">مبلغ (تومان، پیش از مالیات)
        <input type="number" name="price" required min="0" step="1000" dir="ltr" style="background:var(--surface2);border:1px solid var(--line);border-radius:8px;color:var(--text);padding:8px 12px;font:inherit;text-align:left"></label>
      <label style="display:flex;flex-direction:column;gap:5px;font-size:12.5px;color:var(--muted)">مالیات (٪)
        <input type="number" name="tax_percent" value="10" min="0" max="100" dir="ltr" style="background:var(--surface2);border:1px solid var(--line);border-radius:8px;color:var(--text);padding:8px 12px;font:inherit;text-align:left"></label>
      <label style="display:flex;flex-direction:column;gap:5px;font-size:12.5px;color:var(--muted)">دورهٔ پرداخت
        <select name="cycle" style="background:var(--surface2);border:1px solid var(--line);border-radius:8px;color:var(--text);padding:8px 12px;font:inherit">
          @foreach(\App\Models\Service::cycles() as $cv)
            <option value="{{ $cv }}" @selected($cv === 'monthly')>{{ \App\Models\Service::labelFor($cv) }}</option>
          @endforeach
        </select></label>
      {{-- تحویل خودکار (اختیاری) --}}
      <details style="grid-column:1/3;border:1px solid var(--line);border-radius:10px;padding:10px 13px">
        <summary style="cursor:pointer;font-size:13px;color:#22d3ee">تحویل خودکار روی سرور (اختیاری) — WHM/cPanel و…</summary>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px">
          <label style="display:flex;flex-direction:column;gap:5px;font-size:12.5px;color:var(--muted)">سرور تحویل
            <select name="server_id" style="background:var(--surface2);border:1px solid var(--line);border-radius:8px;color:var(--text);padding:8px 12px;font:inherit">
              <option value="">— بدون تحویل خودکار —</option>
              @foreach($servers as $srv)<option value="{{ $srv->id }}">{{ $srv->name }} ({{ $srv->typeLabel() }})</option>@endforeach
            </select></label>
          <label style="display:flex;flex-direction:column;gap:5px;font-size:12.5px;color:var(--muted)">پکیج/پلن (نام package در WHM)
            <input type="text" name="plan" maxlength="80" dir="ltr" placeholder="مثلاً WP-5 — خالی=default" style="background:var(--surface2);border:1px solid var(--line);border-radius:8px;color:var(--text);padding:8px 12px;font:inherit"></label>
          <label style="display:flex;flex-direction:column;gap:5px;font-size:12.5px;color:var(--muted)">نام‌کاربری (اختیاری)
            <input type="text" name="username" maxlength="16" dir="ltr" placeholder="خالی = خودکار ساخته می‌شود" style="background:var(--surface2);border:1px solid var(--line);border-radius:8px;color:var(--text);padding:8px 12px;font:inherit"></label>
          <label style="display:flex;flex-direction:column;gap:5px;font-size:12.5px;color:var(--muted)">دامنه
            <input type="text" name="domain" maxlength="190" dir="ltr" placeholder="client-site.com" style="background:var(--surface2);border:1px solid var(--line);border-radius:8px;color:var(--text);padding:8px 12px;font:inherit"></label>
        </div>
        <p style="font-size:11.5px;color:var(--dim);margin:8px 0 0">اگر سروری انتخاب شود، پس از پرداختِ مشتری، حساب <b>خودکار</b> روی سرور ساخته و اطلاعاتِ ورود در پنلِ مشتری نمایش داده می‌شود (تا یک دقیقه بعد از پرداخت). برای WHM نام‌کاربری و رمز خودکار تولید می‌شوند.</p>
      </details>
      <div style="display:flex;align-items:flex-end">
        <button class="btn btn-primary" type="submit"><svg class="icon"><use href="#i-plus"/></svg>صدور پیش‌فاکتور</button>
      </div>
    </form>
    <p style="margin:10px 0 0;font-size:12px;color:var(--dim)">یک پیش‌فاکتور صادر می‌شود؛ پس از پرداخت مشتری، سرویس خودکار فعال می‌شود و در پنل او دیده می‌شود.</p>
  </div>
</div>

{{-- ══ فاکتورها ══ --}}
<div class="ad-panel">
  <div class="ad-panel-h"><h3>فاکتورها</h3></div>
  @if($c->invoices->isEmpty())
    <p style="padding:16px;color:var(--dim)">فاکتوری ندارد.</p>
  @else
    <table class="ad-table">
      <thead><tr><th>شماره</th><th>نوع</th><th>مبلغ</th><th>پرداخت‌شده</th><th>وضعیت</th><th>تاریخ</th><th></th></tr></thead>
      <tbody>
        @foreach($c->invoices as $inv)
        <tr>
          <td dir="ltr">{{ $inv->number }}</td>
          <td>{{ ['service'=>'خدمت','topup'=>'افزایش اعتبار','domain'=>'دامنه'][$inv->kind] ?? $inv->kind }}</td>
          <td>{{ $money($inv->total) }}</td>
          <td>{{ $money($inv->paid) }}</td>
          <td>
            @php $ist = ['paid'=>['پرداخت‌شده','#34d399'],'unpaid'=>['پرداخت‌نشده','#fbbf24'],'partial'=>['جزئی','#22d3ee'],'overdue'=>['معوق','#ff6b6b'],'canceled'=>['لغو','var(--dim)']][$inv->status] ?? [$inv->status,'var(--muted)']; @endphp
            <span class="ad-badge" style="background:{{ $ist[1] }}22;color:{{ $ist[1] }}">{{ $ist[0] }}</span>
          </td>
          <td dir="ltr" style="color:var(--muted)">{{ sdate($inv->issued_at) }}</td>
          <td style="text-align:left;width:40px">
            @if($inv->isDeletable())
              <form method="post" action="/admin/invoices/{{ $inv->id }}/delete" style="margin:0"
                    data-confirm="فاکتور {{ $inv->number }} حذف شود؟ اگر برای سرویسی باشد، آن سرویس هم لغو می‌شود." data-confirm-danger data-confirm-title="حذف فاکتور">
                @csrf
                <button type="submit" title="حذف فاکتور"
                        style="background:rgba(255,107,107,.12);border:1px solid rgba(255,107,107,.32);color:#ff6b6b;border-radius:8px;padding:5px 8px;cursor:pointer;line-height:0;display:inline-grid;place-items:center">
                  <svg class="icon" style="width:14px;height:14px"><use href="#i-x"/></svg>
                </button>
              </form>
            @endif
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>
  @endif
</div>

<div class="ad-grid2">
  {{-- ══ پرداخت‌ها ══ --}}
  <div class="ad-panel" style="margin:0">
    <div class="ad-panel-h"><h3>پرداخت‌ها</h3></div>
    @if($c->payments->isEmpty())
      <p style="padding:16px;color:var(--dim)">پرداختی ندارد.</p>
    @else
      <table class="ad-table">
        <thead><tr><th>درگاه</th><th>مبلغ</th><th>وضعیت</th><th>تاریخ</th></tr></thead>
        <tbody>
          @foreach($c->payments as $p)
          <tr>
            <td>{{ ['zarinpal'=>'زرین‌پال','bale'=>'بله'][$p->gateway] ?? $p->gateway }}</td>
            <td>{{ $money($p->amount) }}</td>
            <td>
              @php $pst = ['paid'=>['موفق','#34d399'],'pending'=>['در انتظار','#fbbf24'],'redirected'=>['هدایت‌شده','#22d3ee'],'failed'=>['ناموفق','#ff6b6b'],'canceled'=>['لغو','var(--dim)']][$p->status] ?? [$p->status,'var(--muted)']; @endphp
              <span style="color:{{ $pst[1] }}">{{ $pst[0] }}</span>
            </td>
            <td dir="ltr" style="color:var(--muted)">{{ stime($p->paid_at ?? $p->created_at) }}</td>
          </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>

  {{-- ══ تیکت‌ها ══ --}}
  <div class="ad-panel" style="margin:0">
    <div class="ad-panel-h"><h3>تیکت‌ها</h3></div>
    @if($c->tickets->isEmpty())
      <p style="padding:16px;color:var(--dim)">تیکتی ندارد.</p>
    @else
      <table class="ad-table">
        <tbody>
          @foreach($c->tickets as $t)
          <tr onclick="location='/admin/tickets/{{ $t->id }}'" style="cursor:pointer">
            <td dir="ltr">{{ $t->number }}</td>
            <td>{{ $t->subject }}</td>
            <td>
              @if($t->status === 'open')<span class="ad-badge" style="background:rgba(251,191,36,.15);color:#fbbf24">باز</span>
              @elseif($t->status === 'answered')<span class="ad-badge" style="background:rgba(52,211,153,.15);color:#34d399">پاسخ‌داده</span>
              @else<span class="ad-badge" style="background:rgba(95,108,130,.15);color:var(--muted)">بسته</span>@endif
            </td>
          </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>
</div>

{{-- ══ فعالیت (لاگ با IP) ══ --}}
@if($activity->isNotEmpty())
<div class="ad-panel">
  <div class="ad-panel-h"><h3>فعالیت اخیر</h3></div>
  <table class="ad-table">
    <tbody>
      @foreach($activity as $a)
      <tr>
        <td style="width:34px"><svg class="icon" style="width:16px;height:16px;color:var(--muted)"><use href="#{{ $a->icon() }}"/></svg></td>
        <td>{{ $a->description }}@if($a->actor === 'staff')<span class="ad-badge" style="background:rgba(34,211,238,.12);color:#22d3ee;margin-inline-start:6px">پشتیبانی</span>@endif</td>
        <td dir="ltr" style="color:var(--muted)">
          {{ $a->ip ?: '—' }}
          @php $adev = $a->device(); $ageo = $a->geoLabel(); @endphp
          @if($adev['label'] !== '—' || $ageo)
            <div dir="rtl" style="color:var(--dim);font-size:11.5px;margin-top:2px">{{ $adev['label'] !== '—' ? $adev['label'] : '' }}{{ $ageo ? (($adev['label'] !== '—' ? ' · ' : '').$ageo) : '' }}</div>
          @endif
        </td>
        <td dir="ltr" style="color:var(--dim)">{{ stime($a->created_at) }}</td>
      </tr>
      @endforeach
    </tbody>
  </table>
</div>
@endif

{{-- ══ مدیریت حساب: وضعیت + رمز عبور ══ --}}
<div class="ad-grid2">
  <div class="ad-panel" style="margin:0">
    <div class="ad-panel-h"><h3>وضعیت حساب</h3></div>
    <form method="post" action="/admin/customers/{{ $c->id }}/status" style="padding:16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      @csrf
      <select name="status" style="background:var(--surface2);border:1px solid var(--line);border-radius:9px;color:var(--text);padding:8px 12px;font:inherit">
        <option value="active"    @selected($c->status==='active')>فعال</option>
        <option value="pending"   @selected($c->status==='pending')>در انتظار</option>
        <option value="suspended" @selected($c->status==='suspended')>معلق (بستن ورود و خرید)</option>
        <option value="closed"    @selected($c->status==='closed')>بسته</option>
      </select>
      <button class="btn btn-primary" type="submit">ثبت</button>
    </form>
  </div>

  <div class="ad-panel" style="margin:0">
    <div class="ad-panel-h"><h3>تغییر رمز عبور</h3></div>
    <form method="post" action="/admin/customers/{{ $c->id }}/password" style="padding:16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap"
          data-confirm="رمز عبور این مشتری تغییر کند و به او اطلاع داده شود؟" data-confirm-title="تغییر رمز مشتری">
      @csrf
      <input type="text" name="password" required minlength="8" placeholder="رمز عبور جدید (حداقل ۸ نویسه)" dir="ltr"
             style="background:var(--surface2);border:1px solid var(--line);border-radius:9px;color:var(--text);padding:8px 12px;font:inherit;flex:1;min-width:200px;text-align:left">
      <button class="btn btn-primary" type="submit">تغییر رمز</button>
    </form>
    <p style="padding:0 16px 14px;margin:0;font-size:12px;color:var(--dim)">مشتری با پیامک و بله از تغییر رمز خبردار می‌شود.</p>
  </div>
</div>

{{-- ══ منطقهٔ خطر: حذف کامل مشتری ══ --}}
<div class="ad-panel" style="border-color:rgba(255,107,107,.28)">
  <div class="ad-panel-h"><h3 style="color:#ff6b6b">حذف مشتری</h3></div>
  <div style="padding:16px">
    @if($invoiceTotals['paid'] > 0 || $creditBalance != 0)
      <p style="margin:0;color:var(--muted);font-size:13px;line-height:1.9">
        این مشتری سابقهٔ مالی دارد (فاکتور پرداخت‌شده یا ماندهٔ اعتبار) و برای حفظِ سوابقِ حسابداری قابلِ حذف نیست.
        برای مسدودسازی، از بخشِ «وضعیت حساب» گزینهٔ «بسته» را انتخاب کنید.
      </p>
    @else
      <p style="margin:0 0 12px;color:var(--muted);font-size:13px;line-height:1.9">
        حذفِ مشتری بازگشت‌ناپذیر است و همهٔ فاکتورها، سرویس‌ها و سوابقِ او را برای همیشه پاک می‌کند.
      </p>
      <form method="post" action="/admin/customers/{{ $c->id }}/delete" style="margin:0"
            data-confirm="مطمئنید؟ مشتری {{ $c->code }} و همهٔ سوابقش برای همیشه حذف می‌شود." data-confirm-danger data-confirm-title="حذف کامل مشتری" data-confirm-ok="حذف کن">
        @csrf
        <button type="submit" class="btn" style="background:#ff6b6b;color:var(--bg);font-weight:700">
          <svg class="icon"><use href="#i-x"/></svg> حذف کامل مشتری
        </button>
      </form>
    @endif
  </div>
</div>

<style>
.cust-head{ display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; margin-bottom:16px }
.cust-kpis{ display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:16px }
.cust-kpi{ padding:14px 16px; background:var(--panel,var(--surface)); border:1px solid var(--line); border-radius:12px }
.cust-kpi b{ display:block; font-size:19px; color:var(--text); font-variant-numeric:tabular-nums }
.cust-kpi span{ font-size:12px; color:var(--muted) }
.kv{ padding:8px 16px 16px }
.kv > div{ display:flex; justify-content:space-between; gap:12px; padding:9px 0; border-bottom:1px solid #161d2b }
.kv > div:last-child{ border-bottom:0 }
.kv span{ color:var(--muted); font-size:13px }
.kv b{ color:var(--text); font-size:13.5px; font-weight:600; text-align:left }
@media(max-width:900px){ .cust-kpis{ grid-template-columns:repeat(2,1fr) } }
</style>
@endsection

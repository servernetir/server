@extends('admin.layout')
@section('title', 'مشتری ' . $c->code)
@section('nav_customers', 'on')
@section('content')

@php
  $iv = $c->identityVerification;
  $stMap = ['active'=>['فعال','#34d399'],'pending'=>['در انتظار','#fbbf24'],'suspended'=>['معلق','#ff6b6b'],'closed'=>['بسته','#5f6c82']];
  $st = $stMap[$c->status] ?? [$c->status,'#96a3ba'];
  $money = fn($v) => fa_num(number_format((int)$v)).' ت';
@endphp

<div style="margin-bottom:14px"><a href="/admin/customers" style="color:#96a3ba;font-size:13px">→ بازگشت به مشتریان</a></div>

@if(session('ok'))<div class="ad-note ok">{{ session('ok') }}</div>@endif

{{-- ══ سربرگ پرونده ══ --}}
<div class="cust-head">
  <div>
    <h2 style="margin:0;font-size:22px">{{ $c->displayName() }}</h2>
    <div style="color:#5f6c82;margin-top:4px" dir="ltr">{{ $c->code }} · عضویت {{ optional($c->created_at)->format('Y/m/d') }}</div>
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
      @else<span class="ad-badge" style="background:rgba(95,108,130,.15);color:#96a3ba">انجام نشده</span>@endif
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
        <div style="color:#5f6c82;padding:8px 0">این مشتری هنوز احراز هویت نکرده است.</div>
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
      <div><span>آخرین ورود</span><b dir="ltr">{{ optional($c->last_login_at)->format('Y/m/d H:i') ?: '—' }}</b></div>
      <div><span>آخرین IP</span><b dir="ltr">{{ $c->last_login_ip ?: '—' }}</b></div>
      @if($c->locked_until && $c->locked_until->isFuture())<div><span>قفل تا</span><b style="color:#ff6b6b" dir="ltr">{{ $c->locked_until->format('Y/m/d H:i') }}</b></div>@endif
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
        <td>{{ $b->bank_name ?: '—' }} <small style="color:#5f6c82" dir="ltr">{{ $b->card_bin }}••••</small></td>
        <td dir="ltr" style="color:#96a3ba">{{ $b->iban ?: '—' }}</td>
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
    <p style="padding:16px;color:#5f6c82">سرویسی برای این مشتری ثبت نشده. از فرم زیر می‌توانید یک سرویس بفروشید.</p>
  @else
    <table class="ad-table">
      <thead><tr><th>سرویس</th><th>دوره</th><th>مبلغ</th><th>وضعیت</th><th>سررسید</th><th></th></tr></thead>
      <tbody>
        @foreach($services as $s)
        @php $sb = $s->statusBadge(); @endphp
        <tr>
          <td><b>{{ $s->name }}</b>@if($s->description)<div style="font-size:12px;color:#5f6c82;margin-top:2px">{{ \Illuminate\Support\Str::limit($s->description, 60) }}</div>@endif</td>
          <td>{{ $s->cycleLabel() }}</td>
          <td>{{ $money($s->total()) }}</td>
          <td><span class="ad-badge" style="background:{{ $sb[1] }}22;color:{{ $sb[1] }}">{{ $sb[0] }}</span></td>
          <td dir="ltr" style="color:#96a3ba">{{ $s->next_due_at ? $s->next_due_at->format('Y/m/d') : '—' }}</td>
          <td class="ad-row-act" style="white-space:nowrap">
            <form method="post" action="/admin/services/{{ $s->id }}/status" style="display:inline">@csrf
              <select name="status" onchange="this.form.submit()" style="background:#0f1522;border:1px solid #1e2637;border-radius:7px;color:#e7edf7;padding:5px 8px;font:inherit;font-size:12px">
                <option value="">تغییر…</option>
                <option value="active">فعال</option>
                <option value="suspended">تعلیق</option>
                <option value="cancelled">لغو</option>
              </select>
            </form>
            @if($s->isRecurring() && $s->status === 'active')
              <form method="post" action="/admin/services/{{ $s->id }}/renew" style="display:inline">@csrf<button class="del" style="color:#22d3ee" type="submit">فاکتور تمدید</button></form>
            @endif
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>
  @endif

  {{-- فروش سرویس جدید --}}
  <div style="border-top:1px solid #1e2637;padding:16px">
    <h4 style="margin:0 0 12px;font-size:14px;color:#e7edf7">فروش سرویس جدید به این مشتری</h4>
    <form method="post" action="/admin/customers/{{ $c->id }}/services" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
      @csrf
      <label style="display:flex;flex-direction:column;gap:5px;font-size:12.5px;color:#96a3ba;grid-column:1/3">نام سرویس
        <input type="text" name="name" required maxlength="150" placeholder="مثلاً پشتیبانی ویژه ماهانه" style="background:#0f1522;border:1px solid #1e2637;border-radius:8px;color:#e7edf7;padding:8px 12px;font:inherit"></label>
      <label style="display:flex;flex-direction:column;gap:5px;font-size:12.5px;color:#96a3ba;grid-column:1/3">توضیحات (اختیاری)
        <textarea name="description" rows="2" maxlength="2000" style="background:#0f1522;border:1px solid #1e2637;border-radius:8px;color:#e7edf7;padding:8px 12px;font:inherit;resize:vertical"></textarea></label>
      <label style="display:flex;flex-direction:column;gap:5px;font-size:12.5px;color:#96a3ba">مبلغ (تومان، پیش از مالیات)
        <input type="number" name="price" required min="0" step="1000" dir="ltr" style="background:#0f1522;border:1px solid #1e2637;border-radius:8px;color:#e7edf7;padding:8px 12px;font:inherit;text-align:left"></label>
      <label style="display:flex;flex-direction:column;gap:5px;font-size:12.5px;color:#96a3ba">مالیات (٪)
        <input type="number" name="tax_percent" value="10" min="0" max="100" dir="ltr" style="background:#0f1522;border:1px solid #1e2637;border-radius:8px;color:#e7edf7;padding:8px 12px;font:inherit;text-align:left"></label>
      <label style="display:flex;flex-direction:column;gap:5px;font-size:12.5px;color:#96a3ba">دورهٔ پرداخت
        <select name="cycle" style="background:#0f1522;border:1px solid #1e2637;border-radius:8px;color:#e7edf7;padding:8px 12px;font:inherit">
          <option value="once">یک‌بار</option>
          <option value="monthly">ماهانه</option>
          <option value="quarterly">سه‌ماهه</option>
          <option value="yearly">سالانه</option>
        </select></label>
      <div style="display:flex;align-items:flex-end">
        <button class="btn btn-primary" type="submit"><svg class="icon"><use href="#i-plus"/></svg>صدور پیش‌فاکتور</button>
      </div>
    </form>
    <p style="margin:10px 0 0;font-size:12px;color:#5f6c82">یک پیش‌فاکتور صادر می‌شود؛ پس از پرداخت مشتری، سرویس خودکار فعال می‌شود و در پنل او دیده می‌شود.</p>
  </div>
</div>

{{-- ══ فاکتورها ══ --}}
<div class="ad-panel">
  <div class="ad-panel-h"><h3>فاکتورها</h3></div>
  @if($c->invoices->isEmpty())
    <p style="padding:16px;color:#5f6c82">فاکتوری ندارد.</p>
  @else
    <table class="ad-table">
      <thead><tr><th>شماره</th><th>نوع</th><th>مبلغ</th><th>پرداخت‌شده</th><th>وضعیت</th><th>تاریخ</th></tr></thead>
      <tbody>
        @foreach($c->invoices as $inv)
        <tr>
          <td dir="ltr">{{ $inv->number }}</td>
          <td>{{ ['service'=>'خدمت','topup'=>'افزایش اعتبار','domain'=>'دامنه'][$inv->kind] ?? $inv->kind }}</td>
          <td>{{ $money($inv->total) }}</td>
          <td>{{ $money($inv->paid) }}</td>
          <td>
            @php $ist = ['paid'=>['پرداخت‌شده','#34d399'],'unpaid'=>['پرداخت‌نشده','#fbbf24'],'partial'=>['جزئی','#22d3ee'],'overdue'=>['معوق','#ff6b6b'],'canceled'=>['لغو','#5f6c82']][$inv->status] ?? [$inv->status,'#96a3ba']; @endphp
            <span class="ad-badge" style="background:{{ $ist[1] }}22;color:{{ $ist[1] }}">{{ $ist[0] }}</span>
          </td>
          <td dir="ltr" style="color:#96a3ba">{{ optional($inv->issued_at)->format('Y/m/d') }}</td>
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
      <p style="padding:16px;color:#5f6c82">پرداختی ندارد.</p>
    @else
      <table class="ad-table">
        <thead><tr><th>درگاه</th><th>مبلغ</th><th>وضعیت</th><th>تاریخ</th></tr></thead>
        <tbody>
          @foreach($c->payments as $p)
          <tr>
            <td>{{ ['zarinpal'=>'زرین‌پال','bale'=>'بله'][$p->gateway] ?? $p->gateway }}</td>
            <td>{{ $money($p->amount) }}</td>
            <td>
              @php $pst = ['paid'=>['موفق','#34d399'],'pending'=>['در انتظار','#fbbf24'],'redirected'=>['هدایت‌شده','#22d3ee'],'failed'=>['ناموفق','#ff6b6b'],'canceled'=>['لغو','#5f6c82']][$p->status] ?? [$p->status,'#96a3ba']; @endphp
              <span style="color:{{ $pst[1] }}">{{ $pst[0] }}</span>
            </td>
            <td dir="ltr" style="color:#96a3ba">{{ optional($p->paid_at ?? $p->created_at)->format('Y/m/d H:i') }}</td>
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
      <p style="padding:16px;color:#5f6c82">تیکتی ندارد.</p>
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
              @else<span class="ad-badge" style="background:rgba(95,108,130,.15);color:#96a3ba">بسته</span>@endif
            </td>
          </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>
</div>

{{-- ══ مدیریت حساب: وضعیت + رمز عبور ══ --}}
<div class="ad-grid2">
  <div class="ad-panel" style="margin:0">
    <div class="ad-panel-h"><h3>وضعیت حساب</h3></div>
    <form method="post" action="/admin/customers/{{ $c->id }}/status" style="padding:16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      @csrf
      <select name="status" style="background:#0f1522;border:1px solid #1e2637;border-radius:9px;color:#e7edf7;padding:8px 12px;font:inherit">
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
          onsubmit="return confirm('رمز عبور این مشتری تغییر کند و به او اطلاع داده شود؟')">
      @csrf
      <input type="text" name="password" required minlength="8" placeholder="رمز عبور جدید (حداقل ۸ نویسه)" dir="ltr"
             style="background:#0f1522;border:1px solid #1e2637;border-radius:9px;color:#e7edf7;padding:8px 12px;font:inherit;flex:1;min-width:200px;text-align:left">
      <button class="btn btn-primary" type="submit">تغییر رمز</button>
    </form>
    <p style="padding:0 16px 14px;margin:0;font-size:12px;color:#5f6c82">مشتری با پیامک و بله از تغییر رمز خبردار می‌شود.</p>
  </div>
</div>

<style>
.cust-head{ display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; margin-bottom:16px }
.cust-kpis{ display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:16px }
.cust-kpi{ padding:14px 16px; background:var(--panel,#141b2b); border:1px solid #1e2637; border-radius:12px }
.cust-kpi b{ display:block; font-size:19px; color:#e7edf7; font-variant-numeric:tabular-nums }
.cust-kpi span{ font-size:12px; color:#96a3ba }
.kv{ padding:8px 16px 16px }
.kv > div{ display:flex; justify-content:space-between; gap:12px; padding:9px 0; border-bottom:1px solid #161d2b }
.kv > div:last-child{ border-bottom:0 }
.kv span{ color:#96a3ba; font-size:13px }
.kv b{ color:#e7edf7; font-size:13.5px; font-weight:600; text-align:left }
@media(max-width:900px){ .cust-kpis{ grid-template-columns:repeat(2,1fr) } }
</style>
@endsection

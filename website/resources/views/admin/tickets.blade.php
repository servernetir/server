@extends('admin.layout')
@section('title', 'تیکت‌ها')
@section('nav_tickets', 'on')
@section('content')

@php
  // لینکِ تب‌ها فیلترهای جستجو/اولویت/بخش را نگه می‌دارد تا با عوض‌کردنِ وضعیت
  // جستجو دور نریزد.
  $tab = fn ($st) => '/admin/tickets?'.http_build_query(array_filter(
      ['status' => $st, 'q' => $q, 'priority' => $priority, 'department' => $dept],
      fn ($v) => $v !== ''
  ));
  $inp = 'background:var(--surface2);border:1px solid var(--line);border-radius:8px;color:var(--text);padding:7px 10px;font:inherit;font-size:12.5px';
@endphp

<div class="ad-toolbar" style="display:flex;flex-wrap:wrap;gap:12px;align-items:center">
  {{-- تب‌ها از `Ticket::STATUSES` ساخته می‌شوند تا وضعیتِ تازه (مثل
       «نگه‌داشته‌شده») هیچ‌وقت از این‌جا جا نماند. --}}
  <div class="ad-tabs">
    @foreach(\App\Models\Ticket::STATUSES as $st => $lbl)
      <a href="{{ $tab($st) }}" class="{{ $filter === $st ? 'on' : '' }}">{{ $lbl }} ({{ $counts[$st] }})</a>
    @endforeach
    <a href="{{ $tab('all') }}" class="{{ $filter === 'all' ? 'on' : '' }}">همه</a>
  </div>

  <form method="get" action="/admin/tickets" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-inline-start:auto">
    <input type="hidden" name="status" value="{{ $filter }}">
    <input type="search" name="q" value="{{ $q }}" placeholder="شماره، موضوع، کد/ایمیل/موبایل مشتری" style="{{ $inp }};min-width:230px">
    <select name="priority" style="{{ $inp }}">
      <option value="">همهٔ اولویت‌ها</option>
      <option value="urgent" @selected($priority === 'urgent')>فوری</option>
      <option value="high"   @selected($priority === 'high')>زیاد</option>
      <option value="normal" @selected($priority === 'normal')>عادی</option>
      <option value="low"    @selected($priority === 'low')>کم</option>
    </select>
    <select name="department" style="{{ $inp }}">
      <option value="">همهٔ بخش‌ها</option>
      <option value="technical" @selected($dept === 'technical')>فنی</option>
      <option value="billing"   @selected($dept === 'billing')>مالی</option>
      <option value="sales"     @selected($dept === 'sales')>فروش</option>
    </select>
    <button type="submit" style="{{ $inp }};cursor:pointer;color:var(--cyan);border-color:var(--cyan)">جستجو</button>
    @if($q !== '' || $priority !== '' || $dept !== '')
      <a href="/admin/tickets?status={{ $filter }}" style="font-size:12px;color:var(--dim)">پاک کردن</a>
    @endif
  </form>
</div>


<div class="ad-panel">
  <div class="ad-panel-h"><h2>تیکت‌ها</h2></div>
  @if($tickets->isEmpty())
    <p style="padding:20px;color:var(--muted)">تیکتی در این وضعیت نیست.</p>
  @else
    <form method="post" action="/admin/tickets/bulk" id="tk-bulk">
      @csrf
      <table class="ad-table">
        <thead>
          <tr>
            {{-- ⚠️ کلِ ردیف onclick دارد؛ سلولِ چک‌باکس باید جلوی انتشارش را
                 بگیرد وگرنه هر تیک‌زدن به صفحهٔ تیکت می‌بَرد و انتخابِ گروهی
                 — کلِ هدف — ناممکن می‌شود. همان درسِ چک‌باکسِ مقایسهٔ قطعات. --}}
            <th style="width:34px"><input type="checkbox" id="tk-all" title="انتخاب همه"></th>
            <th>شماره</th><th>موضوع</th><th>مشتری</th><th>بخش</th><th>اولویت</th><th>وضعیت</th><th>آخرین پاسخ</th>
          </tr>
        </thead>
      <tbody>
        @foreach($tickets as $t)
          <tr onclick="location='/admin/tickets/{{ $t->id }}'" style="cursor:pointer">
            <td onclick="event.stopPropagation()" style="cursor:default">
              <input type="checkbox" class="tk-pick" name="ids[]" value="{{ $t->id }}">
            </td>
            <td dir="ltr">{{ $t->number }}</td>
            <td>{{ $t->subject }}</td>
            <td>
              @if($t->customer)
                <a href="/admin/customers/{{ $t->customer->id }}" style="color:#22d3ee">{{ $t->customer->displayName() }}</a>
                <small style="color:var(--dim)" dir="ltr">{{ $t->customer->code }}</small>
              @else
                <span style="color:var(--dim)">—</span>
              @endif
            </td>
            <td>{{ ['technical'=>'فنی','billing'=>'مالی','sales'=>'فروش'][$t->department] ?? $t->department }}</td>
            <td>
              @php $pr = ['low'=>['کم','var(--dim)'],'normal'=>['عادی','var(--muted)'],'high'=>['زیاد','#fbbf24'],'urgent'=>['فوری','#ff6b6b']][$t->priority] ?? ['—','var(--dim)']; @endphp
              <span style="color:{{ $pr[1] }}">{{ $pr[0] }}</span>
            </td>
            <td>
              @php $stc = ['open'=>['rgba(251,191,36,.15)','#fbbf24'],'answered'=>['rgba(52,211,153,.15)','#34d399'],'held'=>['rgba(139,92,246,.15)','#8b5cf6'],'closed'=>['rgba(95,108,130,.15)','var(--muted)']][$t->status] ?? ['rgba(95,108,130,.15)','var(--muted)']; @endphp
              <span class="ad-badge" style="background:{{ $stc[0] }};color:{{ $stc[1] }}">{{ $t->statusLabel() }}</span>
            </td>
            <td dir="ltr">{{ stime($t->last_reply_at) }} <small style="color:var(--dim)">{{ $t->last_reply_role === 'staff' ? '(ما)' : '(مشتری)' }}</small></td>
          </tr>
        @endforeach
      </tbody>
      </table>

      {{-- نوارِ عملیاتِ گروهی — تا چیزی تیک نخورده پنهان است --}}
      <div class="tk-bulkbar" id="tk-bulkbar" hidden>
        <b id="tk-bulk-n"></b>
        <select name="status" class="ad-input" style="max-width:190px">
          @foreach(\App\Models\Ticket::STATUSES as $st => $lbl)
            <option value="{{ $st }}">{{ $lbl }}</option>
          @endforeach
        </select>
        <button type="submit" class="ad-badge" style="background:#22d3ee;color:#04121f;border:0;padding:9px 16px;cursor:pointer;font:inherit"
                data-confirm="وضعیتِ تیکت‌های انتخاب‌شده تغییر کند؟">اعمال</button>
        <button type="button" class="ad-linkbtn" id="tk-bulk-clear" style="color:var(--muted);background:none;border:0;cursor:pointer;font:inherit;font-size:12px">پاک کردن انتخاب</button>
      </div>
    </form>
  @endif
</div>

{{ $tickets->links() }}

<style>
/* ⚠️ `[hidden]` صریح — قاعدهٔ نویسنده display پیش‌فرضِ مرورگر را می‌بلعد؛
   همان تلهٔ نوارِ مقایسهٔ قطعات. */
.tk-bulkbar[hidden]{ display:none }
.tk-bulkbar{ display:flex; align-items:center; gap:10px; flex-wrap:wrap;
  padding:12px 16px; border-top:1px solid var(--line); background:var(--surface2) }
.tk-bulkbar b{ font-size:12.5px; color:var(--text) }
.tk-pick, #tk-all{ accent-color:#22d3ee; width:15px; height:15px; cursor:pointer }
</style>

<script>
(function () {
  var all = document.getElementById('tk-all');
  var bar = document.getElementById('tk-bulkbar');
  var n = document.getElementById('tk-bulk-n');
  var clearBtn = document.getElementById('tk-bulk-clear');
  if (!bar) return;
  var picks = function () { return document.querySelectorAll('.tk-pick'); };

  function sync() {
    var c = document.querySelectorAll('.tk-pick:checked').length;
    bar.hidden = c === 0;
    n.textContent = c + ' تیکت انتخاب شد';
    if (all) all.checked = c > 0 && c === picks().length;
  }

  if (all) all.addEventListener('change', function () {
    picks().forEach(function (b) { b.checked = all.checked; });
    sync();
  });
  document.addEventListener('change', function (e) {
    if (e.target.classList && e.target.classList.contains('tk-pick')) sync();
  });
  if (clearBtn) clearBtn.addEventListener('click', function () {
    picks().forEach(function (b) { b.checked = false; });
    sync();
  });
})();
</script>

@endsection

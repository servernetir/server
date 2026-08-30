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
    {{-- ورود به پنلِ مشتری — تا امروز فقط در **فهرستِ** مشتریان بود.
         پشتیبانی معمولاً از پروندهٔ مشتری شروع می‌شود (این‌جا)، پس مدیر مجبور
         بود برگردد به فهرست و همان ردیف را پیدا کند. همان کنش، همان تأیید،
         همان لاگ — فقط جایی که واقعاً لازم می‌شود.
         ⚠️ هر دو شرطِ فهرست عیناً تکرار شده‌اند: فقط مدیر (نه نویسنده) و فقط
         حسابِ بسته‌نشده. کنترلر هم خودش دوباره می‌سنجد. --}}
    @if(auth()->user()->isAdmin() && $c->status !== 'closed')
      <form method="post" action="/admin/customers/{{ $c->id }}/impersonate" style="display:inline"
            data-confirm="وارد پنلِ «{{ $c->displayName() }}» می‌شوید. این کار در لاگ ثبت می‌شود.">
        @csrf
        <button class="btn btn-glass" type="submit">
          <svg class="icon"><use href="#i-key"/></svg>ورود به پنل کاربری
        </button>
      </form>
    @endif
    <a class="btn btn-glass" href="/admin/broadcasts?customer={{ $c->id }}"><svg class="icon"><use href="#i-message"/></svg>ارسال اعلان</a>

    {{-- ══ تماس با مشتری ══
         🔴 جایش این‌جاست، نه داخلِ تبِ تماس‌ها.

         تماس یک **کنشِ** روی این مشتری است، مثلِ «ورود به پنل» و «ارسال
         اعلان» — نه بخشی از خواندنِ تاریخچه. وقتی داخلِ تب بود، برای زنگ‌زدن
         باید یک تب عوض می‌شد، و تبِ تماس‌ها هم‌زمان دو کارِ نامربوط داشت.

         🔴 سه شرطِ **جدا** با سه پیامِ جدا. یک دکمهٔ خاکستریِ بی‌توضیح، مدیر
         را می‌فرستد سراغِ تیم فنی؛ «شمارهٔ مشتری نداریم» و «داخلیِ خودت ثبت
         نشده» و «رله وصل نیست» سه کارِ کاملاً متفاوت لازم دارند. --}}
    @php
      $callTo = $c->phone ?: optional($c->profiles->firstWhere('is_default', true))->mobile ?: optional($c->profiles->first())->mobile;
      $callRelay = app(\App\Services\CloudPhone\OutgoingCallService::class);
      /* شماره‌ای که اول زنگ می‌خورد: شخصیِ کاربر، وگرنه پیش‌فرضِ سراسری */
      $callAgent = $callRelay->agentNumberFor(auth()->user()->phoneExtension());
    @endphp

    @if(auth()->user()->isAdmin() && $callTo && $callRelay->enabled() && $callAgent)
      {{-- ⚠️ متنِ تأیید صریحاً می‌گوید **کدام تلفن** اول زنگ می‌خورد. بی‌آن،
           مدیر کلیک می‌کند و منتظرِ زنگی می‌ماند که روی تلفنِ دیگری است — و
           فکر می‌کند تماس نرفته. --}}
      <form method="post" action="/admin/customers/{{ $c->id }}/call" style="display:inline"
            data-confirm="تماس با {{ $callTo }} برقرار شود؟ اول {{ $callAgent }} زنگ می‌خورد، بعد مشتری.">
        @csrf
        <button class="btn btn-primary" type="submit">
          <svg class="icon"><use href="#i-phone"/></svg>تماس
        </button>
      </form>
    @elseif(auth()->user()->isAdmin())
      {{-- علتِ نبودنِ دکمه دیده می‌شود، وگرنه «چرا دکمه نیست؟» خودش یک تیکت است --}}
      <span class="btn btn-glass" style="opacity:.55;cursor:not-allowed"
            title="{{ ! $callTo ? 'شماره‌ای برای این مشتری ثبت نشده' : (! $callRelay->enabled() ? 'رلهٔ تلفن ابری پیکربندی نشده' : 'شمارهٔ تماس‌گیرنده تنظیم نشده') }}">
        <svg class="icon"><use href="#i-phone"/></svg>تماس
      </span>
    @endif
  </div>
</div>

{{-- ══ آمار سریع ══
     «در یک نگاه» یعنی همین ردیف: چه چیزی از ما دارد، چقدر بدهکار است و
     نزدیک‌ترین سررسیدش کِی است — بدونِ باز کردنِ هیچ تبی. --}}
@php
  $activeSvc  = $services->whereIn('status', ['active', 'awaiting_provision']);
  $domains    = $services->whereIn('status', ['active', 'awaiting_provision'])
                         ->pluck('domain')->filter()->unique();
  $nextDue    = $activeSvc->whereNotNull('next_due_at')->sortBy('next_due_at')->first();
  $unpaidSum  = $c->invoices->whereIn('status', ['unpaid', 'partial'])->sum(fn ($i) => (int) $i->due());
  $openTickets = $c->tickets->where('status', 'open')->count();
@endphp
<div class="cust-kpis">
  <div class="cust-kpi"><b style="color:#22d3ee">{{ fa_num($activeSvc->count()) }}</b><span>سرویس فعال</span></div>
  <div class="cust-kpi"><b>{{ fa_num($domains->count()) }}</b><span>دامنه</span></div>
  <div class="cust-kpi">
    <b style="color:{{ $unpaidSum > 0 ? '#fbbf24' : 'var(--text)' }}">{{ $money($unpaidSum) }}</b>
    <span>بدهی پرداخت‌نشده</span>
  </div>
  <div class="cust-kpi"><b style="color:#34d399">{{ $money($creditBalance) }}</b><span>موجودی اعتبار</span></div>
  <div class="cust-kpi">
    <b>{{ $nextDue?->next_due_at ? sdate($nextDue->next_due_at) : '—' }}</b>
    <span>نزدیک‌ترین سررسید</span>
  </div>
  <div class="cust-kpi"><b>{{ $money($invoiceTotals['paid']) }}</b><span>مجموع پرداخت‌شده</span></div>
</div>

{{-- ══ تب‌ها ══
     صفحه شلوغ شده بود و «هویت و احراز» بالای صفحه جای زیادی می‌گرفت در حالی
     که روزمره لازم نیست. همه‌چیز در DOM می‌ماند (پس Ctrl+F کار می‌کند) و فقط
     یکی دیده می‌شود؛ پیش‌فرض «سرویس‌ها» است. --}}
<div class="ct-tabs" role="tablist">
  <button type="button" class="ct-tab on" data-tab="services" role="tab">
    <svg class="icon"><use href="#i-server"/></svg>سرویس‌ها
    @if($activeSvc->count())<i class="ct-n">{{ fa_num($activeSvc->count()) }}</i>@endif
  </button>
  <button type="button" class="ct-tab" data-tab="finance" role="tab">
    <svg class="icon"><use href="#i-coins"/></svg>مالی
    @if($invoiceTotals['unpaid'])<i class="ct-n warn">{{ fa_num($invoiceTotals['unpaid']) }}</i>@endif
  </button>
  <button type="button" class="ct-tab" data-tab="support" role="tab">
    <svg class="icon"><use href="#i-lifebuoy"/></svg>پشتیبانی
    @if($openTickets)<i class="ct-n warn">{{ fa_num($openTickets) }}</i>@endif
  </button>
  <button type="button" class="ct-tab" data-tab="calls" role="tab">
    <svg class="icon"><use href="#i-phone"/></svg>تماس‌ها
    @if($callsMissed)<i class="ct-n warn">{{ fa_num($callsMissed) }}</i>@endif
  </button>
  <button type="button" class="ct-tab" data-tab="account" role="tab">
    <svg class="icon"><use href="#i-user"/></svg>هویت و حساب
  </button>
  <button type="button" class="ct-tab" data-tab="activity" role="tab">
    <svg class="icon"><use href="#i-flow"/></svg>فعالیت
    {{-- ⚠️ کلِ تاریخچه، نه نتیجهٔ فیلتر: وگرنه با هر فیلتر عددِ تب هم عوض
         می‌شد و «۳ رویداد» نشان می‌داد برای مشتری‌ای که هزار تا دارد. --}}
    @if($activityTotal)<i class="ct-n">{{ fa_num($activityTotal) }}</i>@endif
  </button>
</div>

{{-- ══════════════════════ تماس‌ها ══════════════════════ --}}
<div class="ct-pane" data-pane="calls">
  <div class="ad-panel">
    {{-- ⚠️ دکمهٔ تماس عمداً این‌جا **نیست** — به نوارِ بالای صفحه رفت، کنارِ
         «ورود به پنل کاربری». این پنل فقط تاریخچه است: کارفرما پشتِ تلفن
         می‌خواهد بگوید «شما فلان روز تماس گرفته بودید»، و برای آن باید
         تاریخچه را بخوانَد نه دکمه بزند. --}}
    <div class="ad-panel-h"><h2>تماس‌های این مشتری</h2></div>

    @if($calls->isEmpty())
      <p style="padding:20px;color:var(--muted)">تماسی از این مشتری ثبت نشده.</p>
    @else
      <table class="ad-table">
        <thead><tr><th>زمان</th><th>جهت</th><th>شماره</th><th>نتیجه</th><th>مدت</th><th>مسیر</th></tr></thead>
        <tbody>
          @foreach($calls as $call)
            <tr>
              {{-- 🔴 «روزِ هفته + تاریخِ شمسی» چون کارفرما این را **پشتِ تلفن
                   می‌خوانَد**: «شما سه‌شنبه ۲۸ مرداد تماس گرفته بودید». عددِ
                   ۱۴۰۵/۰۵/۲۸ باید در ذهن ترجمه شود و روزِ هفته اصلاً در آن نیست.
                   ⚠️ ساعت هم از همان تابع می‌آید تا با تاریخ **یک منطقهٔ زمانی**
                   داشته باشد؛ `format('H:i')`ِ خام UTC است و شب‌ها یک روز
                   اختلاف می‌ساخت. --}}
              <td style="color:var(--muted);white-space:nowrap">
                {{ sdate_full($call->started_at) }}
              </td>
              <td>
                @if($call->direction === 'incoming')
                  <span class="ad-badge" style="background:rgba(34,211,238,.15);color:#22d3ee">ورودی</span>
                @else
                  <span class="ad-badge" style="background:rgba(167,139,250,.15);color:#a78bfa">خروجی</span>
                @endif
              </td>
              <td dir="ltr" style="color:var(--muted)">{{ $call->caller_number ?: '—' }}</td>
              <td>
                @if($call->answered === true)
                  <span class="ad-badge" style="background:rgba(52,211,153,.15);color:#34d399">پاسخ داده شد</span>
                @elseif($call->answered === false)
                  <span class="ad-badge" style="background:rgba(255,107,107,.15);color:#ff6b6b">بی‌پاسخ</span>
                @else
                  <span class="ad-badge" style="background:rgba(251,191,36,.15);color:#fbbf24">در جریان</span>
                @endif
                @if(! $call->isConfidentMatch())
                  <div style="font-size:11px;color:#fbbf24" title="شماره بدون پیش‌شمارهٔ شهر آمده">تطبیق نامطمئن</div>
                @endif
              </td>
              <td dir="ltr" style="color:var(--muted)">
                @if($call->duration_seconds !== null)
                  {{ fa_num(gmdate($call->duration_seconds >= 3600 ? 'H:i:s' : 'i:s', $call->duration_seconds)) }}
                @else<span style="color:var(--dim)">—</span>@endif
              </td>
              <td style="font-size:12px;color:var(--dim)">
                @if($call->was_transferred)منتقل شد@endif
                @if($call->menu_name) · {{ $call->menu_name }}@endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
      <p style="padding:12px 20px;color:var(--dim);font-size:12px">
        ۵۰ تماس اخیر. همه‌شان در <a href="/admin/calls?q={{ urlencode((string) ($c->phone ?: '')) }}" style="color:var(--muted)">گزارش تماس‌ها</a>.
      </p>
    @endif
  </div>
</div>

{{-- هر تب می‌تواند چند تکهٔ جدا در صفحه داشته باشد؛ JS همهٔ تکه‌های هم‌نام را
     با هم نشان/پنهان می‌کند. این‌طور لازم نشد بلوک‌های بزرگ را جابه‌جا کنم. --}}
<div class="ct-pane" data-pane="account">
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
        {{-- ⚠️ ستون `date` کست شده، پس چاپِ مستقیمش «۱۳۶۹-۱۱-۰۳ 00:00:00» می‌داد —
             ساعتِ بی‌معنی روی تاریخِ تولد. مقدار از قبل شمسی است، فقط بریده می‌شود. --}}
        <div><span>تاریخ تولد</span><b dir="ltr">{{ $iv->birth_date ? substr((string) $iv->birth_date, 0, 10) : '—' }}</b></div>
        <div><span>شاهکار</span><b>{{ $iv->shahkar_matched ? 'تطابق موبایل ✓' : 'تطابق نشد' }}</b></div>
        @if($iv->fail_reason)<div><span>دلیل رد</span><b style="color:#ff6b6b">{{ $iv->fail_reason }}</b></div>@endif
      @else
        <div class="kv-wide" style="color:var(--dim)">این مشتری هنوز احراز هویت نکرده است.</div>
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

</div>{{-- /pane account (بخشِ اولش) --}}

{{-- ─────────── تبِ سرویس‌ها (پیش‌فرض) ─────────── --}}
<div class="ct-pane on" data-pane="services">

{{-- ══ دامنه‌ها ══
     کنارِ سرویس‌ها و نه در تبِ جدا: از دیدِ پشتیبانی هر دو «چیزی که این آدم
     خریده» هستند، و تبِ جدا یعنی جایی که کسی بازش نمی‌کند. --}}
@if(! $customerDomains->isEmpty())
<div class="ad-panel">
  <div class="ad-panel-h"><h3>دامنه‌ها</h3></div>
  <table class="ad-table">
    <thead><tr><th>دامنه</th><th>وضعیت</th><th>تحویل</th><th>انقضا</th><th></th></tr></thead>
    <tbody>
      @foreach($customerDomains as $d)
      <tr>
        <td><b dir="ltr">{{ $d->domain }}</b></td>
        <td>{{ $d->status === 'active' ? 'فعال' : ($d->status === 'pending' ? 'در انتظار' : $d->status) }}</td>
        <td>
          {{ $d->provision_status === 'manual' ? 'دستی' : ($d->provision_status === 'done' ? 'انجام شد' : $d->provision_status) }}
          @if($d->provision_error)
            <br><small style="color:var(--muted)">{{ mb_substr($d->provision_error, 0, 70) }}</small>
          @endif
        </td>
        <td>{{ $d->expires_at ? sdate($d->expires_at) : '—' }}</td>
        <td><a href="{{ route('admin.domains') }}?f=all">مدیریت</a></td>
      </tr>
      @endforeach
    </tbody>
  </table>
</div>
@endif

{{-- ══ سرویس‌ها ══ --}}
@php
  /*
  | 🔴 سرویسِ مرده همیشه **ته** فهرست و جمع‌شده.
  |
  | کارفرما: «اونایی که لغو شده‌اند همیشه بره پایینِ لیست و یجورایی حالت
  | minimize هم باشه.»
  |
  | و دلیلش فقط سلیقه نیست: پروندهٔ یک مشتریِ قدیمی می‌تواند ده‌ها سرویسِ
  | لغوشده داشته باشد و آن‌ها **کارِ روزمره نیستند**. وقتی با فعال‌ها قاطی
  | باشند، پشتیبان باید هر بار میانشان بگردد تا سرویسِ زنده را پیدا کند —
  | و همان لحظه است که اشتباه رخ می‌دهد (تغییرِ وضعیتِ سرویسِ اشتباه).
  |
  | ⚠️ حذف نمی‌شوند، فقط تاشده‌اند: سابقهٔ مالی باید در دسترس بمانَد.
  | ⚠️ شمارش در خودِ `summary` است — تاکردنی که شمارش نداشته باشد یعنی
  |    پنهان‌کردن، نه جمع‌کردن. (همان قاعدهٔ `.ad-fold` در `/admin/cloud`.)
  */
  $deadServices = $services->whereIn('status', \App\Models\Service::DEAD_STATUSES);
  $liveServices = $services->whereNotIn('status', \App\Models\Service::DEAD_STATUSES);
@endphp

<div class="ad-panel">
  <div class="ad-panel-h" style="display:flex;align-items:center;justify-content:space-between;gap:12px">
    <h3>سرویس‌ها و خدمات</h3>
    {{-- 🔴 فرمِ فروش پشتِ همین دکمه است، نه همیشه‌باز در انتهای صفحه.
         فرمی که همیشه باز باشد، صفحه را بلند می‌کند و فهرستِ سرویس‌ها —
         که کارِ اصلیِ این صفحه است — به زیرِ خطِ دید می‌رود. --}}
    <button type="button" class="ad-btn" data-open-sell>+ فروش سرویس جدید</button>
  </div>
  @if($liveServices->isEmpty() && $deadServices->isEmpty())
    <p style="padding:16px;color:var(--dim)">سرویسی برای این مشتری ثبت نشده. از فرم زیر می‌توانید یک سرویس بفروشید.</p>
  @else
    <table class="ad-table">
      {{--
        🔴 «آدرس» ستونِ مستقلِ خودش است.

        تا امروز دامنه و IP داخلِ **همان** خانهٔ نام تلنبار می‌شدند، کنارِ
        توضیح و پکیج و کاربر و آخرین پرداخت و نشانِ تحویل و متنِ خطا. نتیجه:
        خانهٔ اول گاهی یک خط بود و گاهی هشت خط، پس ارتفاعِ ردیف‌ها بهم می‌ریخت
        و جدول دیگر جدول به‌نظر نمی‌رسید — همان چیزی که کارفرما گزارش کرد.

        و مهم‌تر از ظاهر: «مشتری این سرور را با چه IPای دارد؟» پرتکرارترین
        سؤالِ پشتیبانی است. چیزی که در هر ردیف لازم است، ستونِ خودش را
        می‌خواهد نه یک چیپِ گم‌شده وسطِ متن.
      --}}
      <thead><tr><th>سرویس</th><th>آدرس</th><th>دوره</th><th>مبلغ</th><th>وضعیت</th><th>سررسید</th><th></th></tr></thead>
      <tbody>
        @foreach($liveServices as $s)
        @php $sb = $s->statusBadge(); @endphp
        <tr>
          <td><b>{{ $s->name }}</b>@if($s->description)<div style="font-size:12px;color:var(--dim);margin-top:2px">{{ \Illuminate\Support\Str::limit($s->description, 60) }}</div>@endif
            {{-- «در یک نگاه»: پکیج، دامنه، IP و آخرین پرداخت — بدونِ کلیکِ اضافه.
                 برای هاست دامنه مهم است و برای سرور، IP. --}}
            @php
              $lastPaid = $s->invoices->where('status', 'paid')->sortByDesc('paid_at')->first();
              // ⚠️ provision_meta ممکن است null باشد؛ null['ip'] در PHP ۸ اخطار
              // می‌دهد و لاراول اخطار را به استثنا تبدیل می‌کند → ۵۰۰.
              $meta  = is_array($s->provision_meta) ? $s->provision_meta : [];
              $svcIp = $meta['ip'] ?? $s->server?->server_ip;
            @endphp
            {{-- دامنه و IP از این‌جا به ستونِ «آدرس» رفتند تا ارتفاعِ خانهٔ
                 نام قابلِ پیش‌بینی بمانَد. --}}
            <div class="svc-meta">
              @if($s->plan)<i><b>پکیج:</b> <span dir="ltr">{{ $s->plan }}</span></i>@endif
              @if($s->username)<i><b>کاربر:</b> <span dir="ltr">{{ $s->username }}</span></i>@endif
              @if($lastPaid)<i><b>آخرین پرداخت:</b> {{ sdate($lastPaid->paid_at) }}</i>@endif
            </div>
            {{--
              🔴 `$s->server_id` **تنها** شرط نیست — و این دقیقاً همان باگی است
              که یک‌بار در کرونِ تحویل رخ داد و در CLAUDE.md ثبت شده.

              سرورِ ابری `server_id` ندارد (پیش از خرید وجود ندارد). پس این
              بلوک برای **هر سرویسِ ابری** رد می‌شد: نه نشانِ وضعیتِ تحویل، نه
              علتِ خطا. مدیر یک سرویسِ «در حالِ آماده‌سازی» می‌دید که هفته‌ها
              همان‌جا می‌مانْد و هیچ راهی نداشت بفهمد چرا — چون تنها جایی که
              علت نوشته می‌شود، همین‌جا بود و رندر نمی‌شد.

              ⚠️ درسِ تکراری: هر جا شرطی روی `server_id` گذاشتی، بپرس سرویسِ
              ابری چه می‌شود.
            --}}
            @if($s->server_id || $s->cloud_plan_id)
              @php $pb = $s->provisionBadge(); @endphp
              <div style="margin-top:5px;display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                <span class="ad-badge" style="background:{{ $pb[1] }}22;color:{{ $pb[1] }}">{{ $pb[0] }}</span>
                @if($s->server)<small dir="ltr" style="color:var(--dim)">{{ $s->server->name }}@if($s->username) · {{ $s->username }}@endif</small>@endif
                {{-- وضعیتِ خامِ صف — بی‌این، «در حالِ آماده‌سازی» و «گیر کرده»
                     از هم قابلِ تشخیص نیستند --}}
                @if($s->provision_status && $s->provision_status !== 'done')
                  <small dir="ltr" style="color:var(--dim)">{{ $s->provision_status }}</small>
                @endif
              </div>

              {{-- 🔴 قبلاً فقط روی `failed` نشان داده می‌شد. ولی سرویسی که در
                   حلقهٔ تلاشِ دوباره گیر کرده `pending` می‌مانَد — یعنی دقیقاً
                   حالتی که مدیر بیشتر از همه به علت نیاز دارد، هیچ‌چیز نمی‌دید. --}}
              @if($s->provision_error && $s->provision_status !== 'done')
                <div style="font-size:11px;color:{{ $s->provision_status === 'failed' ? '#ff6b6b' : '#fbbf24' }};margin-top:3px">{{ $s->provision_error }}</div>
              @endif
            @endif
          </td>
          {{--
            ستونِ آدرس — «این مشتری با چه چیزی به این سرویس وصل می‌شود؟»

            ⚠️ برای هاست، دامنه معنا دارد؛ برای سرور، IP. هر دو را نشان
            می‌دهیم چون یک سرویس می‌تواند هر دو را داشته باشد و کدام‌یک
            «اصلی» است به نوعِ سرویس بستگی دارد، نه به ترتیبِ ما.

            ⚠️ `—` وقتی هیچ‌کدام نیست، عمداً و صریح: خانهٔ خالی در جدول یعنی
            «یادم رفت»، ولی `—` یعنی «هست و چیزی ندارد» — و برای سرویسی که
            هنوز تحویل نشده، دقیقاً همان درست است.
          --}}
          <td style="white-space:nowrap">
            @if($s->domain)
              <a href="http://{{ $s->domain }}" target="_blank" rel="noopener" dir="ltr" style="color:#22d3ee">{{ $s->domain }}</a>
            @endif
            @if($svcIp)
              <div dir="ltr" style="font-size:12px;color:var(--muted);user-select:all">{{ $svcIp }}</div>
            @endif
            @unless($s->domain || $svcIp)<span style="color:var(--dim)">—</span>@endunless
          </td>
          <td>{{ $s->cycleLabel() }}</td>
          {{-- ساعتی: نرخِ واقعیِ کسر، نه معادلِ ماهانه که هرگز فاکتور نمی‌شود --}}
          <td>@if($s->isHourly()){{ number_format((int) $s->hourly_rate_irt) }} ت/ساعت@else{{ $money($s->total()) }}@endif</td>
          <td><span class="ad-badge" style="background:{{ $sb[1] }}22;color:{{ $sb[1] }}">{{ $sb[0] }}</span></td>
          {{-- 🔴 سرویسِ **زندهٔ بی‌سررسید** یعنی سرویسِ رایگانِ ابدی:
               `services:renew-due` شرطِ `whereNotNull('next_due_at')` دارد، پس
               این ردیف نه فاکتور می‌گیرد، نه یادآوری، نه تعلیق می‌شود — و هیچ
               خطایی هم تولید نمی‌کند. پس به‌جای یک خطِ تیره، همین‌جا هم هشدار
               داده می‌شود و هم راهِ رفعش هست. --}}
          <td dir="ltr" style="color:var(--muted)">
            @if($s->next_due_at)
              {{ sdate($s->next_due_at) }}
            @elseif($s->isHourly())
              {{-- ساعتی عمداً سررسید ندارد: صورت‌حسابش متر است، نه فاکتورِ دوره‌ای.
                   دکمهٔ «تنظیم» این‌جا یعنی دعوت به فاکتورِ دوبله. --}}
              <span style="color:var(--dim)" title="صورت‌حسابِ ساعتی از اعتبار کسر می‌شود؛ سررسیدِ دوره‌ای ندارد">ساعتی</span>
            @elseif(! $s->isDead())
              <form method="post" action="/admin/services/{{ $s->id }}/due" style="display:flex;gap:5px;align-items:center"
                    data-confirm="سررسیدِ این سرویس تنظیم شود؟ از این پس فاکتورِ تمدید و یادآوری می‌گیرد.">
                @csrf
                {{-- دیت‌پیکرِ شمسی: فیلد مخفی می‌مانَد و jdate.js کنارش دکمهٔ
                     انتخاب می‌سازد. مقدار همیشه میلادیِ ISO است چون سرور
                     می‌سازدش — هیچ تبدیلی در مرورگر انجام نمی‌شود. --}}
                <input type="hidden" name="next_due_at" data-jdate required
                       data-min="{{ now()->addDay()->toDateString() }}">
                <button class="del" style="color:#fbbf24" type="submit" title="بدونِ سررسید، این سرویس هرگز فاکتورِ تمدید نمی‌گیرد">تنظیم</button>
              </form>
            @else
              —
            @endif
          </td>
          <td class="ad-row-act" style="white-space:nowrap">
            <a href="/admin/services/{{ $s->id }}/history" class="del" style="color:var(--muted)" title="تاریخچهٔ مالکیت: کی خرید، تمدید، تعلیق یا حذف کرد">تاریخچه</a>
            <form method="post" action="/admin/services/{{ $s->id }}/status" style="display:inline">@csrf
              <select name="status" onchange="var s=this,v=s.value,f=s.form;if(v==='suspended'||v==='cancelled'){snConfirm('سرویس '+(v==='cancelled'?'لغو':'تعلیق')+' شود؟',{danger:true}).then(function(ok){if(ok){f.submit();}else{s.value='';}});}else if(v){f.submit();}" style="background:var(--surface2);border:1px solid var(--line);border-radius:7px;color:var(--text);padding:5px 8px;font:inherit;font-size:12px">
                <option value="">تغییر…</option>
                <option value="active">فعال</option>
                <option value="suspended">تعلیق</option>
                <option value="cancelled">لغو</option>
              </select>
            </form>
            @if($s->isRecurring() && $s->status === 'active')
              <form method="post" action="/admin/services/{{ $s->id }}/renew" style="display:inline">@csrf<button class="del" style="color:#22d3ee" type="submit">فاکتور تمدید</button></form>
            @endif
            {{-- ══ لغو + بازگشتِ وجه به کیف پول ══
                 مسیرِ کاملِ خاتمه (زیرساخت هم آزاد می‌شود) + اعتبار — نه فقط
                 تغییرِ وضعیت. مبلغ از جمعِ پرداختیِ همین سرویس پیش‌پر می‌شود و
                 سقفش هم همان است؛ مدیر برای بازگشتِ جزئی کمش می‌کند. --}}
            @php $svcPaid = (int) $s->invoices->where('status', 'paid')->sum('paid'); @endphp
            <details class="svc-refund">
              <summary style="color:#ff6b6b;cursor:pointer;font-size:12.5px;display:inline-block">لغو + بازگشت وجه</summary>
              <form method="post" action="/admin/services/{{ $s->id }}/cancel-refund"
                    style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:6px;padding:8px;border:1px solid rgba(255,107,107,.3);border-radius:9px;background:rgba(255,107,107,.05)"
                    data-confirm="سرویس «{{ $s->name }}» لغو و مبلغِ واردشده به کیف پول برگردد؟ داده‌های سرویس حذف می‌شود و برگشت‌پذیر نیست."
                    data-confirm-danger>
                @csrf
                <input type="number" name="amount" dir="ltr" min="0" max="{{ $svcPaid }}" step="1000"
                       value="{{ $svcPaid }}"
                       style="width:120px;background:var(--surface2);border:1px solid var(--line);border-radius:7px;color:var(--text);padding:5px 8px;font:inherit;font-size:12px"
                       title="سقف: جمعِ پرداختیِ همین سرویس ({{ number_format($svcPaid) }} تومان)">
                <span style="font-size:11px;color:var(--dim)">تومان (سقف {{ fa_num(number_format($svcPaid)) }})</span>
                <input type="text" name="note" placeholder="یادداشت (اختیاری)" maxlength="200"
                       style="width:130px;background:var(--surface2);border:1px solid var(--line);border-radius:7px;color:var(--text);padding:5px 8px;font:inherit;font-size:12px">
                <button class="del" style="color:#ff6b6b" type="submit">لغو و بازگشت</button>
              </form>
            </details>
            @php
              /* 🔴 گیتِ قبلی `@if($s->server_id || $s->domain)` بود، و سرورِ ابری
                 هیچ‌کدام را ندارد: `server_id` هرگز (سرور پیش از خرید وجود ندارد) و
                 `domain` فقط **بعد از** تحویلِ موفق در `finalize()` پر می‌شود.
                 نتیجه‌اش این بود که یک سفارشِ پارک‌شده یا شکست‌خوردهٔ ابری در کلِ
                 پنل هیچ دکمه‌ای نداشت و مدیر باید روت را دستی POST می‌کرد — همان
                 چیزی که «هیچ راهِ خروجی نیست» را ساخت. */
              $isCloudSvc = \App\Services\Cloud\CloudProvisioner::handles($s);
            @endphp
            @if($s->server_id || $s->domain || $isCloudSvc)
              {{-- «در حال آزادسازی» یعنی سرویس بسته شده و فقط حذفِ نزدِ زیرساخت مانده؛
                   دکمهٔ «ساخت روی سرور» آن‌جا بی‌معنی است (کنترلر هم ردش می‌کند). --}}
              {{-- 🔴 کارِ دستیِ چرخهٔ عمر: تمدید/تعلیق/ابطالِ نزدِ تأمین‌کننده.
                   جدا از «صفِ تحویل» است چون آن ستون فقط تحویلِ **اول** را
                   می‌شناسد. بی‌این دکمه، چکِ سلامت برای همیشه قرمز می‌مانْد. --}}
              {{-- ⚠️ انتساب داخلِ خودِ @ if، و **نه** شکلِ درون‌خطیِ @ php.
                   آن شکل این‌جا به یک تگِ بازِ بی‌بسته کامپایل شد و از همان
                   نقطه بقیهٔ صفحه خام مانْد. جالب اینکه همان چند خط به‌تنهایی
                   درست کامپایل می‌شود؛ فقط در این فایلِ بزرگ می‌شکند. علتش
                   هرچه باشد، این فایل تا امروز **هیچ** موردی از آن شکل نداشته
                   و ۶۵ بار @ if دارد — پس همان را می‌بریم.

                   🔴 و نسخهٔ اولِ همین کامنت، خودش تلهٔ شمارهٔ یکِ پروژه را
                   ساخت: برای «نشان‌دادنِ» آن تگ، تگِ واقعی را داخلِ متن نوشتم.
                   محلی php -l پاس شد و روی سرور نشد — و دیپلوی کلِ بکاپ را
                   برگرداند. **هرگز نامِ خامِ تگِ PHP را در Blade ننویس، حتی
                   داخلِ کامنت و حتی برای توضیح‌دادنِ خودش.**
                   (فاصله‌های داخلِ این کامنت عمدی است: نامِ خامِ دستور
                   داخلِ کامنت با دستورِ پایانیِ بعدی جفت می‌شود.) --}}
              @if($ma = $s->pendingManualAction())
                <div style="margin-top:6px;padding:7px 9px;border:1px solid #fbbf24;border-radius:8px;background:rgba(251,191,36,.08)">
                  <div style="font-size:12px;color:#fbbf24">
                    🔔 کارِ دستی: <b>{{ ['renew' => 'تمدیدِ نزدِ تأمین‌کننده', 'suspend' => 'غیرفعال‌سازیِ نزدِ تأمین‌کننده', 'terminate' => 'ابطالِ نزدِ تأمین‌کننده'][$ma['kind']] ?? $ma['kind'] }}</b>
                  </div>
                  <div style="font-size:11px;color:var(--dim);margin-top:2px">{{ $ma['note'] ?? '' }}</div>
                  <form method="post" action="/admin/services/{{ $s->id }}/ack-manual" style="display:inline">@csrf
                    <button class="del" style="color:#34d399;margin-top:4px" type="submit">انجام شد</button>
                  </form>
                </div>
              @endif

              @if($s->provision_status !== 'done' && $s->provision_status !== \App\Models\Service::PROVISION_RELEASING)
                {{-- نیاز به ساخت — اگر سروری نخورده، همین‌جا سرور/پلن را تعیین کن و بساز --}}
                <form method="post" action="/admin/services/{{ $s->id }}/provision" style="display:flex;gap:5px;align-items:center;flex-wrap:wrap;margin-top:5px">@csrf
                  @unless($isCloudSvc)
                    @unless($s->server_id)
                      <select name="server_id" required style="background:var(--surface2);border:1px solid var(--line);border-radius:7px;color:var(--text);padding:5px 7px;font:inherit;font-size:12px">
                        <option value="">سرور…</option>@foreach($servers as $srv)<option value="{{ $srv->id }}">{{ $srv->name }}</option>@endforeach
                      </select>
                      <input type="text" name="plan" value="{{ $s->plan }}" placeholder="plan (WHM)" style="width:100px;background:var(--surface2);border:1px solid var(--line);border-radius:7px;color:var(--text);padding:5px 7px;font:inherit;font-size:12px">
                    @endunless
                    @unless($s->domain)<input type="text" name="domain" dir="ltr" placeholder="domain" style="width:130px;background:var(--surface2);border:1px solid var(--line);border-radius:7px;color:var(--text);padding:5px 7px;font:inherit;font-size:12px">@endunless
                  @endunless
                  <button class="del" style="color:#34d399" type="submit">{{ in_array($s->provision_status, ['failed', 'manual'], true) ? 'تلاش دوباره' : 'ساخت روی سرور' }}</button>
                </form>

                @if($isCloudSvc && $s->provision_status === 'manual')
                  {{-- 🔴 «تلاشِ دوباره» روی یک سفارشِ پارک‌شده دوباره از همان محافظ
                       رد می‌شود و همان‌جا برمی‌گردد. تنها درِ خروج این دکمه است:
                       تصمیمِ آگاهانه و تک‌سرویسیِ مدیر، ثبت‌شده در تاریخچه. --}}
                  <form method="post" action="/admin/services/{{ $s->id }}/provision-override" style="display:inline"
                        data-confirm="محافظِ سوءاستفاده برای همین یک سفارش کنار گذاشته شود و سرور ساخته شود؟ این کار ثبت می‌شود." data-confirm-danger>@csrf<button class="del" style="color:#fbbf24" type="submit">تأیید و ساخت (کنارگذاشتنِ محافظ)</button></form>
                @endif
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

  {{-- ══ لغوشده‌ها: ته فهرست و تاشده ══ --}}
  @if($deadServices->isNotEmpty())
    <details class="ad-fold" style="border-top:1px solid var(--line)">
      <summary style="padding:12px 16px;cursor:pointer;color:var(--muted);font-size:13px">
        سرویس‌های لغو/خاتمه‌یافته ({{ fa_num($deadServices->count()) }})
      </summary>
      <table class="ad-table">
        <thead><tr><th>سرویس</th><th>آدرس</th><th>مبلغ</th><th>وضعیت</th><th></th></tr></thead>
        <tbody>
          @foreach($deadServices as $s)
            @php
              $sb2 = $s->statusBadge();
              $m2  = is_array($s->provision_meta) ? $s->provision_meta : [];
              $ip2 = $m2['ip'] ?? $s->server?->server_ip;
            @endphp
            <tr style="opacity:.72">
              <td>{{ $s->name }}</td>
              <td style="white-space:nowrap">
                @if($s->domain)<span dir="ltr">{{ $s->domain }}</span>@endif
                @if($ip2)<div dir="ltr" style="font-size:12px;color:var(--muted)">{{ $ip2 }}</div>@endif
                @unless($s->domain || $ip2)<span style="color:var(--dim)">—</span>@endunless
              </td>
              <td>{{ $money($s->total()) }}</td>
              <td><span class="ad-badge" style="background:{{ $sb2[1] }}22;color:{{ $sb2[1] }}">{{ $sb2[0] }}</span></td>
              <td class="ad-row-act" style="white-space:nowrap">
                <a href="/admin/services/{{ $s->id }}/history" class="del" style="color:var(--muted)">تاریخچه</a>
                {{-- 🔴 «در حالِ آزادسازی» تنها وضعیتی است که خودش بسته نمی‌شود.

                     اگر مدیر ماشین را دستی پاک کرده باشد، حذفِ خودکار هرگز موفق
                     نمی‌شود و `cloud:release-retry` هر ساعت پیامِ تکراری می‌فرستد.

                     ⚠️ و جایش **همین جدول** است، نه فهرستِ زنده: سرویسی که در
                     حالِ آزادسازی است طبقِ تعریف لغو/خاتمه‌یافته است، پس همیشه
                     در `deadServices` می‌افتد. اولین نسخه را در جدولِ بالا
                     گذاشتم و تست گرفتش — دکمه در جدولی بود که آن سرویس هرگز
                     به آن نمی‌رسد، یعنی باز هم کدِ مرده.

                     🔴 و متدِ کنترلرش از قبل روی سرور بود ولی هیچ روت و دکمه‌ای
                     نداشت؛ داکبلاکش می‌گفت مشکل حل شده در حالی که نبود. --}}
                @if($s->provision_status === \App\Models\Service::PROVISION_RELEASING)
                  <form method="post" action="/admin/services/{{ $s->id }}/resolve-release" style="display:inline"
                        onsubmit="event.preventDefault();var f=this;snConfirm('تأیید می‌کنید این سرور دیگر نزدِ زیرساخت وجود ندارد؟ صفِ تلاشِ دوبارهٔ حذف بسته می‌شود.').then(function(ok){if(ok){f.submit();}});">@csrf
                    <button class="del" style="color:#34d399" type="submit">سرور دستی پاک شد — ببند</button>
                  </form>
                @endif
                {{--
                  🔴 حذف فقط برای سرویسی که **هرگز ساخته نشده و پولی رویش
                  ننشسته**. `Service::isDeletable()` تصمیم می‌گیرد، نه این ویو —
                  وگرنه یک شرطِ دستیِ دوم روزی با منطقِ واقعی واگرا می‌شود و
                  دکمه‌ای می‌سازد که سابقهٔ مالی را پاک می‌کند.
                --}}
                @if($s->isDeletable())
                  <form method="post" action="/admin/services/{{ $s->id }}" style="display:inline"
                        onsubmit="event.preventDefault();var f=this;snConfirm('این سرویس برای همیشه حذف شود؟ (هیچ پرداختی رویش نیست)',{danger:true}).then(function(ok){if(ok){f.submit();}});">
                    @csrf @method('DELETE')
                    <button class="del" style="color:#ff6b6b" type="submit">حذف</button>
                  </form>
                @endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </details>
  @endif

  {{--
    فروش سرویس جدید — پشتِ دکمهٔ بالای همین پنل.

    ⚠️ `hidden` به‌تنهایی کافی نیست اگر عنصر `display:` صریح داشته باشد؛
    این‌جا ندارد، پس `hidden` کار می‌کند. (تلهٔ ثبت‌شدهٔ پروژه — سه بار.)
  --}}
  <div id="sell-form-wrap" hidden style="border-top:1px solid var(--line);padding:16px">
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
      <label style="display:flex;flex-direction:column;gap:5px;font-size:12.5px;color:var(--muted)">تخفیف (٪)
        <input type="number" name="discount_pct" value="0" min="0" max="100" step="0.5" dir="ltr" style="background:var(--surface2);border:1px solid var(--line);border-radius:8px;color:var(--text);padding:8px 12px;font:inherit;text-align:left">
        <small style="font-size:11px">روی مبلغ اعمال و در تمدیدها هم حفظ می‌شود</small></label>

      {{-- ⚠️ فقط گذشته: فاکتورِ تاریخ‌دارِ آینده یعنی سندی که هنوز صادر نشده.
           سررسیدِ سرویس با این تاریخ عقب **نمی‌رود** — وگرنه کرونِ چرخهٔ عمر
           همان روز سرویس را «سررسیدگذشته» می‌دید و تعلیقش می‌کرد. --}}
      {{--
        تاریخِ صدور — **شمسی** وارد می‌شود، **میلادی** ذخیره می‌شود.

        🔴 چرا سه انتخابگرِ سرورساخته و نه یک پیکرِ جاوااسکریپتی:

        قاعدهٔ ثبت‌شدهٔ همین پروژه می‌گوید «ریاضیِ جلالی فقط در PHP». یک پیکرِ
        مرورگری یعنی الگوریتمِ دوم، و دو پیاده‌سازیِ جلالی دیر یا زود یک روز
        اختلاف پیدا می‌کنند — در فرمی که **تاریخِ سندِ حسابداری** می‌سازد.
        (همان تله که یک بار `jalali_ymd()` را در سالِ کبیسه شکست.)

        این‌جا گزینه‌ها را PHP می‌سازد، تبدیل را PHP انجام می‌دهد، و مرورگر فقط
        سه عدد پس می‌دهد. هیچ ریاضی‌ای سمتِ کاربر نیست، پس چیزی برای واگرایی
        هم نیست.

        ⚠️ تعدادِ روزِ ماه سمتِ سرور اعتبارسنجی می‌شود (اسفندِ ۳۰ روزه فقط در
        سالِ کبیسه)، نه با پنهان‌کردنِ گزینه‌ها — پنهان‌کردن، خطای واقعی را به
        یک انتخابِ بی‌صدا تبدیل می‌کند.
      --}}
      @php
        $jNow  = \App\Support\Jalali::ofMoment(now(), config('calendar.display_timezone', 'Asia/Tehran'));
        $jYear = (int) $jNow[0];
      @endphp
      <div style="display:flex;flex-direction:column;gap:5px;font-size:12.5px;color:var(--muted)">
        <span>تاریخ صدور فاکتور (شمسی)</span>
        <div style="display:flex;gap:6px" dir="rtl">
          <select name="issued_jy" style="flex:1.2;background:var(--surface2);border:1px solid var(--line);border-radius:8px;color:var(--text);padding:8px 10px;font:inherit">
            <option value="">سال</option>
            @for($y = $jYear; $y >= $jYear - 2; $y--)
              <option value="{{ $y }}">{{ fa_num($y) }}</option>
            @endfor
          </select>
          <select name="issued_jm" style="flex:1.6;background:var(--surface2);border:1px solid var(--line);border-radius:8px;color:var(--text);padding:8px 10px;font:inherit">
            <option value="">ماه</option>
            @for($m = 1; $m <= 12; $m++)
              <option value="{{ $m }}">{{ \App\Support\Jalali::monthName($m) }}</option>
            @endfor
          </select>
          <select name="issued_jd" style="flex:1;background:var(--surface2);border:1px solid var(--line);border-radius:8px;color:var(--text);padding:8px 10px;font:inherit">
            <option value="">روز</option>
            @for($d = 1; $d <= 31; $d++)
              <option value="{{ $d }}">{{ fa_num($d) }}</option>
            @endfor
          </select>
        </div>
        <small style="font-size:11px">خالی = امروز · فقط گذشته · دورهٔ سرویس همیشه از امروز شروع می‌شود</small>
      </div>

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

</div>{{-- /pane services --}}

{{-- ─────────── تبِ مالی ─────────── --}}
<div class="ct-pane" data-pane="finance">
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
        <thead><tr><th>درگاه</th><th>شناسهٔ پیگیری</th><th>مبلغ</th><th>وضعیت</th><th>تاریخ</th></tr></thead>
        <tbody>
          @foreach($c->payments as $p)
          <tr>
            <td>{{ ['zarinpal'=>'زرین‌پال','bale'=>'بله','bank_transfer'=>'واریز به حساب'][$p->gateway] ?? $p->gateway }}</td>
            {{-- شناسهٔ پیگیری: تنها چیزی که با آن می‌شود پرداخت را در بانک/درگاه
                 ردیابی کرد و اولین چیزی که پشتیبانی از مشتری می‌پرسد. --}}
            <td dir="ltr" style="font-size:12px">
              @if($p->ref_id)
                <span class="copyable" title="کلیک = کپی">{{ $p->ref_id }}</span>
              @else<span style="color:var(--dim)">—</span>@endif
              @if($p->card_mask)<div style="color:var(--dim);font-size:11px">{{ $p->card_mask }}</div>@endif
            </td>
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

</div>{{-- /ad-grid2 --}}
</div>{{-- /pane finance --}}

{{-- ─────────── تبِ پشتیبانی ─────────── --}}
<div class="ct-pane" data-pane="support">
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

{{-- ══ فعالیت — تبِ مستقل با فیلتر و صفحه‌بندی ══

  🔴 چرا فیلتر سمتِ سرور است و نه مرورگر: تاریخچهٔ یک مشتریِ قدیمی هزاران ردیف
  می‌شود. فرستادنِ همه به مرورگر برای فیلترِ محلی یعنی صفحه‌ای که بارگذاری‌اش
  طول می‌کشد و حافظه می‌خورد — و همان صفحه‌ای است که مدیر روزی ده بار بازش
  می‌کند.

  ⚠️ فرمِ فیلتر `#activity` را در action دارد تا بعد از ثبت، همین تب باز
  بمانَد. بی‌آن، مدیر فیلتر می‌زد و صفحه روی تبِ «سرویس‌ها» برمی‌گشت. --}}
<div class="ct-pane" data-pane="activity">
<div class="ad-panel">
  @php $actFiltered = request()->hasAny(['q','act','who','from','to']); @endphp
  <div class="ad-panel-h"><h3>تاریخچهٔ فعالیت</h3>
    {{-- 🔴 «چند از چند» صریح گفته می‌شود.
         جدولِ صفحه‌بندی‌شده‌ای که فقط تعدادِ همین صفحه را نشان دهد، این توهم را
         می‌سازد که کلِ تاریخچه همین است — و جستجویی که کلِ تاریخچه را می‌گردد،
         بی‌این عدد قابلِ اعتماد نیست. --}}
    <span class="ad-badge" style="background:var(--surface2);color:var(--muted)">
      @if($actFiltered)
        {{ fa_num($activity->total()) }} از {{ fa_num($activityTotal) }} رویداد
      @else
        {{ fa_num($activityTotal) }} رویداد
      @endif
    </span>
  </div>

  <form method="get" action="{{ url()->current() }}#activity" class="act-filter">
    {{-- جستجو **کلِ** تاریخچه را می‌گردد، نه فقط صفحهٔ جاری (فیلتر سمتِ سرور
         است). متنِ placeholder همین را می‌گوید تا کسی فرض نکند محلی است. --}}
    <input type="search" name="q" value="{{ request('q') }}" placeholder="جستجو در کلِ تاریخچه (شرح یا IP)…">

    <select name="act">
      <option value="">همهٔ رویدادها</option>
      @foreach($activityFacets['actions'] as $a)
        <option value="{{ $a }}" @selected(request('act') === $a)>{{ \App\Models\ActivityLog::label($a) }}</option>
      @endforeach
    </select>

    <select name="who">
      <option value="">همه</option>
      @foreach($activityFacets['actors'] as $w)
        <option value="{{ $w }}" @selected(request('who') === $w)>{{ \App\Models\ActivityLog::actorLabel($w) }}</option>
      @endforeach
    </select>

    {{-- تاریخ‌ها با همان دیت‌پیکرِ شمسی؛ مقدارِ ارسالی میلادیِ ISO است چون
         سرور می‌سازدش — هیچ تبدیلی در مرورگر انجام نمی‌شود. --}}
    <span class="act-dt"><label>از</label><input type="hidden" name="from" data-jdate value="{{ request('from') }}"></span>
    <span class="act-dt"><label>تا</label><input type="hidden" name="to" data-jdate value="{{ request('to') }}"></span>

    <select name="per" title="تعداد ردیف در هر صفحه">
      @foreach(\App\Http\Controllers\Admin\CustomerController::ACTIVITY_SIZES as $sz)
        <option value="{{ $sz }}" @selected((int) request('per', 100) === $sz)>{{ fa_num($sz) }} ردیف</option>
      @endforeach
    </select>

    <button class="ad-btn" type="submit"><svg class="icon"><use href="#i-filter"/></svg>فیلتر</button>
    @if($actFiltered || request('per'))
      <a class="ad-btn" href="{{ url()->current() }}#activity">پاک کردن</a>
    @endif
  </form>

  @if($activity->isEmpty())
    <p style="padding:18px;color:var(--muted)">
      {{ request()->hasAny(['q','act','who','from','to'])
          ? 'با این فیلترها رویدادی پیدا نشد.'
          : 'هنوز فعالیتی برای این مشتری ثبت نشده.' }}
    </p>
  @else
    {{-- ⚠️ `data-no-enhance`: فیلترِ خودکارِ `admin-tables.js` این‌جا **نباید**
         بچسبد. آن فیلتر فقط روی ردیف‌های همین صفحه کار می‌کند، ولی این جدول
         صفحه‌بندی‌شده است — یعنی دو نوارِ فیلترِ روی‌هم که یکی‌شان فقط ۲۵ ردیفِ
         جاری را می‌گردد و دیگری کلِ تاریخچه را. مدیر نمی‌داند کدام را زده و
         چرا نتیجه فرق دارد. --}}
    <table class="ad-table" data-no-enhance>
      <thead>
        <tr><th style="width:34px"></th><th>رویداد</th><th>شرح</th><th>مبدأ</th><th>تاریخ</th></tr>
      </thead>
      <tbody>
        @foreach($activity as $a)
        <tr>
          <td><svg class="icon" style="width:16px;height:16px;color:var(--muted)"><use href="#{{ $a->icon() }}"/></svg></td>
          <td style="white-space:nowrap">
            {{ \App\Models\ActivityLog::label($a->action) }}
            @if($a->actor === 'staff')<span class="ad-badge" style="background:rgba(34,211,238,.12);color:#22d3ee;margin-inline-start:6px">پشتیبانی</span>
            @elseif($a->actor === 'system')<span class="ad-badge" style="background:var(--surface2);color:var(--muted);margin-inline-start:6px">سامانه</span>@endif
          </td>
          <td>{{ $a->description }}</td>
          <td dir="ltr" style="color:var(--muted)">
            {{ $a->ip ?: '—' }}
            @php $adev = $a->device(); $ageo = $a->geoLabel(); @endphp
            @if($adev['label'] !== '—' || $ageo)
              <div dir="rtl" style="color:var(--dim);font-size:11.5px;margin-top:2px">{{ $adev['label'] !== '—' ? $adev['label'] : '' }}{{ $ageo ? (($adev['label'] !== '—' ? ' · ' : '').$ageo) : '' }}</div>
            @endif
          </td>
          {{-- تاریخ شمسی — `stime()` خودش شمسی می‌دهد --}}
          <td style="color:var(--dim);white-space:nowrap">{{ stime($a->created_at) }}</td>
        </tr>
        @endforeach
      </tbody>
    </table>

    @if($activity->hasPages())
      <div class="act-pager">{{ $activity->onEachSide(1)->links() }}</div>
    @endif
  @endif
</div>
</div>{{-- /pane activity --}}

{{-- ─────────── تبِ هویت و حساب: تکهٔ دوم (مدیریت) ─────────── --}}
<div class="ct-pane" data-pane="account">
{{-- ══ نمایندگی دامنه ══

  🔴 چرا مدیر روشنش می‌کند و نه خود مشتری: نمایندگی یک قرارداد است. حساب
  نماینده با یک درخواست HTTP از اعتبارش دامنهٔ واقعی می‌خرد، و مسئولیت
  سوءاستفاده (فیشینگ/اسپم روی دامنه‌ای که ثبت کرده) در برابر رجیسترار پای
  ماست — یعنی چیزی که با چک‌باکس خودسرویس واگذار نمی‌شود. --}}
@php
  $rsProgram = app(\App\Services\Domain\Reseller\ResellerProgram::class);
  $rsLevels  = $rsProgram->levels();
  $rsNow     = $rsProgram->currentLevel($c);
@endphp
<div class="ad-panel" style="margin:0 0 16px">
  <div class="ad-panel-h"><h3>نمایندگی دامنه</h3></div>
  <form method="post" action="/admin/customers/{{ $c->id }}/reseller"
        style="padding:16px;display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
    @csrf
    <label style="display:flex;gap:8px;align-items:center;font-size:14px">
      <input type="hidden" name="is_reseller" value="0">
      <input type="checkbox" name="is_reseller" value="1" @checked($c->is_reseller)>
      نمایندهٔ دامنه است
    </label>

    <label style="display:flex;flex-direction:column;gap:4px;font-size:12px;color:var(--dim)">سطح (خالی = محاسبهٔ خودکار)
      <select name="level" style="background:var(--surface2);border:1px solid var(--line);border-radius:9px;color:var(--text);padding:8px 12px;font:inherit">
        <option value="">— خودکار از روی حجم —</option>
        @foreach($rsLevels as $l)
          <option value="{{ $l['key'] }}" @selected($c->reseller_level === $l['key'])>
            {{ lc($l['name'] ?? []) ?: $l['key'] }} ({{ $l['discount_pct'] }}٪)
          </option>
        @endforeach
      </select>
    </label>

    <label style="display:flex;flex-direction:column;gap:4px;font-size:12px;color:var(--dim)">تخفیف توافقی (٪)
      <input type="number" name="bonus_pct" min="0" max="50" value="{{ (int) $c->reseller_bonus_pct }}" dir="ltr"
             style="background:var(--surface2);border:1px solid var(--line);border-radius:9px;color:var(--text);padding:8px 12px;font:inherit;width:110px;text-align:left">
    </label>

    <label style="display:flex;flex-direction:column;gap:4px;font-size:12px;color:var(--dim)">سقف خرج روزانه (تومان، ۰ = پیش‌فرض)
      <input type="number" name="daily_cap_irt" min="0" value="{{ (int) $c->reseller_daily_cap_irt }}" dir="ltr"
             style="background:var(--surface2);border:1px solid var(--line);border-radius:9px;color:var(--text);padding:8px 12px;font:inherit;width:170px;text-align:left">
    </label>

    <button class="btn btn-primary" type="submit">ذخیره</button>
  </form>

  <p style="padding:0 16px 14px;margin:0;font-size:12px;color:var(--dim);line-height:2">
    @if($c->is_reseller)
      سطح فعلی: <b>{{ lc($rsNow['name'] ?? []) ?: $rsNow['key'] }}</b> ·
      تخفیف مؤثر: <b>{{ fa_num((string) $rsProgram->discountPct($c)) }}٪</b> ·
      حجم ۱۲ ماه: {{ fa_num(number_format((int) $c->reseller_volume)) }} تومان
      @if($c->reseller_level_locked_until)
        {{-- ⚠️ `--amber` توکنِ واقعیِ admin.css است. `--warn` وجود ندارد و
             fallbackِ سخت‌کد (`var(--warn,#e0a800)`) با تمِ روشن عوض نمی‌شود —
             `CssVariablesDefinedTest` دقیقاً همین را می‌گیرد. --}}
        · <span style="color:var(--amber)">مهلت تنزل تا {{ sdate($c->reseller_level_locked_until) }}</span>
      @endif
    @else
      غیرفعال.
    @endif
    <br>
    ⚠️ تخفیف توافقی هم مثل تخفیف سطح، از کف حاشیهٔ سود عبور نمی‌کند — قیمت هرگز زیر بهای تمام‌شده نمی‌رود.
  </p>
</div>

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

</div>{{-- /pane account (تکهٔ مدیریت) --}}

<style>
.cust-head{ display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; margin-bottom:16px }
.cust-kpis{ display:grid; grid-template-columns:repeat(6,1fr); gap:12px; margin-bottom:16px }
.cust-kpi{ padding:14px 16px; background:var(--panel,var(--surface)); border:1px solid var(--line); border-radius:12px }
.cust-kpi b{ display:block; font-size:18px; color:var(--text); font-variant-numeric:tabular-nums }
.cust-kpi span{ font-size:11.5px; color:var(--muted) }
/* .kv حالا در admin.css است (شبکهٔ توریِ دوستونه) — این‌جا تکرار نشود */

/* ── تب‌ها ─────────────────────────────────────────────────────────────── */
.ct-tabs{ display:flex; gap:6px; margin-bottom:16px; border-bottom:1px solid var(--line); flex-wrap:wrap }
.ct-tab{ display:inline-flex; align-items:center; gap:8px; background:none; border:0; cursor:pointer;
  font:inherit; font-size:13.5px; font-weight:600; color:var(--muted); padding:11px 16px;
  border-bottom:2px solid transparent; margin-bottom:-1px; transition:color .15s, border-color .15s }
.ct-tab:hover{ color:var(--text) }
.ct-tab.on{ color:var(--cyan); border-bottom-color:var(--cyan) }
.ct-tab .icon{ width:16px; height:16px }
.ct-n{ font-style:normal; font-size:11px; font-weight:700; background:var(--surface2);
  border:1px solid var(--line); border-radius:20px; padding:1px 7px; color:var(--muted) }
.ct-tab.on .ct-n{ background:rgba(34,211,238,.12); border-color:rgba(34,211,238,.3); color:var(--cyan) }
.ct-n.warn{ background:rgba(251,191,36,.14); border-color:rgba(251,191,36,.32); color:var(--amber) }
.ct-pane{ display:none }
.ct-pane.on{ display:block }

/* ردیفِ سرویس: مشخصاتِ فنی زیرِ نامِ سرویس */
.svc-meta{ display:flex; flex-wrap:wrap; gap:6px 12px; margin-top:6px }
.svc-meta i{ font-style:normal; font-size:11.5px; color:var(--dim); display:inline-flex; align-items:center; gap:4px }
.svc-meta i b{ color:var(--muted); font-weight:600 }

@media(max-width:1100px){ .cust-kpis{ grid-template-columns:repeat(3,1fr) } }
@media(max-width:640px){ .cust-kpis{ grid-template-columns:repeat(2,1fr) } .ct-tab{ padding:10px 12px; font-size:13px } }
</style>

<script>
/* فرمِ فروشِ سرویس: پشتِ دکمهٔ بالای پنل، نه همیشه‌باز در انتهای صفحه.
 *
 * ⚠️ `hidden` روی `#sell-form-wrap` کار می‌کند چون آن عنصر `display:` صریح
 * ندارد. اگر روزی کسی برایش `display:grid` گذاشت، باید قاعدهٔ
 * `[hidden]{display:none}` هم اضافه شود — سه بار در این پروژه همین تله. */
(function () {
  var btn  = document.querySelector('[data-open-sell]');
  var wrap = document.getElementById('sell-form-wrap');
  if (!btn || !wrap) { return; }

  btn.addEventListener('click', function () {
    wrap.hidden = !wrap.hidden;
    btn.textContent = wrap.hidden ? '+ فروش سرویس جدید' : '× بستن فرم';
    if (!wrap.hidden) {
      wrap.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      var first = wrap.querySelector('input[name="name"]');
      if (first) { first.focus(); }
    }
  });

  /* اگر اعتبارسنجی سرور خطا داد، فرم باید **باز** برگردد — وگرنه مدیر پیامِ
   * خطا را می‌بیند و فرمی که خطا داده پنهان است. */
  @if($errors->any())
    btn.click();
  @endif
})();

(function () {
  var tabs  = document.querySelectorAll('.ct-tab');
  var panes = document.querySelectorAll('.ct-pane');
  if (!tabs.length) return;

  function show(name) {
    tabs.forEach(function (t) { t.classList.toggle('on', t.dataset.tab === name); });
    // هر تب می‌تواند چند تکه داشته باشد، پس همهٔ هم‌نام‌ها با هم روشن می‌شوند
    panes.forEach(function (p) { p.classList.toggle('on', p.dataset.pane === name); });
    try { history.replaceState(null, '', '#' + name); } catch (e) {}
  }

  tabs.forEach(function (t) {
    t.addEventListener('click', function () { show(t.dataset.tab); });
  });

  // با لینکِ #finance یا رفرش، همان تب باز بماند
  var initial = (location.hash || '').replace('#', '');
  if (initial && document.querySelector('.ct-pane[data-pane="' + initial + '"]')) show(initial);
})();
</script>
@endsection

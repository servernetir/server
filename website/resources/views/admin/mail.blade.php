@extends('admin.layout')
@section('title', 'صندوق ایمیل')
@section('nav_mail', 'on')
@section('head')<link rel="stylesheet" href="{{ asset_ver('assets/css/marketing.css') }}">@endsection
@section('content')

@if($notReady)
  <div class="mk">
    <div class="mk-empty">
      <svg class="icon"><use href="#i-db"/></svg>
      <b>جدولِ صندوق‌ها ساخته نشده</b>
      <p>یک‌بار <code dir="ltr">php artisan migrate</code> بزن تا این بخش فعال شود.</p>
    </div>
  </div>
@elseif(! $configured)
  <div class="mk">
    <div class="mk-empty">
      <svg class="icon"><use href="#i-mail"/></svg>
      <b>هیچ صندوقی وصل نیست</b>
      <p>
        رمزِ هر صندوق را در <code dir="ltr">.env</code> بگذار (<code dir="ltr">MAILBOX_CEO_PASS</code> و مانندش)
        و بعد <code dir="ltr">php artisan config:clear</code>.
        <br>
        رمزها عمداً در دیتابیس ذخیره نمی‌شوند — رمزِ ایمیل در دیتابیس یعنی رمزِ ایمیل در هر بکاپ.
      </p>
    </div>
  </div>
@else

<div class="mk">

  <div class="mk-head">
    <div>
      <h2>صندوق ایمیل</h2>
      <p>این یک کلاینتِ ایمیل نیست — فقط می‌گوید چه چیزی هنوز رسیدگی نشده و کدامش جواب می‌خواهد.</p>
    </div>
  </div>

  {{--
    ══ صندوقی که خوانده نمی‌شود ══

    🔴 بی‌این نوار، شکستِ IMAP دقیقاً شبیهِ «امروز کسی ایمیل نزده» بود: کاشی‌ها
    عددِ دیروز را نشان می‌دادند و تنها ردِ خرابی یک خط در `laravel.log`ِ ۱۰
    مگابایتی بود که از پنل خوانده نمی‌شود. متنِ **واقعیِ** خطا این‌جا می‌آید تا
    رفعش به SSH گره نخورد.
  --}}
  @foreach($syncErrors as $e)
    <div class="mk-alert">
      <b>صندوقِ {{ $e['label'] }} خوانده نمی‌شود</b>
      <span dir="ltr">{{ $e['error'] }}</span>
      <small>یعنی نامهٔ تازه‌ای از این صندوق وارد پنل نمی‌شود. اگر رمز عوض شده، رمزِ برنامهٔ صندوق را در <code>.env</code> سرور به‌روز کنید.</small>
    </div>
  @endforeach

  {{-- ══ هر صندوق یک کاشی ══ --}}
  <div class="mk-tiles">
    @foreach($boxes as $b)
      <a href="/admin/mail?box={{ $b['key'] }}" class="mk-tile {{ $b['reply'] ? 'is-hot' : '' }} {{ $account === $b['key'] ? 'is-good' : '' }}" style="display:block">
        <div class="mk-tile-k"><svg class="icon"><use href="#i-mail"/></svg>{{ $b['label'] }}</div>
        <div class="mk-tile-v">{{ $b['reply'] }}<small>جواب می‌خواهد</small></div>
        <div class="mk-tile-s">{{ $b['open'] }} باز · آخرین نامه {{ $b['last'] ? \Illuminate\Support\Carbon::parse($b['last'])->diffForHumans() : '—' }}</div>
      </a>
    @endforeach
  </div>

  <div class="mk-health">
    <span class="{{ $pending ? 'warn' : 'ok' }}">{{ $pending ?: 'هیچ' }} نامهٔ دسته‌بندی‌نشده</span>
    <span>{{ $systemSeen }} نامهٔ سیستمیِ خودمان کنار گذاشته شد</span>
    @if($account !== '')<span class="ok">صندوقِ {{ collect($boxes)->firstWhere('key', $account)['label'] ?? $account }}</span>@endif
  </div>

  @php $base = '/admin/mail?'.($account !== '' ? 'box='.$account.'&' : ''); @endphp
  <div class="mk-tabs" style="margin-top:18px">
    @foreach(['open' => 'باز', 'reply' => 'جواب می‌خواهد', 'all' => 'همه', 'system' => 'سیستمی'] as $key => $label)
      <a href="{{ $base }}show={{ $key }}" class="{{ $filter === $key ? 'on' : '' }}">{{ $label }}</a>
    @endforeach
    @if($account !== '')<a href="/admin/mail?show={{ $filter }}">همهٔ صندوق‌ها</a>@endif
  </div>

  @if($messages->isEmpty())
    <div class="mk-empty">
      <svg class="icon"><use href="#i-check"/></svg>
      <b>{{ $filter === 'open' ? 'همه‌چیز رسیدگی شده' : 'نامه‌ای پیدا نشد' }}</b>
      <p>{{ $filter === 'open' ? 'صندوق تمیز است.' : 'با این فیلتر چیزی نیست.' }}</p>
    </div>
  @else
    <div class="mk-rows">
      @foreach($messages as $m)
        <div class="mk-row {{ $m->needs_reply ? 'is-hot' : '' }} {{ $m->is_system ? 'is-wait' : '' }}">
          <div>
            <b>{{ $m->subject ?: '(بدون موضوع)' }}</b>
            <small>{{ $m->from_name ?: $m->from_email }} · <span dir="ltr">{{ $m->from_email }}</span></small>
            @if(filled($m->summary))<div class="mk-obs" style="margin-top:7px">{{ $m->summary }}</div>@endif
          </div>
          <div>
            <span class="mk-tag c">{{ $m->accountLabel() }}</span>
            @if($m->category)<span class="mk-tag {{ $m->category === 'sales' ? 'g' : 'v' }}" style="margin-top:6px">{{ $m->categoryLabel() }}</span>@endif
          </div>
          <div>
            @if($m->needs_reply)<span class="mk-tag r">جواب می‌خواهد</span>@endif
            @if($m->is_system)<span class="mk-tag" style="margin-top:6px">سیستمی</span>@endif
            <small style="margin-top:6px" dir="ltr">{{ $m->received_at?->format('Y-m-d H:i') }}</small>
          </div>
          <div>
            @if($m->handled_at)
              <form method="post" action="/admin/mail/{{ $m->id }}/reopen">@csrf<button class="mk-btn" type="submit">باز کن</button></form>
            @else
              <form method="post" action="/admin/mail/{{ $m->id }}/handled">@csrf<button class="mk-btn" type="submit">رسیدگی شد</button></form>
            @endif
          </div>
        </div>
      @endforeach
    </div>
  @endif

  {{-- ══ بستنِ گروهی ══ --}}
  <div class="ad-panel" style="margin-top:16px">
    <div class="ad-panel-h"><h2>بستنِ گروهی</h2></div>
    <div style="padding:0 18px">
      <p style="color:var(--muted);font-size:13.5px;line-height:1.95">
        روزِ اول، صندوقی که سال‌ها پر شده صدها نامهٔ «باز» می‌سازد که هیچ‌کدام کاری ندارند.
        یک‌بار همه را ببند تا از فردا فقط چیزهای تازه بماند. چیزی پاک نمی‌شود.
      </p>
    </div>
    <form method="post" action="/admin/mail/clear" class="mk-form" style="padding:14px 18px 18px"
          data-confirm="همهٔ این نامه‌ها «رسیدگی‌شده» علامت می‌خورند.">
      @csrf
      <div>
        <label>صندوق</label>
        <select name="box">
          <option value="">همه</option>
          @foreach($boxes as $b)<option value="{{ $b['key'] }}">{{ $b['label'] }}</option>@endforeach
        </select>
      </div>
      <div><label>قدیمی‌تر از</label><input type="date" name="before" dir="ltr" value="{{ now()->toDateString() }}"></div>
      <div style="align-self:end"><button class="mk-btn" type="submit">ببند</button></div>
    </form>
  </div>

</div>
@endif
@endsection

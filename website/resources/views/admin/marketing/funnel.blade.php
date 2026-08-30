@extends('admin.layout')
@section('title', 'بازاریابی هوشمند')
@section('nav_marketing', 'on')
@section('head')<link rel="stylesheet" href="{{ asset_ver('assets/css/marketing.css') }}">@endsection
@section('content')

@if($notReady)
  <div class="mk">
    <div class="mk-empty">
      <svg class="icon"><use href="#i-db"/></svg>
      <b>جدول‌ها هنوز ساخته نشده‌اند</b>
      <p>روی این سرور مهاجرت‌های CMS اجرا نشده. یک‌بار <code dir="ltr">php artisan migrate</code> بزن تا این بخش فعال شود.</p>
    </div>
  </div>
@else

<div class="mk">

  <div class="mk-head">
    <div>
      <h2>بازاریابی هوشمند</h2>
      <p>پیدا کردنِ کسب‌وکارهایی که به سایت نیاز دارند، نوشتنِ پیامی که ارزشِ خواندن دارد، و پی‌گیریِ منظم. هیچ ایمیلی بدونِ عبور از این صفحه بیرون نمی‌رود.</p>
    </div>
    <a class="btn btn-primary" href="/admin/marketing/growth"><svg class="icon"><use href="#i-trend"/></svg>رشد و دیده‌شدن</a>
  </div>

  {{-- ══ چهار عددی که با یک نگاه وضعیت را می‌گویند ══ --}}
  <div class="mk-tiles">
    <div class="mk-tile">
      <div class="mk-tile-k"><svg class="icon"><use href="#i-users"/></svg>سرنخِ فعال</div>
      <div class="mk-tile-v">{{ number_format($stats['active']) }}</div>
      <div class="mk-tile-s">{{ number_format($stats['new']) }} تازه · {{ number_format($stats['enriched']) }} بررسی‌شده</div>
    </div>
    <div class="mk-tile {{ $stats['pending'] ? 'is-warn' : '' }}">
      <div class="mk-tile-k"><svg class="icon"><use href="#i-clock"/></svg>منتظرِ تأییدِ تو</div>
      <div class="mk-tile-v">{{ number_format($stats['pending']) }}</div>
      <div class="mk-tile-s">ارسال فقط با تأییدِ تو</div>
    </div>
    <div class="mk-tile {{ $stats['replied'] ? 'is-hot' : '' }}">
      <div class="mk-tile-k"><svg class="icon"><use href="#i-message"/></svg>جواب گرفته</div>
      <div class="mk-tile-v">{{ number_format($stats['replied']) }}</div>
      <div class="mk-tile-s">{{ $stats['sent'] ? 'از '.number_format($stats['sent']).' پیامِ فرستاده‌شده' : 'هنوز پیامی نرفته' }}</div>
    </div>
    <div class="mk-tile {{ $stats['won'] ? 'is-good' : '' }}">
      <div class="mk-tile-k"><svg class="icon"><use href="#i-coins"/></svg>ارزشِ پایپ‌لاین</div>
      <div class="mk-tile-v">{{ number_format($stats['value']) }}<small>یورو</small></div>
      <div class="mk-tile-s">{{ number_format($stats['won']) }} برنده تا امروز</div>
    </div>
  </div>

  {{-- ══ وضعیتِ موتور — چه چیزی روشن است و چه چیزی نه ══ --}}
  <div class="mk-health">
    <span class="{{ $autopilot ? 'ok' : 'off' }}">خلبانِ خودکار: {{ $autopilot ? 'روشن' : 'خاموش' }}</span>
    <span class="{{ $health['model'] ? 'ok' : 'warn' }}">مدلِ نویسنده</span>
    <span class="{{ $health['places'] ? 'ok' : 'off' }}">کشفِ خودکارِ سرنخ</span>
    <span class="{{ $health['imap'] ? 'ok' : 'warn' }}">خواندنِ جواب‌ها</span>
    <span class="{{ $health['mailer'] ? 'ok' : 'warn' }}">صندوقِ ارسال</span>
    <span>امروز {{ $sentToday }} از {{ $dailyCap }}</span>
    <span class="{{ $inWindow ? 'ok' : 'off' }}">پنجرهٔ ارسال {{ $inWindow ? 'باز' : 'بسته' }}</span>
  </div>

  @unless($health['imap'])
    <div class="mk-note" style="margin-top:14px">
      <svg class="icon"><use href="#i-info"/></svg>
      <div>خواندنِ جواب‌ها تنظیم نشده. بدونِ آن، اگر کسی جواب بدهد «قیمت چند؟» سیستم نمی‌فهمد و <b>فالوآپِ خودکار برایش می‌رود</b> — که همان یک سرنخ را از دست می‌دهد.</div>
    </div>
  @endunless

  <div class="mk-tabs" style="margin-top:18px">
    <a href="/admin/marketing" class="{{ $tab === 'funnel' ? 'on' : '' }}">قیف<span class="n">{{ $stats['active'] }}</span></a>
    <a href="/admin/marketing?tab=queue" class="{{ $tab === 'queue' ? 'on' : '' }}">صفِ تأیید@if($stats['pending'])<span class="n">{{ $stats['pending'] }}</span>@endif</a>
    <a href="/admin/marketing?tab=add" class="{{ $tab === 'add' ? 'on' : '' }}">افزودنِ سرنخ</a>
  </div>

  @if($tab === 'queue')
    @include('admin.marketing._queue', ['pending' => $pending])
  @elseif($tab === 'add')
    @include('admin.marketing._add')
  @else

    {{-- ══ قیف — هر مرحله یک ستون ══ --}}
    <div class="mk-funnel">
      @foreach(\App\Models\CrmLead::STAGES as $key => $label)
        <a href="/admin/marketing{{ $stage === $key ? '' : '?stage='.$key }}"
           class="{{ $stage === $key ? 'on' : '' }} {{ $key === 'replied' && ($counts[$key] ?? 0) ? 'is-hot' : '' }}">
          <div class="v">{{ $counts[$key] ?? 0 }}</div>
          <div class="k">{{ $label }}</div>
        </a>
      @endforeach
    </div>

    @if($leads->isEmpty())
      <div class="mk-empty">
        <svg class="icon"><use href="#i-users"/></svg>
        @if($stage !== '')
          <b>در این مرحله سرنخی نیست</b>
          <p>«{{ \App\Models\CrmLead::STAGES[$stage] ?? $stage }}» فعلاً خالی است. <a href="/admin/marketing">نمایشِ همه</a></p>
        @elseif($health['places'])
          <b>قیف هنوز خالی است</b>
          <p>کشفِ خودکار فعال است و اجرای بعدی‌اش سرنخ می‌آورد. اگر عجله داری، از تبِ «افزودنِ سرنخ» دستی هم می‌شود اضافه کرد.</p>
        @else
          <b>قیف خالی است</b>
          <p>کشفِ خودکار خاموش است — سرنخ را از تبِ «افزودنِ سرنخ» وارد کن. فقط نشانیِ سایت لازم است و بقیه‌اش را خودش درمی‌آورد.</p>
        @endif
      </div>
    @else
      <div class="mk-rows">
        @foreach($leads as $l)
          <div class="mk-row {{ $l->stage === 'replied' ? 'is-hot' : '' }} {{ $l->next_action_at && $l->next_action_at->isPast() ? 'is-wait' : '' }}">
            <div>
              <b>{{ $l->company }}</b>
              <small>{{ trim($l->city.' · '.$l->vertical, ' ·') ?: '—' }} · <span dir="ltr">{{ parse_url($l->website, PHP_URL_HOST) }}</span></small>
              @if(filled($l->observation))
                <div class="mk-obs" style="margin-top:7px">{{ \Illuminate\Support\Str::limit($l->observation, 130) }}</div>
              @else
                <div class="mk-obs" style="margin-top:7px;color:var(--amber)">مشاهده‌ای ثبت نشده — تا آن موقع پیامی ساخته نمی‌شود</div>
              @endif
            </div>
            <div>
              <span class="mk-tag {{ ['replied'=>'g','won'=>'g','lost'=>'r','proposal'=>'v'][$l->stage] ?? 'c' }}">{{ $l->stageLabel() }}</span>
              <small style="margin-top:6px">{{ $l->next_action_at ? 'اقدامِ بعدی: '.$l->next_action_at->format('Y-m-d') : 'بدونِ اقدامِ بعدی' }}</small>
            </div>
            <div>
              @if($l->audit_score !== null)
                <div class="mk-score {{ $l->audit_score >= 75 ? 'ok' : ($l->audit_score >= 55 ? 'mid' : 'bad') }}">{{ $l->audit_score }}</div>
              @else
                <small style="color:var(--dim)">بررسی نشده</small>
              @endif
            </div>
            <div><a class="mk-btn" href="/admin/marketing/{{ $l->id }}">پرونده</a></div>
          </div>
        @endforeach
      </div>
    @endif

  @endif
</div>
@endif
@endsection

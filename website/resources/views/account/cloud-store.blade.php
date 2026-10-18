@extends('panel.layout')
@section('title', __(($gpuMode ?? false) ? 'ui.cvb_gpu_title' : 'ui.cvb_title'))

{{-- Presentation only: catalogue identity, prices and order guards remain server-owned. --}}
@push('panel-head')
<link rel="stylesheet" href="{{ asset_ver('assets/css/cloud-store-experience.css') }}">
@endpush

@section('panel')

@if(count($groups) === 0)
<div class="pnl-head cvb-head cvx-head">
  <div><nav class="blog-crumbs"><a href="{{ lroute('account.home') }}">{{ __('ui.cvb_crumb_panel') }}</a><span>/</span><span>{{ __('cloud-store.workspace') }}</span></nav>
    <h1>{{ __(($gpuMode ?? false) ? 'ui.cvb_gpu_h1' : 'ui.cvb_h1') }}</h1><p>{{ __('cloud-store.workspace_hint') }}</p></div>
  <span class="pnl-pill info">{{ __(($gpuMode ?? false) ? 'ui.cvb_pill_gpu' : 'ui.cvb_pill') }}</span>
</div>

@endif

{{-- پیام‌ها با .dm-note می‌آیند نه .pnl-sec — کامنتِ خودِ panel.css می‌گوید
     .pnl-sec قابِ اشتباهی برای پیام است و متن را بی‌رنگ رها می‌کند. --}}
@if(session('ok'))
  <div class="dm-note ok">{{ \App\Support\PanelText::text(session('ok')) }}</div>
@endif

@php
  // خطاهایی که سرِ خودِ کنترل نشان داده می‌شوند، این‌جا تکرار نمی‌شوند.
  $inlineKeys = ['location', 'plan', 'image', 'cycle', 'billing_mode', 'extra_ipv4', 'ssh_key_id', 'ssh_key_new', 'label'];
  $loose = collect($errors->keys())->reject(fn ($k) => in_array($k, $inlineKeys, true))->all();
@endphp

@if(count($loose) > 0)
  <div class="dm-note danger">
    @foreach($loose as $k)@foreach($errors->get($k) as $e)<div>{{ \App\Support\PanelText::text($e) }}</div>@endforeach @endforeach
  </div>
@endif

@if(count($groups) === 0)
  {{-- کاتالوگ خالی است. سکوت بدترین حالت است: مشتری فکر می‌کند پنل خراب شده. --}}
  <section class="pnl-sec">
    <div class="pnl-empty">
      <svg class="icon"><use href="#i-cloud"/></svg>
      <b>{{ __('ui.cvb_empty_h') }}</b>
      <p>
        {{ __('ui.cvb_empty_p1') }}<a href="{{ lroute('account.tickets') }}">{{ __('ui.cvb_empty_support') }}</a>{{ __('ui.cvb_empty_p2') }}
      </p>
    </div>
  </section>
@else

@php
  // دادهٔ امن برای جاوااسکریپت — همه از پیش ساخته می‌شوند، چون json با آرایهٔ
  // درون‌خطی پارسر Blade را می‌شکند. هیچ ستون زیرساختی این‌جا نیست.
  $jsPlans  = collect($planCards)->mapWithKeys(fn ($p) => [$p['slug'] => (($p['gpu'] ?? '') !== '' ? $p['gpu'] : $p['name'])])->all();
  // خلاصه باید **همهٔ** چیزی را که مشتری انتخاب کرده بگوید: پردازنده، رم، دیسک
  // و ترافیک — نه فقط دیسک. یک رشته، یک جا ساخته، هم برگه و هم جاوااسکریپت از
  // همین می‌خوانند.
  $jsSpecs  = collect($planCards)->mapWithKeys(fn ($p) => [$p['slug'] =>
    fa_num($p['vcpu']).' vCPU · '.fa_num($p['ram']).' · '.fa_num($p['disk']).' · '.fa_num($p['traffic'])])->all();
  // برچسبِ ایمیج **بدون** ایموجی: انتخابگر یک SVGِ خط‌تیز نشان می‌داد و خلاصه
  // همان انتخاب را با 🟠 تأیید می‌کرد — یک شیء، دو زبانِ دیداری.
  $jsImgLbl = $osCatalog->concat($appCatalog)->mapWithKeys(fn ($i) => [$i->key => \App\Support\PanelText::text($i->label)])->all();
  $jsImgLogo = $osCatalog->concat($appCatalog)->mapWithKeys(fn ($i) => [$i->key => $i->logo()])->all();

  // انتخاب‌های اولیه: با old() تا بازگشت خطا انتخاب کاربر را دور نریزد
  $curSlug  = (string) old('plan', $selectedSlug);
  if (! isset($imageMap[$curSlug])) { $curSlug = $selectedSlug; }

  $okOs  = (array) ($imageMap[$curSlug]['os'] ?? []);
  $okApp = (array) ($imageMap[$curSlug]['app'] ?? []);

  // پلنِ بی‌سیستم‌عامل (GPU) باید روی زبانهٔ «برنامه» باز شود، وگرنه مشتری
  // یک زبانهٔ خالیِ «سیستم‌عامل» می‌بیند و گزینهٔ واقعی پشتِ کلیکِ دوم است.
  $startTab = ($okOs === [] && $okApp !== []) ? 'app' : 'os';

  // پیش‌فرض سیستم‌عامل: آنچه در آدرس آمده (چیپِ شهر حملش می‌کند)، وگرنه
  // اوبونتو اگر بود، وگرنه اولین گزینهٔ ممکن
  $defImage = in_array($wantImage, array_merge($okOs, $okApp), true)
    ? $wantImage
    : (collect($okOs)->first(fn ($k) => str_starts_with($k, 'ubuntu')) ?? ($okOs[0] ?? ($okApp[0] ?? '')));
  $curImage = (string) old('image', $defImage);
  if (! in_array($curImage, array_merge($okOs, $okApp), true)) { $curImage = (string) $defImage; }

  $curCycle = (string) old('cycle', $defCycle);
  if (! in_array($curCycle, $cycles, true)) { $curCycle = $defCycle; }

  $curLabel = (string) old('label', '');
  $curIp    = (int) old('extra_ipv4', 0);

  $initial = $priceMap[$curSlug][$curCycle] ?? ['cycle' => 0, 'per' => 0, 'first' => 0, 'save' => 0];
  $hasPrice = isset($priceMap[$curSlug][$curCycle]);

  // مبلغِ اولین پرداخت **با** افزودنی‌ها. عمداً از همان CloudAddons خوانده
  // می‌شود که سرِ ثبتِ سفارش هم می‌خوانَد — دو فرمولِ موازی یعنی دکمه یک عدد
  // نشان دهد و فاکتور عددِ دیگری بگیرد.
  $initTotal = $hasPrice
    ? $initial['cycle'] + app(\App\Services\Cloud\CloudAddons::class)->forCycle(['extra_ipv4' => $curIp], $curCycle)
    : 0;
  $initFirst = $initTotal + (int) round($initTotal * $taxPct / 100);

  $hRate = (int) ($hourlyMap[$curSlug]['rate'] ?? 0);
  $hMin  = (int) ($hourlyMap[$curSlug]['min'] ?? 0);
  $hExisting = (int) ($hourlyMap[$curSlug]['existing'] ?? 0);
  /*
  | تیکِ ساعتی از دو جا می‌آید: بازگشتِ فرم (`old`) یا **لینکِ ورودی**
  | (`?billing_mode=hourly`) — صفحهٔ فرودِ /vps/hourly با همین پارامتر به
  | این‌جا می‌فرستد تا مشتری‌ای که «ساعتی» را خوانده و کلیک کرده، دوباره
  | دنبالِ گزینهٔ ساعتی نگردد. `old` مقدم است: اگر کاربر تیک را برداشته و
  | فرم برگشته، پارامترِ URL نباید تصمیمش را بازنویسی کند.
  */
  $hRequested = (session()->hasOldInput() ? old('billing_mode') : request()->query('billing_mode')) === 'hourly';
  $hOn = $hRequested;
  /*
  | 🔴 و فقط اگر این اندازه در این مکان واقعاً ساعتی خریدنی باشد.
  |
  | `?billing_mode=hourly` از صفحهٔ فرود می‌آید و به اسلاگِ خاصی قول نداده؛
  | بی‌این قید، صفحه برای پلنی که ساعتی ندارد در حالتِ ساعتی باز می‌شد و
  | «۰ تومان در ساعت» را کنارِ دکمهٔ خرید می‌گذاشت.
  */
  $hOn   = $hOn && $hRate > 0;

  /*
  | مرحله‌ای که باید باز باشد — حالا پنج مرحله است و پیش‌فرضش **مرحلهٔ ۱**.
  |
  | 🔴 چرا عوض شد: اندازه‌گیریِ صفحهٔ زنده نشان داد مرحلهٔ ۱ روی هر بارگذاری
  | بسته و «از پیش پاسخ‌داده» می‌آمد («🇩🇪 آلمان — برلین») در حالی که «برلین»
  | خودش پایتختِ جای‌گزین بود، نه شهرِ واقعیِ آن ردیف. یعنی نخستین تصمیمِ مشتری
  | هم از او گرفته می‌شد و هم غلط پاسخ داده می‌شد.
  |
  | حالا مسیر خودش پیش می‌رود: ردیفِ کشور به `?location=<اولین شهرِ باز>` می‌رود
  | (بی‌`plan`) ⇒ مرحلهٔ ۲ باز می‌شود؛ ردیفِ شهر `plan` را هم حمل می‌کند ⇒
  | مرحلهٔ ۳. خطا همیشه برنده است. بی‌جاوااسکریپت همه باز می‌مانند.
  */
  $hasLoc  = $locCode !== null && $locCode !== '';
  $openStep = ! $hasLoc ? 1 : (request()->query('plan') !== null ? 3
    : (request()->query('location') !== null || request()->query('loc') !== null ? 2 : 1));
  $stepFound = false;
  foreach ([1 => ['location'], 3 => ['plan', 'cycle', 'billing_mode'], 4 => ['image', 'label']] as $n => $keys) {
    foreach ($keys as $k) {
      if (! $stepFound && $errors->has($k)) { $openStep = $n; $stepFound = true; }
    }
  }
  if ($errors->has('extra_ipv4') || $errors->has('ssh_key_new') || $errors->has('ssh_key_id')) { $advOpen = true; if (! $stepFound) { $openStep = 4; } }
  else { $advOpen = $curIp > 0 || old('ssh_key_id') !== null || old('ssh_key_new') !== null; }

  /*
  | برچسبِ مکانِ جاری برای برگه — از **همان سطلی** که مرحلهٔ ۲ رندر می‌کند.
  |
  | 🔴 پیش از این `$location->label()` بود، و آن متد وقتی ستونِ شهر خالی یا یک
  | واژهٔ ردهٔ محصول باشد نامِ **پایتخت** را برمی‌گرداند. یعنی برگه و سرصفحهٔ
  | مرحله «🇩🇪 آلمان — برلین» می‌گفتند برای ماشینی که در برلین نیست، و مشتری
  | بعد از انتخاب هم نمی‌فهمید کدام‌یک از چیپ‌های هم‌نام را زده. حالا برچسبِ
  | برگه بایت‌به‌بایت همان چیزی است که روی ردیفِ انتخاب‌شده نوشته شده.
  */
  $curGroup = null; $curBucket = null; $curMemberIdx = 0;
  foreach ($groups as $g) {
    foreach ($g['cities'] as $c) {
      foreach ($c['members'] as $mi => $m) {
        if ((string) $m->code === (string) $locCode) { $curGroup = $g; $curBucket = $c; $curMemberIdx = $mi; }
      }
    }
  }
  $countryLabel = $curGroup ? trim($curGroup['flag'].' '.$curGroup['label']) : '—';
  $cityLabel = $curBucket
    ? $curBucket['label'].($curBucket['n'] > 1 ? ' · '.__('ui.cvb_dc_n', ['n' => fa_num($curMemberIdx + 1)]) : '')
    : '—';
  $locLabel = $curGroup ? $countryLabel.' — '.$cityLabel : '—';
  $curCountry = $curGroup ? strtoupper((string) $curGroup['country']) : '';

  // مقصدِ ردیفِ کشور: **بی‌** پارامترِ plan، تا صفحهٔ بعد روی مرحلهٔ «شهر» باز شود.
  $countryHref = fn (string $code) => lroute('account.cloud.store').'?location='.urlencode($code);
  // «هنوز کشوری انتخاب نشده» — همان وضعِ واقعیِ کنترلر (`?location=` خالی).
  $clearHref = lroute('account.cloud.store').'?location=';

  // ناحیه‌های جغرافیایی، به همان ترتیبی که خوانده می‌شوند.
  $regionOrder = ['me' => __('ui.cvb_reg_me'), 'eu' => __('ui.cvb_reg_eu'), 'other' => __('ui.cvb_reg_other')];

  // برچسب و شمارهٔ مرحله‌ها — تیرکِ کناری، سرصفحهٔ مرحله و برگه همه از همین می‌خوانند.
  $stepNames = [
    1 => __('cloud-store.location'),
    3 => __('ui.cvb_step_size'),
    4 => __(($gpuMode ?? false) ? 'ui.cvb_gpu_step_app' : 'ui.cvb_step_os'),
  ];

  /*
  | 🔴 خطِ GPU یک مکان بیشتر ندارد (شبکهٔ توزیع‌شدهٔ جهانی). «کدام کشور؟ کدام
  | شهر؟» برای این مشتری دو مرحلهٔ بی‌جواب است که هر دو یک مقدارِ ثابت را نشان
  | می‌دهند — همان که کارفرما «مرحلهٔ تکراری» گزارش کرد. مرحله‌ها ۳تایی
  | می‌شوند؛ location از قبل در فیلدِ پنهانِ فرم هست و هیچ انتخابی گم نمی‌شود.
  | کلیدهای آرایه عمداً همان ۳/۴/۵ می‌مانند (id سکشن‌ها و data-go به آن‌ها
  | بسته‌اند)؛ فقط **شمارهٔ نمایشی** از $stepNo می‌آید.
  */
  $isGpuStore = (bool) ($gpuMode ?? false);
  if ($isGpuStore) {
    $stepNames = [3 => __('ui.cvb_step_gpu'), 4 => __('ui.cvb_step_app')];
    if ($openStep < 3) { $openStep = 3; }
  }
  if (! $isGpuStore && $openStep === 2) { $openStep = 1; }
  $stepNo = []; $i = 0;
  foreach (array_keys($stepNames) as $k) { $stepNo[$k] = ++$i; }
  $stepTotal = count($stepNames);

  /*
  | لینکِ شهر/دیتاسنتر — **یک** سازنده برای هر دو سطح.
  | دو جای تولیدِ لینک یعنی روزی ترتیبِ پارامترها در یکی عوض شود و تست که فقط
  | یکی را می‌بیند سبز بماند. ترتیب قفل است: location, plan, cycle, image —
  | و image آخرین است (CloudStoreSlipTest).
  | `&` خام نوشته می‌شود چون {{ }} خودش به `&amp;` تبدیلش می‌کند.
  */
  $cityHref = fn (string $code) => lroute('account.cloud.store')
    .'?location='.urlencode($code)
    .'&plan='.urlencode($curSlug)
    .'&cycle='.urlencode($curCycle)
    .($hRequested ? '&billing_mode=hourly' : '').'&image='.urlencode($curImage);

  /*
  | «برگهٔ مقایسه» — ستونی که در کلِ این مکان **یک مقدار** دارد، ستون نیست؛ نویز
  | است. پس جمع می‌شود به یک پانویس و ستونش اصلاً رندر نمی‌شود. همین‌طور فیلترِ
  | نوعِ پردازنده وقتی چیزی برای فیلترکردن ندارد، اصلاً نمی‌آید — امروز کلِ
  | کاتالوگ cpu_kind=shared است و زدنِ «اختصاصی» فهرست را بی‌هیچ توضیحی خالی
  | می‌کرد.
  |
  | ⚠️ ناموجودها هم در شمارش هستند: اگر تنها ردیفِ اختصاصی ناموجود باشد، ستون
  | همچنان معنا دارد چون کاربر باید تفاوت را ببیند.
  */
  $allCards  = array_merge($planCards, $blockedCards);
  $netVals   = array_values(array_unique(array_map(fn ($p) => (string) $p['traffic'], $allCards)));
  $kindVals  = array_values(array_unique(array_map(fn ($p) => (string) $p['cpuKind'], $allCards)));
  $uniNet    = count($netVals) <= 1;
  $multiKind = count($kindVals) > 1;
  $netAll    = fa_num((string) ($netVals[0] ?? ''));
  $cpuAll    = ($kindVals[0] ?? 'shared') === 'dedicated'
    ? __('ui.cvb_cpu_dedicated')
    : __('ui.cvb_cpu_shared');
@endphp

<form method="POST" action="{{ lroute('account.cloud.store.place') }}" id="cvb-form" class="cvb-wrap cvx-store">
  @csrf
  <input type="hidden" name="quoted_hourly_irt" id="cvb-quoted-hourly" value="{{ $hRate }}">
  @if($isGpuStore)
    <input type="hidden" name="gpu_request_key" value="{{ old('gpu_request_key', \Illuminate\Support\Str::random(40)) }}">
  @endif
  <input type="hidden" name="location" value="{{ $locCode }}">

  {{-- ═══ تیرکِ مسیر — هم شمارهٔ مرحله، هم راهِ برگشت ═══

       پنج گره روی یک خطِ مویی. مرحلهٔ پاسخ‌داده به یک سطرِ ۴۴ پیکسلی جمع
       پنج گره روی یک خطِ مویی، هرکدام یک دکمه که مرحله‌اش را باز می‌کند. پس
       نوارِ پیشرفت و راهِ برگشت یک چیزند و هیچ انتخابی سه بار چاپ نمی‌شود.

       🔴 دو ادعای دقیق دربارهٔ همین نشانه‌گذاری:
       · **رقم** تزئین است (`aria-hidden`) و نامِ مرحله + مقدارش متنِ در دسترسِ
         همان دکمه — یعنی صفحه‌خوان «مرحلهٔ ۲، شهر، فرانکفورت» می‌شنود، نه یک
         عددِ تنها. روی دسکتاپ آن متن `clip-path` شده چون تیرک ۵۶px است؛
         زیرِ ۱۰۰۰px تیرک افقی می‌شود و رقم‌ها کنارِ هم می‌نشینند.
       · هر گره **یک** ایستگاهِ صفحه‌کلید است، نه دو. --}}
<div class="cvx-workspace-header">
    <div class="cvx-workspace-title"><a class="cvx-panel-back" href="{{ lroute('account.home') }}" aria-label="{{ __('ui.cvb_crumb_panel') }}"><svg class="icon"><use href="#i-arrow"/></svg></a><h1>{{ __($isGpuStore ? 'ui.cvb_gpu_h1' : 'ui.cvb_h1') }}</h1></div>
  <nav class="cvb-spine" aria-label="{{ __('ui.cvb_spine') }}">
    @foreach($stepNames as $n => $nm)
      @php
        $vals = [1 => $locLabel, 2 => $cityLabel, 3 => ($jsPlans[$curSlug] ?? '—'),
                 4 => ($jsImgLbl[$curImage] ?? '—'), 5 => ($curLabel !== '' ? $curLabel : $autoLabel)];
      @endphp
      {{-- 🔴 گره باید خودش یک افشاگرِ کامل باشد، چون CSS سرصفحهٔ مرحلهٔ **باز**
           را پنهان می‌کند و آن سرصفحه تنها جایی بود که `aria-expanded` داشت.
           بی‌این دو صفت، کاربرِ صفحه‌خوان روی مرحلهٔ باز هیچ کنترلی نمی‌شنید. --}}
      <button type="button" class="cvb-sp @if($n === $openStep) is-now @endif" data-go="{{ $n }}" data-spine="{{ $n }}"
              aria-expanded="{{ $n === $openStep ? 'true' : 'false' }}" aria-controls="cvb-b-{{ $n }}">
        <span class="cvb-sp-n" aria-hidden="true">{{ fa_num($stepNo[$n]) }}</span>
        <span class="cvb-sp-t">
          <b>{{ $nm }}</b>
          <small class="cvb-sp-v" data-sp-v="{{ $n }}">{{ $vals[$n] }}</small>
        </span>
      </button>
    @endforeach
  </nav>
    <span class="pnl-pill info cvx-delivery">{{ __($isGpuStore ? 'ui.cvb_pill_gpu' : 'ui.cvb_pill') }}</span>
  </div>

  <div class="cvb-main">

    @unless($isGpuStore)
      @include('account.partials.cloud-store-location')
    @endunless

    <section class="pnl-sec cvb-step" id="cvb-step-3" data-step="3">
      <h2 class="cvb-step-hh"><button type="button" class="pnl-sec-h cvb-step-h" aria-expanded="true" aria-controls="cvb-b-3">
        <span class="cvb-step-t"><span class="cb-dot"></span><b>{{ __('ui.cvb_s2') }}</b></span>
        <span class="cvb-step-v"><span id="cvb-v-3">{{ $jsPlans[$curSlug] ?? '—' }}</span><em>{{ __('ui.cvb_slip_edit') }}</em></span>
      </button></h2>
      <div class="cvb-step-b" id="cvb-b-3">
        <div class="cvb-step-i"><div class="pnl-sec-b">

          <p class="cvb-eyebrow">{{ __('ui.cvb_step_idx', ['n' => fa_num($stepNo[3] ?? 3), 't' => fa_num($stepTotal)]) }}</p>
          <h3 class="cvb-q">{{ __('ui.cvb_s2') }}</h3>

          @error('plan')<div class="dm-note danger">{{ \App\Support\PanelText::text($message) }}</div>@enderror
          @error('cycle')<div class="dm-note danger">{{ \App\Support\PanelText::text($message) }}</div>@enderror
          @error('billing_mode')<div class="dm-note danger">{{ \App\Support\PanelText::text($message) }}</div>@enderror
          @if($planMoved)<div class="dm-note warn">{{ __('ui.cvb_plan_moved') }}</div>@endif

          <div class="cvb-billrow">
            <div class="cvb-segs cvb-bill @if($hOn) is-hourly @endif" id="cvb-bill">
              @foreach($cycles as $cy)
                @php $row = $priceMap[$curSlug][$cy] ?? ['save' => 0]; @endphp
                <label class="cvb-seg cvb-seg-c @if($cy === $curCycle) on @endif" data-cyc="{{ $cy }}">
                  <input type="radio" name="cycle" value="{{ $cy }}" @checked($cy === $curCycle)>
                  <span>{{ $cycleLabels[$cy] ?? $cy }}</span>
                  <em data-cycle-saving="{{ $cy }}" @if(($row['save'] ?? 0) <= 0) hidden @endif>{{ ($row['save'] ?? 0) > 0 ? fa_num($row['save']).__('ui.cvb_save_suf') : '' }}</em>
                </label>
              @endforeach

              {{-- ═══ پرداختِ ساعتی ═══
                   چک‌باکسِ ساده (نه رادیو) تا **بی‌جاوااسکریپت هم** کار کند:
                   تیک‌خورده یعنی billing_mode=hourly، تیک‌نخورده یعنی هیچ‌چیز
                   ارسال نمی‌شود و سرور همان چرخهٔ عادی را می‌گیرد. --}}
              {{-- ⚠️ همیشه رندر می‌شود و با `hidden` خاموش می‌شود، نه با `@if`:
                   عوض‌کردنِ پلن در سمتِ کلاینت اتفاق می‌افتد و اگر عنصر اصلاً
                   در DOM نباشد، رفتن به پلنی که ساعتی **دارد** گزینه را
                   برنمی‌گرداند. (`.cvb-seg[hidden]` در panel.css صریح خاموش
                   شده — `display:inline-flex`ِ نویسنده بر [hidden] می‌چربد.) --}}
              <label class="cvb-seg cvb-seg-h @if($hOn) on @endif" @if($hRate <= 0) hidden @endif>
                <input type="checkbox" name="billing_mode" value="hourly" id="cvb-hourly" @checked($hOn)>
                <span>{{ __('ui.cvb_mode_hourly') }}</span>
              </label>
            </div>

            {{-- فیلتر فقط وقتی چیزی برای فیلترکردن هست. با یک نوعِ پردازنده،
                 «اختصاصی» فهرست را بی‌هیچ توضیحی خالی می‌کرد. --}}
            @if($multiKind)
              <div class="cvb-segs" id="cvb-kind">
                <button type="button" class="cvb-seg on" data-kind="">{{ __('ui.cvb_cpu_all') }}</button>
                <button type="button" class="cvb-seg" data-kind="shared">{{ __('ui.cvb_cpu_shared') }}</button>
                <button type="button" class="cvb-seg" data-kind="dedicated">{{ __('ui.cvb_cpu_dedicated') }}</button>
              </div>
            @endif
          </div>

          @if(count($planCards) === 0 && count($blockedCards) === 0)
            <p class="cvb-warn">{{ __('ui.cvb_no_plans') }}</p>
          @else
            {{-- ═══ «برگهٔ مقایسه» — یک DOM، دو شکل ═══

                 روی ظرفِ پهن یک **برگه** است (ستون‌های هم‌تراز، ردیف‌های متراکم)
                 و روی ظرفِ باریک همان کارت‌های قبلی. تصمیم با
                 `container-type:inline-size` گرفته می‌شود، نه با media query،
                 چون عرضِ **پنجره** دربارهٔ این عنصر دروغ می‌گوید: فهرست روی هر
                 دسکتاپی حداکثر ~۴۸۸px است، ولی در پنجرهٔ ۱۰۰۱px فقط ~۲۸۹px و در
                 ۹۹۹px یک‌دفعه ~۹۱۶px می‌شود (برگه از کنار می‌رود). یعنی
                 `max-width:1000px` دقیقاً برعکس عمل می‌کرد.

                 و پیش‌فرضِ بی‌پرس‌وجو **کارت** است: مرورگری که پرس‌وجوی ظرف را
                 نمی‌شناسد همان طرحی را می‌بیند که دیروز پذیرفته شد، نه یک جدولِ
                 شکسته. یک media query ساختاراً نمی‌تواند چنین پس‌افتِ امنی بدهد.

                 🔴 قلّاب‌هایی که تست‌ها عیناً می‌سنجند و هیچ‌کدام این‌جا تکان
                 نخورده‌اند: بعد از «on» هیچ کلاسِ دیگری نمی‌آید و data-slug
                 بلافاصله بعدِ صفتِ class می‌آید؛ ردیفِ ناموجود data-uslug دارد
                 نه data-slug؛ و هیچ فرزندی از ردیفِ ناموجود <div> نیست (رجکسِ
                 CloudStoreSlipTest تا **نخستین** </div> می‌بندد، پس یک div
                 تودرتو سه ادعای پولیِ آن را بی‌صدا پوچ می‌کند). --}}
            <div class="cvb-sheet">
              <div class="cvb-plans @if(! $uniNet) has-net @endif @if($multiKind) has-cpu @endif" id="cvb-plans">

                {{-- سرستون‌ها. روی ظرفِ پهن سرِ ستون‌اند، روی باریک یک نوارِ
                     چیپِ «مرتب‌سازی» — همان دکمه‌ها، همان جاوااسکریپت، پس روی
                     موبایل هیچ کنترلِ دومی لازم نیست. --}}
                <div class="cvb-sheeth" id="cvb-sheeth">
                  <span class="cvb-shl">{{ __('ui.cvb_sort_by') }}</span>
                  <button type="button" class="cvb-sh cvb-sh-ord on" data-sort="ord" data-dir="asc" aria-pressed="true"><span class="cvb-shs">{{ __('ui.cvb_plan') }}</span><span class="cvb-shc">{{ __('ui.cvb_sort_default') }}</span><span class="cvb-sr" data-say></span></button>
                  <button type="button" class="cvb-sh cvb-sh-cpu" data-sort="sv" aria-pressed="false">{{ __('ui.cvb_cores') }}<svg class="icon"><use href="#i-chev"/></svg><span class="cvb-sr" data-say></span></button>
                  <button type="button" class="cvb-sh cvb-sh-ram" data-sort="sr" aria-pressed="false">{{ __('ui.cvb_ram') }}<svg class="icon"><use href="#i-chev"/></svg><span class="cvb-sr" data-say></span></button>
                  <button type="button" class="cvb-sh cvb-sh-dsk" data-sort="sd" aria-pressed="false">{{ __('ui.cvb_disk') }}<svg class="icon"><use href="#i-chev"/></svg><span class="cvb-sr" data-say></span></button>
                  @if(! $uniNet)
                    <button type="button" class="cvb-sh cvb-sh-net" data-sort="sn" aria-pressed="false">{{ __('ui.cvb_traffic') }}<svg class="icon"><use href="#i-chev"/></svg><span class="cvb-sr" data-say></span></button>
                  @endif
                  @if($multiKind)
                    {{-- نوعِ پردازنده بُعدِ خودِ فیلتر است؛ مرتب‌سازی‌اش تکرارِ
                         همان کنترل می‌شد، پس سرستون است نه دکمه. --}}
                    <span class="cvb-shx cvb-sh-kind">{{ __('ui.cvb_cpu') }}</span>
                  @endif
                  <button type="button" class="cvb-sh cvb-sh-pr" data-sort="pr" aria-pressed="false"><span id="cvb-sh-amt">{{ __('ui.cvb_amount') }}</span><svg class="icon"><use href="#i-chev"/></svg><span class="cvb-sr" data-say></span></button>
                </div>

                @foreach($planCards as $p)
                  {{-- 🔴 قلّابِ تست: بعد از «on» هیچ کلاسِ دیگری نیاید و data-slug
                       بلافاصله بعدش بیاید (CloudStoreTest). --}}
                  <label class="cvb-plan @if($p['slug'] === $curSlug) on @endif" data-slug="{{ $p['slug'] }}" data-kind="{{ $p['cpuKind'] }}" data-ord="{{ $loop->index }}" data-sv="{{ $p['vcpu'] }}" data-sr="{{ $p['ramMb'] }}" data-sd="{{ $p['diskGb'] }}"@if(! $uniNet) data-sn="{{ $p['trafficGb'] }}"@endif>
                    <input type="radio" name="plan" value="{{ $p['slug'] }}" @checked($p['slug'] === $curSlug)>
                    <span class="cvb-pn">
                      {{ ($p['gpu'] ?? '') !== '' ? $p['gpu'] : $p['name'] }}
                      <span class="cvb-tick"><svg class="icon"><use href="#i-check"/></svg></span>
                    </span>
                    <span class="cvb-c cvb-c-cpu"><span class="cvb-sr">{{ __('ui.cvb_cores') }} </span>{{ fa_num($p['vcpu']) }} vCPU</span>
                    <span class="cvb-c cvb-c-ram"><span class="cvb-sr">{{ __('ui.cvb_ram') }} </span>{{ fa_num($p['ram']) }}</span>
                    <span class="cvb-c cvb-c-dsk"><span class="cvb-sr">{{ __('ui.cvb_disk') }} </span>{{ fa_num($p['disk']) }}</span>
                    @if(! $uniNet)
                      <span class="cvb-c cvb-c-net"><span class="cvb-sr">{{ __('ui.cvb_traffic') }} </span><bdi>{{ fa_num($p['traffic']) }}</bdi></span>
                    @endif
                    @if($multiKind)
                      <span class="cvb-c cvb-c-kind">{{ $p['cpu'] }}</span>
                    @endif
                    <span class="cvb-pp" data-pp>{{ cloud_price($priceMap[$p['slug']][$curCycle]['cycle'] ?? 0) }}</span>
                  </label>
                @endforeach

                {{-- «هست ولی الان نمی‌شود خرید» — صادقانه دیده می‌شود، بی‌قیمت و
                     بی‌رادیو، ولی **در جای خودش روی نردبان** و ستون‌به‌ستون هم‌تراز
                     با ردیف‌های فروختنی، تا مثلِ یک پلهٔ جاافتاده خوانده شود نه یک
                     واقعیتِ غایب. ستونِ مبلغ کاملاً **خالی** می‌مانَد: نه خط تیره،
                     نه صفر — هرچیزی در ستونِ پول، قیمت خوانده می‌شود
                     (CLAUDE.md §۱۰.۵). data-uslug تا شمارشِ گروه‌بندی نشکند.
                     🔴 هیچ فرزندی این‌جا <div> نشود؛ رجکسِ تست تا نخستین بستنِ
                     div می‌بندد و آن‌وقت سه ادعای «نه تومان، نه €، نه رایگان»
                     سبز می‌مانند و هیچ‌چیز را نگه نمی‌دارند. --}}
                @foreach($blockedCards as $p)
                  <div class="cvb-off cvb-plan" data-uslug="{{ $p['slug'] }}" data-kind="{{ $p['cpuKind'] }}" aria-disabled="true">
                    <span class="cvb-pn">
                      {{ ($p['gpu'] ?? '') !== '' ? $p['gpu'] : $p['name'] }}
                    </span>
                    <span class="cvb-c cvb-c-cpu"><span class="cvb-sr">{{ __('ui.cvb_cores') }} </span>{{ fa_num($p['vcpu']) }} vCPU</span>
                    <span class="cvb-c cvb-c-ram"><span class="cvb-sr">{{ __('ui.cvb_ram') }} </span>{{ fa_num($p['ram']) }}</span>
                    <span class="cvb-c cvb-c-dsk"><span class="cvb-sr">{{ __('ui.cvb_disk') }} </span>{{ fa_num($p['disk']) }}</span>
                    @if(! $uniNet)
                      <span class="cvb-c cvb-c-net"><span class="cvb-sr">{{ __('ui.cvb_traffic') }} </span><bdi>{{ fa_num($p['traffic']) }}</bdi></span>
                    @endif
                    @if($multiKind)
                      <span class="cvb-c cvb-c-kind">{{ $p['cpu'] }}</span>
                    @endif
                    <p class="cvb-offr">
                      @if($p['reason'] === 'stock')
                        {{ __('ui.cvb_off_stock') }}
                      @else
                        {{ __('ui.cvb_off_price') }}
                      @endif
                    </p>
                  </div>
                @endforeach
              </div>
            </div>

            @if($multiKind)
              <p class="cvb-empty" id="cvb-kind-empty" hidden>{{ __('ui.cvb_kind_empty') }}</p>
            @endif

            {{-- ستونی که در کلِ این مکان یک مقدار دارد، ستون نیست — پانویس است. --}}
            @if($uniNet || ! $multiKind)
              <p class="cvb-note">
                <svg class="icon"><use href="#i-info"/></svg>
                <span>
                  @if($uniNet){{ __('ui.cvb_same_all', ['label' => __('ui.cvb_traffic'), 'value' => $netAll]) }}@endif
                  @if($uniNet && ! $multiKind) · @endif
                  @if(! $multiKind){{ __('ui.cvb_same_all', ['label' => __('ui.cvb_cpu'), 'value' => $cpuAll]) }}@endif
                </span>
              </p>
            @endif
          @endif
        </div></div>
      </div>
    </section>

    {{-- ═══ ۴ — سیستم‌عامل یا نرم‌افزار آماده ═══ --}}
    <section class="pnl-sec cvb-step" id="cvb-step-4" data-step="4">
      <h2 class="cvb-step-hh"><button type="button" class="pnl-sec-h cvb-step-h" aria-expanded="true" aria-controls="cvb-b-4">
        <span class="cvb-step-t"><span class="cb-dot"></span><b>{{ __($isGpuStore ? 'ui.cvb_gpu_app_title' : 'ui.cvb_s3') }}</b></span>
        <span class="cvb-step-v"><span id="cvb-v-4">{{ $jsImgLbl[$curImage] ?? '—' }}</span><em>{{ __('ui.cvb_slip_edit') }}</em></span>
      </button></h2>
      <div class="cvb-step-b" id="cvb-b-4">
        <div class="cvb-step-i"><div class="pnl-sec-b">
          <p class="cvb-eyebrow">{{ __('ui.cvb_step_idx', ['n' => fa_num($stepNo[4] ?? 4), 't' => fa_num($stepTotal)]) }}</p>
          <h3 class="cvb-q">{{ __($isGpuStore ? 'ui.cvb_gpu_app_title' : 'ui.cvb_s3') }}</h3>
          @error('image')<div class="dm-note danger">{{ \App\Support\PanelText::text($message) }}</div>@enderror

          @if($isGpuStore)
            <p class="cvb-gpu-app-lead">{{ __('ui.cvb_gpu_app_lead') }}</p>
          @else
          {{-- تنها افشاگرِ صفحه که هیچ ARIAای نداشت. tab/tablist بومی است و
               وضعیتِ انتخاب را به صفحه‌خوان می‌گوید.

               🔴 نامِ گروه باید **نامِ خودش** باشد، نه نامِ نخستین زبانه‌اش.
               با `ui.cvb_os` صفحه‌خوان «سیستم‌عامل، فهرست زبانه … سیستم‌عامل،
               زبانه» می‌گفت: یک واژه دو نقش، و کاربر نمی‌فهمید گروه چیست. --}}
          <div class="cvb-billrow">
            <div class="cvb-segs" role="tablist" aria-label="{{ __('ui.cvb_os_group') }}">
              <button type="button" class="cvb-seg @if($startTab === 'os') on @endif" data-tab="os" role="tab" aria-selected="{{ $startTab === 'os' ? 'true' : 'false' }}" aria-controls="cvb-pane-os" id="cvb-tab-os">{{ __('ui.cvb_os') }}</button>
              <button type="button" class="cvb-seg @if($startTab === 'app') on @endif" data-tab="app" role="tab" aria-selected="{{ $startTab === 'app' ? 'true' : 'false' }}" aria-controls="cvb-pane-app" id="cvb-tab-app">{{ __('ui.cvb_app') }}</button>
            </div>
          </div>
          @endif

          {{-- گزینه‌های ناسازگار با پلن انتخابی پنهان می‌شوند (سمت سرور محاسبه شده،
               جاوااسکریپت فقط با عوض شدن پلن به‌روزش می‌کند). گزینه‌ای که تحویلش
               نشدنی است هرگز نباید دیده شود. --}}
          @unless($isGpuStore)
          <div class="cvb-imgs" data-pane="os" id="cvb-pane-os" role="tabpanel" aria-labelledby="cvb-tab-os" @if($startTab !== 'os') hidden @endif>
            @php $osByFam = $osCatalog->groupBy(fn ($i) => (string) $i->family); @endphp
            @forelse($osByFam as $fam => $rows)
              <div class="cvb-fam" data-fam="{{ $fam }}">
                <div class="cvb-famh"><img class="cvb-logo sm" src="{{ $rows->first()->logo() }}" alt="" loading="lazy" width="16" height="16">{{ $fam !== '' ? ucfirst($fam) : __('ui.cvb_other') }}</div>
                <div class="cvb-opts">
                  @foreach($rows as $img)
                    <label class="cvb-img @if($img->key === $curImage) on @endif"
                           data-key="{{ $img->key }}" @if(! in_array($img->key, $okOs, true)) hidden @endif>
                      <input type="radio" name="image" value="{{ $img->key }}" @checked($img->key === $curImage)>
                      <img class="cvb-logo" src="{{ $img->logo() }}" alt="" loading="lazy" width="20" height="20">
                      <b>{{ \App\Support\PanelText::text($img->label) }}</b>
                    </label>
                  @endforeach
                </div>
              </div>
            @empty
              <p class="cvb-warn">{{ __('ui.cvb_os_na') }}</p>
            @endforelse
            <p class="cvb-empty" data-empty="os" hidden>{{ __('ui.cvb_os_empty') }}</p>
          </div>
          @endunless

          <div class="cvb-imgs" data-pane="app" id="cvb-pane-app" @if($isGpuStore) role="group" aria-label="{{ __('ui.cvb_gpu_app_title') }}" @else role="tabpanel" aria-labelledby="cvb-tab-app" @if($startTab !== 'app') hidden @endif @endif>
            @if($isGpuStore)
              @php
                $gpuAppDescriptions = ['gpu-jupyter' => 'ui.cvb_gpu_jupyter_desc', 'gpu-comfyui' => 'ui.cvb_gpu_comfyui_desc', 'gpu-ollama' => 'ui.cvb_gpu_ollama_desc'];
              @endphp
              <div class="cvb-fam cvb-gpu-app-group" data-fam="gpu">
                <div class="cvb-opts cvb-gpu-app-grid">
                  @foreach($appCatalog as $img)
                    <label class="cvb-img cvb-gpu-app @if($img->key === $curImage) on @endif"
                           data-key="{{ $img->key }}" @if(! in_array($img->key, $okApp, true)) hidden @endif>
                      <input type="radio" name="image" value="{{ $img->key }}" @checked($img->key === $curImage)>
                      <span class="cvb-gpu-app-top" aria-hidden="true">
                        <span class="cvb-gpu-app-icon"><img src="{{ $img->logo() }}" alt="" width="30" height="30" loading="lazy"></span>
                        <span class="cvb-gpu-app-check"><svg class="icon"><use href="#i-check"/></svg></span>
                      </span>
                      <span class="cvb-gpu-app-copy">
                        <b dir="auto">{{ \App\Support\PanelText::text($img->label) }}</b>
                        <small>{{ __($gpuAppDescriptions[$img->key] ?? 'ui.cvb_gpu_app_default_desc') }}</small>
                      </span>
                    </label>
                  @endforeach
                </div>
              </div>
            @else
            @php $appByFam = $appCatalog->groupBy(fn ($i) => (string) $i->family); @endphp
            @forelse($appByFam as $fam => $rows)
              <div class="cvb-fam" data-fam="{{ $fam }}">
                <div class="cvb-famh"><img class="cvb-logo sm" src="{{ $rows->first()->logo() }}" alt="" loading="lazy" width="16" height="16">{{ $fam !== '' ? ucfirst($fam) : __('ui.cvb_other') }}</div>
                <div class="cvb-opts">
                  @foreach($rows as $img)
                    <label class="cvb-img @if($img->key === $curImage) on @endif"
                           data-key="{{ $img->key }}" @if(! in_array($img->key, $okApp, true)) hidden @endif>
                      <input type="radio" name="image" value="{{ $img->key }}" @checked($img->key === $curImage)>
                      <img class="cvb-logo" src="{{ $img->logo() }}" alt="" loading="lazy" width="20" height="20">
                      <b>{{ \App\Support\PanelText::text($img->label) }}</b>
                    </label>
                  @endforeach
                </div>
              </div>
            @empty
              <p class="cvb-warn">{{ __('ui.cvb_app_na') }}</p>
            @endforelse
            @endif
            <p class="cvb-empty" data-empty="app" hidden>{{ __('ui.cvb_app_empty') }}</p>
          </div>

          {{-- 🔴 این جمله تا مهرِ ۱۴۰۵ بی‌قیدوشرط چاپ می‌شد، از جمله روی
               خطِ GPU که نه SSH دارد نه نصبِ دوباره (`SaladClient::capabilities()` ←
               `rebuild:false`). مشتری عیناً همین جمله را در تیکت TK-260924-8595 نقل
               کرد و حق هم داشت: سروری خریده بود تا نرم‌افزارِ خودش را نصب کند.
               وعدهٔ صفحهٔ خرید یک تعهدِ فروش است، نه متنِ تزئینی. --}}
          <p class="cvb-note @if($isGpuStore) cvb-gpu-app-note @endif">
            <svg class="icon"><use href="#i-key"/></svg>
            {{ __($isGpuStore ? 'ui.cvb_gpu_access_note' : 'ui.cvb_os_note') }}
          </p>
        </div></div>
      </div>
    </section>

    {{-- ═══ ۵ — تنظیمات پیشرفته ═══
         details بومی: بی‌جاوااسکریپت، سازگار با صفحه‌کلید، بی‌ربط به CSP. اگر
         خطایی درونش باشد یا کاربر از قبل چیزی انتخاب کرده باشد، باز می‌آید. --}}
    <details class="cvb-adv" id="cvb-adv" @if($advOpen) open @endif>
      <summary>
        <svg class="icon"><use href="#i-wrench"/></svg>
        {{ __('ui.cvb_adv') }} <small>{{ __(($gpuMode ?? false) ? 'ui.cvb_gpu_adv_sub' : 'ui.cvb_adv_sub') }}</small>
        <svg class="icon"><use href="#i-chev"/></svg>
      </summary>
      <div class="cvb-adv-b">

        @if($gpuMode ?? false)
          <p class="cvb-note">{{ __('ui.cvb_gpu_adv_note') }}</p>
        @endif

        @unless($gpuMode ?? false)
        @error('ssh_key_id')<div class="dm-note danger">{{ \App\Support\PanelText::text($message) }}</div>@enderror
        @error('ssh_key_new')<div class="dm-note danger">{{ \App\Support\PanelText::text($message) }}</div>@enderror

        {{-- ورود با کلید SSH — در خطِ GPU رندر نمی‌شود: این محصول SSH ندارد
             (دسترسی با نشانی و توکن است) و فرمی که کلیدِ SSH بخواهد، دربارهٔ
             خودِ محصول دروغ می‌گوید. --}}
        <label class="cvb-field">
          <span>{{ __('ui.cvb_ssh') }} — {{ __('ui.cvb_free') }}</span>
          <select name="ssh_key_id" id="cvb-ssh-pick">
            <option value="">{{ __('ui.cvb_ssh_pw') }}</option>
            @foreach($sshKeys as $k)
              <option value="{{ $k->id }}" @selected((string) old('ssh_key_id') === (string) $k->id)>{{ $k->label() }}</option>
            @endforeach
            <option value="__new" @selected(old('ssh_key_new') !== null)>{{ __('ui.cvb_ssh_add') }}</option>
          </select>
        </label>

        <div id="cvb-ssh-new" hidden>
          <label class="cvb-field">
            <span>{{ __('ui.cvb_ssh_name') }}</span>
            <input type="text" name="ssh_key_name" value="{{ old('ssh_key_name') }}"
                   placeholder="{{ __('ui.cvb_ssh_name_ph') }}" maxlength="60" autocomplete="off">
          </label>
          <label class="cvb-field">
            <span>{{ __('ui.cvb_ssh_pub') }}</span>
            <textarea name="ssh_key_new" dir="ltr" rows="3" maxlength="6000"
                      placeholder="ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAA... you@laptop"
                      autocapitalize="off" autocomplete="off" spellcheck="false">{{ old('ssh_key_new') }}</textarea>
          </label>
          <p class="cvb-note">
            <svg class="icon"><use href="#i-shield"/></svg>
            <span>{!! __('ui.cvb_ssh_note1') !!}</span>
          </p>
          <p class="cvb-note">
            <svg class="icon"><use href="#i-info"/></svg>
            <span>{{ __('ui.cvb_ssh_note2') }}</span>
          </p>
        </div>

        {{-- IP اضافه — فقط وقتی این اسلاگ واقعاً بتواند تحویلش دهد. کارت با
             عوض‌شدنِ پلن پنهان/آشکار می‌شود، وگرنه انتخابی می‌ماند که سرِ ثبتِ
             سفارش رد می‌شود. --}}
        @endunless

        @if(! ($gpuMode ?? false) && collect($addonMap)->contains(true))
        <div id="cvb-ip-box" @if(! $addonOk) hidden @endif>
          @error('extra_ipv4')<div class="dm-note danger">{{ \App\Support\PanelText::text($message) }}</div>@enderror
          <label class="cvb-field">
            <span>{{ __('ui.cvb_ip_pre') }}{{ cloud_price($extraIpPrice) }}{{ __('ui.cvb_ip_suf') }}</span>
            <select name="extra_ipv4" id="cvb-extra-ip" @disabled(! $addonOk)>
              @for($i = 0; $i <= $maxExtraIp; $i++)
                <option value="{{ $i }}" @selected($curIp === $i)>
                  {{ $i === 0 ? __('ui.cvb_ip_none') : fa_num($i).__('ui.cvb_ip_opt_mid').cloud_price($i * $extraIpPrice).__('ui.cvb_ip_opt_suf') }}
                </option>
              @endfor
            </select>
          </label>
          <p class="cvb-note">
            <svg class="icon"><use href="#i-globe"/></svg>
            {{ __('ui.cvb_ip_note') }}
          </p>
        </div>
        @endif

        {{-- جزئیاتِ پرداختِ ساعتی — کلیدش بالا کنارِ دوره‌هاست؛ این‌جا فقط
             چیزهایی که فقط در حالتِ ساعتی معنا دارند. --}}
        @if($hRate > 0)
          <div id="cvb-hourly-body" @if(! $hOn) hidden @endif>
            <p class="cvb-note">
              <svg class="icon"><use href="#i-clock"/></svg>
              <span>
                <b>{{ __('ui.cvb_hourly_t') }}</b> — <span id="cvb-h-rate">{{ cloud_price($hRate) }}</span>{{ __('ui.cvb_hourly_per') }}<br>
                {{ __('ui.cvb_hourly_min_pre') }}<b id="cvb-h-min">{{ cloud_price($hMin) }}</b>{{ __('ui.cvb_hourly_min_suf') }}
                — {{ __('ui.cvb_hourly_credit') }}<b>{{ cloud_price($creditIrt) }}</b><br>
                <span id="cvb-h-existing-note" @if($hExisting === 0) hidden @endif>
                  {{ __('ui.cvb_hourly_existing_pre') }}<b id="cvb-h-existing">{{ cloud_price($hExisting) }}</b>{{ __('ui.cvb_hourly_existing_suf') }}
                </span>
              </span>
            </p>
            <p class="cvb-warn" id="cvb-h-low" @if($creditIrt >= $hMin) hidden @endif>{{ __('ui.cvb_hourly_low') }}</p>
            <label class="cvb-field">
              <span>{{ __('ui.cvb_hourly_end') }}</span>
              <select name="on_credit_out">
                <option value="suspend" @selected(old('on_credit_out', 'suspend') === 'suspend')>{{ __('ui.cvb_hourly_end_suspend') }}</option>
                <option value="convert" @selected(old('on_credit_out') === 'convert')>{{ __('ui.cvb_hourly_end_convert') }}</option>
                <option value="terminate" @selected(old('on_credit_out') === 'terminate')>{{ __('ui.cvb_hourly_end_terminate') }}</option>
              </select>
            </label>
            <p class="cvb-note">
              <svg class="icon"><use href="#i-info"/></svg>
              <span>{{ __('ui.cvb_hourly_note') }}</span>
            </p>
          </div>
        @endif
      </div>
    </details>
  </div>

  {{-- ═══ برگهٔ سرور — نوارِ پیشرفت، خلاصه، قیمتِ زنده و راهِ برگشت، یک‌جا ═══ --}}
  <aside class="cvb-slip" aria-label="{{ __('ui.cvb_sum') }}">
    <section class="pnl-sec">
      <div class="pnl-sec-b">
        <div class="cvb-sliph">
          <h2>{{ __('ui.cvb_slip_h') }}</h2>
          <span class="cb-live"><i></i>{{ __('ui.cvb_slip_live') }}</span>
        </div>

        <div class="cvb-lines">
          @if($isGpuStore)
            {{-- مکانِ GPU ثابت است: یک ردیفِ اطلاع‌رسان، نه دو پرسشِ بی‌جواب --}}
            <button type="button" class="cvb-line is-done" data-go="3">
              <span class="cvb-line-d"></span>
              <span class="cvb-line-k">{{ __('ui.cvb_step_loc') }}</span>
              <span class="cvb-line-v">{{ $countryLabel }}</span>
            </button>
          @else
            <button type="button" class="cvb-line @if($curGroup !== null) is-done @endif" data-go="1">
              <span class="cvb-line-d"></span>
              <span class="cvb-line-k">{{ __('ui.cvb_step_country') }}</span>
              <span class="cvb-line-v">{{ $countryLabel }}</span>
            </button>

            <button type="button" class="cvb-line @if($curBucket !== null) is-done @endif" data-go="1">
              <span class="cvb-line-d"></span>
              <span class="cvb-line-k">{{ __('ui.cvb_step_city') }}</span>
              <span class="cvb-line-v" id="cvb-s-city">{{ $cityLabel }}</span>
            </button>
          @endif

          <button type="button" class="cvb-line is-done" data-go="3">
            <span class="cvb-line-d"></span>
            <span class="cvb-line-k">{{ __('ui.cvb_step_size') }}</span>
            <span class="cvb-line-v"><b id="cvb-s-plan">{{ $jsPlans[$curSlug] ?? '—' }}</b><small class="cvb-sspec" id="cvb-s-spec">{{ $jsSpecs[$curSlug] ?? '' }}</small></span>
          </button>

          <button type="button" class="cvb-line is-done" data-go="4">
            <span class="cvb-line-d"></span>
            <span class="cvb-line-k">{{ __(($gpuMode ?? false) ? 'ui.cvb_gpu_step_app' : 'ui.cvb_step_os') }}</span>
            <span class="cvb-line-v">
              @if(isset($jsImgLogo[$curImage]))
                <img class="cvb-logo sm" id="cvb-s-img-logo" src="{{ $jsImgLogo[$curImage] }}" alt="" width="16" height="16">
              @endif
              <span id="cvb-s-img">{{ $jsImgLbl[$curImage] ?? '—' }}</span>
            </span>
          </button>

          <button type="button" class="cvb-line is-done" data-go="3">
            <span class="cvb-line-d"></span>
            <span class="cvb-line-k">{{ __('ui.cvb_cycle') }}</span>
            <span class="cvb-line-v" id="cvb-s-cyc">{{ $hOn ? __('ui.cvb_hourly_t') : ($cycleLabels[$curCycle] ?? '—') }}</span>
          </button>



          <div class="cvb-line @if($curIp > 0) is-done @endif" id="cvb-s-ip-row" @if($curIp < 1) hidden @endif>
            <span class="cvb-line-d"></span>
            <span class="cvb-line-k">{{ __('ui.cvb_ip') }}</span>
            <span class="cvb-line-v pnl-num" id="cvb-s-ip">{{ fa_num($curIp) }}</span>
          </div>
        </div>

        {{-- خطِ پارگی: زیرش فقط یک عدد و یک دکمه --}}
        <div class="cvb-tear"></div>

        <div class="cvb-totk">{{ __($isGpuStore ? 'ui.gpu_approved_price' : 'cloud-store.total_due') }}</div>
        <div class="cvb-tot pnl-num" id="cvb-s-first">{{ $hasPrice ? cloud_price($initFirst) : '—' }}</div>
        {{-- نوارِ ترکیبِ هزینه: مبلغ فقط «چقدر» نمی‌گوید، «از چه» را هم نشان
             می‌دهد. سه قطعه — پلن، IPِ اضافه، مالیات — همه با یک رنگ و سه
             شفافیت، پس هیچ رنگِ تازه‌ای وارد پالت نمی‌شود.
             تزئینی است (aria-hidden): همان سه عدد در سطرهای بالا و خطِ مالیات
             با واژه گفته شده‌اند و صفحه‌خوان نباید دو بار بشنودشان. --}}
        <div class="cvb-bar" id="cvb-bar" aria-hidden="true">
          <i class="cvb-bar-a"></i>
          <i class="cvb-bar-b"></i>
          <i class="cvb-bar-c"></i>
        </div>
        <div class="cvb-tax" id="cvb-s-tax">{{ __('ui.cvb_tax_incl', ['pct' => fa_num($taxPct)]) }}</div>
        @php $initSetup = (int) ($priceMap[$curSlug][$curCycle]['setup'] ?? 0); @endphp
        {{-- هزینهٔ راه‌اندازیِ یک‌باره — عددِ روی دکمه باید با فاکتور یکی باشد،
             پس همین‌جا صادقانه گفته می‌شود که بخشی از «پرداختِ اول» نصب است. --}}
        <div class="cvb-tax" id="cvb-s-setup" @if($initSetup < 1) hidden @endif>{{ __('ui.cvb_setup_line') }}: <span class="pnl-num" id="cvb-s-setup-n">{{ cloud_price($initSetup) }}</span></div>
        <p class="cvb-warn" id="cvb-s-noprice" @if($hasPrice) hidden @endif>{{ __('ui.cvb_no_price') }}</p>

        @if($isGpuStore)
          <p class="cvb-note" id="cvb-gpu-capacity" role="status" aria-live="polite">{{ __('ui.gpu_capacity_unknown') }}</p>
          <p class="cvb-note">{{ __('ui.gpu_checkout_no_charge') }}</p>
          <p class="cvb-note" data-gpu-sale-policy>{{ __('ui.cvb_gpu_sale_policy') }}</p>
          @foreach(['gpu-ollama' => 'Ollama', 'gpu-comfyui' => 'ComfyUI'] as $gpuAppKey => $gpuAppLabel)
            @if(\App\Services\Cloud\SaladClient::salesPaused($gpuAppKey))
              <p class="cvb-note" data-gpu-app-sales-paused="{{ $gpuAppKey }}">{{ __('ui.gpu_app_sales_paused', ['app' => $gpuAppLabel]) }}</p>
            @endif
          @endforeach
          @if(\App\Models\Setting::get('salad_jupyter_sales_paused', '0') === '1')
          <p class="cvb-note" data-gpu-jupyter-sales-paused>{{ __('ui.gpu_jupyter_sales_paused') }}</p>
          @endif
        @endif
        <div id="cvx-name-home">@include('account.partials.cloud-store-name')</div>
        <button type="submit" class="pnl-btn primary cvb-go" id="cvb-submit" @disabled(count($planCards) === 0 || ! $hasPrice)>
          <svg class="icon"><use href="#i-rocket"/></svg>
          <span data-cvx-action-label>{{ __($isGpuStore ? 'ui.gpu_request_submit' : 'ui.cvb_pay') }}</span>
        </button>
        <p class="cvb-eta">
          <svg class="icon"><use href="#i-clock"/></svg>
          <span>{{ __(($gpuMode ?? false) ? 'ui.cvb_eta_gpu' : 'ui.cvb_eta') }}</span>
        </p>
      </div>
    </section>
  </aside>

  {{-- داکِ موبایل: مبلغ و دکمه در هر نقطهٔ اسکرول روی صفحه می‌مانند. روی
       دسکتاپ display:none است. --}}
  <div class="cvb-dock">
    <div id="cvx-mobile-name"></div>
    <div class="cvx-dock-row">
    <span class="cvb-dock-t">
      <small>{{ __($isGpuStore ? 'ui.gpu_approved_price' : 'cloud-store.total_due') }}</small>
      <b class="pnl-num" id="cvb-d-first">{{ $hasPrice ? cloud_price($initFirst) : '—' }}</b>
    </span>
    <button type="submit" class="pnl-btn primary" id="cvb-submit-2" @disabled(count($planCards) === 0 || ! $hasPrice)>
      <span data-cvx-action-label>{{ __($isGpuStore ? 'ui.gpu_request_submit' : 'ui.cvb_pay') }}</span>
    </button>
    </div>
  </div>
</form>

@php
  $jsData = [
    'gpu' => (bool) $isGpuStore,
    'cta' => ['resources' => __('cloud-store.continue_resources'), 'image' => __($isGpuStore ? 'cloud-store.continue_app' : 'cloud-store.continue_image'), 'pay' => __($isGpuStore ? 'ui.gpu_request_submit' : 'ui.cvb_pay')],
    'gpuCapacityUrl' => lroute('account.gpu.capacity'),
    'gpuCapacityUnknown' => __('ui.gpu_capacity_unknown'),
    'prices' => $priceMap,
    'images' => $imageMap,
    'cycles' => $cycleLabels,
    'months' => $cycleMonths,
    'plans'  => $jsPlans,
    'specs'  => $jsSpecs,
    'imgLbl' => $jsImgLbl,
    'imgLogo' => $jsImgLogo,
    'imageCardWords' => ['os' => __('ui.cvb_os'), 'app' => __('ui.cvb_app'),
      'version' => __('ui.cvb_image_version'), 'selected' => __('ui.cvb_image_selected'),
      'ready' => __('ui.cvb_image_ready'), 'continue' => __('ui.cvb_image_continue')],
    'addon'  => $addonMap,
    // برمتال: فروشِ ساعتی ندارد — کلیدِ ساعتی برایش پنهان می‌شود
    'metal'  => collect($planCards)->mapWithKeys(fn ($c) => [$c['slug'] => (bool) ($c['metal'] ?? false)])->all(),
    'extraIp' => (int) $extraIpPrice,
    'saveSuffix' => __('ui.cvb_save_suf'),
    'tax'    => (int) $taxPct,
    'auto'   => (string) $autoLabel,
    // ارز: فارسی تومان، بقیه یورو با نرخِ زندهٔ همان کلاسی که قیمت‌ها را ساخته.
    'fa'   => app()->getLocale() === 'fa',
    'rate' => cloud_eur_rate(),
    'noPrice' => __('ui.cvb_no_price'),
    'todo' => __('ui.cvb_slip_todo'),
    // فروشِ ساعتی
    'hourly' => $hourlyMap ?? [],
    'credit' => $creditIrt ?? 0,
    'hPer'   => __('ui.cvb_hourly_per'),
    'hLbl'   => __('ui.cvb_hourly_t'),
    'openStep' => (int) $openStep,
    // برگهٔ مقایسه: سرستونِ مبلغ در حالتِ ساعتی عوض می‌شود، و جهتِ مرتب‌سازی
    // برای صفحه‌خوان با واژه گفته می‌شود نه با aria-sort (که فقط روی نقشِ
    // columnheader معتبر است و این‌جا ردیف‌ها یک گروهِ رادیو هستند نه جدول).
    'amtLbl' => __('ui.cvb_amount'),
    'sAsc'   => __('ui.cvb_sort_asc'),
    'sDesc'  => __('ui.cvb_sort_desc'),
  ];
@endphp
<script src="{{ asset_ver('assets/js/cloud-image-cards.js') }}"></script>
<script>
(function(){
  'use strict';

  var D = @json($jsData);
  var form = document.getElementById('cvb-form');
  if (!form) { return; }
  var imageCards = window.ServerNetImageCards ? window.ServerNetImageCards.mount(form, D.imageCardWords) : null;
  if (imageCards) {
    Object.assign(D.imgLbl, imageCards.labels);
    Object.assign(D.imgLogo, imageCards.logos);
  }

  var faN = function(x){ return String(x).replace(/[0-9]/g, function(g){ return '۰۱۲۳۴۵۶۷۸۹'[g]; }); };
  var comma = function(n){ return Math.round(n || 0).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ','); };
  // money() خودش واحد را می‌چسبانَد؛ هیچ‌جای دیگر واحد دستی اضافه نشود.
  var money = function(n){
    if (D.fa) { return faN(comma(n)) + ' تومان'; }
    if (D.rate > 0) { return '€' + ((n || 0) / D.rate).toFixed(2); }
    return comma(n);
  };
  var set = function(id, txt){ var el = document.getElementById(id); if (el) el.textContent = txt; };
  var val = function(name){ var el = form.querySelector('input[name="' + name + '"]:checked'); return el ? el.value : ''; };
  var mark = function(sel, node){
    form.querySelectorAll(sel).forEach(function(o){ o.classList.remove('on'); });
    if (node) node.classList.add('on');
  };
  // مقدارِ جمع‌شدهٔ هر مرحله روی تیرکِ مسیر. یک انتخاب حداکثر **دو بار** چاپ
  // می‌شود (تیرک = ناوبری، برگه = رسید) و هرگز داخلِ مرحلهٔ باز.
  var spineVal = function(n, txt){
    var el = form.querySelector('[data-sp-v="' + n + '"]');
    if (el) { el.textContent = txt; }
  };
  /* نوارِ ترکیبِ هزینه — سه قطعه که با هم ۱۰۰٪ می‌شوند. عمداً بی‌درصدِ نوشتاری:
     عددها در سطرهای بالا و خطِ مالیات با واژه گفته شده‌اند و رقمِ چهارم فقط
     شلوغی است. */
  var bar = function(base, ip, tax){
    var el = document.getElementById('cvb-bar');
    if (!el) { return; }
    var sum = base + ip + tax;
    var seg = el.querySelectorAll('i');
    if (sum <= 0 || seg.length < 3) { el.hidden = sum <= 0; return; }
    el.hidden = false;
    seg[0].style.flexBasis = (base / sum * 100) + '%';
    seg[1].style.flexBasis = (ip / sum * 100) + '%';
    seg[2].style.flexBasis = (tax / sum * 100) + '%';
  };

  // ── گزینه‌های سیستم‌عامل/نرم‌افزار را با پلن انتخابی هم‌تراز کن ──
  // گزینه‌ای که روی این پلن تحویل نمی‌شود پنهان می‌شود؛ اگر همان انتخاب شده
  // بود، اولین گزینهٔ ممکن جایش را می‌گیرد تا فرم هرگز با انتخاب نشدنی ارسال نشود.
  // زبانهٔ سیستم‌عامل/نرم‌افزار — یک تابع، تا وضعیتِ دیداری و aria هرگز از هم
  // جدا نیفتند و جایگزینیِ خودکارِ ایمیج بتواند پنلِ درست را هم بالا بیاورد.
  var showPane = function(kind){
    form.querySelectorAll('[data-tab]').forEach(function(b){
      var mine = b.getAttribute('data-tab') === kind;
      b.classList.toggle('on', mine);
      b.setAttribute('aria-selected', mine ? 'true' : 'false');
    });
    form.querySelectorAll('[data-pane]').forEach(function(p){
      p.hidden = p.getAttribute('data-pane') !== kind;
    });
  };

  var syncImages = function(){
    var slug = val('plan');
    var allow = D.images[slug] || { os: [], app: [] };
    var chosen = val('image');
    var first = null, firstKind = 'os', stillOk = false;

    ['os', 'app'].forEach(function(kind){
      var ok = allow[kind] || [];
      var pane = form.querySelector('[data-pane="' + kind + '"]');
      if (!pane) return;
      var shown = 0;

      pane.querySelectorAll('.cvb-img').forEach(function(lab){
        var can = ok.indexOf(lab.getAttribute('data-key')) !== -1;
        lab.hidden = !can;
        if (can) {
          shown++;
          if (!first) { first = lab; firstKind = kind; }
          if (lab.getAttribute('data-key') === chosen) stillOk = true;
        }
      });

      pane.querySelectorAll('.cvb-fam').forEach(function(fam){
        fam.hidden = fam.querySelectorAll('.cvb-img:not([hidden])').length === 0;
      });

      var empty = pane.querySelector('[data-empty]');
      if (empty) empty.hidden = shown > 0;
    });

    if (!stillOk && first) {
      var r = first.querySelector('input');
      if (r) { r.checked = true; }
      mark('.cvb-img', first);
      // 🔴 جایگزینِ خودکار می‌توانست در پنلِ **پنهان** بنشیند: چیپِ «انتخاب‌شده»
      // ساخته می‌شد و کسی نمی‌دیدش. پنلِ میزبان هم بالا می‌آید.
      showPane(firstKind);
    }
    if (imageCards) imageCards.refresh();
  };

  var hChk = document.getElementById('cvb-hourly');
  var ipSel = document.getElementById('cvb-extra-ip');
  var submits = [document.getElementById('cvb-submit'), document.getElementById('cvb-submit-2')];

  var checkoutLocked = false;
  var nameFields = document.getElementById('cvx-name-fields');
  var nameHome = document.getElementById('cvx-name-home');
  var mobileName = document.getElementById('cvx-mobile-name');
  var mobileCheckout = window.matchMedia('(max-width: 1000px)');
  var placeName = function(){
    var destination = mobileCheckout.matches ? mobileName : nameHome;
    if (destination && nameFields && nameFields.parentNode !== destination) destination.appendChild(nameFields);
  };
  placeName();
  if (mobileCheckout.addEventListener) mobileCheckout.addEventListener('change', placeName);
  var syncPrimaryAction = function(){
    var current = form.getAttribute('data-active-step') || String(D.openStep || (D.gpu ? 3 : 1));
    var final = current === '4';
    var locationField = form.querySelector('input[name="location"]');
    var compatible = (D.images || {})[val('plan')] || {os:[], app:[]};
    var hasImage = (compatible.os || []).concat(compatible.app || []).indexOf(val('image')) !== -1;
    var label = final ? D.cta.pay : (current === '1' ? D.cta.resources : D.cta.image);
    submits.forEach(function(button){
      if (!button) return;
      button.type = final ? 'submit' : 'button';
      button.setAttribute('data-checkout-stage', current);
      var text = button.querySelector('[data-cvx-action-label]');
      if (text) text.textContent = label;
      button.disabled = checkoutLocked || (current === '1' && !(locationField && locationField.value)) || (final && !hasImage);
    });
    if (nameFields) nameFields.hidden = !final;
  };
  var lockSubmit = function(off){
    checkoutLocked = !!off;
    syncPrimaryAction();
  };

  // مبلغِ افزودنی سمتِ سرور با CloudAddons ساخته می‌شود؛ همان فرمول این‌جا
  // بازسازی می‌شود (ماه × تخفیفِ دوره، گردِ رو به **بالا** به ۱۰٬۰۰۰ تومان) تا
  // عددِ دکمه با مبلغِ فاکتور یکی باشد. تستِ CloudStoreSlipTest همین را می‌سنجد.
  var addonForCycle = function(cyc, discount){
    var n = ipSel ? parseInt(ipSel.value, 10) || 0 : 0;
    if (n < 1) { return 0; }
    var months = D.months[cyc] || 1;
    var raw = D.extraIp * n * months * (100 - (discount || 0)) / 100;
    return Math.ceil(raw / 10000) * 10000;
  };

  var capacitySlug = null;
  var capacityChecked = 0;
  var capacitySequence = 0;
  function checkGpuCapacity(slug){
    if (!D.gpu || !slug || (capacitySlug === slug && Date.now() - capacityChecked < 30000)) { return; }
    capacitySlug = slug;
    capacityChecked = Date.now();
    var sequence = ++capacitySequence;
    var capacityLabel = document.getElementById('cvb-gpu-capacity');
    if (capacityLabel) { capacityLabel.textContent = D.gpuCapacityUnknown; }
    fetch(D.gpuCapacityUrl + '?plan=' + encodeURIComponent(slug), {headers:{'Accept':'application/json'}})
      .then(function(r){ if(!r.ok) {throw new Error('capacity');} return r.json(); })
      .then(function(d){ if(sequence === capacitySequence && capacityLabel) {capacityLabel.textContent = d.label || D.gpuCapacityUnknown;} })
      .catch(function(){ if(sequence === capacitySequence && capacityLabel) {capacityLabel.textContent = D.gpuCapacityUnknown;} });
  }
  if(D.gpu) { setInterval(function(){checkGpuCapacity(val('plan'));}, 30000); }

  var render = function(){
    var slug = val('plan'), cyc = val('cycle');
    checkGpuCapacity(slug);
    var bucket = D.prices[slug] || {};
    var h = (D.hourly || {})[slug] || { rate: 0, min: 0 };

    /* ── کلیدِ ساعتی: برمتال، یا اسلاگی که زیرساخت ساعتی نمی‌فروشدش ──
       rate === 0 یعنی هیچ ردیفِ ساعتی‌فروشی برای این اندازه در این مکان نیست.
       نمایشِ کلید در آن حالت یعنی مشتری چیزی را انتخاب می‌کند که سرِ ثبتِ سفارش
       رد می‌شود — همان تجربه‌ای که سرویس‌های ۹۶ و ۹۸ ساختند.

       ⚠️ **بالای** render، پیش از خواندنِ `hourly`: تیک باید در همان نفسی که
       پلن عوض می‌شود برداشته شود، وگرنه ستونِ مبلغ و برچسبِ بالای برگه یک
       رندرِ کامل «تومان/ساعت» می‌مانند برای پلنی که ساعتی ندارد. */
    var isMetal = !!((D.metal || {})[slug]);
    var hOff = isMetal || !(h.rate > 0);
    var hSeg = form.querySelector('.cvb-seg-h');
    if (hSeg) { hSeg.hidden = hOff; }
    if (hOff && hChk && hChk.checked) { hChk.checked = false; }

    var hourly = !!(hChk && hChk.checked);

    // Server savings follow the selected plan's real cost-capped quote. The
    // configured add-on discount is a separate value used only for IP pricing.
    form.querySelectorAll('[data-cycle-saving]').forEach(function(badge){
      var quote = bucket[badge.getAttribute('data-cycle-saving')];
      var saving = quote ? quote.save || 0 : 0;
      badge.hidden = hourly || saving <= 0;
      badge.textContent = saving > 0 ? (D.fa ? faN(String(saving)) : String(saving)) + D.saveSuffix : '';
    });

    // مبلغِ ستونِ هر ردیف = همان دورهٔ انتخابی.
    // ⚠️ این حلقه عمداً **پیش از** بازگشتِ زودهنگامِ ساعتی است، و تا وقتی
    // سرستون اسمی نداشت آن تناقض دیده نمی‌شد: ردیف‌ها مبلغِ دوره را نشان
    // می‌دادند و برگه «تومان/ساعت» را. حالا که ستون «مبلغ دوره» نام دارد،
    // هر دو باید یک چیز بگویند.
    form.querySelectorAll('.cvb-plan[data-slug]').forEach(function(card){
      var s = card.getAttribute('data-slug');
      var el = card.querySelector('[data-pp]');
      if (!el) return;
      if (hourly) {
        var hr = (D.hourly || {})[s] || { rate: 0 };
        el.textContent = hr.rate > 0 ? money(hr.rate) + D.hPer : '—';
        return;
      }
      var r = (D.prices[s] || {})[cyc];
      if (r) el.textContent = money(r.cycle);
    });

    var amtH = document.getElementById('cvb-sh-amt');
    if (amtH) amtH.textContent = hourly ? D.hLbl : D.amtLbl;

    // ── برگه ──
    set('cvb-s-plan', D.plans[slug] || '—');
    set('cvb-s-spec', D.specs[slug] || '');
    set('cvb-s-img', D.imgLbl[val('image')] || '—');
    var logo = document.getElementById('cvb-s-img-logo');
    if (logo && D.imgLogo[val('image')]) { logo.src = D.imgLogo[val('image')]; }
    set('cvb-v-3', D.plans[slug] || '—');
    set('cvb-v-4', D.imgLbl[val('image')] || '—');
    spineVal(3, D.plans[slug] || '—');
    spineVal(4, D.imgLbl[val('image')] || '—');

    // ── IP اضافه: هم روی برگه، هم آشکار/پنهان بر پایهٔ توانِ همین اسلاگ ──
    var ipBox = document.getElementById('cvb-ip-box');
    var canIp = !!D.addon[slug];
    if (ipBox) {
      ipBox.hidden = !canIp;
      // پنهان **و** غیرفعال، همان الگوی کادرِ کلیدِ SSH: کنترلی که پنهان است ولی
      // فعال، مقدارِ کهنه‌اش را همراهِ فرم می‌فرستد و سرور صادقانه ردش می‌کند.
      if (ipSel) {
        ipSel.disabled = !canIp;
        if (!canIp) { ipSel.value = '0'; }
      }
    }
    var ipN = ipSel && canIp ? (parseInt(ipSel.value, 10) || 0) : 0;
    var ipRow = document.getElementById('cvb-s-ip-row');
    if (ipRow) {
      ipRow.hidden = ipN < 1;
      ipRow.classList.toggle('is-done', ipN > 0);
      set('cvb-s-ip', D.fa ? faN(String(ipN)) : String(ipN));
    }

    // ── نرخِ ساعتی ──
    set('cvb-h-rate', money(h.rate));
    document.getElementById('cvb-quoted-hourly').value = h.rate || 0;
    set('cvb-h-min', money(h.min));
    set('cvb-h-existing', money(h.existing || 0));
    var existingNote = document.getElementById('cvb-h-existing-note');
    if (existingNote) existingNote.hidden = !(h.existing > 0);
    var low = document.getElementById('cvb-h-low');
    if (low) low.hidden = (D.credit >= h.min);

    var bill = document.getElementById('cvb-bill');
    if (bill) bill.classList.toggle('is-hourly', hourly);
    var hBody = document.getElementById('cvb-hourly-body');
    if (hBody) hBody.hidden = !hourly;

    var warn = document.getElementById('cvb-s-noprice');
    var taxLine = document.getElementById('cvb-s-tax');

    if (hourly) {
      set('cvb-s-cyc', D.hLbl);
      set('cvb-s-first', money(h.rate) + D.hPer);
      set('cvb-d-first', money(h.rate) + D.hPer);
      if (warn) warn.hidden = true;
      if (taxLine) taxLine.hidden = true;
      bar(0, 0, 0);
      lockSubmit(h.rate <= 0);
      return;
    }

    if (taxLine) taxLine.hidden = false;
    set('cvb-s-cyc', D.cycles[cyc] || '—');

    var row = bucket[cyc];

    // 🔴 هرگز مبلغِ پلنِ قبلی زیرِ نامِ پلنِ تازه نماند. نبودِ ردیف یعنی این
    // اندازه قیمت ندارد: مبلغ خالی، دلیل نوشته، و دکمه بسته.
    if (!row) {
      set('cvb-s-first', '—');
      set('cvb-d-first', '—');
      if (warn) warn.hidden = false;
      bar(0, 0, 0);
      lockSubmit(true);
      return;
    }

    if (warn) warn.hidden = true;
    var addon = addonForCycle(cyc, row.addonDiscount);
    var setup = row.setup || 0;
    var total = row.cycle + addon + setup;
    var vat = Math.round(total * D.tax / 100);
    var first = total + vat;
    set('cvb-s-first', money(first));
    set('cvb-d-first', money(first));
    var setupRow = document.getElementById('cvb-s-setup');
    if (setupRow) { setupRow.hidden = setup < 1; }
    set('cvb-s-setup-n', money(setup));
    bar(row.cycle + setup, addon, vat);
    lockSubmit(false);
  };

  // ── مرحله‌ها: یکی باز، بقیه بسته. بدونِ این اسکریپت همه باز می‌مانند. ──
  var steps = Array.prototype.slice.call(form.querySelectorAll('.cvb-step'));
  form.classList.add('cvx-enhanced');
  var focusFrame = 0;
  var focusStep = function(n){
    cancelAnimationFrame(focusFrame);
    focusFrame = requestAnimationFrame(function(){
      var section = document.getElementById('cvb-step-' + n);
      var target = section && section.querySelector('.cvb-q');
      if (!target) return;
      target.setAttribute('tabindex', '-1');
      target.focus({preventScroll:true});
      var main = form.querySelector('.cvb-main');
      if (main) main.scrollTop = 0;
      var spine = form.querySelector('.cvx-workspace-header') || form.querySelector('.cvb-spine');
      if (spine) spine.scrollIntoView({block:'start', behavior:'instant'});
    });
  };

  var openStep = function(n){
    steps.forEach(function(s){
      var mine = s.getAttribute('data-step') === String(n);
      s.classList.toggle('is-shut', !mine);
      var h = s.querySelector('.cvb-step-h');
      if (h) h.setAttribute('aria-expanded', mine ? 'true' : 'false');
    });
    form.setAttribute('data-active-step', String(n));
    stamp();
  };

  // نقطهٔ هر مرحله: انجام‌شده / جاری / نرسیده — همان واژگانِ `.cb-dot`ِ صفحهٔ
  // ساختِ سرور، تا برگه‌ای که این‌جا پر می‌شود و فهرستی که بعد از پرداخت
  // تماشا می‌شود یک زبان داشته باشند.
  //
  // 🔴 قبلاً وضعیت از **DOM** استنتاج می‌شد: «هیچ‌کدام باز نیست» یعنی «همه پاسخ
  // داده شده». آن استنتاج فقط تا وقتی درست بود که هر انتخاب خودش مرحلهٔ بعد را
  // باز کند. حالا که انتخاب دیگر فهرست را زیرِ دستِ کاربر نمی‌بندد، DOM اصلاً
  // پیشرفت را رمزگذاری نمی‌کند — پس هر مرحله از **پاسخِ واقعیِ خودش** پرسیده
  // می‌شود. وگرنه یک مرحلهٔ دست‌نخورده که کاربر فقط جمعش کرده «انجام‌شده» مهر
  // می‌خورد و نوارِ پیشرفت دروغ می‌گوید.
  var answered = function(n){
    var loc = !!(form.querySelector('input[name="location"]') || {}).value;
    // ۱ و ۲ هر دو از **همان** ورودیِ مکان می‌پرسند: کشور و شهر دو نمایِ یک
    // تصمیم‌اند و کدِ مکان تنها چیزی است که واقعاً پست می‌شود. اگر مکان خالی
    // باشد (`?location=`)، هیچ‌کدام «انجام‌شده» نیست — و آن‌وقت نوارِ پیشرفت
    // همان چیزی را می‌گوید که مرحلهٔ ۲ روی صفحه نشان می‌دهد.
    if (n === '1' || n === '2') { return loc; }
    if (n === '3') { return val('plan') !== ''; }
    if (n === '4') { return val('image') !== ''; }
    return false;
  };

  var stamp = function(){
    var open = -1;
    steps.forEach(function(s, i){ if (!s.classList.contains('is-shut')) { open = i; } });

    steps.forEach(function(s, i){
      var done = answered(s.getAttribute('data-step'));
      s.classList.toggle('is-now', i === open);
      s.classList.toggle('is-done', done && i !== open);
      s.classList.toggle('is-todo', !done && i !== open);
    });

    // تیرکِ مسیر همان وضعیت را می‌گوید — یک منبع، دو نما.
    var nowN = open >= 0 ? steps[open].getAttribute('data-step') : '';
    form.querySelectorAll('.cvb-sp').forEach(function(sp){
      var n = sp.getAttribute('data-spine');
      sp.classList.toggle('is-now', n === nowN);
      sp.classList.toggle('is-done', answered(n) && n !== nowN);
      sp.setAttribute('aria-expanded', n === nowN ? 'true' : 'false');
    });

    // One primary action advances the wizard; payment is available on the image stage.
    form.classList.toggle('is-final', nowN === '4');
    syncPrimaryAction();
  };

  steps.forEach(function(s){
    var h = s.querySelector('.cvb-step-h');
    if (!h) return;
    h.addEventListener('click', function(){
      var n = s.getAttribute('data-step');
      if (s.classList.contains('is-shut')) { openStep(n); }
      else { s.classList.add('is-shut'); h.setAttribute('aria-expanded', 'false'); stamp(); }
    });
  });

  // هر سطرِ برگه — و هر گرهِ تیرک — دکمهٔ بازکردنِ مرحلهٔ خودش است
  form.querySelectorAll('[data-go]').forEach(function(b){
    b.addEventListener('click', function(){
      var n = b.getAttribute('data-go');
      openStep(n);
      focusStep(n);
    });
  });

  // One action advances the wizard; only the final software step can submit.
  var continueCheckout = function(){
    var current = form.getAttribute('data-active-step');
    var next = current === '1' ? '3' : '4';
    openStep(next);
    focusStep(next);
  };
  submits.forEach(function(button){
    if (!button) return;
    button.addEventListener('click', function(event){
      if (form.getAttribute('data-active-step') === '4') return;
      event.preventDefault();
      continueCheckout();
    });
  });
  form.addEventListener('submit', function(event){
    if (form.getAttribute('data-active-step') === '4') return;
    event.preventDefault();
    event.stopImmediatePropagation();
    continueCheckout();
  }, true);

  // Choosing an option updates the quote without moving away from comparisons.
  form.querySelectorAll('.cvb-plan input').forEach(function(r){
    r.addEventListener('change', function(){
      mark('.cvb-plan', r.closest('.cvb-plan'));
      syncImages();
      render();
      stamp();
    });
  });

  form.querySelectorAll('.cvb-seg-c input').forEach(function(r){
    r.addEventListener('change', function(){
      mark('.cvb-seg-c', r.closest('.cvb-seg'));
      if (hChk) { hChk.checked = false; }
      var hs = form.querySelector('.cvb-seg-h');
      if (hs) hs.classList.remove('on');
      render();
      // ترتیبِ مبلغ به دوره وابسته است، پس با عوض شدنِ دوره دوباره چیده می‌شود.
      if (sortKey === 'pr') { applySort(); }
    });
  });

  if (hChk) {
    hChk.addEventListener('change', function(){
      var hs = hChk.closest('.cvb-seg');
      if (hs) hs.classList.toggle('on', hChk.checked);
      render();
      if (sortKey === 'pr') { applySort(); }
    });
  }

  form.querySelectorAll('.cvb-img input').forEach(function(r){
    r.addEventListener('change', function(){
      mark('.cvb-img', r.closest('.cvb-img'));
      if (imageCards) imageCards.refresh();
      render();
      stamp();
    });
  });

  form.querySelectorAll('[data-tab]').forEach(function(btn){
    btn.addEventListener('click', function(){
      showPane(btn.getAttribute('data-tab'));
    });
  });

  /* ══════════ برگهٔ مقایسه — مرتب‌سازی ══════════
     ⚠️ ردیف‌ها **جابه‌جا** می‌شوند (appendChild)، هرگز بازساخته نمی‌شوند. هر
     شنوندهٔ این فایل یک‌بار سرِ بارگذاری روی گرهِ مشخص بسته شده، پس یک
     innerHTML صفحه را ۲۰۰ و ظاهراً سالم نگه می‌دارد و در سکوت انتخابِ پلن،
     همگام‌سازیِ ایمیج و قیمتِ زنده را می‌کشد.
     ⚠️ کلیدِ مبلغ از D.prices خوانده می‌شود، نه از یک صفتِ DOM: هیچ عددِ پولی
     دو بار روی صفحه نمی‌نشیند و «قیمتِ پلنِ ناموجود هیچ‌جا نیست» ساختاری
     می‌مانَد. */
  var plansBox = document.getElementById('cvb-plans');
  var sortKey = '', sortDir = 1;

  var ordOf = function(row){ return parseInt(row.getAttribute('data-ord'), 10) || 0; };

  var keyOf = function(row, key){
    if (key !== 'pr') { return parseInt(row.getAttribute('data-' + key), 10) || 0; }
    var s = row.getAttribute('data-slug');
    if (!s) { return 0; }
    if (hChk && hChk.checked) { return ((D.hourly || {})[s] || {}).rate || 0; }
    return ((D.prices[s] || {})[val('cycle')] || {}).cycle || 0;
  };

  var applySort = function(){
    if (!plansBox) { return; }

    var sell = [], off = [];
    plansBox.querySelectorAll('.cvb-plan').forEach(function(r){
      (r.hasAttribute('data-slug') ? sell : off).push(r);
    });

    sell.sort(function(a, b){
      var d = sortKey === '' ? 0 : (keyOf(a, sortKey) - keyOf(b, sortKey)) * sortDir;
      // گره‌گشاییِ قطعی با ترتیبِ سرور — نه اتکا به پایداریِ sort
      return d !== 0 ? d : ordOf(a) - ordOf(b);
    });

    // ناموجودها همیشه ته فهرست، در هر دو جهت: نه قیمت دارند، نه نرخِ ساعتی،
    // نه ردیفی در نقشهٔ افزودنی — پس هر کلیدِ ساختگی برایشان یک عددِ دروغ است.
    var frag = document.createDocumentFragment();
    sell.forEach(function(r){ frag.appendChild(r); });
    off.forEach(function(r){ frag.appendChild(r); });
    plansBox.appendChild(frag);
  };

  // aria-sort عمداً به کار نمی‌رود: فقط روی نقشِ columnheader معتبر است و
  // این‌جا ردیف‌ها یک گروهِ رادیو هستند، نه یک جدول. پس aria-pressed + واژهٔ
  // صریحِ جهت.
  var markSort = function(){
    form.querySelectorAll('.cvb-sh[data-sort]').forEach(function(b){
      var k = b.getAttribute('data-sort');
      var mine = k === 'ord' ? sortKey === '' : k === sortKey;
      b.classList.toggle('on', mine);
      b.setAttribute('aria-pressed', mine ? 'true' : 'false');
      b.setAttribute('data-dir', mine && sortDir < 0 ? 'desc' : 'asc');
      var say = b.querySelector('[data-say]');
      if (say) { say.textContent = mine && k !== 'ord' ? ' ' + (sortDir < 0 ? D.sDesc : D.sAsc) : ''; }
    });
  };

  form.querySelectorAll('.cvb-sh[data-sort]').forEach(function(b){
    b.addEventListener('click', function(){
      var k = b.getAttribute('data-sort');
      if (k === 'ord') { sortKey = ''; sortDir = 1; }
      else if (sortKey === k) { sortDir = -sortDir; }
      else { sortKey = k; sortDir = 1; }
      markSort();
      applySort();
    });
  });

  /* ══════════ فیلترِ اشتراکی/اختصاصی ══════════
     🔴 این کنترل تا امروز **هیچ‌کاری نمی‌کرد**: hidden ست می‌شد ولی
     `.cvb-plan{display:flex}` یک قاعدهٔ نویسنده است و بر `[hidden]`ِ مرورگر
     می‌چربد. panel.css حالا خنثی‌کننده دارد؛ و دقیقاً همان لحظه که پنهان‌شدن
     واقعی می‌شود، سوراخِ «پلنِ نامرئیِ انتخاب‌شده و ارسال‌شدنی» باز می‌شود.
     پس ترمیمِ انتخاب باید در همین تغییر باشد، نه در تغییرِ بعدی. */
  var kindEmpty = document.getElementById('cvb-kind-empty');

  var applyKind = function(kind){
    var firstOk = null;

    form.querySelectorAll('.cvb-plan').forEach(function(c){
      var hide = kind !== '' && c.getAttribute('data-kind') !== kind;
      c.hidden = hide;
      if (!hide && !firstOk && c.hasAttribute('data-slug')) { firstOk = c; }
    });

    var cur = form.querySelector('input[name="plan"]:checked');
    var curRow = cur ? cur.closest('.cvb-plan') : null;

    if (!curRow || curRow.hidden) {
      if (cur) { cur.checked = false; }
      mark('.cvb-plan', firstOk);

      if (firstOk) {
        var r = firstOk.querySelector('input');
        if (r) { r.checked = true; }
      }

      // همان زنجیره‌ای که یک انتخابِ دستی می‌زند — ولی عمداً بدونِ بازکردنِ
      // مرحلهٔ بعدی: فیلتر یک عملِ مقایسه است نه یک تصمیم، و بستنِ فهرست زیرِ
      // دستِ کاربر همان خصومتی است که یک نمای مقایسه‌ای نباید داشته باشد.
      syncImages();
      render();
    }

    if (kindEmpty) { kindEmpty.hidden = !!firstOk; }
    if (!firstOk) { lockSubmit(true); }
  };

  form.querySelectorAll('#cvb-kind .cvb-seg').forEach(function(btn){
    btn.addEventListener('click', function(){
      form.querySelectorAll('#cvb-kind .cvb-seg').forEach(function(b){ b.classList.toggle('on', b === btn); });
      applyKind(btn.getAttribute('data-kind') || '');
    });
  });

  var lab = document.getElementById('cvb-label');
  if (lab) { lab.addEventListener('input', function(){ render(); stamp(); }); }
  if (ipSel) { ipSel.addEventListener('change', render); }

  // مکان یک لینکِ واقعی است (کاتالوگ سمتِ سرور عوض می‌شود). سرِ کلیک، انتخابِ
  // فعلی روی آدرس سوار می‌شود تا عوض‌کردنِ شهر بقیه را دور نریزد.
  form.querySelectorAll('.cvb-city').forEach(function(a){
    a.addEventListener('click', function(){
      var u = new URL(a.href, window.location.href);
      u.searchParams.set('plan', val('plan'));
      u.searchParams.set('cycle', val('cycle'));
      if (hChk && hChk.checked) { u.searchParams.set('billing_mode', 'hourly'); }
      else { u.searchParams.delete('billing_mode'); }
      u.searchParams.set('image', val('image'));
      a.href = u.toString();
    });
  });

  /* افشاگرِ hover-محورِ کشور←شهر←دیتاسنتر (details[data-hold]) حذف شد:
     شهر مرحلهٔ خودش شد، پس هیچ <details>ای در انتخابِ مکان نمانده و آن ۱۲۰
     خط روی صفر عنصر می‌دوید. کدِ مرده‌ای که ادعای رفتار دارد بدتر از نبودش
     است — همان درسی که قاعدهٔ صفر-اثرِ blur در همین فایل داد. */

  /* ══════════ کم‌رنگ‌کردنِ ملایمِ گروه‌های دیگر ══════════
     🔴 فیلتر فقط روی `.cvb-step-i` می‌نشیند و کم‌رنگی روی خودِ `.cvb-step`.
     `.cvb-slip` (sticky) و `.cvb-dock` (fixed) **هم‌نیای** `.cvb-main` هستند نه
     فرزندش، پس هیچ‌کدام در زنجیرهٔ بلوکِ دربرگیرنده‌شان نمی‌افتند. هر filter/
     transform/contain روی `.cvb-wrap` یا بالاترش، داکِ موبایل را از قابِ دید
     می‌کَنَد و با اسکرول پایین می‌بَرَد — خرابی‌ای که ۲۰۰ می‌دهد و هیچ خطایی
     نمی‌سازد. سرصفحهٔ مرحله هرگز محو نمی‌شود، پس راهِ برگشتن خوانا می‌مانَد. */
  var hoverOK = !!(window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches);
  var main = form.querySelector('.cvb-main');
  var dimT = null, dimWant = null;

  var dim = function(on){
    if (main) { main.classList.toggle('is-focus', !!on); }
  };

  // ⚠️ فقط روی **تغییرِ** خواسته زمان‌سنج بگذار. نسخهٔ ساده‌لوح روی هر
  // pointerover تایمر را ریست می‌کرد، و چون pointerover حباب می‌کند، حرکتِ
  // پیوستهٔ نشانگر روی ردیف‌ها هیچ‌وقت به ۱۲۰ms نمی‌رسید: کم‌رنگی عملاً هرگز
  // روشن نمی‌شد و هیچ خطایی هم نمی‌داد.
  var dimAsk = function(on){
    on = !!on;
    if (dimWant === on) { return; }
    dimWant = on;
    if (dimT) { clearTimeout(dimT); }
    // ۱۲۰ms قصدِ ورود، ۱۶۰ms قصدِ خروج — عبورِ ساده از ستون نباید صفحه را
    // چشمک بزند.
    dimT = setTimeout(function(){ dimT = null; dim(on); }, on ? 120 : 160);
  };

  if (main) {
    main.addEventListener('pointerover', function(e){
      if (!hoverOK) { return; }
      dimAsk(!!(e.target && e.target.closest && e.target.closest('.cvb-step-i')));
    });

    main.addEventListener('pointerleave', function(){
      if (!hoverOK) { return; }
      dimAsk(false);
    });

    main.addEventListener('focusin', function(e){
      dimAsk(!!(e.target && e.target.closest && e.target.closest('.cvb-step-i')));
    });

    main.addEventListener('focusout', function(){
      dimAsk(false);
    });
  }

  syncImages();
  render();
  openStep(String(D.openStep || 1));
  if (D.openStep && Number(D.openStep) !== 1) { focusStep(String(D.openStep)); }
})();
</script>

{{-- انتخابگرِ کلیدِ SSH: «افزودن کلید تازه» کادرِ چسباندن را باز می‌کند.
     ⚠️ وقتی کادر بسته است، فیلدهایش `disabled` می‌شوند نه فقط پنهان — وگرنه
     متنِ نیمه‌تمامِ یک تلاشِ قبلی همراهِ فرم می‌رفت و اعتبارسنجی رد می‌کرد.
     ⚠️ این بلوک عمداً **داخلِ** شرطِ کاتالوگِ ناخالی است؛ قبلاً روی صفحهٔ
     کاتالوگِ خالی هم بارگذاری می‌شد و آن‌جا هیچ فرمی وجود نداشت. --}}
<script>
(function(){
  'use strict';

  var pick = document.getElementById('cvb-ssh-pick');
  var box  = document.getElementById('cvb-ssh-new');

  if (!pick || !box) { return; }

  function sync(){
    var isNew = pick.value === '__new';
    box.hidden = !isNew;

    box.querySelectorAll('input, textarea').forEach(function(el){
      el.disabled = !isNew;
    });

    // مقدارِ '__new' نباید به سرور برود؛ سرور یا شناسهٔ عددی می‌خواهد یا خالی
    pick.querySelectorAll('option').forEach(function(o){
      if (o.value === '__new') { o.disabled = false; }
    });
  }

  pick.addEventListener('change', sync);
  sync();

  // سرِ ارسال، مقدارِ نشانه‌ایِ '__new' را خالی کن تا اعتبارسنجیِ عددیِ
  // ssh_key_id نشکند.
  var form = document.getElementById('cvb-form');

  if (form) {
    form.addEventListener('submit', function(){
      if (pick.value === '__new') { pick.value = ''; }
      if (window.ServerNetAnalytics) {
        try {
          var reg = val('region') || '';
          var fam = val('family') || '';
          var flv = val('flavor') || 'cloud_vps';
          var osV = val('image') || val('os') || '';
          window.ServerNetAnalytics.configureProduct({
            datacenter: reg,
            family: fam,
            os: osV
          });
          window.ServerNetAnalytics.beginCheckout({
            item_id: flv,
            item_name: @json(__('ui.svc_name_vps', ['label' => ''])) + (reg ? '(' + reg + ')' : ''),
            item_category: 'cloud_vps'
          }, val('cycle') || 'monthly');
        } catch(e) {}
      }
    });
  }
})();
</script>

<script src="{{ asset_ver('assets/js/cloud-store-geography.js') }}" defer></script>
<script src="{{ asset_ver('assets/js/cloud-store-globe.js') }}" defer></script>
<script src="{{ asset_ver('assets/js/cloud-store-experience.js') }}" defer></script>
@endif

@endsection

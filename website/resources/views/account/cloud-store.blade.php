@extends('panel.layout')
@section('title', __('ui.cvb_title'))

{{-- «برگهٔ سرور» — سرورساز به‌شکلِ یک رسید که خودش را پر می‌کند.

     پنج مرحله، هر لحظه یکی باز؛ هر پاسخ یک سطر روی برگهٔ کنارِ صفحه می‌نویسد و
     هر سطر همان دکمه‌ای است که مرحله‌اش را دوباره باز می‌کند. پس برگه هم‌زمان
     نوارِ پیشرفت است، هم خلاصه، هم قیمتِ زنده، هم راهِ برگشتن.

     چهار نکتهٔ این فایل:
     ۱) هیچ نام یا شناسهٔ زیرساختی این‌جا نیست — فقط نام عمومی پلن و کد مکان خودمان.
     ۲) مبلغ‌های نمایشی فقط نمایشی‌اند؛ مبلغ نهایی سمت سرور از دیتابیس خوانده می‌شود.
     ۳) هیچ استایلی این‌جا نیست: کلاس‌های cvb-* انتهای public/assets/css/panel.css
        هستند، پس زیرِ CssVariablesDefinedTest می‌مانند. تستی هست که این را قفل می‌کند.
     ۴) بی‌جاوااسکریپت هم کامل کار می‌کند: مرحله‌ها باز می‌مانند، مکان لینکِ واقعی
        است، ساعتی چک‌باکسِ ساده است و «تنظیمات پیشرفته» یک details بومی. --}}

@section('panel')

<div class="pnl-head">
  <div>
    <nav class="blog-crumbs">
      <a href="{{ lroute('account.home') }}">{{ __('ui.cvb_crumb_panel') }}</a><span>/</span>
      {{-- عنوانِ این صفحه **یک جا** تعریف می‌شود (`ui.cvb_h1`) و مسیرِ راهنما و
           سرصفحه هر دو از همان می‌خوانند؛ `ui.cvb_title` فقط همان عنوان به‌علاوهٔ
           نامِ برند برای تگِ <title> است. --}}
      <span>{{ __('ui.cvb_h1') }}</span>
    </nav>
    <h1>{{ __('ui.cvb_h1') }}</h1>
    <p>{{ __('ui.cvb_intro') }}</p>
  </div>
  <span class="pnl-pill info">{{ __('ui.cvb_pill') }}</span>
</div>

{{-- پیام‌ها با .dm-note می‌آیند نه .pnl-sec — کامنتِ خودِ panel.css می‌گوید
     .pnl-sec قابِ اشتباهی برای پیام است و متن را بی‌رنگ رها می‌کند. --}}
@if(session('ok'))
  <div class="dm-note ok">{{ session('ok') }}</div>
@endif

@php
  // خطاهایی که سرِ خودِ کنترل نشان داده می‌شوند، این‌جا تکرار نمی‌شوند.
  $inlineKeys = ['location', 'plan', 'image', 'cycle', 'billing_mode', 'extra_ipv4', 'ssh_key_id', 'ssh_key_new', 'label'];
  $loose = collect($errors->keys())->reject(fn ($k) => in_array($k, $inlineKeys, true))->all();
@endphp

@if(count($loose) > 0)
  <div class="dm-note danger">
    @foreach($loose as $k)@foreach($errors->get($k) as $e)<div>{{ $e }}</div>@endforeach @endforeach
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
  $jsPlans  = collect($planCards)->mapWithKeys(fn ($p) => [$p['slug'] => $p['name']])->all();
  // خلاصه باید **همهٔ** چیزی را که مشتری انتخاب کرده بگوید: پردازنده، رم، دیسک
  // و ترافیک — نه فقط دیسک. یک رشته، یک جا ساخته، هم برگه و هم جاوااسکریپت از
  // همین می‌خوانند.
  $jsSpecs  = collect($planCards)->mapWithKeys(fn ($p) => [$p['slug'] =>
    fa_num($p['vcpu']).' vCPU · '.fa_num($p['ram']).' · '.fa_num($p['disk']).' · '.fa_num($p['traffic'])])->all();
  // برچسبِ ایمیج **بدون** ایموجی: انتخابگر یک SVGِ خط‌تیز نشان می‌داد و خلاصه
  // همان انتخاب را با 🟠 تأیید می‌کرد — یک شیء، دو زبانِ دیداری.
  $jsImgLbl = $osCatalog->concat($appCatalog)->mapWithKeys(fn ($i) => [$i->key => $i->label])->all();
  $jsImgLogo = $osCatalog->concat($appCatalog)->mapWithKeys(fn ($i) => [$i->key => $i->logo()])->all();

  // انتخاب‌های اولیه: با old() تا بازگشت خطا انتخاب کاربر را دور نریزد
  $curSlug  = (string) old('plan', $selectedSlug);
  if (! isset($imageMap[$curSlug])) { $curSlug = $selectedSlug; }

  $okOs  = (array) ($imageMap[$curSlug]['os'] ?? []);
  $okApp = (array) ($imageMap[$curSlug]['app'] ?? []);

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
  $hOn   = old('billing_mode') === 'hourly';

  // مرحله‌ای که باید باز باشد: اولین مرحله‌ای که خطا دارد، وگرنه «اندازه»
  // (مکان معمولاً از لینکِ ورودی می‌آید). بی‌جاوااسکریپت همه باز می‌مانند.
  $openStep = 2;
  $stepFound = false;
  foreach ([1 => ['location'], 2 => ['plan', 'cycle', 'billing_mode'], 3 => ['image'], 4 => ['label']] as $n => $keys) {
    foreach ($keys as $k) {
      if (! $stepFound && $errors->has($k)) { $openStep = $n; $stepFound = true; }
    }
  }
  if ($errors->has('extra_ipv4') || $errors->has('ssh_key_new') || $errors->has('ssh_key_id')) { $advOpen = true; }
  else { $advOpen = $curIp > 0 || old('ssh_key_id') !== null || old('ssh_key_new') !== null; }

  // برچسبِ مکانِ جاری برای برگه
  $locLabel = $location ? trim($location->flagEmoji().' '.$location->label()) : '—';
  $curCountry = $location ? strtoupper((string) $location->country) : '';

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
    .'&image='.urlencode($curImage);

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

<form method="POST" action="{{ lroute('account.cloud.store.place') }}" id="cvb-form" class="cvb-wrap">
  @csrf
  <input type="hidden" name="location" value="{{ $locCode }}">

  <div class="cvb-main">

    {{-- ═══ ۱ — مکان: کشور ← شهر ← دیتاسنتر، همه در همین یک مرحله ═══

         سه سطحِ تودرتو از `<details>`ِ بومی — همان الگویی که «تنظیمات پیشرفته»
         پایین‌تر دارد. دلیلش ساختاری است، نه سلیقه‌ای:

         ۱) پاپ‌آور این‌جا شدنی نیست. `.pnl-sec{overflow:hidden}` و
            `.cvb-step-i{overflow:hidden}` دو قیچیِ روی‌هم‌اند (دومی موتورِ خودِ
            آکاردئون است) و `.cvb-sheet{container:inline-size}` حتی
            position:fixed را هم به خودش می‌چسبانَد.
         ۲) «پنل نباید زیرِ دستِ کاربر بسته شود»: وقتی خودِ trigger همان
            `<summary>` است و پنل فرزندِ همان `<details>`، فاصله‌ای بینشان وجود
            ندارد که باید پل زده شود — `pointerleave` روی خودِ details هر سه
            ناحیه (trigger، پنل، فاصله) را با صفر هندسه پوشش می‌دهد.
         ۳) بی‌جاوااسکریپت همین‌طور کار می‌کند: باز/بسته بومی است، صفحه‌کلید
            بومی است، و شهرها لینکِ واقعی‌اند.

         🔴 چهار بدنهٔ مرحله باید چهار بمانند: کشور و شهر و دیتاسنتر همگی داخلِ
         همین `cvb-step-b` می‌نشینند و هرگز مرحلهٔ پنجم نمی‌شوند. --}}
    <section class="pnl-sec cvb-step" id="cvb-step-1" data-step="1">
      <h2 class="cvb-step-hh"><button type="button" class="pnl-sec-h cvb-step-h" aria-expanded="true" aria-controls="cvb-b-1">
        <span class="cvb-step-t"><span class="cb-dot"></span><b>{{ __('ui.cvb_s1') }}</b></span>
        <span class="cvb-step-v"><span>{{ $locLabel }}</span><em>{{ __('ui.cvb_slip_edit') }}</em></span>
      </button></h2>
      <div class="cvb-step-b" id="cvb-b-1">
        <div class="cvb-step-i"><div class="pnl-sec-b">
          @error('location')<div class="dm-note danger">{{ $message }}</div>@enderror

          {{-- کنترلِ خالی نساز، جمله بگو (خواستهٔ کارفرما).

               🔴 ولی جمله باید **راست** باشد. «اول یک کشور انتخاب کنید» بی‌قید
               چاپ می‌شد، در حالی که این بلوک فقط وقتی رندر می‌شود که
               `count($groups) > 0` باشد، و آن‌وقت `CloudStoreController::index`
               همیشه یک مکان را از پیش انتخاب کرده است
               (`$code = … ?? $openCodes[0] ?? $allCodes[0]`، و `$openCodes`
               زیرمجموعهٔ `$allCodes` است چون قفسه از فروختنی‌ها بازتر است).
               پس صفحه در **هر** بارگذاری خودش را تکذیب می‌کرد: یک کارتِ
               تیک‌خورده، و بالایش دستوری برای کاری که همین حالا انجام شده.

               شرطِ `$curCountry === ''` هم گذاشته نشد: شاخه‌ای که هرگز اجرا
               نمی‌شود همان قاعدهٔ مردهٔ بندِ ۳ است با لباسِ دیگر. جمله بدونِ شرط
               راست است، و تست قفل می‌کند که «کشور همیشه از پیش انتخاب است». --}}
          <p class="cvb-hint">{{ __('ui.cvb_country_set') }}</p>

          <div class="cvb-countries" role="group" aria-label="{{ __('ui.cvb_c_pick') }}">
            @foreach($groups as $g)
              @php
                $gOn   = (string) $g['country'] === (string) $curCountry;
                $gShut = (int) ($g['openCities'] ?? 0) === 0;
              @endphp
              {{-- ⚠️ فاصلهٔ پیش از @-if عمدی است: Blade با \B شروع می‌کند، پس
                   دستوری که به یک حرف چسبیده باشد **کامپایل نمی‌شود** و
                   endifِ بعدی بی‌جفت می‌مانَد → ۵۰۰. --}}
              <details class="cvb-cnat @if($gShut) is-shut @endif" data-hold @if($gOn) open @endif>
                <summary class="cvb-ccard @if($gOn) on @endif" @if($gOn) aria-current="true" @endif>
                  <span class="cvb-flag">{{ $g['flag'] }}</span>
                  <b class="cvb-cname">{{ $g['label'] }}</b>
                  {{-- شمار از شهرهای **باز** می‌آید، نه از تعدادِ کدها.
                       هیچ عددِ تأخیر این‌جا نیست: ستونِ latency در دیتابیس وجود
                       ندارد و چاپِ یک عددِ ساختگی روی صفحه‌ای که کارش «کدام
                       نزدیک‌تر است» است، یک سیگنالِ اعتمادِ جعلی است. --}}
                  {{-- 🔴 trans_choice نه __(): «۱ شهر» در فارسی و ترکی درست است
                       ولی انگلیسی «1 cities» می‌داد. صورتِ جمع فقط در فایلِ en
                       تعریف شده — فارسی و ترکی بعد از عدد جمع نمی‌بندند و
                       بستنِ قاعدهٔ انگلیسی رویشان غلطِ تازه می‌ساخت. جانگهدار
                       عمداً `:n` است نه `:count`: لاراول در trans_choice مقدارِ
                       `count` را با عددِ خام بازنویسی می‌کند و رقمِ فارسی را
                       بی‌صدا به لاتین برمی‌گرداند. --}}
                  <small class="cvb-cmeta">@if($gShut){{ __('ui.cvb_c_soldout') }}@else{{ trans_choice('ui.cvb_c_cities', (int) $g['openCities'], ['n' => fa_num((int) $g['openCities'])]) }}@endif</small>
                  <span class="cvb-cchk"><svg class="icon"><use href="#i-check"/></svg></span>
                </summary>
                <div class="cvb-cities">
                  {{-- شهرها **در سطحِ نمایش** یکتا شده‌اند (CloudStoreController::cityBuckets).
                       هیچ کدی حذف نشده: شهری که چند دیتاسنتر دارد یک پله پایین‌تر
                       همهٔ اعضایش را به‌صورت لینکِ واقعی نشان می‌دهد. --}}
                  @foreach($g['cities'] as $c)
                    @if($c['n'] === 1)
                      @php $l = $c['primary']; @endphp
                      {{-- لینک ساده و نه رادیو: با عوض شدن مکان، پلن‌ها هم عوض می‌شوند و
                           سرور باید فهرست تازه را بدهد. بی‌جاوااسکریپت هم کار می‌کند.
                           انتخاب‌های دیگر روی خودِ لینک سوار می‌شوند تا عوض‌کردنِ شهر
                           پلن و دوره و سیستم‌عامل را دور نریزد. --}}
                      <a class="cvb-city @if(! $c['open']) is-shut @endif @if((string) $l->code === (string) $locCode) on @endif" data-city="{{ $l->code }}" href="{{ $cityHref((string) $l->code) }}"@if((string) $l->code === (string) $locCode) aria-current="true"@endif>{{ $c['label'] }}</a>
                    @else
                      @php $cOn = collect($c['members'])->contains(fn ($m) => (string) $m->code === (string) $locCode); @endphp
                      <details class="cvb-dc @if(! $c['open']) is-shut @endif" data-hold @if($cOn) open @endif>
                        <summary class="cvb-ccard is-dc @if($cOn) on @endif">
                          <b class="cvb-cname">{{ $c['label'] }}</b>
                          <small class="cvb-cmeta">{{ __('ui.cvb_dc_multi', ['count' => fa_num((int) $c['n'])]) }}</small>
                          <span class="cvb-cchk"><svg class="icon"><use href="#i-check"/></svg></span>
                        </summary>
                        <div class="cvb-dcs">
                          {{-- 🔴 برچسبِ عضو فقط شمارهٔ ترتیبی است. `provider` و
                               `provider_location` (fsn1/hel1/gra7) روی دیوارِ
                               سفیدبرچسبی‌اند و هرگز به DOM نمی‌رسند. --}}
                          @foreach($c['members'] as $mi => $m)
                            <a class="cvb-city cvb-city-dc @if(! in_array((string) $m->code, (array) $openCodes, true)) is-shut @endif @if((string) $m->code === (string) $locCode) on @endif" data-city="{{ $m->code }}" href="{{ $cityHref((string) $m->code) }}"@if((string) $m->code === (string) $locCode) aria-current="true"@endif>{{ __('ui.cvb_dc_n', ['n' => fa_num($mi + 1)]) }}</a>
                          @endforeach
                        </div>
                      </details>
                    @endif
                  @endforeach
                </div>
              </details>
            @endforeach
          </div>

          @if(count($planCards) === 0)
            <p class="cvb-warn">{{ __('ui.cvb_loc_off') }}</p>
          @endif
          <p class="cvb-note">
            <svg class="icon"><use href="#i-pin"/></svg>
            {{ __('ui.cvb_loc_note') }}
          </p>
        </div></div>
      </div>
    </section>

    {{-- ═══ ۲ — اندازه (و دورهٔ پرداخت، که همین‌جا بالای فهرست می‌نشیند) ═══
         دوره **پیش از** اندازه انتخاب می‌شود، پس هر قیمتی که روی کارت‌ها
         می‌بینید همان چیزی است که واقعاً می‌پردازید — بی‌هیچ حساب‌وکتابِ ذهنی. --}}
    <section class="pnl-sec cvb-step" id="cvb-step-2" data-step="2">
      <h2 class="cvb-step-hh"><button type="button" class="pnl-sec-h cvb-step-h" aria-expanded="true" aria-controls="cvb-b-2">
        <span class="cvb-step-t"><span class="cb-dot"></span><b>{{ __('ui.cvb_s2') }}</b></span>
        <span class="cvb-step-v"><span id="cvb-v-2">{{ $jsPlans[$curSlug] ?? '—' }}</span><em>{{ __('ui.cvb_slip_edit') }}</em></span>
      </button></h2>
      <div class="cvb-step-b" id="cvb-b-2">
        <div class="cvb-step-i"><div class="pnl-sec-b">

          @error('plan')<div class="dm-note danger">{{ $message }}</div>@enderror
          @error('cycle')<div class="dm-note danger">{{ $message }}</div>@enderror
          @error('billing_mode')<div class="dm-note danger">{{ $message }}</div>@enderror
          @if($planMoved)<div class="dm-note warn">{{ __('ui.cvb_plan_moved') }}</div>@endif

          <div class="cvb-billrow">
            <div class="cvb-segs cvb-bill @if($hOn) is-hourly @endif" id="cvb-bill">
              @foreach($cycles as $cy)
                @php $row = $priceMap[$curSlug][$cy] ?? ['save' => 0]; @endphp
                <label class="cvb-seg cvb-seg-c @if($cy === $curCycle) on @endif" data-cyc="{{ $cy }}">
                  <input type="radio" name="cycle" value="{{ $cy }}" @checked($cy === $curCycle)>
                  <span>{{ $cycleLabels[$cy] ?? $cy }}</span>
                  @if(($row['save'] ?? 0) > 0)<em>{{ fa_num($row['save']) }}{{ __('ui.cvb_save_suf') }}</em>@endif
                </label>
              @endforeach

              {{-- ═══ پرداختِ ساعتی ═══
                   چک‌باکسِ ساده (نه رادیو) تا **بی‌جاوااسکریپت هم** کار کند:
                   تیک‌خورده یعنی billing_mode=hourly، تیک‌نخورده یعنی هیچ‌چیز
                   ارسال نمی‌شود و سرور همان چرخهٔ عادی را می‌گیرد. --}}
              @if($hRate > 0)
                <label class="cvb-seg cvb-seg-h @if($hOn) on @endif">
                  <input type="checkbox" name="billing_mode" value="hourly" id="cvb-hourly" @checked($hOn)>
                  <span>{{ __('ui.cvb_mode_hourly') }}</span>
                </label>
              @endif
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
                      {{ $p['name'] }}
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
                      {{ $p['name'] }}
                      <span class="pnl-pill mute">{{ __('ui.cvb_off_badge') }}</span>
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
                        {{ __('ui.cvb_off_stock') }}<small>{{ __('ui.cvb_off_stock_sub') }}</small>
                      @else
                        {{ __('ui.cvb_off_price') }}<small>{{ __('ui.cvb_off_price_sub') }}</small>
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

    {{-- ═══ ۳ — سیستم‌عامل یا نرم‌افزار آماده ═══ --}}
    <section class="pnl-sec cvb-step" id="cvb-step-3" data-step="3">
      <h2 class="cvb-step-hh"><button type="button" class="pnl-sec-h cvb-step-h" aria-expanded="true" aria-controls="cvb-b-3">
        <span class="cvb-step-t"><span class="cb-dot"></span><b>{{ __('ui.cvb_s3') }}</b></span>
        <span class="cvb-step-v"><span id="cvb-v-3">{{ $jsImgLbl[$curImage] ?? '—' }}</span><em>{{ __('ui.cvb_slip_edit') }}</em></span>
      </button></h2>
      <div class="cvb-step-b" id="cvb-b-3">
        <div class="cvb-step-i"><div class="pnl-sec-b">
          @error('image')<div class="dm-note danger">{{ $message }}</div>@enderror

          {{-- تنها افشاگرِ صفحه که هیچ ARIAای نداشت. tab/tablist بومی است و
               وضعیتِ انتخاب را به صفحه‌خوان می‌گوید.

               🔴 نامِ گروه باید **نامِ خودش** باشد، نه نامِ نخستین زبانه‌اش.
               با `ui.cvb_os` صفحه‌خوان «سیستم‌عامل، فهرست زبانه … سیستم‌عامل،
               زبانه» می‌گفت: یک واژه دو نقش، و کاربر نمی‌فهمید گروه چیست. --}}
          <div class="cvb-billrow">
            <div class="cvb-segs" role="tablist" aria-label="{{ __('ui.cvb_os_group') }}">
              <button type="button" class="cvb-seg on" data-tab="os" role="tab" aria-selected="true" aria-controls="cvb-pane-os" id="cvb-tab-os">{{ __('ui.cvb_os') }}</button>
              <button type="button" class="cvb-seg" data-tab="app" role="tab" aria-selected="false" aria-controls="cvb-pane-app" id="cvb-tab-app">{{ __('ui.cvb_app') }}</button>
            </div>
          </div>

          {{-- گزینه‌های ناسازگار با پلن انتخابی پنهان می‌شوند (سمت سرور محاسبه شده،
               جاوااسکریپت فقط با عوض شدن پلن به‌روزش می‌کند). گزینه‌ای که تحویلش
               نشدنی است هرگز نباید دیده شود. --}}
          <div class="cvb-imgs" data-pane="os" id="cvb-pane-os" role="tabpanel" aria-labelledby="cvb-tab-os">
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
                      <b>{{ $img->label }}</b>
                    </label>
                  @endforeach
                </div>
              </div>
            @empty
              <p class="cvb-warn">{{ __('ui.cvb_os_na') }}</p>
            @endforelse
            <p class="cvb-empty" data-empty="os" hidden>{{ __('ui.cvb_os_empty') }}</p>
          </div>

          <div class="cvb-imgs" data-pane="app" id="cvb-pane-app" role="tabpanel" aria-labelledby="cvb-tab-app" hidden>
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
                      <b>{{ $img->label }}</b>
                    </label>
                  @endforeach
                </div>
              </div>
            @empty
              <p class="cvb-warn">{{ __('ui.cvb_app_na') }}</p>
            @endforelse
            <p class="cvb-empty" data-empty="app" hidden>{{ __('ui.cvb_app_empty') }}</p>
          </div>

          <p class="cvb-note">
            <svg class="icon"><use href="#i-key"/></svg>
            {{ __('ui.cvb_os_note') }}
          </p>
        </div></div>
      </div>
    </section>

    {{-- ═══ ۴ — نام سرور ═══ --}}
    <section class="pnl-sec cvb-step" id="cvb-step-4" data-step="4">
      <h2 class="cvb-step-hh"><button type="button" class="pnl-sec-h cvb-step-h" aria-expanded="true" aria-controls="cvb-b-4">
        <span class="cvb-step-t"><span class="cb-dot"></span><b>{{ __('ui.cvb_s6') }}</b></span>
        <span class="cvb-step-v"><span id="cvb-v-4">{{ $curLabel !== '' ? $curLabel : $autoLabel }}</span><em>{{ __('ui.cvb_slip_edit') }}</em></span>
      </button></h2>
      <div class="cvb-step-b" id="cvb-b-4">
        <div class="cvb-step-i"><div class="pnl-sec-b">
          @error('label')<div class="dm-note danger">{{ $message }}</div>@enderror
          <label class="cvb-field">
            <span>{{ __('ui.cvb_label') }}</span>
            <input type="text" name="label" id="cvb-label" dir="ltr" value="{{ $curLabel }}"
                   placeholder="{{ $autoLabel }}" maxlength="64"
                   autocapitalize="off" autocomplete="off" spellcheck="false">
          </label>
          <p class="cvb-note">
            <svg class="icon"><use href="#i-info"/></svg>
            {{ __('ui.cvb_label_note') }}
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
        {{ __('ui.cvb_adv') }} <small>{{ __('ui.cvb_adv_sub') }}</small>
        <svg class="icon"><use href="#i-chev"/></svg>
      </summary>
      <div class="cvb-adv-b">

        @error('ssh_key_id')<div class="dm-note danger">{{ $message }}</div>@enderror
        @error('ssh_key_new')<div class="dm-note danger">{{ $message }}</div>@enderror

        {{-- ورود با کلید SSH --}}
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
        @if(collect($addonMap)->contains(true))
        <div id="cvb-ip-box" @if(! $addonOk) hidden @endif>
          @error('extra_ipv4')<div class="dm-note danger">{{ $message }}</div>@enderror
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
                — {{ __('ui.cvb_hourly_credit') }}<b>{{ cloud_price($creditIrt) }}</b>
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
          <button type="button" class="cvb-line is-done" data-go="1">
            <span class="cvb-line-d"></span>
            <span class="cvb-line-k">{{ __('ui.cvb_step_loc') }}</span>
            <span class="cvb-line-v">{{ $locLabel }}</span>
          </button>

          <button type="button" class="cvb-line is-done" data-go="2">
            <span class="cvb-line-d"></span>
            <span class="cvb-line-k">{{ __('ui.cvb_step_size') }}</span>
            <span class="cvb-line-v"><b id="cvb-s-plan">{{ $jsPlans[$curSlug] ?? '—' }}</b><small class="cvb-sspec" id="cvb-s-spec">{{ $jsSpecs[$curSlug] ?? '' }}</small></span>
          </button>

          <button type="button" class="cvb-line is-done" data-go="3">
            <span class="cvb-line-d"></span>
            <span class="cvb-line-k">{{ __('ui.cvb_step_os') }}</span>
            <span class="cvb-line-v">
              @if(isset($jsImgLogo[$curImage]))
                <img class="cvb-logo sm" id="cvb-s-img-logo" src="{{ $jsImgLogo[$curImage] }}" alt="" width="16" height="16">
              @endif
              <span id="cvb-s-img">{{ $jsImgLbl[$curImage] ?? '—' }}</span>
            </span>
          </button>

          <button type="button" class="cvb-line is-done" data-go="2">
            <span class="cvb-line-d"></span>
            <span class="cvb-line-k">{{ __('ui.cvb_cycle') }}</span>
            <span class="cvb-line-v" id="cvb-s-cyc">{{ $hOn ? __('ui.cvb_hourly_t') : ($cycleLabels[$curCycle] ?? '—') }}</span>
          </button>

          <button type="button" class="cvb-line @if($curLabel !== '') is-done @endif" data-go="4">
            <span class="cvb-line-d"></span>
            <span class="cvb-line-k">{{ __('ui.cvb_step_name') }}</span>
            <span class="cvb-line-v" id="cvb-s-label">{{ $curLabel !== '' ? $curLabel : $autoLabel }}</span>
          </button>

          <div class="cvb-line @if($curIp > 0) is-done @endif" id="cvb-s-ip-row" @if($curIp < 1) hidden @endif>
            <span class="cvb-line-d"></span>
            <span class="cvb-line-k">{{ __('ui.cvb_ip') }}</span>
            <span class="cvb-line-v pnl-num" id="cvb-s-ip">{{ fa_num($curIp) }}</span>
          </div>
        </div>

        {{-- خطِ پارگی: زیرش فقط یک عدد و یک دکمه --}}
        <div class="cvb-tear"></div>

        <div class="cvb-totk">{{ __('ui.cvb_pay_now') }}</div>
        <div class="cvb-tot pnl-num" id="cvb-s-first">{{ $hasPrice ? cloud_price($initFirst) : '—' }}</div>
        <div class="cvb-tax" id="cvb-s-tax">{{ __('ui.cvb_tax_incl', ['pct' => fa_num($taxPct)]) }}</div>
        <p class="cvb-warn" id="cvb-s-noprice" @if($hasPrice) hidden @endif>{{ __('ui.cvb_no_price') }}</p>

        <button type="submit" class="pnl-btn primary cvb-go" id="cvb-submit" @disabled(count($planCards) === 0 || ! $hasPrice)>
          <svg class="icon"><use href="#i-rocket"/></svg>
          {{ __('ui.cvb_pay') }}
        </button>
        <p class="cvb-eta">
          <svg class="icon"><use href="#i-clock"/></svg>
          <span>{{ __('ui.cvb_eta') }}</span>
        </p>
      </div>
    </section>
  </aside>

  {{-- داکِ موبایل: مبلغ و دکمه در هر نقطهٔ اسکرول روی صفحه می‌مانند. روی
       دسکتاپ display:none است. --}}
  <div class="cvb-dock">
    <span class="cvb-dock-t">
      <small>{{ __('ui.cvb_pay_now') }}</small>
      <b class="pnl-num" id="cvb-d-first">{{ $hasPrice ? cloud_price($initFirst) : '—' }}</b>
    </span>
    <button type="submit" class="pnl-btn primary" id="cvb-submit-2" @disabled(count($planCards) === 0 || ! $hasPrice)>
      {{ __('ui.cvb_pay') }}
    </button>
  </div>
</form>

@php
  $jsData = [
    'prices' => $priceMap,
    'images' => $imageMap,
    'cycles' => $cycleLabels,
    'months' => $cycleMonths,
    'plans'  => $jsPlans,
    'specs'  => $jsSpecs,
    'imgLbl' => $jsImgLbl,
    'imgLogo' => $jsImgLogo,
    'addon'  => $addonMap,
    'extraIp' => (int) $extraIpPrice,
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
<script>
(function(){
  'use strict';

  var D = @json($jsData);
  var form = document.getElementById('cvb-form');
  if (!form) { return; }

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
  };

  var hChk = document.getElementById('cvb-hourly');
  var ipSel = document.getElementById('cvb-extra-ip');
  var submits = [document.getElementById('cvb-submit'), document.getElementById('cvb-submit-2')];

  var lockSubmit = function(off){
    submits.forEach(function(b){ if (b) b.disabled = !!off; });
  };

  // مبلغِ افزودنی سمتِ سرور با CloudAddons ساخته می‌شود؛ همان فرمول این‌جا
  // بازسازی می‌شود (ماه × تخفیفِ دوره، گردِ رو به **بالا** به ۱۰٬۰۰۰ تومان) تا
  // عددِ دکمه با مبلغِ فاکتور یکی باشد. تستِ CloudStoreSlipTest همین را می‌سنجد.
  var addonForCycle = function(cyc, save){
    var n = ipSel ? parseInt(ipSel.value, 10) || 0 : 0;
    if (n < 1) { return 0; }
    var months = D.months[cyc] || 1;
    var raw = D.extraIp * n * months * (100 - (save || 0)) / 100;
    return Math.ceil(raw / 10000) * 10000;
  };

  var render = function(){
    var slug = val('plan'), cyc = val('cycle');
    var bucket = D.prices[slug] || {};
    var hourly = !!(hChk && hChk.checked);

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
    set('cvb-v-2', D.plans[slug] || '—');
    set('cvb-v-3', D.imgLbl[val('image')] || '—');

    var lab = document.getElementById('cvb-label');
    var labRow = document.getElementById('cvb-s-label');
    if (labRow) {
      var txt = lab && lab.value.trim() !== '' ? lab.value.trim() : D.auto;
      labRow.textContent = txt;
      labRow.closest('.cvb-line').classList.toggle('is-done', !!(lab && lab.value.trim() !== ''));
      set('cvb-v-4', txt);
    }

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
    var h = (D.hourly || {})[slug] || { rate: 0, min: 0 };
    set('cvb-h-rate', money(h.rate));
    set('cvb-h-min', money(h.min));
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
      lockSubmit(true);
      return;
    }

    if (warn) warn.hidden = true;
    var total = row.cycle + addonForCycle(cyc, row.save);
    var first = total + Math.round(total * D.tax / 100);
    set('cvb-s-first', money(first));
    set('cvb-d-first', money(first));
    lockSubmit(false);
  };

  // ── مرحله‌ها: یکی باز، بقیه بسته. بدونِ این اسکریپت همه باز می‌مانند. ──
  var steps = Array.prototype.slice.call(form.querySelectorAll('.cvb-step'));

  var openStep = function(n){
    steps.forEach(function(s){
      var mine = s.getAttribute('data-step') === String(n);
      s.classList.toggle('is-shut', !mine);
      var h = s.querySelector('.cvb-step-h');
      if (h) h.setAttribute('aria-expanded', mine ? 'true' : 'false');
    });
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
    if (n === '1') { return !!(form.querySelector('input[name="location"]') || {}).value; }
    if (n === '2') { return val('plan') !== ''; }
    if (n === '3') { return val('image') !== ''; }
    if (n === '4') { var l = document.getElementById('cvb-label'); return !!(l && l.value.trim() !== ''); }
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

  // هر سطرِ برگه، دکمهٔ بازکردنِ مرحلهٔ خودش است
  document.querySelectorAll('.cvb-line[data-go]').forEach(function(b){
    b.addEventListener('click', function(){
      var n = b.getAttribute('data-go');
      openStep(n);
      var el = document.getElementById('cvb-step-' + n);
      if (el) el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    });
  });

  // ── شنونده‌ها ──
  /* 🔴 این‌جا `openStep('3')` بی‌قید بود و بدترین «پنل زیرِ دستِ کاربر بسته
     شد»ِ صفحه را می‌ساخت: انتخابِ یک اندازه، کلِ برگهٔ مقایسه‌ای را که کاربر
     داشت می‌خواند جمع می‌کرد؛ مقایسهٔ دو اندازه با کلیک روی هرکدام غیرممکن بود.
     همین برای سیستم‌عامل: عوض‌کردنِ اوبونتو ۲۲ و ۲۴ برای خواندنِ برچسب‌ها،
     کاتالوگ را می‌بست.

     حرکتِ رو به جلوی طرحِ پذیرفته‌شده حفظ می‌شود، ولی **فقط یک بار**: نخستین
     تصمیم مرحلهٔ بعد را باز می‌کند، تصمیم‌های بعدی هیچ‌چیز را نمی‌بندند. یعنی
     «رفتن به جلو» می‌مانَد و «بستنِ فهرست زیرِ دستِ کسی که دارد مقایسه می‌کند»
     می‌رود. */
  var advanced = {};

  /* 🔴 نیمهٔ دومِ همان اصلاح. «فقط یک بار» بس نبود: همان یک بار هم `openStep()`
     را صدا می‌زد و `openStep` **هر** مرحلهٔ دیگری را می‌بندد — یعنی نخستین
     انتخابِ اندازه، دقیقاً برگهٔ مقایسه‌ای را که کاربر باز کرده بود جمع می‌کرد،
     و نخستین انتخابِ سیستم‌عامل همان بلا را سرِ کاتالوگ می‌آورد. کاربر یک بار
     کلیک می‌کرد و چیزی که داشت می‌خواند از زیرِ دستش می‌رفت.

     پیشروی خودش دفاع‌پذیر است؛ **بستن** دفاع‌پذیر نیست. پس مرحلهٔ بعد باز
     می‌شود و هیچ مرحله‌ای بسته نمی‌شود. آکاردئونِ انحصاری فقط برای کلیکِ صریحِ
     کاربر روی سرصفحه (یا «ویرایش»ِ برگه) می‌مانَد، که آن‌جا خودِ کاربر خواسته. */
  var revealStep = function(n){
    steps.forEach(function(s){
      if (s.getAttribute('data-step') !== String(n)) { return; }
      s.classList.remove('is-shut');
      var h = s.querySelector('.cvb-step-h');
      if (h) { h.setAttribute('aria-expanded', 'true'); }
    });
    stamp();
  };

  var advance = function(from, to){
    if (advanced[from]) { return; }
    advanced[from] = true;
    revealStep(to);
    var el = document.getElementById('cvb-step-' + to);
    if (el) { el.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
  };

  form.querySelectorAll('.cvb-plan input').forEach(function(r){
    r.addEventListener('change', function(){
      mark('.cvb-plan', r.closest('.cvb-plan'));
      syncImages();
      render();
      stamp();
      advance('2', '3');
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
      render();
      stamp();
      advance('3', '4');
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
      u.searchParams.set('image', val('image'));
      a.href = u.toString();
    });
  });

  /* ══════════ افشاگرِ پایدار: کشور ← شهر ← دیتاسنتر ══════════

     قرارداد (خواستهٔ کارفرما، بندِ ۴ و ۵):
       · دسکتاپ — hover یا focus باز می‌کند؛ تا وقتی نشانگر **هرجای** trigger،
         پنل یا فاصلهٔ بینشان است باز می‌مانَد؛ فقط بعد از ترکِ کلِ ناحیه و با
         تأخیرِ ۱۶۰ms (لغوپذیر) بسته می‌شود.
       · لمس — هیچ hoverای. تپ باز می‌کند، تپ انتخاب می‌کند، تپِ بیرون می‌بندد.
       · صفحه‌کلید — Tab/Enter/Space/Escape، همه بومیِ <summary>.

     چرا هندسه لازم نیست: trigger خودِ `<summary>` است و پنل فرزندِ همان
     `<details>`. پس «فاصلهٔ بینشان» درونِ یک عنصر است و یک `pointerleave` روی
     details هر سه را می‌پوشاند — نه پلِ نامرئی، نه محاسبهٔ مختصات.

     ⚠️ دروازه **توانِ اشاره‌گر** است نه عرضِ پنجره: `innerWidth > 1020`ِ
     مگامنویِ site.js به یک تبلتِ لمسیِ ۱۰۲۴px مسیرِ hover-only می‌داد.
     ⚠️ بستنِ خودکار با اسکرول (که site.js دارد) عمداً کپی نشده: پنل را زیرِ
     چشمِ کسی که دارد می‌خواند می‌بندد.
     ⚠️ نه Escape و نه کلیکِ سند stopPropagation نمی‌زنند — site.js روی همین دو
     رویداد، چت و مگامنو و کشویِ موبایل را می‌بندد. */
  var hoverOK = !!(window.matchMedia && window.matchMedia('(hover:hover) and (pointer:fine)').matches);

  var holdShut = function(d){
    if (!d.open) { return; }
    if (d.getAttribute('data-peek') !== '1') { return; }   // باز شده با تصمیم، نه با نشانگر
    if (d.contains(document.activeElement)) { return; }
    d.open = false;
    d.removeAttribute('data-peek');
  };

  var holds = Array.prototype.slice.call(form.querySelectorAll('details[data-hold]'));

  holds.forEach(function(d){
    var sum = d.querySelector('summary');

    // ⚠️ زمان‌سنجِ **هر ناحیه جداگانه**. یک تایمرِ مشترک بینِ کشور و
    // دیتاسنترِ تودرتویش، ترکِ سطحِ درونی را با ترکِ سطحِ بیرونی قاطی می‌کرد و
    // یکی از دو بستن بی‌صدا می‌افتاد.
    var t = null;

    var cancel = function(){ if (t) { clearTimeout(t); t = null; } };

    d.addEventListener('pointerenter', function(e){
      if (!hoverOK || e.pointerType === 'touch') { return; }
      cancel();
      if (!d.open) { d.open = true; d.setAttribute('data-peek', '1'); }
    });

    d.addEventListener('pointerleave', function(e){
      if (!hoverOK || e.pointerType === 'touch') { return; }
      cancel();
      // ۱۶۰ms، وسطِ بازهٔ ۱۲۰–۲۰۰ خواسته‌شده و کاملاً لغوپذیر: هر بازگشتِ
      // نشانگر به هر نقطهٔ ناحیه، بستن را منتفی می‌کند.
      t = setTimeout(function(){ t = null; holdShut(d); }, 160);
    });

    /* ── فوکوس: قفل می‌کند، ولی هرگز باز نمی‌کند ──────────────────────────
       🔴 این یک شنونده، **علتِ ریشه‌ایِ دو باگِ هم‌زمان** بود. نسخهٔ قبلی روی
       هر `focusin` می‌گفت «باز کن و data-peek را بردار» — و چون focus حباب
       می‌کند و خودِ `<summary>` فوکوس‌پذیر است، شنونده هیچ راهی نداشت فوکوسِ
       کاربر را از فوکوسِ برنامه‌ایِ خودِ ما جدا کند:

         · **Tab** روی کارتِ کشور بازش می‌کرد و data-peek را هم می‌کند، پس
           `holdShut` دیگر نمی‌توانست ببنددش. کاربرِ صفحه‌کلید نه می‌توانست از
           شبکهٔ کشورها رد شود، نه چیزی را که باز کرده بود ببندد.
         · **Escape** پنل را می‌بست و بعد `summary.focus()` می‌زد تا فوکوس گم
           نشود — و همان focus بی‌درنگ همین شنونده را روشن و پنل را دوباره باز
           می‌کرد. Escape پیاده‌سازی‌شده به نظر می‌رسید و در عمل هیچ کاری
           نمی‌کرد.

       درمانِ ریشه، نه علامت: فوکوس **هیچ‌وقت** `d.open` را دست نمی‌زند. لازم
       هم نیست — محتوای یک `<details>`ِ بسته فوکوس‌پذیر نیست، پس هر فوکوسی که
       به درونِ پنل برسد یعنی پنل از قبل باز است. و فوکوس روی خودِ `<summary>`
       فقط یک **عبور** است نه یک تصمیم، پس هیچ چیزی را قفل نمی‌کند؛ باز/بستنِ
       صفحه‌کلید همان Enter/Spaceِ بومی است که پایین‌تر قفل را می‌گذارد.
       (پنلِ hover-شده زیرِ دستِ کاربرِ صفحه‌کلید بسته نمی‌شود، چون خودِ
       `holdShut` وقتی `document.activeElement` داخلِ ناحیه است برمی‌گردد.) */
    d.addEventListener('focusin', function(ev){
      if (sum && ev.target && sum.contains(ev.target)) { return; }
      cancel();
      d.removeAttribute('data-peek');
    });

    if (sum) {
      // تصمیمِ صریح (کلیک/Enter/Space) پیش از toggleِ بومی، حالت را قفل می‌کند
      sum.addEventListener('mousedown', function(){ cancel(); d.removeAttribute('data-peek'); });
      sum.addEventListener('click', function(){ cancel(); d.removeAttribute('data-peek'); });
      sum.addEventListener('keydown', function(ev){
        if (ev.key === 'Enter' || ev.key === ' ' || ev.key === 'Spacebar') {
          cancel();
          d.removeAttribute('data-peek');
        }
      });
    }
  });

  if (holds.length > 0) {
    document.addEventListener('click', function(e){
      var inside = e.target && e.target.closest ? e.target.closest('details[data-hold]') : null;
      holds.forEach(function(d){
        if (d !== inside && !d.contains(inside)) { holdShut(d); }
      });
    });

    document.addEventListener('keydown', function(e){
      if (e.key !== 'Escape' && e.key !== 'Esc') { return; }
      var host = document.activeElement && document.activeElement.closest
        ? document.activeElement.closest('details[data-hold][open]') : null;
      if (!host) {
        for (var i = holds.length - 1; i >= 0; i--) {
          if (holds[i].open && holds[i].getAttribute('data-peek') === '1') { host = holds[i]; break; }
        }
      }
      if (!host) { return; }                 // هیچ پنلی باز نیست → دست نزن
      host.open = false;
      host.removeAttribute('data-peek');
      var s = host.querySelector('summary');
      if (s && typeof s.focus === 'function') { s.focus(); }
    });
  }

  /* ══════════ کم‌رنگ‌کردنِ ملایمِ گروه‌های دیگر ══════════
     🔴 فیلتر فقط روی `.cvb-step-i` می‌نشیند و کم‌رنگی روی خودِ `.cvb-step`.
     `.cvb-slip` (sticky) و `.cvb-dock` (fixed) **هم‌نیای** `.cvb-main` هستند نه
     فرزندش، پس هیچ‌کدام در زنجیرهٔ بلوکِ دربرگیرنده‌شان نمی‌افتند. هر filter/
     transform/contain روی `.cvb-wrap` یا بالاترش، داکِ موبایل را از قابِ دید
     می‌کَنَد و با اسکرول پایین می‌بَرَد — خرابی‌ای که ۲۰۰ می‌دهد و هیچ خطایی
     نمی‌سازد. سرصفحهٔ مرحله هرگز محو نمی‌شود، پس راهِ برگشتن خوانا می‌مانَد. */
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
  openStep(String(D.openStep || 2));
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
    });
  }
})();
</script>

@endif

@endsection

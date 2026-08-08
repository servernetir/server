@extends('admin.layout')
@section('title', 'زیرساختِ سرورِ ابری')
@section('nav_cloud', 'on')
@section('content')

<div class="ad-panel">
  <div class="ad-panel-h"><h2>زیرساختِ سرورِ ابری</h2></div>
  <p style="padding:0 18px;color:var(--muted);font-size:13.5px;line-height:1.9">
    سرورهای مجازی از <b>چند زیرساخت</b> تأمین می‌شوند، ولی مشتری فقط «مکان» و «مشخصات» را می‌بیند.
    اگر دو زیرساخت پلنِ یکسان در یک شهر داشته باشند، روی سایت <b>یک گزینه</b> است و تحویل از
    <b>ارزان‌ترینِ موجود</b> انجام می‌شود.
    <br>توکن‌ها در <a href="/admin/settings" style="color:#22d3ee">تنظیمات</a> وارد می‌شوند.
  </p>

  @if($notReady)
    <p style="padding:18px;color:#fbbf24">جدول‌های ابری هنوز روی این سرور ساخته نشده‌اند. پس از اجرای مهاجرت فعال می‌شود.</p>
  @else

  <div style="padding:2px 18px 14px;display:flex;gap:10px;flex-wrap:wrap">
    <form method="post" action="/admin/cloud/test" style="display:inline">
      @csrf<button class="btn btn-glass" style="font-size:12.5px"><svg class="icon"><use href="#i-server"/></svg>آزمونِ اتصال</button>
    </form>
    <form method="post" action="/admin/cloud/sync" style="display:inline"
          data-confirm="کاتالوگ از زیرساخت‌ها خوانده و قیمت‌ها بازمحاسبه شود؟">
      @csrf<button class="btn btn-primary" style="font-size:12.5px"><svg class="icon"><use href="#i-restore"/></svg>همگام‌سازیِ کاتالوگ</button>
    </form>
    <form method="post" action="/admin/cloud/sync" style="display:inline">
      @csrf<input type="hidden" name="prices_only" value="1">
      <button class="btn btn-glass" style="font-size:12.5px"><svg class="icon"><use href="#i-coins"/></svg>فقط بازمحاسبهٔ قیمت</button>
    </form>
    <a class="btn btn-glass" style="font-size:12.5px" href="/admin/cloud/inventory"><svg class="icon"><use href="#i-search"/></svg>تطبیقِ موجودی</a>
    <a class="btn btn-glass" style="font-size:12.5px" href="/admin/cloud/attach"><svg class="icon"><use href="#i-plus"/></svg>اتصالِ سرورِ موجود</a>
    <a class="btn btn-glass" style="font-size:12.5px" href="/admin/cloud/probe"><svg class="icon"><use href="#i-code"/></svg>ساختارِ خامِ پاسخ</a>
  </div>

  {{-- وضعیتِ زیرساخت‌ها. عمداً «زیرساختِ ۱/۲» نام‌گذاری شده‌اند تا حتی در پنلِ
       مدیریت هم عادتِ نوشتنِ نامِ ارائه‌دهنده در رابط شکل نگیرد. --}}
  <table class="ad-table">
    <thead><tr><th>زیرساخت</th><th>توکن</th><th>پلنِ فعال</th><th>توانایی‌ها</th><th>فروش</th></tr></thead>
    <tbody>
      @foreach($providers as $slug => $p)
      <tr>
        <td>
          <b>{{ app(\App\Services\Cloud\CloudManager::class)->realLabel($slug) }}</b>
          @if(! empty($byProvider[$slug]))
            <small style="display:block;color:var(--muted);font-size:11.5px;line-height:1.8;margin-top:3px">
              {{ implode(' · ', $byProvider[$slug]) }}
            </small>
          @endif
        </td>
        <td>
          @if($p['configured'])
            <span class="ad-badge" style="background:rgba(52,211,153,.12);color:#34d399">ذخیره‌شده</span>
          @else
            <span class="ad-badge" style="background:rgba(251,191,36,.12);color:#fbbf24">تنظیم نشده</span>
          @endif
        </td>
        <td dir="ltr">{{ fa_num($p['plans']) }}</td>
        <td style="font-size:12px;color:var(--muted)">
          @php
            $capLabels = [
              'console' => 'کنسول', 'rebuild' => 'نصبِ دوباره', 'resize' => 'تغییرِ پلن',
              'metrics' => 'نمودارِ مصرف', 'reset_password' => 'رمزِ تازه', 'rescue' => 'حالتِ نجات',
            ];
          @endphp
          @foreach($capLabels as $k => $label)
            <span style="color:{{ ($p['caps'][$k] ?? false) ? '#34d399' : 'var(--dim)' }}">
              {{ ($p['caps'][$k] ?? false) ? '✓' : '×' }} {{ $label }}
            </span>@if(! $loop->last) · @endif
          @endforeach
        </td>
        <td>
          @php $provOff = in_array($slug, $offProviders, true); @endphp
          <form method="post" action="/admin/cloud/providers/{{ $slug }}/toggle" style="display:inline"
                data-confirm="{{ $provOff ? 'فروشِ این زیرساخت دوباره باز شود؟' : 'کلِ این زیرساخت خاموش شود؟ هیچ پلنی از آن فروخته نمی‌شود (سرویس‌های فعلی دست نمی‌خورند).' }}">
            @csrf
            <button class="btn btn-glass" style="font-size:12px;padding:5px 11px;color:{{ $provOff ? '#34d399' : '#ff6b6b' }}">
              {{ $provOff ? 'روشن کن' : 'خاموش کن' }}
            </button>
          </form>
          @if($provOff)<span class="ad-badge" style="background:rgba(255,107,107,.12);color:#ff6b6b;margin-inline-start:6px">خاموش</span>@endif
        </td>
      </tr>
      @endforeach
    </tbody>
  </table>
  @endif
</div>

@unless($notReady)

{{-- ══ گران‌شدنِ بهایِ زیرساخت ══
     مهم‌ترین هشدارِ این صفحه: قیمتِ فروشِ سرویس‌های فعال سرِ سفارش قفل شده و
     خودکار تمدید می‌شود. اگر بها بالا رفته باشد، هر تمدید ضررِ خالص است و
     هیچ‌جا صدا در نمی‌آورد.

     ⚠️ عمداً **تاشو و بسته** است: این جدول همیشه لازم نیست و بالای صفحه را
     می‌گیرد، ولی شمارش در خودِ summary می‌آید تا مدیر بی‌بازکردن بفهمد چیزی
     داخلش هست یا نه. کارفرما: «اگر نیاز شد خودم بازش میکنم». --}}
@if($risen->isNotEmpty())
<details class="ad-fold" style="border-color:rgba(255,107,107,.35)">
  <summary style="color:#ff6b6b">🔴 بهایِ زیرساخت گران شده
    <span class="ad-badge" style="background:rgba(255,107,107,.12);color:#ff6b6b">{{ fa_num($risen->count()) }}</span>
    <small>ردیف — برای دیدن باز کنید</small>
  </summary>
  <p style="padding:0 18px;color:var(--muted);font-size:13px;line-height:1.9">
    قیمتِ فروشِ سرویس‌های فعال <b>سرِ سفارش قفل</b> شده و خودکار تمدید می‌شود.
    برای این پلن‌ها بهایِ ما بالا رفته، پس از تمدیدِ بعدی <b>ضرر</b> می‌دهیم.
    <br>قیمت را <b>خودکار بالا نبردیم</b> — بالا بردنِ قیمتِ سرویسِ فعالِ مشتری
    تصمیمی تجاری است، نه فنی.
  </p>
  <div style="overflow-x:auto">
  <table class="ad-table">
    <thead><tr><th>پلن</th><th>مکان</th><th>بهای قبلی</th><th>بهای تازه</th><th>تغییر</th><th>سرویسِ فعال</th><th>زیرساخت</th></tr></thead>
    <tbody>
      @foreach($risen as $p)
      <tr>
        <td><b dir="ltr">{{ $p->public_name }}</b></td>
        <td>{{ $p->location?->label('fa') ?? $p->location_code }}</td>
        <td dir="ltr" style="color:var(--dim)">€{{ number_format($p->previous_cost_eur_cents / 100, 2) }}</td>
        <td dir="ltr"><b>€{{ number_format($p->cost_eur_cents / 100, 2) }}</b></td>
        <td dir="ltr" style="color:#ff6b6b">+{{ fa_num($p->costChangePct()) }}٪</td>
        <td dir="ltr">
          {{ fa_num(\App\Models\Service::where('cloud_plan_id', $p->id)->whereIn('status', ['active', 'awaiting_provision'])->count()) }}
        </td>
        <td><small style="color:var(--muted)">{{ app(\App\Services\Cloud\CloudManager::class)->realLabel($p->provider) }}</small></td>
      </tr>
      @endforeach
    </tbody>
  </table>
  </div>
</details>
@endif

{{-- ══ شکافِ سئویی: کشوری که می‌فروشیم و صفحه ندارد ══ --}}
@if(! empty($noPage))
<div class="ad-panel" style="border-color:rgba(251,191,36,.3)">
  <div class="ad-panel-h"><h3 style="color:#fbbf24">کشورهایی که صفحهٔ فروش ندارند</h3></div>
  <p style="padding:0 18px 16px;color:var(--muted);font-size:13px;line-height:1.9">
    در این کشورها <b>پلنِ قابلِ فروش داریم</b> ولی صفحهٔ بازاریابی نداریم، پس
    کسی که «سرور مجازی + نامِ کشور» را در گوگل می‌جوید ما را پیدا نمی‌کند.
    این درآمدِ ازدست‌رفته است، نه نکتهٔ ظاهری.
    <br><b>{{ implode(' · ', array_map(fn ($iso) => (\App\Models\CloudLocation::COUNTRIES[$iso]['fa'] ?? $iso), $noPage)) }}</b>
    <br><small style="color:var(--dim)">افزودنِ صفحه: یک ورودی در
    <span dir="ltr">config/catalog/vps.php</span> با متنِ سه‌زبانه، و نگاشتش در
    <span dir="ltr">CloudCountry::MARKETING_SLUG</span>.</small>
  </p>
</div>
@endif

{{-- مکان‌ها --}}
<div class="ad-panel">
  <div class="ad-panel-h"><h3>مکان‌ها <span class="ad-badge" style="background:rgba(34,211,238,.12);color:#22d3ee">{{ fa_num($locations->count()) }}</span></h3></div>
  @if($locations->isEmpty())
    <p style="padding:16px;color:var(--dim)">هنوز همگام‌سازی نشده.</p>
  @else
    <div class="cl-locs">
      @foreach($locations as $loc)
        <div class="cl-loc" style="{{ $loc->is_active ? '' : 'opacity:.55' }}">
          <span style="font-size:20px">{{ $loc->flagEmoji() }}</span>
          <div>
            <b>{{ $loc->label('fa') }}</b>
            <small dir="ltr" style="display:block;color:var(--dim)">{{ $loc->code }}</small>
          </div>
          <div style="margin-inline-start:auto;display:flex;align-items:center;gap:8px">
            <small style="color:var(--muted)">{{ fa_num($loc->plans()->where('is_active', true)->count()) }} پلن</small>
            <form method="post" action="/admin/cloud/locations/{{ $loc->code }}/toggle" style="display:inline"
                  data-confirm="{{ $loc->is_active ? 'مکانِ «'.$loc->label('fa').'» بسته شود؟ از منو و فروشگاه حذف می‌شود.' : 'این مکان دوباره باز شود؟' }}">
              @csrf
              <button class="btn btn-glass" style="font-size:11px;padding:3px 9px;color:{{ $loc->is_active ? '#ff6b6b' : '#34d399' }}">
                {{ $loc->is_active ? 'ببند' : 'باز کن' }}
              </button>
            </form>
          </div>
        </div>
      @endforeach
    </div>
  @endif
</div>


{{-- ══ مدیریتِ پلن‌ها ══
     فیلتر روی ردیف‌های **خام** است نه عرضه‌ها: مدیر باید ردیفِ هر زیرساخت را
     جدا ببیند و بتواند خاموشش کند — چیزی که نمای «عرضه» پنهان می‌کند.
     دکمهٔ خاموش روی admin_disabled می‌نویسد؛ سینک هرگز آن را دست نمی‌زند. --}}
<div class="ad-panel">
  <div class="ad-panel-h" id="plan-mgmt"><h3>مدیریتِ پلن‌ها
    <span class="ad-badge" style="background:rgba(34,211,238,.12);color:#22d3ee">{{ fa_num($rows->count()) }}</span>
  </h3></div>

  <form method="get" action="/admin/cloud" class="cl-filter" id="cl-filter">
    <label>زیرساخت
      <select name="provider">
        <option value="">همه</option>
        @foreach(array_keys(\App\Services\Cloud\CloudManager::DRIVERS) as $pv)
          <option value="{{ $pv }}" @selected($f['provider'] === $pv)>{{ app(\App\Services\Cloud\CloudManager::class)->realLabel($pv) }}</option>
        @endforeach
      </select>
    </label>
    <label>کشور
      <select name="country">
        <option value="">همه</option>
        @foreach($countries as $iso => $loc)
          <option value="{{ $iso }}" @selected($f['country'] === strtoupper($iso))>{{ $loc->flagEmoji() }} {{ $loc->countryLabel('fa') }}</option>
        @endforeach
      </select>
    </label>
    <label>پردازنده
      <select name="cpu">
        <option value="">همه</option>
        <option value="shared" @selected($f['cpu'] === 'shared')>اشتراکی</option>
        <option value="dedicated" @selected($f['cpu'] === 'dedicated')>اختصاصی</option>
      </select>
    </label>
    <label>وضعیت
      <select name="state">
        <option value="">همه</option>
        <option value="on" @selected($f['state'] === 'on')>در حالِ فروش (واقعاً قابلِ خرید)</option>
        <option value="unsellable" @selected($f['state'] === 'unsellable')>فروخته نمی‌شود — به هر علتی</option>
        <option value="off" @selected($f['state'] === 'off')>بسته‌شده توسطِ من</option>
        {{-- ردیف‌هایی که «باز کردن»ِ گروهی واقعاً بازشان می‌کند --}}
        <option value="quarantined" @selected($f['state'] === 'quarantined')>بستهٔ قرنطینهٔ خودکار (قابلِ بازکردنِ گروهی)</option>
        <option value="oos" @selected($f['state'] === 'oos')>ناموجود نزدِ زیرساخت</option>
        <option value="noprice" @selected($f['state'] === 'noprice')>بی‌قیمت (نرخِ ارز نبود)</option>
        <option value="inactive" @selected($f['state'] === 'inactive')>غیرفعال در آخرین همگام‌سازی</option>
      </select>
    </label>
    <label>مرتب‌سازی
      <select name="sort">
        <option value="price" @selected($f['sort'] === 'price')>قیمت ↑</option>
        <option value="price_d" @selected($f['sort'] === 'price_d')>قیمت ↓</option>
        <option value="cost" @selected($f['sort'] === 'cost')>بها ↑</option>
        <option value="cost_d" @selected($f['sort'] === 'cost_d')>بها ↓</option>
        <option value="cpu" @selected($f['sort'] === 'cpu')>هسته ↑</option>
        <option value="cpu_d" @selected($f['sort'] === 'cpu_d')>هسته ↓</option>
        <option value="ram" @selected($f['sort'] === 'ram')>رم ↑</option>
        <option value="ram_d" @selected($f['sort'] === 'ram_d')>رم ↓</option>
        <option value="name" @selected($f['sort'] === 'name')>نام</option>
      </select>
    </label>
    <label>جستجو
      <input type="search" name="q" value="{{ $f['q'] }}" dir="ltr" placeholder="CV-2-4 …">
    </label>
    <div style="display:flex;gap:8px;align-items:flex-end">
      <button class="btn btn-primary" style="font-size:12.5px">اعمال روی سرور</button>
      @if(array_filter($f))<a class="btn btn-glass" style="font-size:12.5px" href="/admin/cloud">پاک‌کردن</a>@endif
    </div>
    {{-- ⚠️ فیلتر بی‌درنگ و سمتِ مرورگر است، ولی مرتب‌سازی و شمارشِ دقیق کارِ
         سرور می‌مانَد؛ اگر فهرست به سقفِ ردیف خورده باشد، فیلترِ مرورگر فقط
         همان ردیف‌های بارگذاری‌شده را می‌بیند و این باید گفته شود. --}}
    <p style="grid-column:1/-1;margin:0;font-size:11.5px;color:var(--dim);line-height:1.8">
      فیلترها <b style="color:var(--muted)">بی‌درنگ</b> اعمال می‌شوند و صفحه بارِ دوباره نمی‌گیرد.
      برای <b style="color:var(--muted)">مرتب‌سازی</b> یا شمارشِ دقیقِ بیش از {{ fa_num($rowLimit) }} ردیف،
      «اعمال روی سرور» را بزنید.
    </p>
  </form>

  @if($rows->isEmpty())
    <p style="padding:16px;color:var(--dim)">با این فیلتر چیزی پیدا نشد.</p>
  @else
    {{-- شمارشِ واقعی، نه تعدادِ ردیفِ نشان‌داده‌شده: فهرستِ بریده‌ای که بریدگی‌اش
         را نگوید، «همه را دیدم» خوانده می‌شود.

         ⚠️ رشتهٔ «N پلن با این فیلتر» باید **یکپارچه** بمانَد؛ تستِ
         `test_admin_shows_the_real_match_count` همین را می‌سنجد، پس عدد را در
         span نگذار. شمارشِ بی‌درنگ یک span **جدا** است که JS نشانش می‌دهد و
         این یکی را پنهان می‌کند. --}}
    <p style="padding:0 16px 10px;color:var(--muted);font-size:12.5px">
      <span id="cl-count-srv">{{ fa_num($matched) }} پلن با این فیلتر</span><span id="cl-count-live" hidden></span>
      @if($matched > $rowLimit)
        — <b style="color:#fbbf24">فقط {{ fa_num($rowLimit) }} ردیفِ اول نشان داده شده؛ فیلتر را باریک‌تر کنید.</b>
      @endif
    </p>
    <div style="overflow-x:auto">
    <table class="ad-table" id="cl-table">
      <thead><tr>
        {{-- «همه» یعنی همهٔ ردیف‌های **همین فیلتر**، نه کلِ کاتالوگ --}}
        <th style="width:1%">
          <input type="checkbox" class="ad-pick" id="cl-all" title="انتخابِ همهٔ ردیف‌های همین فیلتر"
                 aria-label="انتخابِ همهٔ ردیف‌های همین فیلتر">
        </th>
        <th style="width:1%">ردیف</th><th>پلن</th><th>مکان</th><th>هسته</th><th>رم</th><th>دیسک</th>
        <th>بها</th><th>فروش (تومان)</th><th>زیرساخت</th><th>وضعیت</th><th></th>
      </tr></thead>
      <tbody>
        @foreach($rows as $i => $r)
        @php $m = $rowMeta[$r->id] ?? ['state' => '', 'country' => '', 'q' => '']; @endphp
        <tr data-plan="{{ $r->slug }}" data-id="{{ $r->id }}"
            data-prov="{{ $r->provider }}" data-country="{{ $m['country'] }}"
            data-cpu="{{ $r->cpu_kind }}" data-state="{{ $m['state'] }}" data-q="{{ $m['q'] }}"
            style="{{ $r->admin_disabled ? 'opacity:.55' : '' }}">
          <td><input type="checkbox" class="ad-pick cl-pick" value="{{ $r->id }}"
                     aria-label="انتخابِ {{ $r->public_name }}"></td>
          <td class="cl-n" style="color:var(--dim);font-size:12px">{{ fa_num($i + 1) }}</td>
          <td><b dir="ltr">{{ $r->public_name }}</b>
            @if($r->admin_note)<small style="display:block;color:var(--dim)">{{ $r->admin_note }}</small>@endif
          </td>
          <td>{{ $r->location?->label('fa') ?? $r->location_code }}</td>
          <td dir="ltr">{{ fa_num($r->vcpu) }}</td>
          <td dir="ltr">{{ $r->ramLabel() }}</td>
          <td dir="ltr">{{ $r->diskLabel() }}</td>
          <td dir="ltr" style="color:var(--dim)">€{{ number_format($r->cost_eur_cents / 100, 2) }}</td>
          <td dir="ltr">{{ $r->price_irt > 0 ? fa_num(number_format($r->price_irt)) : '—' }}</td>
          <td><small style="color:var(--muted)">{{ app(\App\Services\Cloud\CloudManager::class)->realLabel($r->provider) }}</small></td>
          <td>
            @if($r->admin_disabled)
              <span class="ad-badge" style="background:rgba(255,107,107,.12);color:#ff6b6b">بستهٔ من</span>
            @elseif(! $r->in_stock)
              <span class="ad-badge" style="background:rgba(251,191,36,.12);color:#fbbf24">ناموجود</span>
            @elseif($r->price_irt <= 0)
              <span class="ad-badge" style="background:rgba(148,163,184,.12);color:var(--muted)">بی‌قیمت</span>
            @else
              <span class="ad-badge" style="background:rgba(52,211,153,.12);color:#34d399">در فروش</span>
            @endif
          </td>
          <td>
            <form method="post" action="/admin/cloud/plans/{{ $r->id }}/toggle" style="display:flex;gap:6px;align-items:center">
              @csrf
              @unless($r->admin_disabled)
                <input type="text" name="note" maxlength="180" placeholder="دلیل (اختیاری)"
                       style="width:110px;background:var(--surface2);border:1px solid var(--line);border-radius:7px;color:var(--text);padding:4px 8px;font:inherit;font-size:11.5px">
              @endunless
              <button class="btn btn-glass" style="font-size:11.5px;padding:4px 10px;color:{{ $r->admin_disabled ? '#34d399' : '#ff6b6b' }}">
                {{ $r->admin_disabled ? 'باز کن' : 'ببند' }}
              </button>
            </form>
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>
    </div>

    <p id="cl-none" hidden style="padding:16px;color:var(--dim)">با این فیلتر چیزی پیدا نشد.</p>

    {{-- ══ نوارِ اقدامِ گروهی ══
         تا چیزی انتخاب نشده دیده نمی‌شود، و **تعداد را صریح می‌گوید**: نوارِ
         بی‌شمارش یعنی مدیر فکر می‌کند ۱۲ ردیف انتخاب کرده و ۴۰۰ ردیف را
         می‌بندد. --}}
    <div class="ad-bulk" id="cl-bulk" hidden>
      <b><span id="cl-bulk-n">۰</span> ردیف انتخاب شده</b>

      <form method="post" action="/admin/cloud/plans/bulk-open"
            data-confirm="ردیف‌های انتخاب‌شده دوباره برای فروش باز شوند؟ فقط آن‌هایی باز می‌شوند که «قرنطینهٔ خودکار» بسته بود و همین حالا قابلِ فروش‌اند — بقیه با علتش گزارش می‌شوند."
            data-confirm-title="بازکردن برای فروش">
        @csrf
        <input type="hidden" name="ids" value="">
        <button class="btn btn-glass" style="font-size:12px;padding:6px 12px;color:#34d399">باز کردن برای فروش</button>
      </form>

      <form method="post" action="/admin/cloud/plans/bulk-close" data-confirm-danger
            data-confirm="ردیف‌های انتخاب‌شده بسته شوند؟ از فروشگاه حذف می‌شوند (سرویس‌های فعلی دست نمی‌خورند)."
            data-confirm-title="بستنِ گروهی">
        @csrf
        <input type="hidden" name="ids" value="">
        <input type="text" name="note" maxlength="180" placeholder="دلیل (اختیاری)"
               style="width:150px;background:var(--surface);border:1px solid var(--line2);border-radius:7px;color:var(--text);padding:5px 9px;font:inherit;font-size:11.5px">
        <button class="btn btn-glass" style="font-size:12px;padding:6px 12px;color:#ff6b6b">بستن</button>
      </form>

      <button type="button" class="btn btn-glass sep" id="cl-bulk-clear" style="font-size:12px;padding:6px 12px">لغوِ انتخاب</button>

      <span class="warn">
        «باز کردن» فقط ردیف‌هایی را باز می‌کند که <b>قرنطینهٔ خودکار</b> بسته بود.
        پلنی که خودتان بسته‌اید، یا بی‌قیمت/ناموجود/غیرفعال است، دست نمی‌خورد و علتش گزارش می‌شود.
        @if($matched > $rowLimit)
          <br>⚠️ فهرست به سقفِ {{ fa_num($rowLimit) }} ردیف خورده است، پس «انتخابِ همه» فقط همین ردیف‌های
          بارگذاری‌شده را می‌گیرد، نه هر {{ fa_num($matched) }} ردیفِ فیلتر.
        @endif
      </span>
    </div>
  @endif
</div>

{{-- عرضه‌های عمومی: دقیقاً همان چیزی که مشتری می‌بیند --}}
<div class="ad-panel">
  <div class="ad-panel-h">
    <h3>عرضه‌های عمومی
      <span class="ad-badge" style="background:rgba(52,211,153,.12);color:#34d399">{{ fa_num($offers->count()) }}</span>
      <small style="color:var(--dim);font-weight:400">از {{ fa_num($planCount) }} ردیفِ خام</small>
    </h3>
  </div>
  <p style="padding:0 18px;color:var(--muted);font-size:12.5px;line-height:1.8">
    هر ردیف یک کارت روی سایت است. ستونِ «زیرساخت» فقط برای شماست تا ببینید تحویل از کجا انجام می‌شود.
  </p>
  @if($offers->isEmpty())
    <p style="padding:16px;color:var(--dim)">
      هنوز عرضه‌ای نیست. اگر همگام‌سازی انجام شده ولی این‌جا خالی است، احتمالاً
      <b>نرخِ یورو</b> در دسترس نبوده و قیمتِ تومانی ساخته نشده.
    </p>
  @else
    <div style="overflow-x:auto">
    <table class="ad-table">
      <thead><tr>
        <th>نام</th><th>مکان</th><th>هسته</th><th>رم</th><th>دیسک</th><th>ترافیک</th>
        <th>بها (یورو)</th><th>فروش (یورو)</th><th>فروش (تومان)</th><th>زیرساخت</th>
      </tr></thead>
      <tbody>
        @foreach($offers as $o)
        <tr>
          <td><b dir="ltr">{{ $o->public_name }}</b><small dir="ltr" style="display:block;color:var(--dim)">{{ $o->slug }}</small></td>
          <td>{{ $o->location?->label('fa') ?? $o->location_code }}</td>
          <td dir="ltr">{{ fa_num($o->vcpu) }}</td>
          <td dir="ltr">{{ $o->ramLabel() }}</td>
          <td dir="ltr">{{ $o->diskLabel() }}</td>
          <td dir="ltr">{{ $o->trafficLabel('fa') }}</td>
          <td dir="ltr" style="color:var(--dim)">€{{ number_format($o->cost_eur_cents / 100, 2) }}</td>
          <td dir="ltr">€{{ number_format($o->price_eur_cents / 100, 2) }}</td>
          <td dir="ltr"><b>{{ fa_num(number_format($o->price_irt)) }}</b></td>
          <td><small style="color:var(--muted)">{{ app(\App\Services\Cloud\CloudManager::class)->realLabel($o->provider) }}</small></td>
        </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  @endif
</div>
@endunless

<style>
.cl-filter{ padding:14px 18px; display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:11px; align-items:end; border-bottom:1px solid var(--line) }
.cl-filter label{ display:flex; flex-direction:column; gap:5px; font-size:12px; color:var(--muted) }
.cl-filter select, .cl-filter input{ background:var(--surface2); border:1px solid var(--line); border-radius:9px; color:var(--text); padding:8px 10px; font:inherit; font-size:12.5px }
.cl-locs{ padding:8px 18px 18px; display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:10px }
.cl-loc{ display:flex; align-items:center; gap:11px; padding:11px 13px; background:var(--surface2); border:1px solid var(--line); border-radius:11px; font-size:13px }
.cl-loc b{ font-size:13px }
</style>

{{--
  ══ فیلترِ بی‌درنگ + انتخابِ گروهی ══

  ⚠️ سه تلهٔ ثبت‌شدهٔ همین پروژه در این بلوک:

  ۱) `@verbatim` لازم است. یک `{{` یا یک `@word` تصادفی در جاوااسکریپت را Blade
     به‌عنوان دستور می‌خوانَد، و علامتِ کوچک‌ترِ چسبیده به علامتِ سؤال روی سرور
     (که short_open_tag روشن دارد) ۵۰۰ می‌دهد و محلی سالم است.

  ۲) هیچ فیلتری این‌جا **از نو تعریف نمی‌شود.** معنیِ «در حالِ فروش» در
     `CloudPlan::scopeSellable` است و سرور آن را به‌شکلِ نشانه‌های
     `data-state` روی هر ردیف می‌گذارد؛ این کد فقط رشته مقایسه می‌کند.

  ۳) فیلدِ پنهانِ `ids` **در لحظهٔ تغییرِ انتخاب** پر می‌شود، نه سرِ ارسال:
     دیالوگِ تأییدِ پنل با `form.submit()` می‌فرستد و آن متد هیچ رویدادِ
     submitای تولید نمی‌کند، پس هر شنوندهٔ «سرِ ارسال پرش کن» بی‌صدا اجرا
     نمی‌شد و فهرست خالی می‌رفت.
--}}
@verbatim
<script>
(function () {
  var table = document.getElementById('cl-table');
  var form  = document.getElementById('cl-filter');
  var bar   = document.getElementById('cl-bulk');
  if (!table || !form || !bar || !table.tBodies.length) return;

  var rows  = Array.prototype.slice.call(table.tBodies[0].rows);
  var all   = document.getElementById('cl-all');
  var nEl   = document.getElementById('cl-bulk-n');
  var none  = document.getElementById('cl-none');
  var srv   = document.getElementById('cl-count-srv');
  var live  = document.getElementById('cl-count-live');
  var idIn  = bar.querySelectorAll('input[name="ids"]');
  var clear = document.getElementById('cl-bulk-clear');
  var touched = false;

  function fa(n) {
    return String(n).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.charAt(Number(d)); });
  }

  function val(name) {
    var el = form.elements[name];
    return el ? String(el.value || '').trim() : '';
  }

  function visible(tr) { return !tr.classList.contains('ad-hide'); }

  function keep(tr, f) {
    if (f.provider && tr.getAttribute('data-prov') !== f.provider) return false;
    if (f.country && tr.getAttribute('data-country') !== f.country) return false;
    if (f.cpu && tr.getAttribute('data-cpu') !== f.cpu) return false;
    if (f.state && (' ' + tr.getAttribute('data-state') + ' ').indexOf(' ' + f.state + ' ') === -1) return false;
    if (f.q && String(tr.getAttribute('data-q')).indexOf(f.q) === -1) return false;
    return true;
  }

  /* شمارشِ انتخاب + پرکردنِ فیلدِ پنهان.

     قرارداد: **انتخاب همیشه زیرمجموعهٔ ردیف‌های دیده‌شده است.** فیلتر که عوض
     شود، تیکِ ردیف‌های پنهان‌شده برداشته می‌شود و شمارش هم بی‌درنگ عوض. بی‌این
     قاعده، مدیر ۴۰۰ ردیف را انتخاب می‌کرد، فیلتر را باریک می‌کرد، «۱۲ ردیف»
     می‌دید و ۴۰۰ ردیف را می‌بست. */
  function sync() {
    var ids = [];
    for (var i = 0; i < rows.length; i++) {
      if (!visible(rows[i])) continue;
      var c = rows[i].querySelector('.cl-pick');
      if (c && c.checked) ids.push(rows[i].getAttribute('data-id'));
    }
    nEl.textContent = fa(ids.length);
    for (var j = 0; j < idIn.length; j++) idIn[j].value = ids.join(',');
    bar.hidden = ids.length === 0;
  }

  function apply() {
    var f = {
      provider: val('provider'),
      country:  val('country').toUpperCase(),
      cpu:      val('cpu'),
      state:    val('state'),
      q:        val('q').toLowerCase()
    };
    var shown = 0;
    for (var i = 0; i < rows.length; i++) {
      var tr = rows[i], ok = keep(tr, f), box = tr.querySelector('.cl-pick');
      if (ok) {
        tr.classList.remove('ad-hide');
        shown++;
        var n = tr.querySelector('.cl-n');
        if (n) n.textContent = fa(shown);
      } else {
        tr.classList.add('ad-hide');
        if (box) box.checked = false;
      }
    }
    if (none) none.hidden = shown > 0;
    if (touched && srv && live) {
      live.textContent = fa(shown) + ' پلن با این فیلتر — از ' + fa(rows.length) + ' ردیفِ بارگذاری‌شده';
      srv.hidden = true;
      live.hidden = false;
    }
    if (all) all.checked = false;
    sync();
  }

  ['provider', 'country', 'cpu', 'state'].forEach(function (name) {
    var el = form.elements[name];
    if (el) el.addEventListener('change', function () { touched = true; apply(); });
  });

  var q = form.elements['q'];
  if (q) q.addEventListener('input', function () { touched = true; apply(); });

  /* مرتب‌سازی کارِ سرور است (ترتیب روی کلِ فهرست معنی دارد، نه روی ردیف‌های
     بارگذاری‌شده)، پس این یکی عمداً صفحه را می‌فرستد. */
  var sort = form.elements['sort'];
  if (sort) sort.addEventListener('change', function () { form.submit(); });

  table.addEventListener('change', function (e) {
    var t = e.target;
    if (all && t === all) {
      for (var i = 0; i < rows.length; i++) {
        if (!visible(rows[i])) continue;
        var c = rows[i].querySelector('.cl-pick');
        if (c) c.checked = all.checked;
      }
      sync();
    } else if (t && t.classList && t.classList.contains('cl-pick')) {
      sync();
    }
  });

  if (clear) clear.addEventListener('click', function () {
    for (var i = 0; i < rows.length; i++) {
      var c = rows[i].querySelector('.cl-pick');
      if (c) c.checked = false;
    }
    if (all) all.checked = false;
    sync();
  });

  /* روی بارگذاری فیلتر نمی‌زنیم: شمارشِ سرور باید دست‌نخورده بمانَد و ردیف‌ها
     همان‌اند که سرور فیلتر کرده. فقط انتخاب را همگام می‌کنیم، چون مرورگر با
     دکمهٔ back تیک‌ها را برمی‌گردانَد و نوار وگرنه کهنه می‌مانْد. */
  sync();
})();
</script>
@endverbatim
@endsection

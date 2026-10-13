@extends('panel.layout')
@section('title', 'DNS — '.$zone->domain)

@push('head')
<link rel="stylesheet" href="{{ asset_ver('assets/css/dns.css') }}">
@endpush

@section('panel')
@php
  $pill = match(true) {
      $zone->status === 'suspended'                  => ['off',  'معلق'],
      $zone->status === 'active' && $zone->verify_error => ['wait', 'نیم‌سرور تغییر کرده'],
      $zone->status === 'active'                     => ['on',   'فعال'],
      default                                        => ['wait', 'در انتظارِ تنظیمِ نیم‌سرور'],
  };

  $typeCounts = $records->groupBy('type')->map->count();
  $expires = $zone->pendingExpiresAt();
  $editOpen = str_starts_with((string) session('dns_form'), 'edit-') ? (int) substr((string) session('dns_form'), 5) : null;
  $newOpen = session('dns_form') === 'new';
  $zoneUrl = lroute('account.dns.zone', $zone->id);
@endphp

<div class="dns">

  {{-- ═════════ سرصفحه ═════════ --}}
  <div>
    <a class="dns-back" href="{{ lroute('account.dns') }}">→ همهٔ دامنه‌ها</a>
    <div class="dns-hero" style="margin-top:8px">
      <div>
        <h1>{{ $zone->domain }}</h1>
        <div class="dns-hero-m">
          <span class="dns-pill {{ $pill[0] }}">{{ $pill[1] }}</span>
          @if($zone->dirty && $zone->sync_error)
            <span class="dns-pill off">انتشار با تأخیر</span>
          @endif
        </div>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a class="pnl-btn" href="{{ $zoneUrl }}/export"><svg class="icon"><use href="#i-file"/></svg>دریافتِ فایلِ zone</a>
        <form method="POST" action="{{ $zoneUrl }}/verify" class="dns-inline">
          @csrf
          <button class="pnl-btn" type="submit"><svg class="icon"><use href="#i-restore"/></svg>بررسیِ نیم‌سرورها</button>
        </form>
      </div>
    </div>
  </div>

  <div class="dns-stats">
    <div class="dns-stat"><span>رکوردها</span><b>{{ fa_num($records->count()) }}</b></div>
    <div class="dns-stat"><span>تفویض</span>
      <b class="{{ $zone->verified_at && ! $zone->verify_error ? 'dns-t-ok' : 'dns-t-warn' }}">
        {{ $zone->verified_at && ! $zone->verify_error ? 'به سرورنت' : 'هنوز نه' }}</b></div>
    <div class="dns-stat"><span>آخرین بررسی</span><b>{{ $zone->checked_at ? sdate($zone->checked_at, true) : '—' }}</b></div>
    <div class="dns-stat"><span>انتشار روی سرورها</span>
      <b class="{{ $zone->dirty ? 'dns-t-warn' : 'dns-t-ok' }}">{{ $zone->dirty ? 'در صف' : ($zone->synced_at ? sdate($zone->synced_at, true) : '—') }}</b></div>
  </div>

  @if(session('ok'))<div class="dns-note ok">{{ session('ok') }}</div>@endif
  @foreach(['verify', 'import', 'delete'] as $k)
    @if($errors->has($k))<div class="dns-note danger">{{ $errors->first($k) }}</div>@endif
  @endforeach

  @if($zone->status === 'suspended')
    <div class="dns-note danger"><b>این دامنه معلق است.</b> رکوردهایش فعلاً از سرورهای DNS پاسخ داده نمی‌شوند. برای بررسی با پشتیبانی تماس بگیرید.</div>
  @elseif($zone->status === 'active' && $zone->verify_error)
    <div class="dns-note warn">
      <b>نیم‌سرورهای این دامنه دیگر به سرورنت اشاره نمی‌کنند.</b><br>
      {{ \App\Services\Dns\DelegationCheck::explain($zone->verify_error) }}
      رکوردهایی که این‌جا می‌بینید تا وقتی نیم‌سرورها برنگردند، روی اینترنت اثری ندارند.
    </div>
  @endif

  {{-- ═════════ راه‌اندازی — تا وقتی تفویض نشده، مهم‌ترین بخشِ صفحه ═════════ --}}
  @if($zone->status === 'pending')
  <section class="pnl-sec">
    <div class="pnl-sec-h"><h2>راه‌اندازی در سه قدم</h2></div>
    <div class="pnl-sec-b">
      <div class="dns-steps">
        <div class="dns-step {{ $records->count() ? 'done' : '' }}">
          <i>{{ $records->count() ? '✓' : '۱' }}</i>
          <div>
            <b>رکوردهای فعلی را این‌جا داشته باشید</b>
            <p>
              لحظه‌ای که نیم‌سرورها عوض شوند، فقط رکوردهای همین صفحه پاسخ داده می‌شوند.
              اگر ایمیل، زیردامنه یا سرویسی دارید که این‌جا نیست، همان لحظه قطع می‌شود.
            </p>
            @unless($import)
              <a class="pnl-btn" href="{{ $zoneUrl }}?import=1"><svg class="icon"><use href="#i-search"/></svg>خواندنِ رکوردهای فعلی</a>
            @endunless
          </div>
        </div>

        <div class="dns-step">
          <i>۲</i>
          <div>
            <b>نیم‌سرورها را در پنلِ ثبت‌کنندهٔ دامنه عوض کنید</b>
            <p>همهٔ نیم‌سرورهای فعلی را پاک کنید و فقط این‌ها را بگذارید:</p>
            <div class="dns-ns">
              @foreach($zone->nameservers() as $ns)
                <div class="dns-copy"><code>{{ $ns }}</code>
                  <button type="button" data-copy="{{ $ns }}"><svg class="icon"><use href="#i-copy"/></svg>کپی</button></div>
              @endforeach
            </div>
          </div>
        </div>

        <div class="dns-step">
          <i>۳</i>
          <div>
            <b>تأیید</b>
            <p>
              انتشارِ تغییرِ نیم‌سرور معمولاً چند دقیقه تا چند ساعت (گاهی تا ۴۸ ساعت) طول می‌کشد.
              ما هر ساعت خودکار بررسی می‌کنیم و به محضِ تأیید، دامنه فعال می‌شود.
              @if($zone->verify_error)
                <br><span class="dns-t-warn">آخرین بررسی: {{ \App\Services\Dns\DelegationCheck::explain($zone->verify_error) }}</span>
              @endif
            </p>
            <form method="POST" action="{{ $zoneUrl }}/verify">
              @csrf
              <button class="pnl-btn primary" type="submit"><svg class="icon"><use href="#i-check"/></svg>الان بررسی کن</button>
            </form>
          </div>
        </div>
      </div>

      @if($expires)
        <div class="dns-note warn" style="margin-top:16px">
          اگر نیم‌سرورها تا <b>{{ sdate($expires) }}</b> تنظیم نشوند، این دامنه از حسابتان برداشته می‌شود
          (تا دامنهٔ کسِ دیگری بی‌اجازه در سرورنت رزرو نماند). هر وقت خواستید می‌توانید دوباره اضافه‌اش کنید.
        </div>
      @endif
    </div>
  </section>
  @endif

  {{-- ═════════ پیش‌نمایشِ واردکردن ═════════ --}}
  @if($import)
  <section class="pnl-sec">
    <div class="pnl-sec-h"><h2>رکوردهای فعلیِ دامنه</h2></div>
    <div class="pnl-sec-b">
      @if(! ($import['ok'] ?? false))
        <div class="dns-note warn">الان نتوانستیم رکوردهای فعلی را بخوانیم. چند دقیقهٔ دیگر دوباره امتحان کنید یا رکوردها را دستی اضافه کنید.</div>
      @elseif(($import['records'] ?? []) === [])
        <div class="dns-note">رکوردِ تازه‌ای پیدا نشد{{ $records->count() ? ' — همهٔ رکوردهای یافت‌شده از قبل این‌جا هستند' : '' }}.</div>
      @else
        {{-- 🔴 این جمله تزئینی نیست. DNS راهی برای فهرست‌کردنِ کاملِ یک zone
             ندارد؛ اگر مشتری نداند فهرست ناقص است، زیردامنه‌ای که ما ندیده‌ایم
             با تعویضِ نیم‌سرور بی‌صدا ناپدید می‌شود. --}}
        <div class="dns-note warn" style="margin-bottom:14px">
          <b>این فهرست ممکن است کامل نباشد.</b>
          DNS اجازهٔ خواندنِ همهٔ رکوردهای یک دامنه را نمی‌دهد؛ ما دامنهٔ اصلی و زیردامنه‌های رایج را پرسیده‌ایم.
          لطفاً با پنلِ فعلی‌تان مقایسه کنید و هر رکوردِ جامانده را بعداً دستی اضافه کنید.
          رکوردهای <span class="dns-crit">ایمیل</span> را حتماً نگه دارید.
        </div>
        <form method="POST" action="{{ $zoneUrl }}/import">
          @csrf
          <div class="dns-tbl-wrap">
            <table class="dns-tbl dns-imp">
              <thead><tr><th><input type="checkbox" checked data-dns-all aria-label="انتخابِ همه"></th><th>نوع</th><th>نام</th><th>مقدار</th><th>TTL</th></tr></thead>
              <tbody>
                @foreach($import['records'] as $i => $r)
                  <tr>
                    <td>
                      <input type="checkbox" name="pick[]" value="{{ $i }}" checked aria-label="وارد شود">
                      @foreach(['type', 'name', 'content', 'ttl', 'prio'] as $f)
                        <input type="hidden" name="rows[{{ $i }}][{{ $f }}]" value="{{ $r[$f] ?? '' }}">
                      @endforeach
                    </td>
                    <td><span class="dns-type t-{{ $r['type'] }}">{{ $r['type'] }}</span>
                      @if($r['critical'] ?? false)<span class="dns-crit">ایمیل</span>@endif</td>
                    <td class="c-name">{{ $r['name'] }}</td>
                    <td class="c-val">@if($r['prio'] !== null)<span class="dns-t-dim">{{ $r['prio'] }}</span> @endif{{ $r['content'] }}</td>
                    <td>{{ fa_num($r['ttl']) }}</td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
          <div style="display:flex;gap:8px;margin-top:14px;flex-wrap:wrap">
            <button class="pnl-btn primary" type="submit"><svg class="icon"><use href="#i-check"/></svg>واردکردنِ رکوردهای انتخاب‌شده</button>
            <a class="pnl-btn" href="{{ $zoneUrl }}">فعلاً نه</a>
          </div>
        </form>
      @endif
    </div>
  </section>
  @endif

  {{-- ═════════ رکوردها ═════════ --}}
  <section class="pnl-sec" id="records">
    <div class="pnl-sec-h">
      <h2>رکوردهای DNS</h2>
      <button class="pnl-btn primary" type="button" data-dns-open="new" aria-expanded="{{ $newOpen ? 'true' : 'false' }}">
        <svg class="icon"><use href="#i-plus"/></svg>رکوردِ جدید</button>
    </div>

    <div class="pnl-sec-b" data-dns-panel="new" @unless($newOpen) hidden @endunless style="border-bottom:1px solid var(--line)">
      @include('account.partials.dns-record-form', [
          'rec' => null, 'formKey' => 'new', 'action' => $zoneUrl.'/records',
      ])
    </div>

    @if($records->count())
      <div class="dns-tools">
        <input class="dns-input" type="search" placeholder="جست‌وجو در نام یا مقدار…" data-dns-search aria-label="جست‌وجو">
        <div class="dns-chips" role="group" aria-label="فیلترِ نوع">
          <button type="button" class="dns-chip" data-dns-chip="" aria-pressed="true">همه ({{ fa_num($records->count()) }})</button>
          @foreach($typeCounts as $t => $n)
            <button type="button" class="dns-chip" data-dns-chip="{{ $t }}" aria-pressed="false">{{ $t }} ({{ fa_num($n) }})</button>
          @endforeach
        </div>
      </div>

      <div class="dns-tbl-wrap">
        <table class="dns-tbl dns-rec">
          <thead><tr><th>نوع</th><th>نام</th><th>مقدار</th><th>TTL</th><th class="c-act"><span class="sr-only">عملیات</span></th></tr></thead>
          <tbody>
            @foreach($records as $r)
              @php
                $short = $r->shortName($zone->domain);
              @endphp
              <tr class="dns-row {{ $r->disabled ? 'is-off' : '' }}" data-type="{{ $r->type }}"
                  data-q="{{ mb_strtolower($r->name.' '.$r->content.' '.$r->comment) }}">
                <td><span class="dns-type t-{{ $r->type }}">{{ $r->type }}</span></td>
                <td class="c-name">{{ $short }}</td>
                <td class="c-val">
                  @if($r->prio !== null)<span class="dns-t-dim">{{ $r->prio }}</span> @endif{{ $r->content }}
                  @if($r->comment)<span class="c-note">{{ $r->comment }}</span>@endif
                  @if($r->disabled)<span class="c-note dns-t-warn">غیرفعال — پاسخ داده نمی‌شود</span>@endif
                </td>
                <td class="c-ttl">{{ $r->ttl < 3600 ? fa_num(intdiv($r->ttl, 60)).' دقیقه' : fa_num(intdiv($r->ttl, 3600)).' ساعت' }}</td>
                <td class="c-act">
                  <button class="dns-ic-btn" type="button" data-dns-open="edit-{{ $r->id }}" title="ویرایش" aria-label="ویرایش"
                          aria-expanded="{{ $editOpen === $r->id ? 'true' : 'false' }}"><svg class="icon"><use href="#i-wrench"/></svg></button>
                  <form method="POST" action="{{ $zoneUrl }}/records/{{ $r->id }}/toggle" class="dns-inline">
                    @csrf
                    <button class="dns-ic-btn" type="submit" title="{{ $r->disabled ? 'فعال‌کردن' : 'غیرفعال‌کردن' }}"
                            aria-label="{{ $r->disabled ? 'فعال‌کردن' : 'غیرفعال‌کردن' }}">
                      <svg class="icon"><use href="#{{ $r->disabled ? 'i-eye' : 'i-eye-off' }}"/></svg></button>
                  </form>
                  <form method="POST" action="{{ $zoneUrl }}/records/{{ $r->id }}/delete" class="dns-inline"
                        data-confirm="رکوردِ {{ $r->type }} «{{ $short }}» حذف شود؟">
                    @csrf
                    <button class="dns-ic-btn danger" type="submit" title="حذف" aria-label="حذف"><svg class="icon"><use href="#i-x"/></svg></button>
                  </form>
                </td>
              </tr>
              <tr class="dns-edit" data-dns-panel="edit-{{ $r->id }}" data-type="{{ $r->type }}" @unless($editOpen === $r->id) hidden @endunless>
                <td colspan="5">
                  @include('account.partials.dns-record-form', [
                      'rec' => $r, 'formKey' => 'edit-'.$r->id, 'action' => $zoneUrl.'/records/'.$r->id,
                  ])
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @else
      <div class="pnl-empty">
        <svg class="icon"><use href="#i-list"/></svg>
        <b>هنوز رکوردی ندارید</b>
        <p class="dns-t-dim" style="font-size:13px">رکوردهای فعلیِ دامنه را وارد کنید یا یک رکوردِ جدید بسازید.</p>
      </div>
    @endif
  </section>

  {{-- ═════════ تاریخچه ═════════ --}}
  @if($events->count())
  <section class="pnl-sec">
    <div class="pnl-sec-h"><h2>تاریخچهٔ تغییرات</h2></div>
    <div class="pnl-sec-b">
      <div class="dns-log">
        @foreach($events as $e)
          <div><span>{{ $e->message }}</span><time>{{ $e->created_at ? sdate($e->created_at, true) : '' }}</time></div>
        @endforeach
      </div>
    </div>
  </section>
  @endif

  {{-- ═════════ حذفِ دامنه ═════════ --}}
  <section class="pnl-sec dns-danger">
    <div class="pnl-sec-h"><h2>حذفِ دامنه از DNSِ سرورنت</h2></div>
    <div class="pnl-sec-b">
      <p class="dns-t-dim" style="font-size:13px;line-height:1.9;margin:0 0 12px">
        همهٔ رکوردها پاک می‌شوند. اگر نیم‌سرورهای دامنه هنوز روی سرورنت باشد، سایت و ایمیلتان <b>بلافاصله</b> از کار می‌افتد.
        پیش از حذف، فایلِ zone را دریافت کنید و نیم‌سرورها را به ارائه‌دهندهٔ جدید ببرید.
      </p>
      <form method="POST" action="{{ $zoneUrl }}/delete" class="dns-add">
        @csrf
        <input class="dns-input dns-ltr" name="confirm" autocomplete="off" spellcheck="false"
               placeholder="{{ $zone->domain }}" aria-label="برای تأیید نامِ دامنه را بنویسید">
        <button class="pnl-btn danger" type="submit"><svg class="icon"><use href="#i-x"/></svg>حذفِ دامنه</button>
      </form>
    </div>
  </section>

</div>

<script src="{{ asset_ver('assets/js/dns.js') }}" defer></script>
@endsection

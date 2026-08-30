@extends('admin.layout')
@section('title', $lead->company)
@section('nav_marketing', 'on')
@section('head')<link rel="stylesheet" href="{{ asset_ver('assets/css/marketing.css') }}">@endsection
@section('content')

<div class="mk">

  <div class="mk-head">
    <div>
      <h2>{{ $lead->company }}</h2>
      <p>
        <a href="{{ $lead->website }}" target="_blank" rel="noopener nofollow" dir="ltr">{{ parse_url($lead->website, PHP_URL_HOST) }}</a>
        · {{ trim($lead->city.' '.$lead->country) ?: '—' }}
        @if($lead->phone) · <span dir="ltr">{{ $lead->phone }}</span>@endif
      </p>
    </div>
    <a class="mk-btn" href="/admin/marketing">بازگشت به قیف</a>
  </div>

  <div class="mk-tiles">
    <div class="mk-tile">
      <div class="mk-tile-k"><svg class="icon"><use href="#i-flow"/></svg>مرحله</div>
      <div class="mk-tile-v" style="font-size:20px;padding-top:6px">{{ $lead->stageLabel() }}</div>
      <div class="mk-tile-s">{{ $lead->next_action_at ? 'اقدامِ بعدی '.$lead->next_action_at->format('Y-m-d') : 'بدونِ اقدامِ بعدی' }}</div>
    </div>
    <div class="mk-tile {{ $lead->audit_score !== null && $lead->audit_score < 60 ? 'is-warn' : '' }}">
      <div class="mk-tile-k"><svg class="icon"><use href="#i-gauge"/></svg>امتیازِ سایتشان</div>
      <div class="mk-tile-v">{{ $lead->audit_score !== null ? $lead->audit_score : '—' }}<small>از ۱۰۰</small></div>
      <div class="mk-tile-s">{{ $lead->audit_score !== null ? 'ممیزیِ خودمان' : 'هنوز بررسی نشده' }}</div>
    </div>
    <div class="mk-tile {{ $blocked ? 'is-hot' : '' }}">
      <div class="mk-tile-k"><svg class="icon"><use href="#i-mail"/></svg>نشانیِ تماس</div>
      <div class="mk-tile-v" style="font-size:15px;padding-top:10px" dir="ltr">{{ $lead->email ?: '—' }}</div>
      <div class="mk-tile-s">{{ $blocked ? 'در فهرستِ سیاه — هیچ پیامی نمی‌رود' : ($lead->email ? 'از روی سایتشان برداشته شد' : 'هنوز پیدا نشده') }}</div>
    </div>
    <div class="mk-tile">
      <div class="mk-tile-k"><svg class="icon"><use href="#i-message"/></svg>گفتگو</div>
      <div class="mk-tile-v">{{ $messages->count() }}</div>
      <div class="mk-tile-s">{{ $messages->where('direction', 'in')->count() }} جواب از آن‌ها</div>
    </div>
  </div>

  {{-- ══ مشاهده — تنها دلیلِ مجازِ نوشتنِ پیام ══ --}}
  <div class="ad-panel">
    <div class="ad-panel-h"><h2>مشاهده</h2></div>
    @if(filled($lead->observation))
      <p style="padding:16px 18px;line-height:2;font-size:14.5px">{{ $lead->observation }}</p>
    @else
      <div style="padding:16px 18px">
        <div class="mk-note" style="margin:0">
          <svg class="icon"><use href="#i-info"/></svg>
          <div>مشاهده‌ای ثبت نشده و تا آن موقع <b>هیچ پیامی ساخته نمی‌شود</b>. این عمدی است: پیامی که چیزِ مشخصی دربارهٔ سایتِ خودشان نگوید، همان اسپمی است که کسی نمی‌خواند.</div>
        </div>
      </div>
    @endif
    <div style="padding:0 18px 18px;display:flex;gap:8px;flex-wrap:wrap">
      <form method="post" action="/admin/marketing/{{ $lead->id }}/enrich">@csrf
        <button class="mk-btn" type="submit"><svg class="icon"><use href="#i-search"/></svg>بررسیِ سایتشان</button>
      </form>
      <form method="post" action="/admin/marketing/{{ $lead->id }}/compose">@csrf
        <button class="btn btn-primary" type="submit"><svg class="icon"><use href="#i-mail"/></svg>نوشتنِ ایمیلِ بعدی</button>
      </form>
      <form method="post" action="/admin/marketing/{{ $lead->id }}/suppress" data-confirm="این نشانی برای همیشه در فهرستِ سیاه می‌رود. برگشتی ندارد." data-confirm-danger>@csrf
        <button class="mk-btn danger" type="submit">فهرستِ سیاه</button>
      </form>
    </div>
  </div>

  {{-- ══ پیش‌نویسِ شبکه‌های اجتماعی ══ --}}
  <div class="ad-panel" style="margin-top:16px">
    <div class="ad-panel-h"><h2>پیام برای لینکدین و اینستاگرام</h2></div>
    <div style="padding:14px 18px">
      <div class="mk-note" style="margin-bottom:14px">
        <svg class="icon"><use href="#i-info"/></svg>
        <div>این‌ها را <b>خودت</b> می‌فرستی. ارسالِ خودکار روی این دو پلتفرم نقضِ شرایطشان است و اکانت را برای همیشه می‌سوزاند — ولی کارِ سختِ این پیام‌ها نوشتنشان است، نه کلیک کردن.</div>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        @foreach([
          ['linkedin', 'note', 'یادداشتِ درخواستِ ارتباط', 'i-linkedin'],
          ['linkedin', 'dm',   'پیامِ لینکدین', 'i-linkedin'],
          ['instagram', 'dm',  'دایرکتِ اینستاگرام', 'i-instagram'],
        ] as [$ch, $kind, $label, $icon])
          <form method="post" action="/admin/marketing/{{ $lead->id }}/social">
            @csrf
            <input type="hidden" name="channel" value="{{ $ch }}">
            <input type="hidden" name="kind" value="{{ $kind }}">
            <button class="mk-btn" type="submit"><svg class="icon"><use href="#{{ $icon }}"/></svg>{{ $label }}</button>
          </form>
        @endforeach
      </div>
    </div>
  </div>

  {{-- ══ ممیزیِ سایتشان ══ --}}
  @if(is_array($lead->audit) && ($lead->audit['ok'] ?? false))
  <div class="ad-panel" style="margin-top:16px">
    <div class="ad-panel-h"><h2>ممیزیِ سایتشان</h2></div>
    <div style="padding:16px 18px;display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(170px,1fr))">
      @foreach(($lead->audit['scores'] ?? []) as $cat => $score)
        @php $issues = collect($lead->audit['checks'][$cat] ?? [])->filter(fn ($c) => ($c['status'] ?? 'pass') !== 'pass'); @endphp
        <div style="background:var(--surface2);border:1px solid var(--line);border-radius:12px;padding:14px">
          <div style="display:flex;align-items:center;justify-content:space-between;gap:10px">
            <span style="color:var(--muted);font-size:12.5px">{{ ['seo'=>'سئو','performance'=>'سرعت','security'=>'امنیت','mobile'=>'موبایل','best'=>'بهترین‌روش'][$cat] ?? $cat }}</span>
            <div class="mk-score {{ $score >= 75 ? 'ok' : ($score >= 55 ? 'mid' : 'bad') }}" style="width:34px;height:34px;font-size:12px">{{ $score }}</div>
          </div>
          @foreach($issues->take(3) as $check)
            <div style="color:var(--dim);font-size:12px;margin-top:7px;line-height:1.8">· {{ $check['label'] ?? $check['title'] ?? '' }}</div>
          @endforeach
        </div>
      @endforeach
    </div>
  </div>
  @endif

  {{-- ══ گفتگو ══ --}}
  <div class="ad-panel" style="margin-top:16px">
    <div class="ad-panel-h"><h2>گفتگو</h2></div>
    <div style="padding:16px 18px">
      @forelse($messages as $m)
        <div class="mk-msg {{ $m->direction === 'in' ? 'in' : '' }}">
          <div class="mk-msg-h">
            <span>
              {{ $m->direction === 'in' ? '↙ از مشتری' : '↗ از ما' }}
              · <span class="mk-tag {{ $m->status === 'draft' ? 'a' : ($m->status === 'sent' ? 'g' : '') }}">{{ $m->channel }} · {{ $m->status }}</span>
            </span>
            <span dir="ltr">{{ ($m->sent_at ?: $m->created_at)->format('Y-m-d H:i') }}</span>
          </div>
          <div class="mk-ltr">
            <b>{{ $m->subject }}</b>
            <pre style="white-space:pre-wrap;font:inherit;color:var(--muted);margin:6px 0 0;line-height:1.95">{{ $m->body }}</pre>
          </div>
          @if($m->error)<div style="color:var(--red);font-size:12.5px;margin-top:8px" dir="ltr">{{ $m->error }}</div>@endif

          @if($m->status === 'queued')
            <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">
              <form method="post" action="/admin/marketing/message/{{ $m->id }}/approve" data-confirm="ارسالِ این ایمیل؟">@csrf
                <button class="btn btn-primary" type="submit">تأیید و ارسال</button>
              </form>
              <form method="post" action="/admin/marketing/message/{{ $m->id }}/reject">@csrf
                <button class="mk-btn" type="submit">رد</button>
              </form>
            </div>
          @elseif($m->status === 'draft')
            <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap;align-items:center">
              <button class="mk-btn js-copy" type="button" data-copy="msg-{{ $m->id }}"><svg class="icon"><use href="#i-file"/></svg>کپی متن</button>
              <textarea id="msg-{{ $m->id }}" readonly style="position:absolute;left:-9999px;top:-9999px">{{ $m->body }}</textarea>
              <form method="post" action="/admin/marketing/message/{{ $m->id }}/sent">@csrf
                <button class="btn btn-primary" type="submit">فرستادم</button>
              </form>
              <form method="post" action="/admin/marketing/message/{{ $m->id }}/reject">@csrf
                <button class="mk-btn danger" type="submit">دور بینداز</button>
              </form>
              <small style="color:var(--dim);font-size:12px">{{ mb_strlen($m->body) }} نویسه</small>
            </div>
          @endif
        </div>
      @empty
        <div class="mk-empty" style="padding:30px 20px">
          <svg class="icon"><use href="#i-message"/></svg>
          <b>هنوز پیامی رد و بدل نشده</b>
          <p>اول «بررسیِ سایتشان» را بزن تا مشاهده ثبت شود، بعد «نوشتنِ ایمیلِ بعدی».</p>
        </div>
      @endforelse
    </div>
  </div>

  {{-- ══ تغییرِ دستیِ مرحله ══ --}}
  <div class="ad-panel" style="margin-top:16px">
    <div class="ad-panel-h"><h2>تغییرِ دستیِ مرحله</h2></div>
    <form method="post" action="/admin/marketing/{{ $lead->id }}/stage" class="mk-form" style="padding:16px 18px">
      @csrf
      <div>
        <label>مرحله</label>
        <select name="stage">
          @foreach(\App\Models\CrmLead::STAGES as $key => $label)
            <option value="{{ $key }}" @selected($lead->stage === $key)>{{ $label }}</option>
          @endforeach
        </select>
      </div>
      <div>
        <label>پکیج</label>
        <select name="offer">
          <option value="">—</option>
          @foreach(array_keys((array) config('crm.offers')) as $offer)
            <option value="{{ $offer }}" @selected($lead->offer === $offer)>{{ $offer }}</option>
          @endforeach
        </select>
      </div>
      <div><label>ارزش (یورو)</label><input name="value" type="number" min="0" dir="ltr" value="{{ $lead->value_eur }}"></div>
      <div><label>دلیلِ ازدست‌رفتن</label><input name="reason" value="{{ $lead->lost_reason }}" placeholder="اختیاری"></div>
      <div style="align-self:end"><button class="btn btn-primary" type="submit">ثبت</button></div>
    </form>
  </div>

</div>
@endsection

@section('scripts')
<script>
/*
 * کپیِ متنِ پیش‌نویس.
 *
 * ⚠️ navigator.clipboard فقط روی HTTPS (یا localhost) کار می‌کند و روی HTTP
 * بی‌صدا رد می‌شود — که دقیقاً همان الگویی است که این پروژه سه بار خورده.
 * پس مسیرِ دوم هم هست، و اگر هر دو شکست خوردند به کاربر گفته می‌شود.
 */
document.addEventListener('click', function (e) {
  var btn = e.target.closest('.js-copy');
  if (!btn) return;

  var el = document.getElementById(btn.dataset.copy);
  if (!el) return;

  var done = function () {
    var old = btn.innerHTML;
    btn.textContent = 'کپی شد ✓';
    setTimeout(function () { btn.innerHTML = old; }, 1600);
  };

  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(el.value).then(done, fallback);
  } else {
    fallback();
  }

  function fallback() {
    try {
      el.style.position = 'static'; el.style.left = 'auto'; el.style.top = 'auto';
      el.select();
      var ok = document.execCommand('copy');
      el.style.position = 'absolute'; el.style.left = '-9999px'; el.style.top = '-9999px';
      ok ? done() : (btn.textContent = 'کپی نشد — دستی بردار');
    } catch (err) {
      btn.textContent = 'کپی نشد — دستی بردار';
    }
  }
});
</script>
@endsection

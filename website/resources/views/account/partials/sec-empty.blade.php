{{--
  حالتِ خالی — دو شکل، عمداً.

  🔴 `full = true` (داخلِ اتاقِ خودش): جعبهٔ کاملِ `.pnl-empty` — آیکن، تیتر،
  یک بندِ ۳۸ نویسه‌ای که می‌گوید این بخش **به چه دردی می‌خورد**، و یک دکمه.
  کاربر خودش این در را باز کرده، پس جواب کامل می‌خواهد.

  🔴 `full = false` (نمای «همه»): یک خطِ کم‌رنگ با لینکِ درون‌خطی. چهار جعبهٔ
  ۴۴پیکسلیِ روی‌هم برای مشتریِ تازه یک دیوارِ هیچ است.

  ورودی: $full، $icon، $h، $p، $cta، $url، $short
--}}
@if($full)
  <div class="pnl-empty">
    <svg class="icon"><use href="#i-{{ $icon }}"/></svg>
    <b>{{ $h }}</b>
    <p>{{ $p }}</p>
    @if($url)<a class="pnl-btn primary" href="{{ $url }}">{{ $cta }}</a>@endif
  </div>
@else
  <p class="svc-none">{{ $short }} @if($url)<a href="{{ $url }}">{{ $cta }}</a>@endif</p>
@endif

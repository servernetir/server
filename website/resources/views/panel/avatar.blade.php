{{--
  آواتار کاربر.

  حرف اول همیشه رندر می‌شود و تصویر روی آن می‌نشیند. اگر گراواتاری وجود
  نداشته باشد (۴۰۴) تصویر بار نمی‌شود و حرف سر جایش می‌ماند — بدون پرش
  چیدمان و بدون آواتار ژنریکِ بی‌روح.

  onerror لازم است چون ۴۰۴ را نمی‌شود با CSS گرفت.
--}}
@php
  $av   = $user['avatar'] ?? null;
  $init = initials($user['name'] ?? null, $user['email'] ?? null);
  // رنگ از روی نام: هر کاربر رنگ ثابت خودش را دارد، نه رنگ تصادفی هر بار
  $hue  = filled($user['name'] ?? null) ? (crc32($user['name']) % 360) : 200;
@endphp

<span class="pnl-avatar" style="--av-h:{{ $hue }}" aria-hidden="true">
  <i>{{ $init }}</i>
  @if($av)
    <img src="{{ $av }}" alt="" loading="lazy" referrerpolicy="no-referrer"
         onload="this.classList.add('on')" onerror="this.remove()">
  @endif
</span>

{{--
  سوییچرِ بخش‌ها — «همه · هاست · سرور · دامنه · خدمات».

  🔴 `<a>` است نه `<button>`: هر بخش نشانیِ خودش را دارد، پس این کنترل بدونِ
  جاوااسکریپت کار می‌کند، بوکمارک می‌شود و دکمهٔ بازگشتِ مرورگر راست می‌گوید.
  (همان دلیلی که کشوی موبایلِ پنل هم فقط به `a` بسته شده است.)

  🔴 شمارش این‌جاست و نه در منوی کناری: `AccountController::shell()` روی **هر**
  صفحهٔ پنل اجرا می‌شود، پس شمارشِ per-kind آن‌جا یعنی سه پرس‌وجوی اضافه روی
  تیکت‌ها و فاکتورها هم. این‌جا مجموعه از قبل در حافظه است و شمارش رایگان.

  ورودی: $secCounts (آرایهٔ شمارش)، $secLens (بخشِ جاری)
--}}
@php
  $lensNow = $secLens ?? 'all';

  $lensItems = [
      ['k' => 'all',     'label' => __('ui.sec_all'),     'url' => lroute('account.services'), 'n' => $secCounts['all'] ?? 0],
      ['k' => 'hosting', 'label' => __('ui.sec_hosting'), 'url' => lroute('account.hosting'),  'n' => $secCounts['hosting'] ?? 0],
      ['k' => 'servers', 'label' => __('ui.sec_servers'), 'url' => lroute('account.servers'),  'n' => $secCounts['server'] ?? 0],
      ['k' => 'domains', 'label' => __('ui.sec_domains'), 'url' => lroute('account.domains'),  'n' => $secCounts['domains'] ?? 0],
      ['k' => 'other',   'label' => __('ui.sec_other'),   'url' => lroute('account.other'),    'n' => $secCounts['other'] ?? 0],
  ];
@endphp

<nav class="svc-lens" aria-label="{{ __('ui.sec_lens_label') }}">
  @foreach($lensItems as $it)
    <a class="svc-lens-i {{ $it['k'] === $lensNow ? 'on' : '' }}"
       href="{{ $it['url'] }}"
       @if($it['k'] === $lensNow) aria-current="page" @endif>
      <span>{{ $it['label'] }}</span>
      <em>{{ fa_num($it['n']) }}</em>
    </a>
  @endforeach
</nav>

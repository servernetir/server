{{--
  پرچمِ کشور — SVGِ خودمیزبان، با بازگشتِ امن به اموجی.

  ورودی‌ها (همه اختیاری، همه با پیشوندِ flag تا با متغیرهای ویوِ میزبان قاطی نشوند):
    $flagSrc   مسیرِ ریشه‌نسبیِ SVG یا null  ← CloudLocation::flagSvg()
    $flagAlt   نامِ کشور، فقط برای جاهایی که نام کنارش نوشته نشده (پیش‌فرض: خالی)
    $flagSize  اندازه به پیکسل — از همان مقیاسی که ویوها اعلام کرده‌اند: ۳۴ / ۲۴ / ۱۸
    $flagEmoji اموجیِ همان کشور، برای وقتی فایلِ SVG نیست
    $flagEager تصویرِ بالای صفحه را eager بگذار تا در نخستین نقاشی حاضر باشد

  ⚠️ دو ادعای دقیق در همین چند خط:
  · `width`/`height` **هم صفت و هم استایل**اند. صفت‌ها نسبتِ تصویر را پیش از
    بارگذاری رزرو می‌کنند (جهش نمی‌کند)، و استایلِ درون‌خطی جلوی هر قاعدهٔ عمومیِ
    `img{height:auto}` را در شیت‌های آینده می‌گیرد. بی‌آن، پرچم روی یک صفحه
    کشیده می‌شد و روی صفحهٔ دیگر نه.
  · `alt=""` + `aria-hidden` عمدی است: نامِ کشور **همیشه** کنارِ همین تصویر
    نوشته شده، پس هر متنِ جایگزینی یعنی صفحه‌خوان کشور را دو بار بگوید.
    اگر روزی جایی پرچم بی‌نام رندر شد، `$flagAlt` را بده.
--}}
@php
    /* ⚠️ `img_url()`: مقدارِ خرابِ رشتهٔ «null» از `@if($flagSrc)` رد می‌شود و
       `src="null"` می‌سازد — که مرورگر نسبی حل می‌کند و روی `/cloud/<slug>`
       یک ۴۰۴ روی `/cloud/null` می‌سازد. این پارشال روی همهٔ صفحاتِ ابری است. */
    $flagSrc   = img_url($flagSrc ?? null);
    $flagAlt   = trim((string) ($flagAlt ?? ''));
    $flagSize  = max(8, (int) ($flagSize ?? 18));
    $flagEmoji = $flagEmoji ?? null;
    $flagEager = (bool) ($flagEager ?? false);
@endphp
@if($flagSrc)<img src="{{ $flagSrc }}" width="{{ $flagSize }}" height="{{ $flagSize }}" alt="{{ $flagAlt }}"@if($flagAlt === '') aria-hidden="true"@endif decoding="async" loading="{{ $flagEager ? 'eager' : 'lazy' }}" style="width:{{ $flagSize }}px;height:{{ $flagSize }}px;flex:0 0 auto;vertical-align:-.16em;border-radius:50%">@elseif(filled($flagEmoji))<span aria-hidden="true">{{ $flagEmoji }}</span>@endif

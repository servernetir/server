<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $subjectLine }}</title>
</head>
{{--
  ══ چرا این قالب تا این حد ساده است ══

  🔴 این نامه **جوابِ یک آدم** است، نه اعلانِ سیستم. قالبِ برنددارِ رنگی با
  هدر و فوتر، در رشتهٔ گفتگو مثلِ تبلیغات دیده می‌شود و طرف را از جواب دور
  می‌کند. پس فقط چیزی که کارفرما نوشته، با کمی نفس‌کشیدن.

  ⚠️ **همهٔ استایل‌ها درون‌خطی‌اند و باید بمانند.** جیمیل و اوت‌لوک تگِ
  `<style>` را در بدنه دور می‌ریزند؛ چیزی که آن‌جا بنویسی روی سایت درست
  دیده می‌شود و در صندوقِ مشتری هیچ.

  ⚠️ `{!! !!}` عمدی است و **فقط** روی خروجیِ `HtmlSanitizer` می‌نشیند
  (`MailboxReplier::composeHtml()`). اگر روزی این خط جابه‌جا شد، پاک‌سازی
  باید همراهش برود.
--}}
<body style="margin:0;padding:0;background:#ffffff">
  <div style="max-width:640px;margin:0 auto;padding:18px 20px;font-family:Tahoma,Arial,sans-serif;font-size:14px;line-height:1.9;color:#1a1a1a">

    {!! $bodyHtml !!}

    @if(trim($signature) !== '')
      <div style="margin-top:22px;padding-top:14px;border-top:1px solid #e5e5e5;color:#555;font-size:13px;line-height:1.8">
        {!! nl2br(e($signature)) !!}
      </div>
    @endif

    @if(trim($quoted) !== '')
      {{--
        نقلِ نامهٔ اصلی. عمداً کم‌رنگ و با خطِ کناری، تا جوابِ تازه اول دیده
        شود نه بعد از یک دیوارِ متنِ تکراری.
      --}}
      <div style="margin-top:24px;color:#777;font-size:12.5px">در پاسخ به (بخشی از نامهٔ شما):</div>
      <blockquote style="margin:8px 0 0;padding:2px 12px;border-inline-start:3px solid #dddddd;color:#666;font-size:13px;line-height:1.8">
        {!! nl2br(e($quoted)) !!}
      </blockquote>
    @endif

  </div>
</body>
</html>

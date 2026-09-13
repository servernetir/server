<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow,noarchive"><title>نمایش امن شماره کارت</title></head>
<body style="font-family:Tahoma,sans-serif;background:#0f172a;color:#e2e8f0;padding:32px;text-align:center">
<h1 style="font-size:18px">شماره کارت {{ $customer->code }}</h1>
<p>این نمایش ثبت شده است؛ شماره را در تیکت، پیام یا یادداشت کپی نکنید.</p>
<div dir="ltr" style="font:700 26px monospace;letter-spacing:2px;background:#1e293b;padding:20px;border-radius:12px">{{ chunk_split($pan, 4, ' ') }}</div>
<p style="opacity:.7">حساب بانکی #{{ $bankAccount->id }} · این صفحه cache نمی‌شود.</p>
<button onclick="window.close()">بستن</button>
</body></html>

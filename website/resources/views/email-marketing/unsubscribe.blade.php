<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'fa' ? 'rtl' : 'ltr' }}">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer">
  <title>{{ __('email-marketing.unsubscribe') }}</title>
</head>
<body style="margin:0;padding:24px;background:#eef4f7;color:#17324d;font-family:Tahoma,Arial,sans-serif;line-height:1.9">
  <main style="max-width:500px;margin:36px auto;border:1px solid #dbe5eb;border-radius:16px;background:white;padding:24px">
    <h1 style="font-size:23px;line-height:1.6">{{ __('email-marketing.unsubscribe') }}</h1>
    @if($done)
      <p role="status">{{ __('email-marketing.unsubscribe_done') }}</p>
    @elseif($invalid ?? false)
      <p role="alert">{{ __('email-marketing.unsubscribe_invalid') }}</p>
    @else
      <p>{{ __('email-marketing.unsubscribe_confirm') }}</p>
      <form method="post" action="{{ lroute('email-marketing.unsubscribe.submit', ['token' => $token]) }}">
        <button type="submit" style="border:0;border-radius:8px;padding:12px 18px;background:#0b8280;color:white;font:inherit;cursor:pointer">{{ __('email-marketing.unsubscribe') }}</button>
      </form>
    @endif
    <p>{{ __('email-marketing.transactional_note') }}</p>
  </main>
</body>
</html>

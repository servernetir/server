<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'fa' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="referrer" content="no-referrer">
    <title>{{ __('email-campaigns.unsubscribe_title') }} — ServerNet</title>
    <style>body{margin:0;background:#f3f6fa;color:#172338;font:17px/1.8 system-ui,sans-serif}main{max-width:560px;margin:12vh auto;padding:32px;background:#fff;border:1px solid #dbe3ee;border-radius:20px}button{border:0;border-radius:10px;padding:12px 24px;background:#087f8c;color:#fff;font:inherit;cursor:pointer}h1{font-size:26px}small{color:#637187}</style>
</head>
<body><main>
    <small>ServerNet</small>
    <h1>{{ __('email-campaigns.unsubscribe_title') }}</h1>
    @if($complete)
        <p role="status">{{ __('email-campaigns.unsubscribe_done') }}</p>
    @else
        <p>{{ __('email-campaigns.unsubscribe_body') }}</p>
        <form method="post">
            <button type="submit">{{ __('email-campaigns.unsubscribe_button') }}</button>
        </form>
    @endif
</main></body>
</html>

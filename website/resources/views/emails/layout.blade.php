@php
  // جهت بر اساس زبانِ Mailable (locale). فارسی راست‌به‌چپ، بقیه چپ‌به‌راست.
  $dir  = app()->getLocale() === 'fa' ? 'rtl' : 'ltr';
  $brand = app()->getLocale() === 'fa' ? 'سرورنت' : 'ServerNet';
  $c = site_contact();
  // فونت: در ایمیل فونت سفارشی بارگذاری نمی‌شود؛ Tahoma فارسی را خوب می‌سازد
  $font = "Tahoma, 'Segoe UI', Arial, sans-serif";
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $dir }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<title>@yield('title', $brand)</title>
</head>
<body style="margin:0; padding:0; background:#eef1f6; -webkit-text-size-adjust:100%;">
  <!-- پیش‌نمایشِ مخفیِ اینباکس -->
  <div style="display:none; max-height:0; overflow:hidden; opacity:0;">@yield('preview', $brand)</div>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef1f6;">
    <tr>
      <td align="center" style="padding:28px 14px;">

        <table role="presentation" width="600" cellpadding="0" cellspacing="0" dir="{{ $dir }}"
               style="width:600px; max-width:100%; background:#ffffff; border-radius:16px; overflow:hidden;
                      box-shadow:0 6px 26px rgba(20,30,50,.10); font-family:{{ $font }};">

          <!-- ══ سربرگ برند ══ -->
          <tr>
            <td style="padding:24px 30px 18px; background:#ffffff; border-bottom:1px solid #eef1f6;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td align="{{ $dir === 'rtl' ? 'right' : 'left' }}">
                    <span style="font-size:22px; font-weight:800; color:#0b1220; letter-spacing:.3px;">
                      <span style="color:#0891b2;">●</span> {{ $brand }}
                    </span>
                    <span style="display:block; font-size:11.5px; color:#8a93a6; margin-top:3px;">servernet.cloud · زیرساخت ابری</span>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- ══ محتوا ══ -->
          <tr>
            <td style="padding:30px; color:#1a2233; font-size:14.5px; line-height:1.9;">
              @yield('content')
            </td>
          </tr>

          <!-- ══ پاورقی ══ -->
          <tr>
            <td style="padding:20px 30px; background:#f6f8fb; border-top:1px solid #eef1f6;
                       font-size:12px; color:#8a93a6; line-height:1.8;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td align="{{ $dir === 'rtl' ? 'right' : 'left' }}">
                    <div style="color:#5b6577;">{{ __('ui.email_footer_note') }}</div>
                    @if(!empty($c['email']) || !empty($c['phone']))
                      <div style="margin-top:6px;" dir="ltr">
                        @if(!empty($c['email'])){{ $c['email'] }}@endif
                        @if(!empty($c['email']) && !empty($c['phone'])) &nbsp;·&nbsp; @endif
                        @if(!empty($c['phone'])){{ $c['phone'] }}@endif
                      </div>
                    @endif
                    <div style="margin-top:8px;">
                      <a href="https://servernet.cloud" style="color:#0891b2; text-decoration:none;">servernet.cloud</a>
                      &nbsp;·&nbsp; {{ __('ui.email_footer_rights') }}
                    </div>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

        </table>

      </td>
    </tr>
  </table>
</body>
</html>

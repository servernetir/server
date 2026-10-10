@if(\App\Services\Auth\GoogleIdentity::configured() && !session('google_registration'))
  <a class="auth-btn" href="{{ lroute('google.start', !empty($phoneImport) ? ['phone' => 1] : []) }}" style="margin-bottom:12px;background:#fff;color:#202124;border:1px solid #dadce0;text-decoration:none;display:flex;align-items:center;justify-content:center;gap:10px">
    <svg aria-hidden="true" width="20" height="20" viewBox="0 0 48 48"><path fill="#4285F4" d="M43.61 24.46c0-1.36-.12-2.66-.35-3.92H24v7.43h11a9.41 9.41 0 0 1-4.08 6.18v5.13h6.62c3.87-3.56 6.07-8.8 6.07-14.82Z"/><path fill="#34A853" d="M24 44c5.51 0 10.13-1.83 13.51-4.72l-6.62-5.13c-1.83 1.23-4.17 1.98-6.89 1.98-5.3 0-9.8-3.58-11.41-8.41H5.76v5.29A20 20 0 0 0 24 44Z"/><path fill="#FBBC05" d="M12.59 27.72A12 12 0 0 1 12 24c0-1.3.21-2.55.59-3.72v-5.29H5.76A20 20 0 0 0 4 24c0 3.23.77 6.28 1.76 9.01l6.83-5.29Z"/><path fill="#EA4335" d="M24 11.87c3 0 5.68 1.04 7.79 3.08l5.83-5.83C34.13 5.86 29.51 4 24 4A20 20 0 0 0 5.76 14.99l6.83 5.29C14.2 15.45 18.7 11.87 24 11.87Z"/></svg>
    <span>{{ __('google.continue') }}</span>
  </a>
  <p style="font-size:12px;line-height:1.8;margin:0 0 18px">{{ __('google.note') }}</p>
  @if(!empty($phoneImport))
    <p style="font-size:12px;line-height:1.8;margin:0 0 18px">{{ __('google.phone_signup_note') }}</p>
  @endif
@endif

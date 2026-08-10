{{--
  عمل‌های مشترکِ هر ردیف: پرداختِ فاکتورِ باز، لغوِ سفارشِ تحویل‌نشده، و حذفِ
  دومرحله‌ایِ سرویسِ تحویل‌شده.

  ورودی: $s (Service)، $kind ('hosting'|'server'|'other')

  🔴 سه چیز این‌جا **نباید** عوض شود:

   ۱) «لغو سفارش» راهِ فرارِ سفارشِ گیرکرده است. بی‌آن، سفارشی که تحویلش شکسته
      تا ابد «در حالِ آماده‌سازی» می‌ماند و مشتری نه سرور دارد نه پولش.
   ۲) دو شرط **ناسازگارند**: چیزی که لغو می‌شود هرگز حذف نمی‌شود و برعکس.
   ۳) مرحلهٔ دومِ حذف فقط برای همان سرویسی رندر می‌شود که برایش کد گرفته شده،
      و کد سمتِ سرور هم به همان شناسه گره خورده — پس کدِ یک سرویس، سرویسِ
      دیگری را پاک نمی‌کند.
--}}
@php
  $unpaid = $s->invoices->firstWhere('status', 'unpaid');

  /* 🔴 «هنوز تحویل نشده» سومین حالتِ لازم بود.
     `finalize()` سرِ پذیرشِ سفارش `active` + `done` می‌نویسد، پس سرویسی که
     ماشینش هنوز نیست در شرطِ قدیمی **حذف‌شدنی** بود و **لغوشدنی نبود** —
     یعنی مشتریِ پول‌داده فقط یک دکمهٔ قرمزِ بی‌اثر می‌دید و هیچ راهی به
     پولش نداشت. تعریف از مدل می‌آید تا با کنترلر یکی بمانَد. */
  $pendingDelivery = $s->cloudUndelivered();

  $cancellable = in_array($s->status, ['awaiting_provision', 'provision_failed'], true)
      || ($s->status === 'active' && $s->provision_status === 'failed')
      || ($pendingDelivery && in_array($s->status, ['active', 'suspended', 'expired'], true));

  /* ⚠️ دو شرط ناسازگار می‌مانند: `$pendingDelivery` از یکی کم و به دیگری
     اضافه می‌کند، پس هیچ سرویسی هم‌زمان هر دو را نمی‌گیرد. */
  $terminable = in_array($s->status, ['active', 'suspended', 'expired'], true)
      && $s->provision_status !== 'failed'
      && ! $pendingDelivery;

  /* دکمهٔ حذفِ خاموش فقط در همان **پنجرهٔ خرابی** نشان داده می‌شود: جایی که
     بدونِ این تغییر یک دکمهٔ فعالِ بی‌اثر رندر می‌شد. سفارشی که هنوز
     `awaiting_provision` است هیچ‌وقت دکمهٔ حذف نداشته، پس افزودنِ یک دکمهٔ
     خاموش به آن فقط نویز است — و بدتر، متنش («در حال تحویل») برای سفارشی که
     به بازبینی رفته **نادرست** است. */
  $deliveryLocked = $pendingDelivery
      && in_array($s->status, ['active', 'suspended', 'expired'], true)
      && $s->provision_status !== 'failed';

  /*
   | متنِ تأیید به **نوعِ** سرویس بستگی دارد.
   |
   | کلیدِ یگانهٔ قبلی می‌گفت «سرور و تمام داده‌های روی آن برای همیشه پاک
   | می‌شود» و روی ردیف‌های صرفاً مالی هم چاپ می‌شد — سرویسی که اصلاً سروری
   | ندارد. متنِ ترسناکِ نادرست، همان‌قدر بد است که متنِ نرمِ نادرست.
   */
  $confirmKey = match ($kind) {
      'hosting' => 'ui.svc_terminate_confirm_hosting',
      'server'  => 'ui.svc_terminate_confirm',
      default   => 'ui.svc_terminate_confirm_other',
  };

  $otpOpen = (int) session('svc_terminate_ctx.service_id') === (int) $s->id;
@endphp

<div class="pnl-acts svc-acts">
  @if($unpaid)
    <a class="pnl-btn primary" href="{{ lroute('account.invoice', $unpaid) }}">{{ __('ui.svc_pay') }}</a>
  @endif

  @if($cancellable)
    <form method="post" action="{{ lroute('account.services.cancel', $s) }}"
          data-confirm="{{ __('ui.svc_cancel_confirm') }}" data-confirm-danger>
      @csrf
      <button class="pnl-btn danger">{{ __('ui.svc_cancel') }}</button>
    </form>
  @endif

  @if($deliveryLocked)
    {{-- 🔴 دکمهٔ حذف **با علتِ گفته‌شده** خاموش است، نه غایب.
         افورد‌نسی که بی‌توضیح ناپدید شود، مشتری را به تیکت می‌فرستد؛ و
         افورد‌نسی که بزنی و هیچ اتفاقی نیفتد از هر دو بدتر است. راهِ خروجِ
         سفارشِ گیرکرده دقیقاً کنارش است: دکمهٔ «لغو سفارش» با بازگشتِ پول. --}}
    <button class="pnl-btn danger" disabled aria-disabled="true">{{ __('ui.svc_terminate') }}</button>
    <p class="svc-note warn">{{ __('ui.svc_terminate_locked') }}</p>
  @endif

  @if($terminable)
    <form method="post" action="{{ lroute('account.services.terminate.start', $s) }}"
          data-confirm="{{ __($confirmKey) }}" data-confirm-danger
          data-confirm-ok="{{ __('ui.svc_terminate_ok') }}">
      @csrf
      <button class="pnl-btn danger">{{ __('ui.svc_terminate') }}</button>
    </form>
  @endif
</div>

@if($otpOpen)
  <div class="svc-otp-box">
    <form method="post" action="{{ lroute('account.services.terminate', $s) }}" class="svc-otp">
      @csrf
      <p class="svc-note warn">{{ __('ui.svc_terminate_otp_hint') }}</p>

      {{-- دلیلِ حذف — **اختیاری**. برای بازاریابی لازم است، ولی مشتری در این
           لحظه از ما ناراضی است و فیلدِ اجباری یک دیوار است؛ پس بی‌انتخاب هم
           حذف انجام می‌شود. کدِ پایدار ذخیره می‌شود نه این متن. --}}
      <div class="svc-otp-why">
        <p class="svc-otp-why-h">{{ __('ui.svc_del_reason_h') }}</p>
        <p class="svc-otp-why-p">{{ __('ui.svc_del_reason_lead') }}</p>

        <select name="reason" class="svc-input">
          <option value="">{{ __('ui.svc_del_reason_skip') }}</option>
          @foreach(\App\Models\Service::terminateReasonCodes() as $rc)
            <option value="{{ $rc }}">{{ __('ui.svc_del_reason_'.$rc) }}</option>
          @endforeach
        </select>

        <textarea name="reason_note" rows="2" maxlength="500" class="svc-input svc-ta"
                  placeholder="{{ __('ui.svc_del_reason_note') }}"></textarea>
        @error('reason')<p class="svc-note warn">{{ $message }}</p>@enderror
        @error('reason_note')<p class="svc-note warn">{{ $message }}</p>@enderror
      </div>

      <div class="svc-otp-go">
        <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code"
               maxlength="12" required dir="ltr" placeholder="------" class="svc-code">
        <button class="pnl-btn danger">{{ __('ui.svc_terminate_final') }}</button>
      </div>
      @error('code')<p class="svc-note warn">{{ $message }}</p>@enderror
    </form>
  </div>
@endif

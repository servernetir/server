{{--
  عمل‌های مشترکِ هر ردیف: پرداختِ فاکتورِ باز، لغوِ سفارشِ تحویل‌نشده، و حذفِ
  دومرحله‌ایِ سرویسِ تحویل‌شده.

  ورودی: $s (Service)، $kind ('hosting'|'server'|'other')

  🔴 سه چیز این‌جا **نباید** عوض شود:

   ۱) «لغو سفارش» راهِ فرارِ سفارشِ گیرکرده است. بی‌آن، سفارشی که تحویلش شکسته
      تا ابد «در حالِ آماده‌سازی» می‌ماند و مشتری نه سرور دارد نه پولش.
   ۲) دو شرط **ناسازگارند**: چیزی که لغو می‌شود هرگز حذف نمی‌شود و برعکس.
   ۳) تأیید حذف به همان سرویس، نشست و فرم با مهلت محدود گره می‌خورد.
--}}
@php
  $unpaid = $s->hasUnverifiedBillingTerms() ? null : $s->invoices->firstWhere('status', 'unpaid');

  /* 🔴 «هنوز تحویل نشده» سومین حالتِ لازم بود.
     `finalize()` سرِ پذیرشِ سفارش `active` + `done` می‌نویسد، پس سرویسی که
     ماشینش هنوز نیست در شرطِ قدیمی **حذف‌شدنی** بود و **لغوشدنی نبود** —
     یعنی مشتریِ پول‌داده فقط یک دکمهٔ قرمزِ بی‌اثر می‌دید و هیچ راهی به
     پولش نداشت. تعریف از مدل می‌آید تا با کنترلر یکی بمانَد. */
  $pendingDelivery = $s->cloudUndelivered();
  $removalProtected = $s->isInternalInfrastructure() || $s->hasUnverifiedBillingTerms();

  $cancellable = ! $removalProtected
      && (in_array($s->status, ['awaiting_provision', 'provision_failed'], true)
      || ($s->status === 'active' && $s->provision_status === 'failed')
      || ($pendingDelivery && in_array($s->status, ['active', 'suspended', 'expired'], true)));

  /* ⚠️ دو شرط ناسازگار می‌مانند: `$pendingDelivery` از یکی کم و به دیگری
     اضافه می‌کند، پس هیچ سرویسی هم‌زمان هر دو را نمی‌گیرد. */
  $terminable = ! $removalProtected
      && in_array($s->status, ['active', 'suspended', 'expired'], true)
      && $s->provision_status !== 'failed'
      && ! $pendingDelivery;

  /* دکمهٔ حذفِ خاموش فقط در همان **پنجرهٔ خرابی** نشان داده می‌شود: جایی که
     بدونِ این تغییر یک دکمهٔ فعالِ بی‌اثر رندر می‌شد. سفارشی که هنوز
     `awaiting_provision` است هیچ‌وقت دکمهٔ حذف نداشته، پس افزودنِ یک دکمهٔ
     خاموش به آن فقط نویز است — و بدتر، متنش («در حال تحویل») برای سفارشی که
     به بازبینی رفته **نادرست** است. */
  $deliveryLocked = ! $removalProtected && $pendingDelivery
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

  $deleteContext = session('svc_terminate_ctx', []);
  $confirmationOpen = $terminable
      && (int) ($deleteContext['service_id'] ?? 0) === (int) $s->id
      && is_string($deleteContext['phrase'] ?? null)
      && is_string($deleteContext['token'] ?? null)
      && (int) ($deleteContext['expires_at'] ?? 0) > now()->timestamp
      && ((int) session('svc_terminate_open') === (int) $s->id
          || (int) old('termination_service_id') === (int) $s->id);
@endphp

<div class="pnl-acts svc-acts">
  @if($removalProtected && ! $s->isDead())
    <p class="svc-note warn">{{ __($s->hasUnverifiedBillingTerms() ? 'ui.svc_existing_management_notice' : 'ui.svc_removal_protected') }}</p>
  @endif

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
    <form method="post" action="{{ lroute('account.services.terminate.start', $s) }}">
      @csrf
      <button class="pnl-btn danger">{{ __('ui.svc_terminate') }}</button>
    </form>
  @endif
</div>

@once
  @push('head')
    <link rel="stylesheet" href="{{ asset_ver('assets/css/service-delete.css') }}">
    <script src="{{ asset_ver('assets/js/service-delete.js') }}" defer></script>
  @endpush
@endonce

@if($confirmationOpen)
  <dialog open class="svc-delete-dialog" data-service-delete aria-labelledby="svc-delete-title-{{ $s->id }}"
          aria-describedby="svc-delete-warning-{{ $s->id }}">
    <form method="post" action="{{ lroute('account.services.terminate', $s) }}" data-delete-form>
      @csrf
      <input type="hidden" name="termination_service_id" value="{{ $s->id }}">
      <input type="hidden" name="confirmation_token" value="{{ $deleteContext['token'] }}">
      <div class="svc-delete-header">
        <h2 id="svc-delete-title-{{ $s->id }}">{{ __('ui.svc_terminate') }}</h2>
        <button type="button" class="svc-delete-close" data-delete-cancel aria-label="{{ __('ui.dlg_cancel') }}">×</button>
      </div>
      <p class="svc-delete-intro">{{ __('ui.svc_terminate_question', ['name' => $s->name]) }}</p>
      <div class="svc-delete-warning" id="svc-delete-warning-{{ $s->id }}">
        <p>{{ __($confirmKey) }}</p>
        <p>{{ __('ui.svc_terminate_billing_warning') }}</p>
      </div>
      <label for="svc-delete-phrase-{{ $s->id }}" class="svc-delete-label">{{ __('ui.svc_terminate_phrase_label') }}</label>
      <p class="svc-delete-phrase" data-delete-phrase>{{ $deleteContext['phrase'] }}</p>
      <textarea id="svc-delete-phrase-{{ $s->id }}" name="confirmation_phrase" rows="2" maxlength="400"
                class="svc-input" required autocomplete="off" spellcheck="false" data-delete-input
                aria-describedby="svc-delete-phrase-help-{{ $s->id }}">{{ old('confirmation_phrase') }}</textarea>
      <p class="svc-delete-help" id="svc-delete-phrase-help-{{ $s->id }}">{{ __('ui.svc_terminate_phrase_hint') }}</p>
      @error('confirmation_phrase')<p class="svc-note warn" role="alert">{{ \App\Support\PanelText::text($message) }}</p>@enderror
      @error('confirmation_token')<p class="svc-note warn" role="alert">{{ \App\Support\PanelText::text($message) }}</p>@enderror

      <details class="svc-delete-reason" @if($errors->has('reason') || $errors->has('reason_note')) open @endif>
        <summary>{{ __('ui.svc_del_reason_h') }}</summary>
        <p class="svc-note">{{ __('ui.svc_del_reason_lead') }}</p>
        <label class="svc-delete-label" for="svc-delete-reason-{{ $s->id }}">{{ __('ui.svc_del_reason_skip') }}</label>
        <select name="reason" id="svc-delete-reason-{{ $s->id }}" class="svc-input">
          <option value="">{{ __('ui.svc_del_reason_skip') }}</option>
          @foreach(\App\Models\Service::terminateReasonCodes() as $rc)
            <option value="{{ $rc }}" @selected(old('reason') === $rc)>{{ __('ui.svc_del_reason_'.$rc) }}</option>
          @endforeach
        </select>
        <label class="svc-delete-label" for="svc-delete-note-{{ $s->id }}">{{ __('ui.svc_del_reason_note') }}</label>
        <textarea name="reason_note" id="svc-delete-note-{{ $s->id }}" rows="2" maxlength="500" class="svc-input svc-ta">{{ old('reason_note') }}</textarea>
        @error('reason')<p class="svc-note warn" role="alert">{{ \App\Support\PanelText::text($message) }}</p>@enderror
        @error('reason_note')<p class="svc-note warn" role="alert">{{ \App\Support\PanelText::text($message) }}</p>@enderror
      </details>
      <div class="svc-delete-footer">
        <a class="pnl-btn" href="{{ lroute('account.services') }}" data-delete-cancel>{{ __('ui.dlg_cancel') }}</a>
        <button type="submit" class="pnl-btn danger" data-delete-submit>{{ __('ui.svc_terminate_final') }}</button>
      </div>
    </form>
  </dialog>
@endif

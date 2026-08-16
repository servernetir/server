{{--
  امضای «نردبانِ سطحِ نمایندگی».

  🔴 اعداد از `config/domain_reseller.levels` می‌آیند — همان آرایه‌ای که
  `ResellerProgram` برای محاسبهٔ سطح و `ResellerPricing` برای تخفیف می‌خواند.

  چرا این‌قدر مهم است: اگر پله‌ها این‌جا دستی نوشته می‌شدند، اولین باری که
  کارفرما آستانه‌ای را در config عوض کند، صفحهٔ فروش عددِ قدیمی را تبلیغ
  می‌کرد و پنلِ نماینده عددِ جدید را نشان می‌داد. آن اختلاف هیچ خطایی تولید
  نمی‌کند و تنها راهِ کشفش شکایتِ نماینده‌ای است که حس می‌کند سرش کلاه رفته —
  یعنی گران‌ترین راهِ ممکن. یک منبعِ حقیقت، همیشه.
--}}
@php
  $tiers = (array) config('domain_reseller.levels', []);
  usort($tiers, fn ($a, $b) => ($a['min_spend_irt'] ?? 0) <=> ($b['min_spend_irt'] ?? 0));

  $window = (int) config('domain_reseller.window_months', 12);
  $grace  = (int) config('domain_reseller.demote_grace_days', 30);
  $isFaLocale = app()->getLocale() === 'fa';

  /* «۵۰٬۰۰۰٬۰۰۰» خوانا نیست. روی صفحهٔ فروش، «۵۰ میلیون» است که تصمیم می‌سازد. */
  $short = function (int $t) use ($isFaLocale) {
      if ($t <= 0) {
          return null;
      }
      if ($t >= 1_000_000_000) {
          $n = rtrim(rtrim(number_format($t / 1_000_000_000, 1), '0'), '.');
          return $isFaLocale ? fa_num($n).' میلیارد' : $n.'B';
      }
      $n = rtrim(rtrim(number_format($t / 1_000_000, 0), '0'), '.') ?: '0';
      $n = number_format($t / 1_000_000, 0);
      return $isFaLocale ? fa_num($n).' میلیون' : $n.'M';
  };
@endphp

<div class="sig-panel reveal">
  <div class="section-head">
    <span class="badge">{{ __('ui.hp_sig_badge') }}</span>
    <h2>{{ lc($sig)['t'] }}</h2>
    <p>{{ lc($sig)['d'] }}</p>
  </div>

  <div class="tier-ladder">
    @foreach($tiers as $i => $t)
      @php
        $spend = $short((int) ($t['min_spend_irt'] ?? 0));
        $doms  = (int) ($t['min_active_domains'] ?? 0);
        $off   = (float) ($t['discount_pct'] ?? 0);
      @endphp
      <div class="tier {{ $off > 0 ? '' : 'is-base' }}" style="--i:{{ $i }}">
        <div class="tier-top">
          <b class="tier-name">{{ lc($t['name'] ?? []) ?: $t['key'] }}</b>
          <span class="tier-off">
            @if($off > 0)
              {{ $isFaLocale ? fa_num((string) $off) : $off }}<small>٪</small>
            @else
              <small class="tier-off-base">{{ __('ui.rs_tier_base') }}</small>
            @endif
          </span>
        </div>

        {{-- 🔴 هر دو شرط نوشته می‌شوند.
             نمایشِ فقط مبلغ یعنی نماینده‌ای که مبلغش رسیده ولی دامنهٔ فعالش کم
             است، خودش را در سطح بعد می‌بیند و نمی‌رسد — و ما را بدقول می‌داند.
             شرطی که پنهان بماند، شرط نیست؛ غافلگیری است. --}}
        <ul class="tier-req">
          @if($spend)
            <li>{{ __('ui.rs_tier_spend', ['amount' => $spend, 'months' => $isFaLocale ? fa_num((string) $window) : $window]) }}</li>
          @else
            <li>{{ __('ui.rs_tier_start') }}</li>
          @endif
          @if($doms > 0)
            <li>{{ __('ui.rs_tier_domains', ['count' => $isFaLocale ? fa_num((string) $doms) : $doms]) }}</li>
          @endif
        </ul>
      </div>
    @endforeach
  </div>

  <p class="tier-note">
    {{ __('ui.rs_tier_note', ['days' => $isFaLocale ? fa_num((string) $grace) : $grace]) }}
  </p>
</div>

{{--
  بخشِ دامنه — **تنها جدولِ** این طراحی، و عمداً.

  دامنه یگانه نوعی است که هر ردیفش روی یک محور با بقیه قابلِ مقایسه است:
  انقضا. برای این کار ستون‌های هم‌تراز از کارت بهترند. سه نوعِ دیگر هیچ محورِ
  مشترکی ندارند و همان بود که جدولِ قبلی را به «همه چی توهم» رساند.

  ورودی: $domains، $secRenew، $secAttached، $room (bool)
--}}
@php
  $room = $room ?? false;
  $renew = $secRenew ?? [];
  $attached = $secAttached ?? [];
@endphp

<section class="pnl-sec" id="sec-domains">
  <div class="pnl-sec-h">
    <h2><svg class="icon svc-sec-ic"><use href="#i-globe"/></svg>{{ __('ui.sec_domains') }}</h2>
    {{-- روی خودِ /account/domains این لینک به همان صفحه می‌رفت — یک درِ بی‌جا. --}}
    @if(! $room && $domains->isNotEmpty())
      <a class="pnl-more" href="{{ lroute('account.domains') }}">{{ __('ui.sec_view_all') }}</a>
    @endif
  </div>
  <div class="pnl-sec-b {{ $domains->isEmpty() ? '' : 'flush' }}">
    @if($domains->isEmpty())
      @include('account.partials.sec-empty', [
        'full'  => $room,
        'icon'  => 'globe',
        'h'     => __('ui.sec_empty_domains_h'),
        'p'     => __('ui.sec_empty_domains_p'),
        'short' => __('ui.sec_none_domains'),
        'cta'   => __('ui.sec_empty_domains_cta'),
        'url'   => lroute('account.domains'),
      ])
    @else
      <div class="pnl-tw">
        <table class="pnl-table dmn-table">
          <thead>
            <tr>
              <th>{{ __('ui.dmn_th_domain') }}</th>
              <th>{{ __('ui.dmn_th_state') }}</th>
              <th>{{ __('ui.dmn_th_expiry') }}</th>
              <th>{{ __('ui.dmn_th_autorenew') }}</th>
              <th>{{ __('ui.dmn_th_lock') }}</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            @foreach($domains as $d)
              @php
                [$pillClass, $pillKey, $pillNum] = \App\Support\PanelSections::domainState($d);
                $left = $d->daysLeft();
                $inv  = $renew[$d->id] ?? null;
                $bare = ! isset($attached[mb_strtolower($d->domain)]);
              @endphp
              <tr>
                <td>
                  <b dir="ltr">{{ $d->domain }}</b>
                  {{-- تنها ایدهٔ ارزشمندِ «برگهٔ دارایی» که بی‌هیچ پرس‌وجوی تازه
                       زنده می‌ماند: دامنه‌ای که چیزی رویش سوار نیست. --}}
                  @if($bare)
                    <small class="dmn-bare">{{ __('ui.dmn_nothing_attached') }}
                      <a href="{{ lroute('account.store') }}">{{ __('ui.dmn_get_hosting') }}</a></small>
                  @endif
                </td>
                <td>
                  <span class="pnl-pill {{ $pillClass }}">{{ __('ui.'.$pillKey) }}</span>
                  @if($pillNum !== null)
                    <small class="dmn-days">{{ fa_num($pillNum) }}
                      {{ $pillClass === 'danger' ? __('ui.dmn_days_to_delete') : __('ui.dmn_days_left') }}</small>
                  @endif
                </td>
                <td>{{ $d->expires_at ? sdate($d->expires_at) : '—' }}</td>
                <td>
                  <span class="pnl-pill {{ $d->auto_renew ? 'ok' : 'mute' }}">{{ $d->auto_renew ? __('ui.dmn_on') : __('ui.dmn_off') }}</span>
                </td>
                <td>
                  <span class="pnl-pill {{ $d->is_locked ? 'ok' : 'mute' }}">{{ $d->is_locked ? __('ui.dmn_on') : __('ui.dmn_off') }}</span>
                </td>
                <td class="dmn-acts">
                  {{-- ⚠️ فاکتورِ تمدید حتی وقتی «تمدید خودکار» خاموش است هم نشان
                       داده می‌شود: چرخهٔ عمر آن را ۲۱ روز پیش از انقضا بی‌توجه به
                       آن پرچم صادر می‌کند. --}}
                  @if($inv)
                    <a class="pnl-btn primary" href="{{ lroute('account.invoice', $inv) }}">{{ __('ui.dmn_renew_pay') }}</a>
                  @endif
                  <a class="pnl-btn" href="{{ lroute('account.domain', $d) }}">{{ __('ui.dmn_manage') }}</a>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </div>
</section>

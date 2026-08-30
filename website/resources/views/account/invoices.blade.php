@extends('panel.layout')
@section('title', __('ui.invl_title'))

@section('panel')

<div class="pnl-head">
  <div>
    <h1>{{ __('ui.invl_heading') }}</h1>
    <p>{{ __('ui.invl_balance_label') }} <b class="pnl-num">{{ invoice_money($balance, 'IRT') }}</b></p>
  </div>
  <div class="pnl-acts">
    <a class="pnl-btn primary" href="{{ lroute('account.topup') }}">
      <svg class="icon"><use href="#i-plus"/></svg>{{ __('ui.invl_topup') }}
    </a>
  </div>
</div>

<section class="pnl-sec">
  <div class="pnl-sec-h"><h2>{{ __('ui.invl_all_invoices') }}</h2></div>

  @if($invoices->isEmpty())
    <div class="pnl-sec-b">
      <p style="font-size:13.5px;color:var(--muted);line-height:2;margin:0 0 14px">
        {{ __('ui.invl_empty') }}
      </p>
      <a class="pnl-btn" href="{{ lroute('account.topup') }}">
        <svg class="icon"><use href="#i-coins"/></svg>{{ __('ui.invl_topup') }}
      </a>
    </div>
  @else
    <div class="pnl-sec-b flush">
      <div class="pnl-tw">
        <table class="pnl-table">
          <thead>
            <tr><th>{{ __('ui.invl_th_number') }}</th><th>{{ __('ui.invl_th_kind') }}</th><th>{{ __('ui.invl_th_date') }}</th><th>{{ __('ui.invl_th_status') }}</th><th class="num">{{ __('ui.invl_th_amount') }}</th><th></th></tr>
          </thead>
          <tbody>
            @foreach($invoices as $inv)
              <tr>
                <td dir="ltr">{{ $inv->number }}</td>
                <td>{{ $inv->kind === 'topup' ? __('ui.invl_kind_topup') : __('ui.invl_kind_service') }}</td>
                <td>{{ sdate($inv->issued_at ?? $inv->created_at) }}</td>
                <td>
                  @if($inv->status === 'paid')
                    <span class="pnl-pill ok">{{ __('ui.invl_status_paid') }}</span>
                  @elseif($inv->status === 'void' || $inv->status === 'canceled')
                    <span class="pnl-pill mute">{{ $inv->status === 'canceled' ? __('ui.invl_status_canceled') : __('ui.invl_status_void') }}</span>
                  @else
                    <span class="pnl-pill warn">{{ __('ui.invl_status_pending') }}</span>
                  @endif
                </td>
                <td class="num pnl-num">{{ invoice_money($inv->total, $inv->currency_code) }}</td>
                <td style="white-space:nowrap">
                  <a class="pnl-btn" href="{{ lroute('account.invoice', $inv) }}">{{ __('ui.invl_view') }}</a>
                  @if($inv->status !== 'paid' && $inv->status !== 'canceled' && $inv->status !== 'void' && $inv->paid == 0)
                    <form method="POST" action="{{ lroute('account.invoice.cancel', $inv) }}" style="display:inline"
                          data-confirm="{{ __('ui.invl_confirm_msg') }}" data-confirm-danger data-confirm-title="{{ __('ui.invl_confirm_title') }}" data-confirm-ok="{{ __('ui.invl_confirm_ok') }}">
                      @csrf
                      <button type="submit" class="pnl-btn" style="color:var(--danger);border-color:var(--danger-line)">{{ __('ui.invl_cancel') }}</button>
                    </form>
                  @endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>
  @endif
</section>

{{ $invoices->links() }}

@endsection

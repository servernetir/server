@extends('panel.layout')
@section('title', __('refund.title'))
@section('panel')
<div class="pnl-head"><div><h1>{{ __('refund.title') }}</h1><p>{{ __('refund.help') }}</p></div></div>
@if($errors->any())<div class="pnl-sec"><div class="pnl-sec-b" role="alert">{{ $errors->first() }}</div></div>@endif
@if(session('ok'))<div class="pnl-sec"><div class="pnl-sec-b">{{ session('ok') }}</div></div>@endif
<section class="pnl-sec"><div class="pnl-sec-b" style="display:grid;gap:16px">
  <p>{{ __('refund.balance') }}: <b>{{ number_format($balance) }} {{ __('refund.toman') }}</b> · {{ __('refund.available') }}: <b>{{ number_format(max(0,$available)) }} {{ __('refund.toman') }}</b></p>
  @if(!$enabled)
    <p>{{ __('refund.disabled') }}</p>
  @elseif($pending)
    <p>{{ __('refund.pending_help') }}</p>
  @elseif($banks->isEmpty())
    <p>{{ __('refund.no_bank') }} <a href="{{ lroute('account.bank') }}">{{ __('refund.bank_manage') }}</a></p>
  @else
    <form method="post" action="{{ lroute('account.wallet-refunds.store') }}" style="display:grid;gap:16px;max-width:640px">
      @csrf
      <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', (string) \Illuminate\Support\Str::uuid()) }}">
      <label>{{ __('refund.amount') }}<input class="pnl-input" name="amount_irt" inputmode="numeric" dir="ltr" required maxlength="24" value="{{ old('amount_irt') }}"></label>
      <label>{{ __('refund.bank') }}<select class="pnl-input" name="bank_account_id" required>
        <option value="">—</option>@foreach($banks as $bank)<option value="{{ $bank->id }}" @selected(old('bank_account_id') == $bank->id)>{{ $bank->bankLabel() }} · {{ $bank->iban }} · {{ $bank->owner_name }}</option>@endforeach
      </select></label>
      <label>{{ __('refund.note') }}<textarea class="pnl-input" name="note" required minlength="5" maxlength="2000" rows="3">{{ old('note') }}</textarea></label>
      <label style="display:flex;gap:8px;align-items:flex-start"><input type="checkbox" name="acknowledge" value="1" required>{{ __('refund.acknowledge') }}</label>
      <button type="submit" class="pnl-btn primary">{{ __('refund.submit') }}</button>
    </form>
  @endif
</div></section>
<section class="pnl-sec"><div class="pnl-sec-h"><h2>{{ __('refund.history') }}</h2></div><div class="pnl-sec-b">
@forelse($refunds as $r)
  <article style="padding:16px 0;border-bottom:1px solid var(--line);display:grid;gap:8px;overflow-wrap:anywhere">
    <b>{{ number_format($r->amount_irt) }} {{ __('refund.toman') }} · {{ __('refund.status_'.$r->status) }}</b>
    <small>{{ __('refund.request_id') }}: <bdi dir="ltr">{{ $r->public_id }}</bdi> · {{ sdate($r->created_at) }}</small>
    <span dir="ltr">{{ $r->bank_snapshot['iban'] }}</span>
    @if($r->review_note)<p style="margin:0;white-space:pre-wrap">{{ $r->review_note }}</p>@endif
    @if($r->status === 'requested')
      <form method="post" action="{{ lroute('account.wallet-refunds.cancel', $r) }}">@csrf<button class="pnl-btn">{{ __('refund.cancel') }}</button></form>
    @endif
    @if($r->status === 'paid')
      <span>{{ __('refund.reference') }}: <bdi dir="ltr">{{ $r->bank_reference }}</bdi> · {{ __('refund.payout_date') }}: {{ sdate($r->payout_date) }}</span>
      <a href="{{ lroute('account.wallet-refunds.receipt', $r) }}">{{ __('refund.receipt') }}</a>
    @endif
  </article>
@empty<p>{{ __('refund.empty') }}</p>@endforelse
{{ $refunds->links() }}
</div></section>
<style>
.pnl-main .pnl-input{display:block;width:100%;box-sizing:border-box;margin-top:7px;padding:10px 12px;border:1px solid var(--line);border-radius:10px;background:var(--surface-2);color:var(--text);font:inherit}
</style>
@endsection

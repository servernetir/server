@extends('master')

@section('title', 'finances')
@section('extra-js')
    <script src="{{ asset('js/finances.js') }}" defer></script>
@endsection

@section('content')
    <header class="header">
        <h1 class="title">Finances</h1>
    </header>
    <fieldset class="section section--plans" data-section="plans">
        <legend class="legend">Balance</legend>
        <div class="plan-groups">

            <article class="plan-group">
                <header class="plan-group__header">
                    <p class="muted">Balance</p>
                    <h3 class="title">0 €</h3>
                </header>
                <button type="submit" class="btn btn--primary" data-action="pay" data-target="#buyModal">Add funds</button>
            </article>

            <article class="plan-group">
                <header class="plan-group__header">
                    <p class="muted">Transactions</p>
                </header>
            </article>
        </div>
    </fieldset>

    <!-- Overlay + Right Drawer -->
<div class="modal-overlay" id="buyModal" aria-hidden="true">
  <div class="drawer">
    <button class="drawer__close" data-action="close-modal" aria-label="Close">×</button>

    <h2 class="drawer__title">Buy</h2>

    <!-- Amount -->
    <label class="field">
      <span>Amount</span>
      <input id="amountInput" type="number" min="1" step="1" value="1">
    </label>

    <!-- Payment widget -->
    <section id="payWidget" class="pay-widget">
      <!-- Payment method (selected) -->
      <h3 class="pw-subtitle">Payment method</h3>
      <div id="selectedCard" class="method-card"></div>

      <!-- Coins -->
      <h3 class="pw-subtitle" style="margin-top:18px;">Cryptocurrencies</h3>
      <div id="coinGrid" class="coin-grid"></div>

      <!-- Notes -->
      <ul id="promoList" class="promo-list"></ul>

      <!-- Total -->
      <div class="total">
        <div>TOTAL:</div>
        <div id="totalValue">1 €</div>
      </div>

      <button class="pay-btn">Pay</button>

      <!-- Demo: switch data -->
      <div style="margin-top:12px;display:flex;gap:8px;">
        <button class="mini" id="loadSetA">Load dataset A</button>
        <button class="mini" id="loadSetB">Load dataset B</button>
      </div>
    </section>
  </div>
</div>

@endsection
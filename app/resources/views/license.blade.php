@extends('master')
@section('title', 'license')
@section('extra-js')
    <script src="{{ asset('js/license.js') }}" defer></script>
@endsection
@section('content')
    <header class="header">
        <h1 class="title">Purchase license</h1>
    </header>
    <form id="order-form" class="form" method="POST" action="{{ url()->current() }}" autocomplete="off" novalidate>
        @csrf
        <!-- Name -->
        <section class="section section--name">
            <label for="hostname" class="label">Name</label>
            <div class="input-wrap" data-field="hostname">
                <input id="hostname" name="hostname" class="input" type="text" inputmode="latin"
                    placeholder="fascinated-spark" value="fascinated-spark" />
                <button type="button" class="btn btn--icon" aria-label="Randomize name" data-action="randomize-hostname">
                    <svg viewBox="0 0 24 24" aria-hidden="true" width="20" height="20">
                        <path
                            d="M12 6v3l4-4-4-4v3a8 8 0 0 0-8 8h2a6 6 0 0 1 6-6zm8 4h-2a6 6 0 0 1-6 6v-3l-4 4 4 4v-3a8 8 0 0 0 8-8z" />
                    </svg>
                </button>
            </div>
            <p class="help">Based on it, we will set the hostname</p>
        </section>
        <!-- Plan selection -->
        <fieldset class="section section--plans" data-section="plans">
            <legend class="legend">Plan selection</legend>
            <div class="plan-groups">
                <!-- ispmanager -->
                <article class="plan-group is-active" data-group="ispmanager">
                    <header class="plan-group__header">
                        <h3 class="subtitle">ispmanager</h3>
                        <p class="muted">An advanced Linux-based dashboard for managing websites, dedicated and gaming and
                            VPS web servers, as well as the sale of hosting services</p>
                    </header>
                    <div class="plan-list" role="list">
                        <!-- lite -->
                        <label class="plan-card" role="listitem" data-plan-code="lite">
                            <input type="radio" name="plan" value="lite" data-price-month="4.94" data-cores="1"
                                data-ram="2" data-nvme="30" data-gbps="25" />
                            <header class="plan-card__head">
                                <h4 class="plan-name">lite</h4>
                                <div class="plan-price">
                                    <strong class="price"><span data-price>4.94</span> €/month</strong>
                                </div>
                            </header>
                            <p class="help">the...</p>
                        </label>
                        <!-- pro -->
                        <label class="plan-card" role="listitem" data-plan-code="pro">
                            <input type="radio" name="plan" value="pro" data-price-month="9.89" data-cores="2"
                                data-ram="4" data-nvme="60" data-gbps="25" checked />
                            <header class="plan-card__head">
                                <h4 class="plan-name">pro</h4>
                                <div class="plan-price">
                                    <strong class="price"><span data-price>9.89</span> €/month</strong>
                                </div>
                            </header>
                            <p class="help">the...</p>
                        </label>
                        <!-- host -->
                        <label class="plan-card" role="listitem" data-plan-code="host">
                            <input type="radio" name="plan" value="host" data-price-month="19.77" data-cores="4"
                                data-ram="8" data-nvme="120" data-gbps="25" />
                            <header class="plan-card__head">
                                <h4 class="plan-name">host</h4>
                                <div class="plan-price">
                                    <strong class="price"><span data-price>19.77</span> €/month</strong>
                                </div>
                            </header>
                            <p class="help">the...</p>
                        </label>
                    </div>
                </article>
                <!-- CPanel -->
                <article class="plan-group" data-group="cpanel">
                    <header class="plan-group__header">
                        <h3 class="subtitle">CPanel</h3>
                        <p class="muted">An advanced Linux-based dashboard for managing websites, dedicated and gaming and
                            VPS web servers, as well as the sale of hosting services</p>
                    </header>
                    <div class="plan-list" role="list">
                        <label class="plan-card" role="listitem" data-plan-code="lite">
                            <input type="radio" name="plan" value="lite" data-price-month="59.00" data-cores="8"
                                data-ram="16" data-nvme="200" data-gbps="25" />
                            <header class="plan-card__head">
                                <h4 class="plan-name">lite</h4>
                                <div class="plan-price">
                                    <strong class="price"><span data-price>59.00</span> €/month</strong>
                                </div>
                            </header>
                            <p class="help">the...</p>
                        </label>
                        <label class="plan-card" role="listitem" data-plan-code="pro">
                            <input type="radio" name="plan" value="pro" data-price-month="99.00"
                                data-cores="12" data-ram="32" data-nvme="400" data-gbps="25" />
                            <header class="plan-card__head">
                                <h4 class="plan-name">pro</h4>
                                <div class="plan-price">
                                    <strong class="price"><span data-price>99.00</span> €/month</strong>
                                </div>
                            </header>
                            <p class="help">the...</p>
                        </label>
                    </div>
                </article>
            </div>
        </fieldset>
        <!-- Backups -->
        <fieldset class="section section--backups" data-section="backups">
            <legend class="legend">License parameters</legend>
            <p class="muted">IP-address can be changed at any time</p>
            <input id="ipaddress" name="ipaddress" class="input" type="text" placeholder="IP-Address" />
        </fieldset>

        <!-- Billing period -->
        <fieldset class="section section--billing" data-section="billing">
            <legend class="legend">Choosing the payment period and placing an order</legend>
            <div class="chip-row" role="radiogroup" aria-label="Billing period">
                <label class="chip is-active">
                    <input type="radio" name="billing" value="month" data-months="1" data-discount="0" checked />
                    <span>Month</span>
                </label>
                <label class="chip">
                    <input type="radio" name="billing" value="year" data-months="12" data-discount="0" />
                    <span>Year</span>
                </label>
            </div>
        </fieldset>


        <hr class="sep" aria-hidden="true" />
        <!-- Total -->
        <section class="section section--total" data-section="total">
            <header class="total__head">
                {{-- <h3 class="subtitle">TOTAL</h3> --}}
                <h3 class="legend" id="summaryTitle">—</h3>

                <div class="qty">
                    <span class="qty__label" aria-hidden="true">pro</span>
                    <label class="qty__control" for="quantity" aria-label="Quantity">
                        <input id="quantity" name="quantity" type="number" min="1" step="1"
                            value="1" class="input input--qty" />
                    </label>
                    <span class="qty__unit">pcs.</span>
                </div>
            </header>

            <div class="includes">
                <ul class="bullets">
                    <li>Installation assistance</li>
                </ul>
            </div>

            <div class="total__pay">
                <div id="totalPrice" class="total-amount">0.00 €</div>
                <button type="submit" class="btn btn--primary" data-action="pay">Pay</button>
            </div>

            <label class="check autoprolong">
                <input type="checkbox" name="autoprolong" value="1" />
                <span>Autoprolong</span>
            </label>
        </section>

    </form>
@endsection

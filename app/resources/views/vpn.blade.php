@extends('master')

@section('title', 'vpn')

@section('extra-js')
    <script src="{{ asset('js/vpn.js') }}" defer></script>
@endsection

@section('content')
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <section class="section">
        <h1 class="title">Your new vpn</h1>

        <label class="label" for="vpn_name">Name</label>
        <div class="input-wrap" style="max-width:420px;">
            <input id="vpn_name" class="input" type="text" value="asdadad.com"
                placeholder="Enter a name (hostname will be based on it)">
            <button type="button" class="btn btn--icon" title="Refresh name"
                onclick="document.getElementById('vpn_name').value = Math.random().toString(36).slice(2,10)+'.com'">
                <svg viewBox="0 0 24 24">
                    <path d="M12 6V3L8 7l4 4V8a4 4 0 1 1-4 4H6a6 6 0 1 0 6-6z" />
                </svg>
            </button>
        </div>
        <p class="muted">Based on it, we will set the hostname</p>
    </section>

    {{-- Plans comparison like the screenshot --}}
    <section class="section">
        <div class="ui-table-wrap">
            <table class="ui-table is-hover">
                <thead>
                    <tr>
                        <th style="width:25%"></th>
                        <th>SMALL<br><span class="muted">€1.9/month</span></th>
                        <th class="is-selected">MEDIUM<br><span class="muted">€5/month</span></th>
                        <th>LARGE<br><span class="muted">€7.5/month</span></th>
                        <th>EXTRA LARGE<br><span class="muted">€12.9/month</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td></td>
                        <td><button class="tab choose-plan" data-id="small" data-name="Small"
                                data-price="1.9">Choose</button></td>
                        <td><button class="tab choose-plan is-active" data-id="medium" data-name="Medium"
                                data-price="5">Selected</button></td>
                        <td><button class="tab choose-plan" data-id="large" data-name="Large"
                                data-price="7.5">Choose</button></td>
                        <td><button class="tab choose-plan" data-id="xlarge" data-name="Extra Large"
                                data-price="12.9">Choose</button></td>
                    </tr>
                    <tr>
                        <td><strong>Number of profiles</strong></td>
                        <td>1</td>
                        <td>3</td>
                        <td>5</td>
                        <td>10</td>
                    </tr>
                    <tr>
                        <td><strong>Number of locations</strong></td>
                        <td>6</td>
                        <td>7</td>
                        <td>7</td>
                        <td>7</td>
                    </tr>
                    <tr>
                        <td><strong>Premium locations</strong></td>
                        <td>✕</td>
                        <td>✓</td>
                        <td>✓</td>
                        <td>✓</td>
                    </tr>
                    <tr>
                        <td><strong>Connection method</strong></td>
                        <td>VLESS,<br>Shadowsocks<br>(Outline)</td>
                        <td>VLESS,<br>Shadowsocks<br>(Outline)</td>
                        <td>VLESS,<br>Shadowsocks<br>(Outline)</td>
                        <td>VLESS,<br>Shadowsocks<br>(Outline)</td>
                    </tr>
                    <tr>
                        <td><strong>Network speed (up to)</strong></td>
                        <td>1 Gbps</td>
                        <td>1 Gbps</td>
                        <td>1 Gbps</td>
                        <td>1 Gbps</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <section class="section">
        <p class="legend">List of available servers:
            <span class="muted">🇸🇪 STO 🇫🇮 HEL 🇩🇪 FRA 🇫🇷 PAR 🇳🇱 AMS 🇦🇹 VIE 🇷🇺 SPB</span>
        </p>
    </section>

    <section class="section">
        <h3 class="legend">Choosing the payment period and placing an order</h3>
        <div class="tabs" id="periodTabs">
            <button class="tab period" data-months="1">Month</button>
            <button class="tab period is-active" data-months="3" data-discount="0.05">3 months <span
                    class="chip">-5%</span></button>
            <button class="tab period" data-months="6" data-discount="0.09">6 months <span
                    class="chip">-9%</span></button>
            <button class="tab period" data-months="12" data-discount="0.12">Year <span class="chip">-12%</span></button>
        </div>
    </section>

    <hr class="sep">

    <section class="section section--total" id="total">
        <div class="total__head">
            <h3 class="legend" id="totalTitle">Medium • 3 months</h3>
            <div class="qty">
                <span class="qty__label">1</span>
                <input id="qty" type="number" class="input input--qty" value="1" min="1" />
                <span class="qty__unit">pcs.</span>
            </div>
        </div>

        <ul class="bullets includes">
            <li>✓ DDoS-Protection</li>
            <li>✓ Installation assistance</li>
        </ul>

        <div class="total__pay">
            <div>
                <span id="oldPrice" class="muted" style="text-decoration:line-through; margin-right:10px;"></span>
                <span id="newPrice" class="total-amount">0 €</span>
            </div>
            <div class="muted" style="margin-top:6px;">Pay</div>
        </div>

        <label class="check autoprolong">
            <input type="checkbox" id="autoprolong">
            <span>Autoprolong</span>
        </label>
    </section>
@endsection
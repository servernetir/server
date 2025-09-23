@extends('master')

@section('title', 'vps')

@section('extra-js')
    <script src="{{ asset('js/vps.js') }}" defer></script>
@endsection

@section('content')
<header class="header">
  <h1 class="title">Your new virtual server</h1>
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
          <path d="M12 6v3l4-4-4-4v3a8 8 0 0 0-8 8h2a6 6 0 0 1 6-6zm8 4h-2a6 6 0 0 1-6 6v-3l-4 4 4 4v-3a8 8 0 0 0 8-8z"/>
        </svg>
      </button>
    </div>
    <p class="help">Based on it, we will set the hostname</p>
  </section>

  <!-- Location selection -->
  <fieldset class="section section--locations" data-section="locations">
    <legend class="legend">Location selection</legend>
    <div class="pill-grid" role="listbox" aria-label="Locations">
      <label class="pill" role="option">
        <input type="radio" name="location" value="charlotte" data-city="Charlotte" checked />
        <span class="flag" aria-hidden="true">🇺🇸</span><span>Charlotte</span>
      </label>
      <label class="pill" role="option">
        <input type="radio" name="location" value="frankfurt" data-city="Frankfurt" />
        <span class="flag" aria-hidden="true">🇩🇪</span><span>Frankfurt</span>
      </label>
      <label class="pill" role="option">
        <input type="radio" name="location" value="paris" data-city="Paris" />
        <span class="flag" aria-hidden="true">🇫🇷</span><span>Paris</span>
      </label>
      <label class="pill" role="option">
        <input type="radio" name="location" value="amsterdam" data-city="Amsterdam" />
        <span class="flag" aria-hidden="true">🇳🇱</span><span>Amsterdam</span>
      </label>
      <label class="pill" role="option">
        <input type="radio" name="location" value="vienna" data-city="Vienna" />
        <span class="flag" aria-hidden="true">🇦🇹</span><span>Vienna</span>
      </label>
      <label class="pill" role="option">
        <input type="radio" name="location" value="stockholm" data-city="Stockholm" />
        <span class="flag" aria-hidden="true">🇸🇪</span><span>Stockholm</span>
      </label>
      <label class="pill" role="option">
        <input type="radio" name="location" value="helsinki" data-city="Helsinki" />
        <span class="flag" aria-hidden="true">🇫🇮</span><span>Helsinki</span>
      </label>
    </div>
  </fieldset>

  <!-- Plan selection -->
  <fieldset class="section section--plans" data-section="plans">
    <legend class="legend">Plan selection</legend>

    <div class="plan-groups">
      <!-- Shared -->
      <article class="plan-group is-active" data-group="shared">
        <header class="plan-group__header">
          <h3 class="subtitle">Shared</h3>
          <p class="muted">
            Value-priced cloud servers with basic DDoS protection, AMD Ryzen 9 9950X, NVMe storage and shared vCPU.
            Ideal for websites, VPN or development. Nested virtualization enabled.
          </p>
        </header>

        <div class="plan-list" role="list">
          <!-- CLTs-1 -->
          <label class="plan-card" role="listitem" data-plan-code="CLTs-1">
            <input type="radio" name="plan" value="CLTs-1"
                   data-price-month="4.94" data-price-hour="0.02"
                   data-cores="1" data-ram="2" data-nvme="30" data-gbps="25" />
            <header class="plan-card__head">
              <h4 class="plan-name">CLTs-1</h4>
              <div class="plan-price">
                <strong class="price"><span data-price>4.94</span> €/month</strong>
                <span class="price--small">0.02 €/hour</span>
              </div>
            </header>
            <ul class="specs">
              <li><span class="chip"><b>1</b> Core</span></li>
              <li><span class="chip"><b>2</b> GB RAM</span></li>
              <li><span class="chip"><b>30</b> GB NVMe</span></li>
              <li><span class="chip">up to <b>25</b> Gbps</span></li>
            </ul>
          </label>

          <!-- CLTs-2 -->
          <label class="plan-card" role="listitem" data-plan-code="CLTs-2">
            <input type="radio" name="plan" value="CLTs-2"
                   data-price-month="9.89" data-price-hour="0.03"
                   data-cores="2" data-ram="4" data-nvme="60" data-gbps="25" checked />
            <header class="plan-card__head">
              <h4 class="plan-name">CLTs-2</h4>
              <div class="plan-price">
                <strong class="price"><span data-price>9.89</span> €/month</strong>
                <span class="price--small">0.03 €/hour</span>
              </div>
            </header>
            <ul class="specs">
              <li><span class="chip"><b>2</b> Cores</span></li>
              <li><span class="chip"><b>4</b> GB RAM</span></li>
              <li><span class="chip"><b>60</b> GB NVMe</span></li>
              <li><span class="chip">up to <b>25</b> Gbps</span></li>
            </ul>
          </label>

          <!-- CLTs-3 -->
          <label class="plan-card" role="listitem" data-plan-code="CLTs-3">
            <input type="radio" name="plan" value="CLTs-3"
                   data-price-month="19.77" data-price-hour="0.05"
                   data-cores="4" data-ram="8" data-nvme="120" data-gbps="25" />
            <header class="plan-card__head">
              <h4 class="plan-name">CLTs-3</h4>
              <div class="plan-price">
                <strong class="price"><span data-price>19.77</span> €/month</strong>
                <span class="price--small">0.05 €/hour</span>
              </div>
            </header>
            <ul class="specs">
              <li><span class="chip"><b>4</b> Cores</span></li>
              <li><span class="chip"><b>8</b> GB RAM</span></li>
              <li><span class="chip"><b>120</b> GB NVMe</span></li>
              <li><span class="chip">up to <b>25</b> Gbps</span></li>
            </ul>
          </label>

          <!-- CLTs-4 -->
          <label class="plan-card" role="listitem" data-plan-code="CLTs-4">
            <input type="radio" name="plan" value="CLTs-4"
                   data-price-month="39.54" data-price-hour="0.11"
                   data-cores="6" data-ram="16" data-nvme="240" data-gbps="25" />
            <header class="plan-card__head">
              <h4 class="plan-name">CLTs-4</h4>
              <div class="plan-price">
                <strong class="price"><span data-price>39.54</span> €/month</strong>
                <span class="price--small">0.11 €/hour</span>
              </div>
            </header>
            <ul class="specs">
              <li><span class="chip"><b>6</b> Cores</span></li>
              <li><span class="chip"><b>16</b> GB RAM</span></li>
              <li><span class="chip"><b>240</b> GB NVMe</span></li>
              <li><span class="chip">up to <b>25</b> Gbps</span></li>
            </ul>
          </label>
        </div>
      </article>

      <!-- Dedicated -->
      <article class="plan-group" data-group="dedicated">
        <header class="plan-group__header">
          <h3 class="subtitle">Dedicated</h3>
          <p class="muted">
            Top-tier cloud servers with guaranteed resources (Ryzen 9 9950X), NVMe + RAM cache, and DDoS protection.
            For 1C/Bitrix/high-load hosting. Nested virtualization enabled.
          </p>
        </header>

        <div class="plan-list" role="list">
          <label class="plan-card" role="listitem" data-plan-code="CLTd-1">
            <input type="radio" name="plan" value="CLTd-1"
                   data-price-month="59.00" data-price-hour="0.16"
                   data-cores="8" data-ram="16" data-nvme="200" data-gbps="25" />
            <header class="plan-card__head">
              <h4 class="plan-name">CLTd-1</h4>
              <div class="plan-price">
                <strong class="price"><span data-price>59.00</span> €/month</strong>
                <span class="price--small">0.16 €/hour</span>
              </div>
            </header>
            <ul class="specs">
              <li><span class="chip"><b>8</b> Cores</span></li>
              <li><span class="chip"><b>16</b> GB RAM</span></li>
              <li><span class="chip"><b>200</b> GB NVMe</span></li>
              <li><span class="chip">up to <b>25</b> Gbps</span></li>
            </ul>
          </label>

          <label class="plan-card" role="listitem" data-plan-code="CLTd-2">
            <input type="radio" name="plan" value="CLTd-2"
                   data-price-month="99.00" data-price-hour="0.27"
                   data-cores="12" data-ram="32" data-nvme="400" data-gbps="25" />
            <header class="plan-card__head">
              <h4 class="plan-name">CLTd-2</h4>
              <div class="plan-price">
                <strong class="price"><span data-price>99.00</span> €/month</strong>
                <span class="price--small">0.27 €/hour</span>
              </div>
            </header>
            <ul class="specs">
              <li><span class="chip"><b>12</b> Cores</span></li>
              <li><span class="chip"><b>32</b> GB RAM</span></li>
              <li><span class="chip"><b>400</b> GB NVMe</span></li>
              <li><span class="chip">up to <b>25</b> Gbps</span></li>
            </ul>
          </label>
        </div>
      </article>
    </div>
  </fieldset>

  <!-- OS selection -->
  <fieldset class="section section--os" data-section="os">
    <legend class="legend">Choosing an operating system and software</legend>

    <div class="tabs" role="tablist" aria-label="OS tabs">
      <button type="button" class="tab is-active" role="tab" aria-selected="true" data-tab="os-list">Operating system</button>
      <button type="button" class="tab" role="tab" aria-selected="false" data-tab="apps-list">Preinstalled programs</button>
    </div>

    <div id="os-list" class="os-grid" role="tabpanel" aria-labelledby="Operating system">
      <!-- Ubuntu -->
      <label class="os-card">
        <input type="radio" name="os" value="ubuntu" checked />
        <span class="os-card__icon" aria-hidden="true">🟠</span>
        <span class="os-card__name">Ubuntu</span>
        <div class="os-card__meta">
          <select name="ubuntu_version" class="select" aria-label="Ubuntu version">
            <option value="24.04" selected>24.04</option>
            <option value="22.04">22.04</option>
            <option value="20.04">20.04</option>
          </select>
        </div>
      </label>

      <!-- Debian -->
      <label class="os-card">
        <input type="radio" name="os" value="debian" />
        <span class="os-card__icon" aria-hidden="true">🔴</span>
        <span class="os-card__name">Debian</span>
        <div class="os-card__meta">
          <select name="debian_version" class="select" aria-label="Debian version">
            <option value="12" selected>12</option>
            <option value="11">11</option>
          </select>
        </div>
      </label>

      <!-- Alma -->
      <label class="os-card">
        <input type="radio" name="os" value="alma" />
        <span class="os-card__icon" aria-hidden="true">🟢</span>
        <span class="os-card__name">Alma</span>
        <div class="os-card__meta">
          <select name="alma_version" class="select" aria-label="AlmaLinux version">
            <option value="9" selected>Linux 9</option>
            <option value="8">Linux 8</option>
          </select>
        </div>
      </label>

      <!-- Rocky -->
      <label class="os-card">
        <input type="radio" name="os" value="rocky" />
        <span class="os-card__icon" aria-hidden="true">🟩</span>
        <span class="os-card__name">Rocky</span>
        <div class="os-card__meta">
          <select name="rocky_version" class="select" aria-label="Rocky Linux version">
            <option value="9" selected>Linux 9</option>
            <option value="8">Linux 8</option>
          </select>
        </div>
      </label>

      <!-- Windows Server -->
      <label class="os-card">
        <input type="radio" name="os" value="windows" />
        <span class="os-card__icon" aria-hidden="true">💢</span>
        <span class="os-card__name">Windows Server</span>
        <div class="os-card__meta">
          <select name="windows_version" class="select" aria-label="Windows Server version">
            <option value="2022" selected>2022</option>
            <option value="2019">2019</option>
            <option value="2016">2016</option>
          </select>
        </div>
      </label>

      <!-- ISO -->
      <label class="os-card os-card--iso">
        <input type="radio" name="os" value="iso" />
        <span class="os-card__icon" aria-hidden="true">💿</span>
        <span class="os-card__name">ISO</span>
      </label>
    </div>

    <!-- Preinstalled apps -->
    <div id="apps-list" class="apps-grid" role="tabpanel" aria-labelledby="Preinstalled programs" hidden>
      <label class="check"><input type="checkbox" name="apps[]" value="docker" /><span>Docker</span></label>
      <label class="check"><input type="checkbox" name="apps[]" value="lamp" /><span>LAMP stack</span></label>
      <label class="check"><input type="checkbox" name="apps[]" value="wireguard" /><span>WireGuard VPN</span></label>
      <label class="check"><input type="checkbox" name="apps[]" value="hestiacp" /><span>HestiaCP</span></label>
    </div>

    <p class="note" data-os-note>
      <strong>Windows Server OS</strong> comes without a license.
    </p>
  </fieldset>

  <!-- Billing period -->
  <fieldset class="section section--billing" data-section="billing">
    <legend class="legend">Choosing the payment period and placing an order</legend>
    <div class="chip-row" role="radiogroup" aria-label="Billing period">
      <label class="chip">
        <input type="radio" name="billing" value="hour" data-discount="0" />
        <span>Hour</span>
      </label>
      <label class="chip is-active">
        <input type="radio" name="billing" value="month" data-discount="0" checked />
        <span>Month</span>
      </label>
      <label class="chip">
        <input type="radio" name="billing" value="3months" data-discount="5" />
        <span>3 months <small>−5%</small></span>
      </label>
      <label class="chip">
        <input type="radio" name="billing" value="6months" data-discount="9" />
        <span>6 months <small>−9%</small></span>
      </label>
      <label class="chip">
        <input type="radio" name="billing" value="year" data-discount="12" />
        <span>Year <small>−12%</small></span>
      </label>
    </div>
  </fieldset>

  <!-- Backups -->
  <fieldset class="section section--backups" data-section="backups">
    <legend class="legend">Backups</legend>
    <p class="muted">
      You can make up to 7 copies and set up automatic copying.<br/>Cost – 20% of the service price.
    </p>
    <label class="check">
      <input type="checkbox" name="backups" value="1" data-addon="backups" />
      <span>Connect backups – <b data-backup-price>1.97 €</b></span>
    </label>
  </fieldset>

  <hr class="sep" aria-hidden="true" />

<!-- Total -->
<section class="section section--total" data-section="total">
  <header class="total__head">
    <h3 class="subtitle">TOTAL</h3>
    <div class="qty">
      <span class="qty__label" aria-hidden="true">CLTs-2</span>
      <label class="qty__control" for="quantity" aria-label="Quantity">
        <input id="quantity" name="quantity" type="number" min="1" step="1" value="1" class="input input--qty" />
      </label>
      <span class="qty__unit">pcs.</span>
    </div>
  </header>

  <div class="includes">
    <p class="muted">
      The OS will be installed:
      <span data-selected-os class="highlight-orange">Ubuntu 24.04</span>
    </p>

    <div class="muted" data-selected-apps-wrap hidden>
      <span>Preinstalled programs:</span>
      <ul class="bullets" data-selected-apps></ul>
    </div>

    <ul class="bullets">
      <li>DDoS-Protection</li>
      <li>Installation assistance</li>
    </ul>
  </div>

  <div class="total__pay">
    <output id="total-price" class="total-amount" aria-live="polite" data-currency="EUR">9.89 €</output>
    <button type="submit" class="btn btn--primary" data-action="pay">Pay</button>
  </div>

  <label class="check autoprolong">
    <input type="checkbox" name="autoprolong" value="1" />
    <span>Autoprolong</span>
  </label>
</section>
</form>
@endsection
@extends('master')

@section('title', 'domain')
@section('extra-js')
    <script src="{{ asset('js/domain.js') }}" defer></script>
@endsection

@section('content')
    <section class="section">
        <h1 class="title">Your new domain</h1>

        <form id="searchForm" class="form" onsubmit="return false;">
            <div class="input-wrap">
                <input id="q" class="input" type="text" placeholder="Enter domain" autocomplete="off" />
                <button id="checkBtn" class="tab" type="button">Check</button>
            </div>
            <p class="muted">Type a name and click Check.</p>
        </form>
    </section>

    <section class="section" id="results">
        <div class="ui-table-wrap">
            <table class="ui-table is-striped is-hover">
                <thead>
                    <tr>
                        <th>Domain</th>
                        <th>Status</th>
                        <th>Order</th>
                    </tr>
                </thead>
                <tbody id="rows">
                </tbody>
            </table>
        </div>
        <p id="moreZones" class="muted">And 219 more zones...</p>
    </section>

    <form id="regForm" class="section" method="POST" action="{{ route('domain.order') }}" hidden>
        @csrf
        <h3 class="legend">Data for domain registration</h3>
        <p class="muted">Fill in the data strictly according to the template for domain registration.</p>

        <div class="plan-groups">
            <div>
                <label class="label" for="full_name">Full Name</label>
                <input class="input" id="full_name" name="full_name" placeholder="Ivanov Ivan Ivanovich" required>
            </div>
            <div>
                <label class="label" for="dob">Date of Birth</label>
                <input class="input" id="dob" type="date" name="dob" required>
            </div>

            <div>
                <label class="label" for="passport_series">Series and number</label>
                <input class="input" id="passport_series" name="passport_series" placeholder="81 18 810180" required>
            </div>
            <div>
                <label class="label" for="passport_issuer">Passport/ID issued by</label>
                <input class="input" id="passport_issuer" name="passport_issuer"
                    placeholder="organization ABVGD in Kotyatsky district" required>
            </div>
            <div>
                <label class="label" for="issue_date">Date of issue</label>
                <input class="input" id="issue_date" type="date" name="issue_date" required>
            </div>

            <div>
                <label class="label" for="postcode">Postcode</label>
                <input class="input" id="postcode" name="postcode" placeholder="670000" required>
            </div>
            <div>
                <label class="label" for="region">Region</label>
                <input class="input" id="region" name="region" placeholder="The Republic of Khakassia" required>
            </div>
            <div>
                <label class="label" for="country">Country</label>
                <select id="country" name="country" class="input" required>
                    <option value="" selected disabled>Choose…</option>
                    <option value="RU">Russia</option>
                    <option value="DE">Germany</option>
                    <option value="NL">Netherlands</option>
                    <option value="US">United States</option>
                    <option value="GB">United Kingdom</option>
                </select>
            </div>
            <div>
                <label class="label" for="city">City</label>
                <input class="input" id="city" name="city" placeholder="Moscow city" required>
            </div>
            <div>
                <label class="label" for="address">Address</label>
                <input class="input" id="address" name="address" placeholder="st. Butlerova 7, apt. 404" required>
            </div>
            <div>
                <label class="label" for="phone">Phone</label>
                <input class="input" id="phone" name="phone" placeholder="+7 (999) 111-2233" required>
            </div>
        </div>

        <input type="hidden" id="domain" name="domain">
        <input type="hidden" id="tld" name="tld">
        <input type="hidden" id="price" name="price">
        <input type="hidden" id="period" name="period" value="1">
    </form>
    <fieldset class="section section--plans" data-section="plans">
        <legend class="legend" style="color: #f59e0b">payment period is Yearly</legend>

        <section class="section section--total" id="total" hidden>
            <div class="total__head">
                <h3 class="legend" id="summaryTitle">—</h3>

                <div id="totalDomain" class="muted" hidden></div>
                <div id="totalPrice" class="total-amount">0.00 €</div>
            </div>

            <div class="total__pay">
                <button class="btn--primary" type="submit" form="regForm">Pay</button>
                <label class="check" style="margin-left:12px;">
                    <input type="checkbox" id="autoprolong" name="autoprolong">
                    <span>Autoprolong</span>
                </label>
            </div>
        </section>
    </fieldset>

@endsection

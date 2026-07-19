<div class="wt-pane">
  <label>{{ __('ui.wt_input') }}</label>
  <textarea id="w-in" class="wt-ta" rows="5" spellcheck="false" dir="ltr"
            placeholder="eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjMifQ.signature"></textarea>
</div>
<div class="wt-bar">
  <button class="btn btn-glass" id="w-clear">{{ __('ui.wt_clear') }}</button>
  <span class="wt-status" id="w-msg"></span>
</div>
<div id="w-res" hidden>
  <div class="wt-io" style="margin-top:16px">
    <div class="wt-pane">
      <label>Header</label>
      <textarea id="w-head" class="wt-ta" rows="7" readonly dir="ltr"></textarea>
    </div>
    <div class="wt-pane">
      <label>Payload</label>
      <textarea id="w-pay" class="wt-ta" rows="7" readonly dir="ltr"></textarea>
    </div>
  </div>
  <div class="wt-out-box" id="w-claims"></div>
</div>

<script>
(function () {
  const $ = id => document.getElementById(id);
  const L = {
    invalid: @json(__('ui.wt_jwt_invalid')),
    alg:     @json(__('ui.wt_jwt_alg')),
    issued:  @json(__('ui.wt_jwt_issued')),
    expires: @json(__('ui.wt_jwt_expires')),
    valid:   @json(__('ui.wt_jwt_valid')),
    expired: @json(__('ui.wt_jwt_expired')),
    noexp:   @json(__('ui.wt_jwt_noexp')),
    subject: @json(__('ui.wt_jwt_subject')),
  };

  /* base64url → متن، با پشتیبانی کامل یونیکد */
  function b64url(str) {
    let s = str.replace(/-/g, '+').replace(/_/g, '/');
    while (s.length % 4) s += '=';
    const bin = atob(s);
    const bytes = Uint8Array.from(bin, c => c.charCodeAt(0));
    return new TextDecoder().decode(bytes);
  }

  const fmt = ts => new Date(ts * 1000).toISOString().replace('T', ' ').slice(0, 19) + ' UTC';

  function run() {
    const raw = $('w-in').value.trim();
    const msg = $('w-msg'), res = $('w-res');
    if (!raw) { res.hidden = true; msg.textContent = ''; msg.className = 'wt-status'; return; }

    const parts = raw.split('.');
    if (parts.length < 2) { res.hidden = true; msg.textContent = L.invalid; msg.className = 'wt-status err'; return; }

    let head, pay;
    try {
      head = JSON.parse(b64url(parts[0]));
      pay = JSON.parse(b64url(parts[1]));
    } catch (e) {
      res.hidden = true; msg.textContent = L.invalid; msg.className = 'wt-status err'; return;
    }

    $('w-head').value = JSON.stringify(head, null, 2);
    $('w-pay').value = JSON.stringify(pay, null, 2);

    const rows = [];
    if (head.alg) rows.push([L.alg, head.alg]);
    if (pay.sub) rows.push([L.subject, String(pay.sub)]);
    if (pay.iat) rows.push([L.issued, fmt(pay.iat)]);

    if (pay.exp) {
      const left = pay.exp - Math.floor(Date.now() / 1000);
      rows.push([L.expires, fmt(pay.exp) + ' — ' + (left > 0 ? L.valid : L.expired)]);
    } else {
      rows.push([L.expires, L.noexp]);
    }

    $('w-claims').innerHTML = rows.map(r =>
      '<div class="wt-out-row"><span>' + r[0] + '</span><b dir="ltr">' + r[1] + '</b></div>').join('');

    res.hidden = false;
    msg.textContent = ''; msg.className = 'wt-status';
  }

  $('w-in').addEventListener('input', run);
  $('w-clear').onclick = () => { $('w-in').value = ''; run(); };
})();
</script>

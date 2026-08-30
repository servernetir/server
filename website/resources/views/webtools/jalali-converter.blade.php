@php $jcDays = [__('ui.wt_sat'), __('ui.wt_sun'), __('ui.wt_mon'), __('ui.wt_tue'), __('ui.wt_wed'), __('ui.wt_thu'), __('ui.wt_fri')]; @endphp
<div class="wt-two">
  <div class="wt-pane">
    <label>{{ __('ui.wt_jalali') }}</label>
    <div class="wt-date">
      <input type="number" id="jy" placeholder="1405" min="1" max="1700" aria-label="{{ __('ui.wt_jalali') }}">
      <input type="number" id="jm" placeholder="1" min="1" max="12">
      <input type="number" id="jd" placeholder="1" min="1" max="31">
    </div>
  </div>
  <div class="wt-pane">
    <label>{{ __('ui.wt_gregorian') }}</label>
    <div class="wt-date">
      <input type="number" id="gy" placeholder="2026" min="700" max="2300" aria-label="{{ __('ui.wt_gregorian') }}">
      <input type="number" id="gm" placeholder="3" min="1" max="12">
      <input type="number" id="gd" placeholder="21" min="1" max="31">
    </div>
  </div>
</div>
<div class="wt-bar">
  <button class="btn btn-primary" id="d-today">{{ __('ui.wt_today') }}</button>
  <span class="wt-status" id="d-msg"></span>
</div>
<div class="wt-out-box" id="d-out"></div>

<script>
(function () {
  /* الگوریتم استاندارد تبدیل جلالی ↔ میلادی بر پایه‌ی شمارش روز ژولیَن.
     جدول BREAKS سال‌های کبیسه‌ی تقویم جلالی را دقیق تعیین می‌کند؛ بدون آن،
     تبدیل در بازه‌های طولانی چند روز خطا پیدا می‌کند. */
  const div = (a, b) => Math.trunc(a / b);
  const BREAKS = [-61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210,
                  1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178];

  function jalCal(jy) {
    const bl = BREAKS.length;
    let leapJ = -14, jp = BREAKS[0], jm, jump = 0, n, i;
    if (jy < jp || jy >= BREAKS[bl - 1]) return null;
    for (i = 1; i < bl; i++) {
      jm = BREAKS[i];
      jump = jm - jp;
      if (jy < jm) break;
      leapJ += div(jump, 33) * 8 + div(jump % 33, 4);
      jp = jm;
    }
    n = jy - jp;
    leapJ += div(n, 33) * 8 + div((n % 33) + 3, 4);
    if ((jump % 33) === 4 && (jump - n) === 4) leapJ += 1;
    const gy = jy + 621;
    const leapG = div(gy, 4) - div((div(gy, 100) + 1) * 3, 4) - 150;
    const march = 20 + leapJ - leapG;
    if (jump - n < 6) n = n - jump + div(jump + 4, 33) * 33;
    let leap = (((n + 1) % 33) - 1) % 4;
    if (leap === -1) leap = 4;
    return { leap: leap, gy: gy, march: march };
  }

  function g2d(gy, gm, gd) {
    const d = div((gy + div(gm - 8, 6) + 100100) * 1461, 4)
            + div(153 * ((gm + 9) % 12) + 2, 5) + gd - 34840408;
    return d - div(div(gy + 100100 + div(gm - 8, 6), 100) * 3, 4) + 752;
  }

  function d2g(jdn) {
    let j = 4 * jdn + 139361631;
    j += div(div(4 * jdn + 183187720, 146097) * 3, 4) * 4 - 3908;
    const i = div(j % 1461, 4) * 5 + 308;
    const gd = div(i % 153, 5) + 1;
    const gm = div(i, 153) % 12 + 1;
    const gy = div(j, 1461) - 100100 + div(8 - gm, 6);
    return [gy, gm, gd];
  }

  function j2d(jy, jm, jd) {
    const r = jalCal(jy);
    if (!r) return null;
    return g2d(r.gy, 3, r.march) + (jm - 1) * 31 - div(jm, 7) * (jm - 7) + jd - 1;
  }

  function d2j(jdn) {
    const gy = d2g(jdn)[0];
    let jy = gy - 621;
    let r = jalCal(jy);
    if (!r) return null;
    let k = jdn - g2d(r.gy, 3, r.march);
    if (k >= 0) {
      if (k <= 185) return [jy, 1 + div(k, 31), (k % 31) + 1];
      k -= 186;
    } else {
      jy -= 1;
      r = jalCal(jy);
      if (!r) return null;
      k += 179;
      if (r.leap === 1) k += 1;
    }
    return [jy, 7 + div(k, 30), (k % 30) + 1];
  }

  const $ = id => document.getElementById(id);
  const msg = $('d-msg'), out = $('d-out');
  const DAYS = @json($jcDays);
  const L = {
    jal:  @json(__('ui.wt_jalali')),
    greg: @json(__('ui.wt_gregorian')),
    wd:   @json(__('ui.wt_weekday')),
    err:  @json(__('ui.wt_date_err')),
  };
  let busy = false;

  function fail() { msg.textContent = L.err; msg.className = 'wt-status err'; out.innerHTML = ''; }

  function render(jy, jm, jd, gy, gm, gd, jdn) {
    const iso = gy + '-' + String(gm).padStart(2, '0') + '-' + String(gd).padStart(2, '0');
    const jal = jy + '/' + String(jm).padStart(2, '0') + '/' + String(jd).padStart(2, '0');
    out.innerHTML =
        '<div class="wt-out-row"><span>' + L.jal + '</span><b dir="ltr">' + jal + '</b></div>'
      + '<div class="wt-out-row"><span>' + L.greg + '</span><b dir="ltr">' + iso + '</b></div>'
      // JDN%7==5 یعنی شنبه، و DAYS از شنبه شروع می‌شود → افست ۲
      + '<div class="wt-out-row"><span>' + L.wd + '</span><b>' + DAYS[(jdn + 2) % 7] + '</b></div>';
    msg.textContent = ''; msg.className = 'wt-status';
  }

  function fromJ() {
    if (busy) return;
    const jy = +$('jy').value, jm = +$('jm').value, jd = +$('jd').value;
    if (!jy || !jm || !jd) return;
    if (jm < 1 || jm > 12 || jd < 1 || jd > 31) return fail();
    const jdn = j2d(jy, jm, jd);
    if (jdn === null) return fail();
    const g = d2g(jdn);
    busy = true; $('gy').value = g[0]; $('gm').value = g[1]; $('gd').value = g[2]; busy = false;
    render(jy, jm, jd, g[0], g[1], g[2], jdn);
  }

  function fromG() {
    if (busy) return;
    const gy = +$('gy').value, gm = +$('gm').value, gd = +$('gd').value;
    if (!gy || !gm || !gd) return;
    if (gm < 1 || gm > 12 || gd < 1 || gd > 31) return fail();
    const jdn = g2d(gy, gm, gd);
    const j = d2j(jdn);
    if (!j) return fail();
    busy = true; $('jy').value = j[0]; $('jm').value = j[1]; $('jd').value = j[2]; busy = false;
    render(j[0], j[1], j[2], gy, gm, gd, jdn);
  }

  ['jy', 'jm', 'jd'].forEach(id => $(id).addEventListener('input', fromJ));
  ['gy', 'gm', 'gd'].forEach(id => $(id).addEventListener('input', fromG));
  $('d-today').onclick = () => {
    const d = new Date();
    busy = true; $('gy').value = d.getFullYear(); $('gm').value = d.getMonth() + 1; $('gd').value = d.getDate(); busy = false;
    fromG();
  };
  $('d-today').click();
})();
</script>

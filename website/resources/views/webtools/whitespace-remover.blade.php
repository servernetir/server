@php
  $wsStats = [
    __('ui.wt_ws_before'), __('ui.wt_ws_after'), __('ui.wt_ws_removed'),
    __('ui.wt_ws_lines_b'), __('ui.wt_ws_lines_a'), __('ui.wt_ws_zwnj_kept'),
  ];
  $wsSample = [__('ui.wt_ws_sample_a'), __('ui.wt_ws_sample_b')];
@endphp

<style>
  .ws-note{display:flex;align-items:flex-start;gap:9px;margin-top:16px;font-size:13px;line-height:1.85;
    color:var(--muted);background:rgba(52,211,153,.08);border:1px solid rgba(52,211,153,.24);border-radius:12px;padding:11px 14px}
  .ws-note .icon{width:16px;height:16px;color:var(--green);flex:none;margin-top:4px}
  .ws-warn{display:none;align-items:flex-start;gap:9px;margin-top:14px;font-size:13px;line-height:1.85;
    color:var(--text);background:rgba(251,191,36,.1);border:1px solid rgba(251,191,36,.34);border-radius:12px;padding:11px 14px}
  .ws-warn.on{display:flex}
  .ws-warn .icon{width:16px;height:16px;color:#fbbf24;flex:none;margin-top:4px}
  .ws-risk{color:#fbbf24}
  .ws-revwrap{display:none;margin-top:18px}
  .ws-revwrap.on{display:block}
  .ws-legend{display:flex;flex-wrap:wrap;gap:7px 16px;font-size:12px;color:var(--dim);margin-bottom:9px}
  .ws-legend span{display:inline-flex;align-items:center;gap:5px}
  .ws-legend b{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-weight:700}
  .ws-rev{white-space:pre-wrap;word-break:break-word;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
    font-size:13.5px;line-height:2.1;background:var(--surface-2);border:1px solid var(--line-2);border-radius:12px;
    padding:13px 15px;min-height:64px;max-height:320px;overflow:auto}
  .ws-m{font-style:normal;opacity:.9}
  .ws-m.sp{color:var(--cyan)}
  .ws-m.tb{color:var(--blue)}
  .ws-m.br{color:var(--violet)}
  .ws-m.nb{color:#fbbf24}
  .ws-m.zw{color:var(--green);font-weight:800}
  .ws-m.iv{color:#ff6b6b}
  html[data-theme="light"] .ws-note{background:rgba(16,185,129,.1);border-color:rgba(16,185,129,.34);color:#0f5132}
  html[data-theme="light"] .ws-warn{background:rgba(251,191,36,.18);border-color:rgba(180,83,9,.4);color:#6b3d00}
  html[data-theme="light"] .ws-warn .icon{color:#b45309}
  html[data-theme="light"] .ws-risk{color:#b45309}
  html[data-theme="light"] .ws-m.nb{color:#b45309}
  html[data-theme="light"] .ws-m.iv{color:#c02626}
</style>

<div class="wt-io">
  <div class="wt-pane">
    <label>{{ __('ui.wt_input') }}</label>
    <textarea id="ws-in" class="wt-ta" rows="13" dir="auto" spellcheck="false" placeholder="{{ __('ui.wt_ws_ph') }}"></textarea>
  </div>
  <div class="wt-pane">
    <label>{{ __('ui.wt_output') }}</label>
    <textarea id="ws-out" class="wt-ta" rows="13" dir="auto" spellcheck="false" readonly></textarea>
  </div>
</div>

<div class="ws-note">
  <svg class="icon"><use href="#i-shield"/></svg>
  <span>{{ __('ui.wt_ws_safe') }}</span>
</div>

<div class="wt-fields">
  <label class="wt-chk"><input type="checkbox" id="ws-collapse" checked> {{ __('ui.wt_ws_collapse') }}</label>
  <label class="wt-chk"><input type="checkbox" id="ws-trimline" checked> {{ __('ui.wt_ws_trimlines') }}</label>
  <label class="wt-chk"><input type="checkbox" id="ws-trimall" checked> {{ __('ui.wt_ws_trimall') }}</label>
  <label class="wt-chk"><input type="checkbox" id="ws-tabs" checked> {{ __('ui.wt_ws_tabs') }}</label>
  <label class="wt-range">{{ __('ui.wt_ws_tabw') }}
    <select id="ws-tabw" class="wt-select">
      <option value="1">1</option>
      <option value="2">2</option>
      <option value="4" selected>4</option>
      <option value="8">8</option>
    </select>
  </label>
  <label class="wt-chk"><input type="checkbox" id="ws-nbsp" checked> {{ __('ui.wt_ws_nbsp') }}</label>
  <label class="wt-chk"><input type="checkbox" id="ws-blank"> {{ __('ui.wt_ws_blank') }}</label>
  <label class="wt-chk"><input type="checkbox" id="ws-nobreak"> {{ __('ui.wt_ws_nobreak') }}</label>
  <label class="wt-chk"><input type="checkbox" id="ws-inv"> {{ __('ui.wt_ws_inv') }}</label>
  <label class="wt-chk ws-risk"><input type="checkbox" id="ws-zwnj"> {{ __('ui.wt_ws_zwnj') }}</label>
</div>

<div class="ws-warn" id="ws-warn">
  <svg class="icon"><use href="#i-zap"/></svg>
  <span>{{ __('ui.wt_ws_zwnj_warn') }}</span>
</div>

<div class="wt-bar">
  <button class="btn btn-primary" id="ws-copy" data-done="{{ __('ui.wt_copied') }}">{{ __('ui.wt_copy') }}</button>
  <button class="btn btn-glass" id="ws-sample">{{ __('ui.wt_ws_sample') }}</button>
  <button class="btn btn-glass" id="ws-reset">{{ __('ui.wt_ws_reset') }}</button>
  <button class="btn btn-glass" id="ws-clear">{{ __('ui.wt_clear') }}</button>
  <label class="wt-chk"><input type="checkbox" id="ws-rev"> {{ __('ui.wt_ws_reveal') }}</label>
  <span class="wt-status" id="ws-msg"></span>
</div>

<div class="wt-stats" id="ws-stats"></div>

<div class="ws-revwrap" id="ws-revwrap">
  <div class="ws-legend">
    <span><b class="ws-m sp">·</b> {{ __('ui.wt_ws_lbl_space') }}</span>
    <span><b class="ws-m tb">→</b> {{ __('ui.wt_ws_lbl_tab') }}</span>
    <span><b class="ws-m br">¶</b> {{ __('ui.wt_ws_lbl_break') }}</span>
    <span><b class="ws-m nb">°</b> {{ __('ui.wt_ws_lbl_nbsp') }}</span>
    <span><b class="ws-m zw">│</b> {{ __('ui.wt_ws_lbl_zwnj') }}</span>
    <span><b class="ws-m iv">¤</b> {{ __('ui.wt_ws_lbl_inv') }}</span>
  </div>
  <div class="ws-rev" id="ws-rev" dir="auto"></div>
</div>

<script>
(function () {
  var $ = function (id) { return document.getElementById(id); };
  var C = String.fromCharCode;

  /* نویسه‌های ویژه با کد ساخته می‌شوند تا در سورس نامرئی نباشند و در ویرایش خراب نشوند */
  var NL = C(10), TAB = C(9), SP = ' ';

  /* فاصله‌های افقی: فاصله، تب، NBSP و فاصله‌های تایپوگرافیک یونیکد
     — نیم‌فاصله (U+200C) عمداً در هیچ‌کدام از این کلاس‌ها نیست. */
  var HS = SP + TAB + C(0x00A0) + C(0x1680) + C(0x2000) + '-' + C(0x200A) + C(0x202F) + C(0x205F) + C(0x3000);
  var VS = NL + C(11) + C(12) + C(13) + C(0x0085) + C(0x2028) + C(0x2029);

  var RE_RUN   = new RegExp('[' + HS + ']{2,}', 'g');
  var RE_LEAD  = new RegExp('^[' + HS + ']+');
  var RE_TRAIL = new RegExp('[' + HS + ']+$');
  var RE_BLANK = new RegExp('^[' + HS + ']*$');
  var RE_UNI   = new RegExp('[' + C(0x00A0) + C(0x1680) + C(0x2000) + '-' + C(0x200A) + C(0x202F) + C(0x205F) + C(0x3000) + ']', 'g');
  var RE_INV   = new RegExp('[' + C(0x200B) + C(0x2060) + C(0xFEFF) + C(0x00AD) + C(0x200E) + C(0x200F) + C(0x061C) + ']', 'g');
  var RE_JOIN  = new RegExp('[' + C(0x200C) + C(0x200D) + ']', 'g');
  var RE_ZWNJ  = new RegExp(C(0x200C), 'g');
  var RE_TRIMA = new RegExp('^[' + HS + VS + ']+');
  var RE_TRIMZ = new RegExp('[' + HS + VS + ']+$');

  var MAX = 500000;      /* سقف پردازش تا یک متن غول‌آسا تب مرورگر را قفل نکند */
  var REV_MAX = 20000;   /* سقف پیش‌نمایش نویسه‌های نامرئی */

  var LBL = @json($wsStats);
  var TRUNC = @json(__('ui.wt_ws_trunc'));
  var MORE = @json(__('ui.wt_ws_more'));
  var SAMPLE = @json($wsSample);

  var DEF = {
    'ws-collapse': true, 'ws-trimline': true, 'ws-trimall': true, 'ws-tabs': true,
    'ws-nbsp': true, 'ws-blank': false, 'ws-nobreak': false, 'ws-inv': false, 'ws-zwnj': false
  };

  function isUniSpace(c) {
    return c === 0x00A0 || c === 0x1680 || (c >= 0x2000 && c <= 0x200A) ||
           c === 0x202F || c === 0x205F || c === 0x3000;
  }
  function isInvisible(c) {
    return c === 0x200B || c === 0x2060 || c === 0xFEFF || c === 0x00AD ||
           c === 0x200E || c === 0x200F || c === 0x061C;
  }

  /* یک دنباله‌ی فاصله را به یک نویسه کوتاه می‌کند: اگر فاصله‌ی معمولی داشت، فاصله؛
     وگرنه همان نویسه‌ی اول (مثلاً چند تب پشت‌سرهم می‌شود یک تب). */
  function collapseRun(m) { return m.indexOf(SP) >= 0 ? SP : m.charAt(0); }

  function clean(src, o) {
    /* پایان‌خط‌ها همیشه یکدست می‌شوند تا شمارش خط‌ها بین ویندوز و لینوکس فرق نکند */
    var s = src.split(C(13) + NL).join(NL).split(C(13)).join(NL);

    if (o.tabs) {
      var pad = '', i;
      for (i = 0; i < o.tabw; i++) pad += SP;
      s = s.split(TAB).join(pad);
    }
    if (o.nbsp) s = s.replace(RE_UNI, SP);
    if (o.inv)  s = s.replace(RE_INV, '');
    if (o.zwnj) s = s.replace(RE_JOIN, '');

    if (o.trimline) {
      s = s.split(NL).map(function (l) {
        return l.replace(RE_LEAD, '').replace(RE_TRAIL, '');
      }).join(NL);
    }
    if (o.collapse) s = s.replace(RE_RUN, collapseRun);

    if (o.blank) {
      /* خطی که فقط نیم‌فاصله دارد خالی نیست و حذف نمی‌شود */
      s = s.split(NL).filter(function (l) { return !RE_BLANK.test(l); }).join(NL);
    }
    if (o.nobreak) {
      s = s.split(NL).join(SP);
      if (o.collapse) s = s.replace(RE_RUN, collapseRun);
    }
    if (o.trimall) s = s.replace(RE_TRIMA, '').replace(RE_TRIMZ, '');
    return s;
  }

  function reveal(s) {
    var txt = s.slice(0, REV_MAX), out = [], i, ch, c;
    for (i = 0; i < txt.length; i++) {
      ch = txt.charAt(i);
      c = txt.charCodeAt(i);
      if (ch === SP) out.push('<i class="ws-m sp">·</i>');
      else if (ch === TAB) out.push('<i class="ws-m tb">→</i>');
      else if (ch === NL) out.push('<i class="ws-m br">¶</i>' + NL);
      else if (c === 0x200C || c === 0x200D) out.push('<i class="ws-m zw">│</i>');
      else if (isUniSpace(c)) out.push('<i class="ws-m nb">°</i>');
      else if (isInvisible(c)) out.push('<i class="ws-m iv">¤</i>');
      else if (ch === '&') out.push('&amp;');
      else if (ch === '<') out.push('&lt;');
      else if (ch === '>') out.push('&gt;');
      else out.push(ch);
    }
    if (s.length > REV_MAX) out.push(NL + MORE);
    return out.join('');
  }

  function lines(s) {
    return s.length ? s.split(C(13) + NL).join(NL).split(C(13)).join(NL).split(NL).length : 0;
  }

  function run() {
    var raw = $('ws-in').value, cut = false;
    if (raw.length > MAX) { raw = raw.slice(0, MAX); cut = true; }

    var o = {
      collapse: $('ws-collapse').checked,
      trimline: $('ws-trimline').checked,
      trimall:  $('ws-trimall').checked,
      tabs:     $('ws-tabs').checked,
      tabw:     parseInt($('ws-tabw').value, 10) || 4,
      nbsp:     $('ws-nbsp').checked,
      blank:    $('ws-blank').checked,
      nobreak:  $('ws-nobreak').checked,
      inv:      $('ws-inv').checked,
      zwnj:     $('ws-zwnj').checked
    };

    var out = clean(raw, o);
    $('ws-out').value = out;

    $('ws-warn').className = 'ws-warn' + (o.zwnj ? ' on' : '');

    var vals = [
      raw.length, out.length, Math.max(0, raw.length - out.length),
      lines(raw), lines(out), (out.match(RE_ZWNJ) || []).length
    ];
    $('ws-stats').innerHTML = LBL.map(function (t, k) {
      return '<div class="wt-stat"><b dir="ltr">' + vals[k] + '</b><span>' + t + '</span></div>';
    }).join('');

    $('ws-msg').textContent = cut ? TRUNC.split(':n').join(MAX) : '';
    $('ws-msg').className = 'wt-status' + (cut ? ' err' : '');

    if ($('ws-rev').checked) $('ws-rev').innerHTML = reveal(out);
  }

  var boxes = ['ws-collapse', 'ws-trimline', 'ws-trimall', 'ws-tabs', 'ws-nbsp',
               'ws-blank', 'ws-nobreak', 'ws-inv', 'ws-zwnj'];
  boxes.forEach(function (id) { $(id).addEventListener('change', run); });
  $('ws-tabw').addEventListener('change', run);
  $('ws-in').addEventListener('input', run);

  $('ws-rev').addEventListener('change', function () {
    $('ws-revwrap').className = 'ws-revwrap' + (this.checked ? ' on' : '');
    run();
  });

  $('ws-copy').addEventListener('click', function () { wtCopy(this, $('ws-out').value); });

  $('ws-clear').addEventListener('click', function () {
    $('ws-in').value = '';
    $('ws-rev').checked = false;
    $('ws-revwrap').className = 'ws-revwrap';
    $('ws-in').focus();
    run();
  });

  $('ws-reset').addEventListener('click', function () {
    boxes.forEach(function (id) { $(id).checked = DEF[id]; });
    $('ws-tabw').value = '4';
    run();
  });

  $('ws-sample').addEventListener('click', function () {
    $('ws-in').value = '   ' + SAMPLE[0] + '   ' + NL + NL + NL + TAB + SAMPLE[1] + '  ' + TAB;
    run();
  });

  run();
})();
</script>

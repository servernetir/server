<style>
.csm-meter{margin-top:18px;height:10px;border-radius:99px;background:var(--surface-2);border:1px solid var(--line);overflow:hidden}
.csm-meter span{display:block;height:100%;width:0;border-radius:99px;background:linear-gradient(90deg,#22d3ee,#34d399);transition:width .3s ease}
.csm-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(132px,1fr));gap:12px;margin-top:12px}
.csm-stat{background:var(--surface-2);border:1px solid var(--line);border-radius:13px;padding:14px 12px;text-align:center}
.csm-stat b{display:block;font-size:20px;font-weight:800;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;color:var(--text);line-height:1.35;word-break:break-all}
.csm-stat span{font-size:11.5px;color:var(--dim)}
.csm-stat.csm-win b{color:var(--green)}
.csm-stat.csm-big b{font-size:24px;background:linear-gradient(100deg,#34D399,#22D3EE);-webkit-background-clip:text;background-clip:text;color:transparent}
.csm-note{display:flex;align-items:flex-start;gap:9px;font-size:12.5px;line-height:1.85;color:var(--dim);margin-top:16px;padding-top:14px;border-top:1px solid var(--line)}
.csm-note .icon{width:15px;height:15px;color:var(--green);flex:none;margin-top:4px}
</style>

<div class="wt-io">
  <div class="wt-pane">
    <label>{{ __('ui.wt_input') }}</label>
    <textarea id="csm-in" class="wt-ta" rows="15" dir="ltr" spellcheck="false" placeholder=".card { color : #AABBCC;  margin: 0.50em 0px; }"></textarea>
  </div>
  <div class="wt-pane">
    <label>{{ __('ui.wt_output') }}</label>
    <textarea id="csm-out" class="wt-ta" rows="15" dir="ltr" readonly spellcheck="false"></textarea>
  </div>
</div>

<div class="csm-meter"><span id="csm-fill"></span></div>

<div class="csm-stats">
  <div class="csm-stat"><b id="csm-so" dir="ltr">—</b><span>{{ __('ui.wt_csm_orig') }}</span></div>
  <div class="csm-stat"><b id="csm-sm" dir="ltr">—</b><span>{{ __('ui.wt_csm_min') }}</span></div>
  <div class="csm-stat csm-win"><b id="csm-ss" dir="ltr">—</b><span>{{ __('ui.wt_csm_saved') }}</span></div>
  <div class="csm-stat csm-win csm-big"><b id="csm-sp" dir="ltr">—</b><span>{{ __('ui.wt_csm_ratio') }}</span></div>
</div>

<div class="wt-fields">
  <label class="wt-chk"><input type="checkbox" id="csm-oc" checked> {{ __('ui.wt_csm_opt_color') }}</label>
  <label class="wt-chk"><input type="checkbox" id="csm-on" checked> {{ __('ui.wt_csm_opt_num') }}</label>
  <label class="wt-chk"><input type="checkbox" id="csm-oe" checked> {{ __('ui.wt_csm_opt_empty') }}</label>
  <label class="wt-chk"><input type="checkbox" id="csm-ob" checked> {{ __('ui.wt_csm_opt_bang') }}</label>
</div>

<div class="csm-note">
  <svg class="icon"><use href="#i-shield"/></svg>
  <span>{{ __('ui.wt_csm_note') }}</span>
</div>

<div class="wt-bar">
  <button class="btn btn-primary" id="csm-run" type="button">{{ __('ui.wt_minify') }}</button>
  <button class="btn btn-glass" id="csm-copy" type="button" data-done="{{ __('ui.wt_copied') }}">{{ __('ui.wt_copy') }}</button>
  <button class="btn btn-glass" id="csm-sample" type="button">{{ __('ui.wt_csm_sample') }}</button>
  <button class="btn btn-glass" id="csm-clear" type="button">{{ __('ui.wt_clear') }}</button>
  <span class="wt-status" id="csm-msg"></span>
</div>

<script>
(function () {
  var $ = function (id) { return document.getElementById(id); };

  var T = {
    done:     @json(__('ui.wt_csm_done')),
    rules:    @json(__('ui.wt_csm_rules')),
    dropped:  @json(__('ui.wt_csm_dropped')),
    nochange: @json(__('ui.wt_csm_nochange')),
    toobig:   @json(__('ui.wt_csm_toobig')),
    warn:     @json(__('ui.wt_csm_warn'))
  };

  var MAX = 2000000;                 // hard cap: 2M chars, keeps the tab responsive
  var LF  = String.fromCharCode(10);
  var CE  = '*' + '/';               // comment end, split so it cannot close anything

  function mk(list) {
    var o = {}, a = list.toLowerCase().split(' ');
    for (var i = 0; i < a.length; i++) { if (a[i]) { o[a[i]] = 1; } }
    return o;
  }

  // at-rules whose body holds further rules (not declarations)
  var RULELIST = mk('media supports container layer scope document -moz-document ' +
                    'keyframes -webkit-keyframes -moz-keyframes -o-keyframes');

  // functions where the space around + and - is part of the syntax and must survive
  var MATHFN = mk('calc min max clamp -webkit-calc -moz-calc');

  // characters that never need a space beside them inside [ ... ]
  var ATTROP = { '[': 1, ']': 1, '=': 1, '~': 1, '|': 1, '^': 1, '$': 1, '*': 1 };

  // ---- tokenizer -----------------------------------------------------------
  // Strings, url() bodies and comments are lifted out as whole tokens, so no
  // later rewrite step can ever reach inside them.
  function isWS(c) { return c === 32 || c === 9 || c === 10 || c === 13 || c === 12; }
  function isDig(c) { return c >= 48 && c <= 57; }
  function isNameStart(c) {
    return (c >= 97 && c <= 122) || (c >= 65 && c <= 90) || c === 95 || c === 45 || c === 92 || c > 127;
  }
  function isName(c) { return isNameStart(c) || isDig(c); }

  function tokenize(s) {
    var out = [], n = s.length, i = 0;

    function identEnd(j) {
      while (j < n) {
        var d = s.charCodeAt(j);
        if (d === 92) { j += 2; continue; }      // escape: consume the pair
        if (isName(d)) { j++; } else { break; }
      }
      return j;
    }

    function readNum() {
      var j = i;
      if (s.charCodeAt(j) === 45) { j++; }
      while (j < n && isDig(s.charCodeAt(j))) { j++; }
      if (s.charCodeAt(j) === 46 && isDig(s.charCodeAt(j + 1))) {
        j++;
        while (j < n && isDig(s.charCodeAt(j))) { j++; }
      }
      var e = s.charCodeAt(j);
      if (e === 101 || e === 69) {               // scientific notation
        var k = j + 1, g = s.charCodeAt(k);
        if (g === 43 || g === 45) { k++; }
        if (isDig(s.charCodeAt(k))) {
          k++;
          while (k < n && isDig(s.charCodeAt(k))) { k++; }
          j = k;
        }
      }
      var num = s.slice(i, j), u = '';
      if (s.charCodeAt(j) === 37) { u = '%'; j++; }
      else if (isNameStart(s.charCodeAt(j))) { var m = identEnd(j); u = s.slice(j, m); j = m; }
      out.push({ t: 'num', v: num, u: u });
      return j > i ? j : i + 1;
    }

    while (i < n) {
      var c = s.charCodeAt(i), j;

      if (isWS(c)) {
        j = i;
        while (j < n && isWS(s.charCodeAt(j))) { j++; }
        out.push({ t: 'ws' }); i = j; continue;
      }

      if (c === 47 && s.charCodeAt(i + 1) === 42) {          // comment
        j = s.indexOf(CE, i + 2);
        var closed = j >= 0;
        j = closed ? j + 2 : n;
        out.push({ t: 'com', v: s.slice(i, j), open: !closed });
        i = j; continue;
      }

      if (c === 34 || c === 39) {                            // quoted string
        var q = c, done = false;
        j = i + 1;
        while (j < n) {
          var d = s.charCodeAt(j);
          if (d === 92) { j += 2; continue; }
          if (d === q) { j++; done = true; break; }
          j++;
        }
        out.push({ t: 'str', v: s.slice(i, j), open: !done });
        i = j; continue;
      }

      // a minus only starts a number when a digit follows: --var and -webkit- stay idents
      if (c === 45 && (isDig(s.charCodeAt(i + 1)) ||
          (s.charCodeAt(i + 1) === 46 && isDig(s.charCodeAt(i + 2))))) { i = readNum(); continue; }
      if (isDig(c) || (c === 46 && isDig(s.charCodeAt(i + 1)))) { i = readNum(); continue; }

      if (isNameStart(c)) {
        j = identEnd(i);
        var id = s.slice(i, j);
        if (id.length === 3 && id.toLowerCase() === 'url' && s.charCodeAt(j) === 40) {
          var k = j + 1, inner = '', m2;
          while (k < n && isWS(s.charCodeAt(k))) { k++; }
          var qc = s.charCodeAt(k);
          if (qc === 34 || qc === 39) {                      // url("...")
            m2 = k + 1;
            while (m2 < n) {
              var d2 = s.charCodeAt(m2);
              if (d2 === 92) { m2 += 2; continue; }
              if (d2 === qc) { m2++; break; }
              m2++;
            }
            inner = s.slice(k, m2); k = m2;
          } else {                                           // url(bare/path.png)
            m2 = k;
            while (m2 < n) {
              var d3 = s.charCodeAt(m2);
              if (d3 === 92) { m2 += 2; continue; }
              if (d3 === 41) { break; }
              m2++;
            }
            var e2 = m2;
            while (e2 > k && isWS(s.charCodeAt(e2 - 1))) { e2--; }
            inner = s.slice(k, e2); k = m2;
          }
          while (k < n && isWS(s.charCodeAt(k))) { k++; }
          if (s.charCodeAt(k) === 41) { k++; }
          out.push({ t: 'url', v: 'url(' + inner + ')' });
          i = k > i ? k : i + 1; continue;
        }
        out.push({ t: 'ident', v: id }); i = j > i ? j : i + 1; continue;
      }

      if (c === 35) { j = identEnd(i + 1); out.push({ t: 'hash', v: s.slice(i, j) }); i = j > i ? j : i + 1; continue; }
      if (c === 64) { j = identEnd(i + 1); out.push({ t: 'at',   v: s.slice(i, j) }); i = j > i ? j : i + 1; continue; }

      out.push({ t: 'p', v: s.charAt(i) }); i++;
    }
    return out;
  }

  // ---- value rewrites ------------------------------------------------------
  // 0.50 -> .5, 1.0 -> 1, 010 -> 10. Units are never dropped: 0px inside calc()
  // and every <time> value must keep its unit to stay valid.
  function trimNum(raw) {
    if (raw.indexOf('e') >= 0 || raw.indexOf('E') >= 0) { return raw; }
    var sign = '', s = raw;
    if (s.charAt(0) === '-') { sign = '-'; s = s.slice(1); }
    if (s.indexOf('.') >= 0) {
      s = s.replace(/0+$/, '');
      if (s.charAt(s.length - 1) === '.') { s = s.slice(0, -1); }
    }
    s = s.replace(/^0+(?=[0-9])/, '');
    if (s.charAt(0) === '0' && s.charAt(1) === '.') { s = s.slice(1); }
    if (s === '' || s === '.') { s = '0'; }
    if (s === '0') { sign = ''; }
    return sign + s;
  }

  // #AABBCC -> #abc, #AABBCCDD -> #abcd. Only pair-identical values collapse.
  function shortHex(h) {
    var b = h.slice(1);
    if (!/^[0-9a-fA-F]+$/.test(b)) { return h; }
    if (b.length !== 3 && b.length !== 4 && b.length !== 6 && b.length !== 8) { return h; }
    var s = b.toLowerCase();
    if (s.length === 6 && s[0] === s[1] && s[2] === s[3] && s[4] === s[5]) {
      s = s[0] + s[2] + s[4];
    } else if (s.length === 8 && s[0] === s[1] && s[2] === s[3] && s[4] === s[5] && s[6] === s[7]) {
      s = s[0] + s[2] + s[4] + s[6];
    }
    return '#' + s;
  }

  // ---- minifier ------------------------------------------------------------
  function minify(css, opt) {
    var raw = tokenize(css), tk2 = [], warn = false, i, tk;

    for (i = 0; i < raw.length; i++) {
      tk = raw[i];
      if (tk.open) { warn = true; }
      if (tk.t === 'com') {
        if (opt.bang && tk.v.charAt(2) === '!') { tk2.push({ t: 'keep', v: tk.v }); }
        else if (tk2.length && tk2[tk2.length - 1].t !== 'ws') { tk2.push({ t: 'ws' }); }
        continue;                                  // a comment separates tokens, never joins them
      }
      if (tk.t === 'ws') {
        if (!tk2.length || tk2[tk2.length - 1].t === 'ws') { continue; }
        tk2.push(tk); continue;
      }
      tk2.push(tk);
    }
    while (tk2.length && tk2[tk2.length - 1].t === 'ws') { tk2.pop(); }

    var out = [], stack = [{ type: 'rules' }], blocks = [];
    var parens = [], mode = 'sel', atName = '', prop = '';
    var stmtStart = 0, brk = 0, kept = 0, dropped = 0;

    function emit(t) { out.push(t); }

    // decide whether the whitespace between p and x survives
    function decide(p, x) {
      var pc = p.t === 'p' ? p.v : '';
      var xc = x.t === 'p' ? x.v : '';
      if (p.t === 'keep' || x.t === 'keep') { return false; }
      if (xc === '{' || xc === '}' || xc === ';') { return false; }
      if (pc === '{' || pc === '}' || pc === ';') { return false; }
      if (pc === ',' || xc === ',') { return false; }

      // inside [ ... ]: only the operators lose their padding. The space in
      // [href$=".pdf" i] and between grid line names [col-a col-b] is load-bearing.
      if (brk > 0) { return !(ATTROP[pc] || ATTROP[xc]); }

      if (parens.length && parens[parens.length - 1].math) {
        if (pc === '(' || xc === ')') { return false; }
        return true;                               // calc(100% - 20px) keeps its spaces
      }
      if (mode === 'val') {
        if (pc === '(' || xc === ')') { return false; }
        if (pc === ':') { return false; }
        if (pc === '!' || xc === '!') { return false; }
        if (pc === '/' || xc === '/') { return false; }
        return true;                               // margin: 0 auto — the gap is data
      }
      if (mode === 'prop') {
        if (xc === ':') { return false; }
        return true;
      }
      if (mode === 'at') {
        if (parens.length) {
          if (pc === '(' || xc === ')' || pc === ':' || xc === ':') { return false; }
          return true;
        }
        return true;                               // "screen and (...)" needs its spaces
      }
      // selector: only combinators may swallow the space; "li :first-child" must not
      if (pc === '(' || xc === ')' || xc === '(') { return false; }
      if (pc === '>' || pc === '+' || pc === '~') { return false; }
      if (xc === '>' || xc === '+' || xc === '~') { return false; }
      return true;
    }

    for (i = 0; i < tk2.length; i++) {
      tk = tk2[i];

      if (tk.t === 'ws') {
        var p = tk2[i - 1], x = tk2[i + 1];
        if (p && x && decide(p, x)) { emit(' '); }
        continue;
      }

      if (tk.t === 'keep') { emit(tk.v + LF); stmtStart = out.length; continue; }

      var c = tk.t === 'p' ? tk.v : '';

      if (c === '}') {
        while (out.length && out[out.length - 1] === ';') { out.pop(); }   // last semicolon
        var bi = blocks.pop();
        if (bi && opt.empty && out.length === bi.body) {
          out.length = bi.pre; dropped++; kept--;                          // rule was empty
        } else {
          emit('}');
        }
        if (stack.length > 1) { stack.pop(); }
        mode = stack[stack.length - 1].type === 'decls' ? 'prop' : 'sel';
        stmtStart = out.length; parens = []; brk = 0; atName = ''; prop = '';
        continue;
      }

      if (c === '{') {
        var type = (mode === 'at' && RULELIST[atName]) ? 'rules' : 'decls';
        emit('{');
        stack.push({ type: type });
        blocks.push({ pre: stmtStart, body: out.length });
        kept++;
        mode = type === 'decls' ? 'prop' : 'sel';
        stmtStart = out.length; parens = []; brk = 0; atName = ''; prop = '';
        continue;
      }

      if (c === ';' && !parens.length) {
        var last = out.length ? out[out.length - 1] : '';
        if (out.length && last !== ';' && last !== '{') { emit(';'); }     // drop empty statements
        mode = stack[stack.length - 1].type === 'decls' ? 'prop' : 'sel';
        stmtStart = out.length; brk = 0; atName = ''; prop = '';
        continue;
      }

      if (c === '(') {
        // math context is read off the token immediately before "(", never off a
        // stale identifier, so a media query following a calc() is not treated as math
        var pv = tk2[i - 1], fn = (pv && pv.t === 'ident') ? pv.v.toLowerCase() : '';
        var parentMath = parens.length ? parens[parens.length - 1].math : false;
        parens.push({ math: parentMath || !!MATHFN[fn] });
        emit('('); continue;
      }
      if (c === ')') { parens.pop(); emit(')'); continue; }
      if (c === '[') { brk++; emit('['); continue; }
      if (c === ']') { if (brk > 0) { brk--; } emit(']'); continue; }

      if (c === ':' && mode === 'prop' && !parens.length && !brk) { emit(':'); mode = 'val'; continue; }

      if (tk.t === 'at')    { atName = tk.v.slice(1).toLowerCase(); mode = 'at'; emit(tk.v); continue; }
      if (tk.t === 'ident') {
        if (mode === 'prop') { prop = tk.v.toLowerCase(); }
        emit(tk.v); continue;
      }

      var inValue = (mode === 'val') || (mode === 'at' && parens.length > 0);
      if (inValue && prop !== 'unicode-range') {                           // U+0025 must not be trimmed
        if (tk.t === 'num'  && opt.num)   { emit(trimNum(tk.v) + tk.u); continue; }
        if (tk.t === 'hash' && opt.color) { emit(shortHex(tk.v)); continue; }
      }

      emit(tk.t === 'num' ? tk.v + tk.u : tk.v);
    }

    while (out.length && out[out.length - 1] === ' ') { out.pop(); }
    if (stack.length > 1) { warn = true; }
    return { out: out.join(''), rules: kept < 0 ? 0 : kept, dropped: dropped, warn: warn };
  }

  // ---- sizes ---------------------------------------------------------------
  var enc = (typeof TextEncoder !== 'undefined') ? new TextEncoder() : null;
  function bytes(s) {
    if (!s) { return 0; }
    if (enc) { return enc.encode(s).length; }
    try { return new Blob([s]).size; } catch (e) { return s.length; }
  }
  function fmtBytes(b) {
    if (b < 1024) { return b + ' B'; }
    if (b < 1048576) { return (b / 1024).toFixed(1) + ' KB'; }
    return (b / 1048576).toFixed(2) + ' MB';
  }
  function fmtPct(x) {
    var v = Math.round(x * 10) / 10;
    return ((v === Math.round(v)) ? String(Math.round(v)) : v.toFixed(1)) + '%';
  }

  // ---- wiring --------------------------------------------------------------
  function setMsg(txt, cls) {
    var m = $('csm-msg');
    m.textContent = txt || '';
    m.className = 'wt-status' + (cls ? ' ' + cls : '');
  }

  function reset() {
    $('csm-so').textContent = '—'; $('csm-sm').textContent = '—';
    $('csm-ss').textContent = '—'; $('csm-sp').textContent = '—';
    $('csm-fill').style.width = '0';
  }

  function run() {
    var src = $('csm-in').value;
    if (!src) { $('csm-out').value = ''; reset(); setMsg(''); return; }
    if (src.length > MAX) { $('csm-out').value = ''; reset(); setMsg(T.toobig, 'err'); return; }

    var opt = {
      color: $('csm-oc').checked,
      num:   $('csm-on').checked,
      empty: $('csm-oe').checked,
      bang:  $('csm-ob').checked
    };

    var res;
    try { res = minify(src, opt); }
    catch (err) { $('csm-out').value = ''; reset(); setMsg(String((err && err.message) || err), 'err'); return; }

    $('csm-out').value = res.out;

    var o = bytes(src), m = bytes(res.out), saved = o - m;
    $('csm-so').textContent = fmtBytes(o);
    $('csm-sm').textContent = fmtBytes(m);
    $('csm-ss').textContent = (saved > 0 ? '-' : '') + fmtBytes(Math.abs(saved));
    var pct = (o > 0 && saved > 0) ? (saved / o * 100) : 0;
    $('csm-sp').textContent = fmtPct(pct);
    $('csm-fill').style.width = Math.max(0, Math.min(100, pct)) + '%';

    var parts = [];
    parts.push(saved > 0 ? T.done.split(':n').join(String(saved)) : T.nochange);
    parts.push(T.rules.split(':n').join(String(res.rules)));
    if (res.dropped > 0) { parts.push(T.dropped.split(':n').join(String(res.dropped))); }
    if (res.warn) { parts.push(T.warn); }
    setMsg(parts.join(' · '), res.warn ? 'err' : (saved > 0 ? 'ok' : ''));
  }

  var tmr = null;
  function later() { clearTimeout(tmr); tmr = setTimeout(run, 180); }

  $('csm-run').onclick = run;
  $('csm-copy').onclick = function (e) { wtCopy(e.currentTarget, $('csm-out').value); };
  $('csm-clear').onclick = function () {
    $('csm-in').value = ''; $('csm-out').value = ''; reset(); setMsg('');
  };
  $('csm-sample').onclick = function () {
    var AT = String.fromCharCode(64);              // keeps a bare at-rule out of the Blade source
    $('csm-in').value = [
      '/*! ServerNet sample sheet */',
      '',
      '/* layout */',
      '.card {',
      '    color   : #AABBCC;',
      '    margin  : 0.50em 0px;',
      '    padding : 10px   20px;',
      '}',
      '',
      '.card > .title ,  .card > .meta {',
      '    font    : 12px / 1.5 Tahoma , sans-serif ;',
      '    opacity : 0.80 ;',
      '}',
      '',
      '.card a[href$=".pdf" i] { color : red !important ; }',
      '',
      '.is-empty { }',
      '',
      AT + 'media screen and (min-width: 768px) {',
      '    .card { width : calc( 100% - 2rem ) ; }',
      '}',
      '',
      '.card::after { content : "  keep  me  /* not a comment */  " ; }',
      '.card::before { background : url( img/hero pic.png ) no-repeat ; }'
    ].join(LF);
    run();
  };
  ['csm-oc', 'csm-on', 'csm-oe', 'csm-ob'].forEach(function (id) { $(id).onchange = run; });
  $('csm-in').addEventListener('input', later);
})();
</script>

<style>
.cfm-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(118px,1fr));gap:12px;margin-top:16px}
.cfm-stat{background:var(--surface-2);border:1px solid var(--line);border-radius:13px;padding:13px 12px;text-align:center}
.cfm-stat b{display:block;font-size:19px;font-weight:800;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;color:var(--text);line-height:1.4}
.cfm-stat span{font-size:11.5px;color:var(--dim)}
.cfm-stat.cfm-hi b{font-size:22px;background:linear-gradient(100deg,#34D399,#22D3EE);-webkit-background-clip:text;background-clip:text;color:transparent}
.cfm-note{display:flex;align-items:flex-start;gap:9px;font-size:12.5px;line-height:1.85;color:var(--dim);margin-top:16px;padding-top:14px;border-top:1px solid var(--line)}
.cfm-note .icon{width:15px;height:15px;color:var(--cyan);flex:none;margin-top:4px}
</style>

<div class="wt-io">
  <div class="wt-pane">
    <label>{{ __('ui.wt_input') }}</label>
    <textarea id="cfm-in" class="wt-ta" rows="17" dir="ltr" spellcheck="false" placeholder=".card{color:#0bf;margin:0 auto;padding:10px 20px}"></textarea>
  </div>
  <div class="wt-pane">
    <label>{{ __('ui.wt_output') }}</label>
    <textarea id="cfm-out" class="wt-ta" rows="17" dir="ltr" readonly spellcheck="false"></textarea>
  </div>
</div>

<div class="wt-fields">
  <label class="wt-range">{{ __('ui.wt_cfm_indent') }}
    <select id="cfm-ind" class="wt-select">
      <option value="2">{{ __('ui.wt_cfm_ind2') }}</option>
      <option value="4">{{ __('ui.wt_cfm_ind4') }}</option>
      <option value="t">{{ __('ui.wt_cfm_indtab') }}</option>
    </select>
  </label>
  <label class="wt-range">{{ __('ui.wt_cfm_brace') }}
    <select id="cfm-br" class="wt-select">
      <option value="same">{{ __('ui.wt_cfm_brace_same') }}</option>
      <option value="next">{{ __('ui.wt_cfm_brace_next') }}</option>
    </select>
  </label>
  <label class="wt-chk"><input type="checkbox" id="cfm-sel" checked> {{ __('ui.wt_cfm_selline') }}</label>
  <label class="wt-chk"><input type="checkbox" id="cfm-bl" checked> {{ __('ui.wt_cfm_blank') }}</label>
  <label class="wt-chk"><input type="checkbox" id="cfm-com" checked> {{ __('ui.wt_cfm_keepcom') }}</label>
</div>

<div class="cfm-stats">
  <div class="cfm-stat"><b id="cfm-nr" dir="ltr">—</b><span>{{ __('ui.wt_cfm_rules') }}</span></div>
  <div class="cfm-stat"><b id="cfm-nd" dir="ltr">—</b><span>{{ __('ui.wt_cfm_decls') }}</span></div>
  <div class="cfm-stat"><b id="cfm-na" dir="ltr">—</b><span>{{ __('ui.wt_cfm_ats') }}</span></div>
  <div class="cfm-stat cfm-hi"><b id="cfm-nl" dir="ltr">—</b><span>{{ __('ui.wt_cfm_lines') }}</span></div>
</div>

<div class="cfm-note">
  <svg class="icon"><use href="#i-shield"/></svg>
  <span>{{ __('ui.wt_cfm_note') }}</span>
</div>

<div class="wt-bar">
  <button class="btn btn-primary" id="cfm-run" type="button">{{ __('ui.wt_format') }}</button>
  <button class="btn btn-glass" id="cfm-copy" type="button" data-done="{{ __('ui.wt_copied') }}">{{ __('ui.wt_copy') }}</button>
  <button class="btn btn-glass" id="cfm-sample" type="button">{{ __('ui.wt_cfm_sample') }}</button>
  <button class="btn btn-glass" id="cfm-clear" type="button">{{ __('ui.wt_clear') }}</button>
  <span class="wt-status" id="cfm-msg"></span>
</div>

<script>
(function () {
  var $ = function (id) { return document.getElementById(id); };

  var TXT = {
    ok:     @json(__('ui.wt_cfm_ok')),
    warn:   @json(__('ui.wt_cfm_warn')),
    toobig: @json(__('ui.wt_cfm_toobig'))
  };

  var MAX = 1000000;                 // hard cap so a pasted bundle cannot freeze the tab
  var LF  = String.fromCharCode(10);
  var CR  = String.fromCharCode(13);
  var TAB = String.fromCharCode(9);
  var CE  = '*' + '/';               // comment terminator, built so it cannot close anything

  /* ------------------------------------------------------------------ *
   * Tokenizer. Strings, comments and url() bodies are lifted out whole, *
   * so no later spacing decision can ever reach inside them.            *
   * ------------------------------------------------------------------ */
  function isWS(c) { return c === 32 || c === 9 || c === 10 || c === 13 || c === 12; }
  function isDig(c) { return c >= 48 && c <= 57; }
  function isNameStart(c) {
    return (c >= 97 && c <= 122) || (c >= 65 && c <= 90) || c === 95 || c === 45 || c === 92 || c > 127;
  }
  function isName(c) { return isNameStart(c) || isDig(c); }

  function tokenize(s) {
    var TK = [], n = s.length, i = 0;

    function identEnd(j) {
      while (j < n) {
        var d = s.charCodeAt(j);
        if (d === 92) { j += 2; continue; }        // escape: consume the pair
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
      if (e === 101 || e === 69) {                 // scientific notation
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
      TK.push({ t: 'num', v: num, u: u });
      return j > i ? j : i + 1;
    }

    while (i < n) {
      var c = s.charCodeAt(i), j;

      if (isWS(c)) {
        j = i;
        while (j < n && isWS(s.charCodeAt(j))) { j++; }
        TK.push({ t: 'ws' }); i = j; continue;
      }

      if (c === 47 && s.charCodeAt(i + 1) === 42) {            // block comment
        j = s.indexOf(CE, i + 2);
        var closed = j >= 0;
        j = closed ? j + 2 : n;
        TK.push({ t: 'com', v: s.slice(i, j), open: !closed });
        i = j; continue;
      }

      if (c === 34 || c === 39) {                              // quoted string
        var q = c, done = false;
        j = i + 1;
        while (j < n) {
          var d = s.charCodeAt(j);
          if (d === 92) { j += 2; continue; }
          if (d === q) { j++; done = true; break; }
          j++;
        }
        TK.push({ t: 'str', v: s.slice(i, j), open: !done });
        i = j; continue;
      }

      // a minus only opens a number when a digit follows: --var and -webkit- stay idents
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
          if (qc === 34 || qc === 39) {                        // url("...")
            m2 = k + 1;
            while (m2 < n) {
              var d2 = s.charCodeAt(m2);
              if (d2 === 92) { m2 += 2; continue; }
              if (d2 === qc) { m2++; break; }
              m2++;
            }
            inner = s.slice(k, m2); k = m2;
          } else {                                             // url(bare/path.png)
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
          TK.push({ t: 'url', v: 'url(' + inner + ')' });
          i = k > i ? k : i + 1; continue;
        }
        TK.push({ t: 'ident', v: id }); i = j > i ? j : i + 1; continue;
      }

      if (c === 35) { j = identEnd(i + 1); TK.push({ t: 'hash', v: s.slice(i, j) }); i = j > i ? j : i + 1; continue; }
      if (c === 64) { j = identEnd(i + 1); TK.push({ t: 'at',   v: s.slice(i, j) }); i = j > i ? j : i + 1; continue; }

      TK.push({ t: 'p', v: s.charAt(i) }); i++;
    }
    return TK;
  }

  /* ------------------------------------------------------------------ *
   * Parser: a generic block tree. A statement ends at a top level ';',  *
   * a rule opens at a top level '{'. Nesting therefore works for        *
   * at-rules, native CSS nesting and keyframe blocks alike.             *
   * ------------------------------------------------------------------ */
  function trimTok(a) {
    while (a.length && a[0].t === 'ws') { a.shift(); }
    while (a.length && a[a.length - 1].t === 'ws') { a.pop(); }
  }

  function parse(tokens) {
    var i = 0, n = tokens.length, warn = false;

    function block() {
      var nodes = [];
      while (i < n) {
        var t = tokens[i];
        if (t.t === 'ws') { i++; continue; }
        if (t.t === 'com') { nodes.push({ k: 'com', v: t.v }); i++; continue; }
        if (t.t === 'p' && t.v === '}') { return nodes; }
        if (t.t === 'p' && t.v === ';') { i++; continue; }      // stray semicolon

        var pre = [], depth = 0, brk = 0, custom = false, colon = false, node = null;

        while (i < n) {
          var u = tokens[i];
          if (u.t === 'p') {
            var c = u.v;
            if (c === ')') { if (depth > 0) { depth--; } }
            else if (c === ']') { if (brk > 0) { brk--; } }
            else if (c === ';' && depth === 0 && brk === 0) { i++; node = { k: 'stmt', pre: pre, semi: true }; break; }
            else if (c === '}' && depth === 0 && brk === 0) { node = { k: 'stmt', pre: pre, semi: false }; break; }
            else if (c === '{' && depth === 0 && brk === 0) {
              if (custom && colon) {                            // --x: { ... } is a value, not a block
                var d2 = 0;
                while (i < n) {
                  var w = tokens[i];
                  if (w.t === 'p' && w.v === '{') { d2++; }
                  else if (w.t === 'p' && w.v === '}') { d2--; }
                  pre.push(w); i++;
                  if (d2 === 0) { break; }
                }
                continue;
              }
              i++;
              var kids = block();
              if (i < n && tokens[i].t === 'p' && tokens[i].v === '}') { i++; } else { warn = true; }
              node = { k: 'rule', pre: pre, kids: kids };
              break;
            }
            else if (c === ':' && depth === 0 && brk === 0) { colon = true; }
            else if (c === '(') { depth++; }
            else if (c === '[') { brk++; }
          }
          if (!pre.length && u.t === 'ident' && u.v.charAt(0) === '-' && u.v.charAt(1) === '-') { custom = true; }
          pre.push(u); i++;
        }

        if (!node) { node = { k: 'stmt', pre: pre, semi: false }; if (pre.length) { warn = true; } }
        trimTok(pre);
        if (pre.length || node.k === 'rule') { nodes.push(node); }
      }
      return nodes;
    }

    var top = block(), q;
    while (i < n) {                                             // an unbalanced '}' at top level
      warn = true; i++;
      var more = block();
      for (q = 0; q < more.length; q++) { top.push(more[q]); }
    }
    return { nodes: top, warn: warn };
  }

  /* ------------------------------------------------------------------ *
   * Renderers. Source whitespace decides the descendant combinator and  *
   * the operators inside calc(); only comma, colon and the combinators  *
   * >, + and ~ get canonical spacing forced onto them.                  *
   * ------------------------------------------------------------------ */
  function txt(t) {
    if (t.t === 'num') { return t.v + t.u; }
    if (t.t === 'ws') { return ' '; }
    return t.v;
  }

  // characters that form an attribute matcher: [a^="b"], [a~="b"], [a|="b"] ...
  var ATTROP = { '=': 1, '~': 1, '^': 1, '$': 1, '*': 1, '|': 1 };

  function splitComma(pre) {
    var out = [], cur = [], depth = 0, brk = 0, i;
    for (i = 0; i < pre.length; i++) {
      var t = pre[i];
      if (t.t === 'p') {
        if (t.v === '(') { depth++; }
        else if (t.v === ')') { if (depth > 0) { depth--; } }
        else if (t.v === '[') { brk++; }
        else if (t.v === ']') { if (brk > 0) { brk--; } }
        else if (t.v === ',' && depth === 0 && brk === 0) {
          trimTok(cur); if (cur.length) { out.push(cur); } cur = []; continue;
        }
      }
      cur.push(t);
    }
    trimTok(cur);
    if (cur.length) { out.push(cur); }
    return out;
  }

  function joinSel(pre) {
    var out = '', pend = false, prev = null, depth = 0, brk = 0, i;
    for (i = 0; i < pre.length; i++) {
      var t = pre[i];
      if (t.t === 'ws') { pend = true; continue; }
      var c = (t.t === 'p') ? t.v : '', sp = false;
      if (prev) {
        var pc = (prev.t === 'p') ? prev.v : '';
        if (c === ')' || c === ']' || c === ',' || pc === '(' || pc === '[') { sp = false; }
        else if (brk > 0) {
          // inside [attr = "x"] the gaps around the operator are noise, but the
          // trailing match flag in [attr="x" i] is a separate token and must keep its space
          if (ATTROP[c] || pc === '=') { sp = false; } else { sp = pend; }
        }
        else { sp = pend; }                           // "li :first-child" must keep its space
        if (depth === 0 && brk === 0) {
          if (c === '>' || c === '+' || c === '~') { sp = true; }
          if (pc === '>' || pc === '+' || pc === '~') { sp = true; }
        }
      }
      if (sp && out) { out += ' '; }
      out += txt(t);
      if (c === '(') { depth++; } else if (c === ')') { if (depth > 0) { depth--; } }
      if (c === '[') { brk++; } else if (c === ']') { if (brk > 0) { brk--; } }
      pend = false; prev = t;
    }
    return out;
  }

  function joinVal(pre) {
    var out = '', pend = false, prev = null, i;
    for (i = 0; i < pre.length; i++) {
      var t = pre[i];
      if (t.t === 'ws') { pend = true; continue; }
      var c = (t.t === 'p') ? t.v : '', sp = false;
      if (prev) {
        var pc = (prev.t === 'p') ? prev.v : '';
        if (c === ')' || pc === '(' || c === ',') { sp = false; }
        else if (pc === ',') { sp = true; }            // rgba(0, 0, 0, .5)
        else if (pc === '!') { sp = false; }
        else if (c === '!') { sp = true; }             // red !important
        else { sp = pend; }                            // calc(100% - 2rem) keeps its operators
      }
      if (sp && out) { out += ' '; }
      out += txt(t);
      pend = false; prev = t;
    }
    return out;
  }

  function joinAt(pre) {
    var out = '', pend = false, prev = null, depth = 0, i;
    for (i = 0; i < pre.length; i++) {
      var t = pre[i];
      if (t.t === 'ws') { pend = true; continue; }
      var c = (t.t === 'p') ? t.v : '', sp = false;
      if (prev) {
        var pc = (prev.t === 'p') ? prev.v : '';
        if (prev.t === 'at') { sp = true; }            // the at-keyword always gets one space
        else if (c === ')' || pc === '(' || c === ',') { sp = false; }
        else if (pc === ',') { sp = true; }
        else if (depth > 0 && c === ':') { sp = false; }
        else if (depth > 0 && pc === ':') { sp = true; }   // (min-width: 768px)
        else { sp = pend; }
      }
      if (sp && out) { out += ' '; }
      out += txt(t);
      if (c === '(') { depth++; } else if (c === ')') { if (depth > 0) { depth--; } }
      pend = false; prev = t;
    }
    return out;
  }

  function joinProp(pre) {
    var s = '', i;
    for (i = 0; i < pre.length; i++) { if (pre[i].t !== 'ws') { s += txt(pre[i]); } }
    return s;
  }

  function splitDecl(pre) {
    var depth = 0, brk = 0, i;
    for (i = 0; i < pre.length; i++) {
      var t = pre[i];
      if (t.t !== 'p') { continue; }
      var c = t.v;
      if (c === '(') { depth++; }
      else if (c === ')') { if (depth > 0) { depth--; } }
      else if (c === '[') { brk++; }
      else if (c === ']') { if (brk > 0) { brk--; } }
      else if (c === ':' && depth === 0 && brk === 0) { return i; }
    }
    return -1;
  }

  /* ---------------------------- emitter ----------------------------- */
  function rep(unit, level) { var s = '', k; for (k = 0; k < level; k++) { s += unit; } return s; }
  function rtrim(s) { var j = s.length; while (j > 0 && isWS(s.charCodeAt(j - 1))) { j--; } return s.slice(0, j); }
  function endsBrace(lines) {
    var s = lines.length ? lines[lines.length - 1] : '';
    return s.charAt(s.length - 1) === '}';
  }

  function pushCom(lines, ind, v) {
    var cl = v.split(LF), k, s;
    lines.push(ind + rtrim(cl[0]));
    for (k = 1; k < cl.length; k++) {
      s = cl[k].trim();
      if (s === '') { lines.push(''); }
      else { lines.push(s.charAt(0) === '*' ? ind + ' ' + s : ind + s); }
    }
  }

  function emit(nodes, level, opt, lines, st) {
    var startLen = lines.length, ind = rep(opt.unit, level), i, q;

    for (i = 0; i < nodes.length; i++) {
      var nd = nodes[i];

      if (nd.k === 'com') {
        if (opt.blank && lines.length > startLen && endsBrace(lines)) { lines.push(''); }
        pushCom(lines, ind, nd.v);
        continue;
      }

      if (nd.k === 'rule') {
        if (opt.blank && lines.length > startLen && lines[lines.length - 1] !== '') { lines.push(''); }
        var head, isAt = nd.pre.length > 0 && nd.pre[0].t === 'at';
        if (isAt) {
          st.ats++;
          head = [joinAt(nd.pre)];
        } else {
          st.rules++;
          var parts = splitComma(nd.pre), arr = [];
          for (q = 0; q < parts.length; q++) { arr.push(joinSel(parts[q])); }
          if (!arr.length) { arr = ['']; }
          if (opt.selLine) {
            head = arr;
            for (q = 0; q < head.length - 1; q++) { head[q] = head[q] + ','; }
          } else {
            head = [arr.join(', ')];
          }
        }
        for (q = 0; q < head.length; q++) {
          if (q === head.length - 1 && opt.brace === 'same') {
            lines.push(ind + head[q] + (head[q] ? ' ' : '') + '{');
          } else {
            lines.push(ind + head[q]);
          }
        }
        if (opt.brace !== 'same') { lines.push(ind + '{'); }
        emit(nd.kids, level + 1, opt, lines, st);
        lines.push(ind + '}');
        continue;
      }

      if (!nd.pre.length) { continue; }

      if (nd.pre[0].t === 'at') {                                // at-statement, e.g. an import
        if (opt.blank && lines.length > startLen && endsBrace(lines)) { lines.push(''); }
        st.ats++;
        lines.push(ind + joinAt(nd.pre) + ';');
        continue;
      }

      var ci = splitDecl(nd.pre);
      if (ci > 0) {
        st.decls++;
        var propTok = nd.pre.slice(0, ci), valTok = nd.pre.slice(ci + 1);
        trimTok(propTok); trimTok(valTok);
        var v = joinVal(valTok), pn = joinProp(propTok);
        // "--x: ;" is a legal empty custom property; keep the space so it stays readable
        if (!v && pn.charAt(0) === '-' && pn.charAt(1) === '-') { v = ''; lines.push(ind + pn + ': ;'); }
        else { lines.push(ind + pn + ':' + (v ? ' ' + v : '') + ';'); }
      } else {
        lines.push(ind + joinSel(nd.pre) + (nd.semi ? ';' : ''));
      }
    }
  }

  function format(css, opt) {
    var src = css.split(CR).join(''), toks = tokenize(src), open = false, i;
    for (i = 0; i < toks.length; i++) { if (toks[i].open) { open = true; } }

    if (!opt.comments) {                                        // a comment still separates tokens
      var t2 = [];
      for (i = 0; i < toks.length; i++) {
        t2.push(toks[i].t === 'com' ? { t: 'ws' } : toks[i]);
      }
      toks = t2;
    }

    var p = parse(toks), lines = [], st = { rules: 0, decls: 0, ats: 0 };
    emit(p.nodes, 0, opt, lines, st);
    while (lines.length && lines[lines.length - 1] === '') { lines.pop(); }
    return { out: lines.join(LF), st: st, warn: p.warn || open, n: lines.length };
  }

  /* ---------------------------- wiring ------------------------------ */
  function setMsg(t, cls) {
    var m = $('cfm-msg');
    m.textContent = t || '';
    m.className = 'wt-status' + (cls ? ' ' + cls : '');
  }

  function reset() {
    $('cfm-nr').textContent = '—'; $('cfm-nd').textContent = '—';
    $('cfm-na').textContent = '—'; $('cfm-nl').textContent = '—';
  }

  function options() {
    var iv = $('cfm-ind').value;
    return {
      unit:     iv === 't' ? TAB : (iv === '4' ? '    ' : '  '),
      brace:    $('cfm-br').value,
      selLine:  $('cfm-sel').checked,
      blank:    $('cfm-bl').checked,
      comments: $('cfm-com').checked
    };
  }

  function run() {
    var src = $('cfm-in').value;
    if (!src || !src.trim()) { $('cfm-out').value = ''; reset(); setMsg(''); return; }
    if (src.length > MAX) { $('cfm-out').value = ''; reset(); setMsg(TXT.toobig, 'err'); return; }

    var res;
    try { res = format(src, options()); }
    catch (err) { $('cfm-out').value = ''; reset(); setMsg(String((err && err.message) || err), 'err'); return; }

    $('cfm-out').value = res.out;
    $('cfm-nr').textContent = String(res.st.rules);
    $('cfm-nd').textContent = String(res.st.decls);
    $('cfm-na').textContent = String(res.st.ats);
    $('cfm-nl').textContent = String(res.n);

    var msg = TXT.ok.split(':r').join(String(res.st.rules))
                    .split(':d').join(String(res.st.decls))
                    .split(':l').join(String(res.n));
    if (res.warn) { setMsg(msg + ' · ' + TXT.warn, 'err'); }
    else { setMsg(msg, 'ok'); }
  }

  var tmr = null;
  function later() { clearTimeout(tmr); tmr = setTimeout(run, 180); }

  $('cfm-run').onclick = run;
  $('cfm-copy').onclick = function (e) { wtCopy(e.currentTarget, $('cfm-out').value); };
  $('cfm-clear').onclick = function () {
    $('cfm-in').value = ''; $('cfm-out').value = ''; reset(); setMsg('');
  };
  $('cfm-sample').onclick = function () {
    var AT = String.fromCharCode(64);              // keeps a bare at-keyword out of the Blade source
    $('cfm-in').value =
      '/*! ServerNet sample sheet */' +
      '.card{color:#0bf;margin:0 auto;padding:10px 20px;' +
      'background:url(data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=) no-repeat}' +
      '.card>.title,.card .meta{font:12px/1.5 Tahoma,sans-serif;opacity:.8;color:rgba(0,0,0,.62)}' +
      '.card::after{content:"a > b { still a string; not css }"}' +
      AT + 'media screen and (min-width:768px){.card{width:calc(100% - 2rem)}}' +
      AT + 'supports (display:grid){.grid{display:grid;gap:1rem}}' +
      AT + 'keyframes fade{from{opacity:0}to{opacity:1}}';
    run();
  };
  ['cfm-ind', 'cfm-br', 'cfm-sel', 'cfm-bl', 'cfm-com'].forEach(function (id) { $(id).onchange = run; });
  $('cfm-in').addEventListener('input', later);
})();
</script>

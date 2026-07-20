<style>
.hff-ta{tab-size:4;-moz-tab-size:4}
.hff-warns{margin-top:16px;display:none;flex-direction:column;gap:8px}
.hff-warns.on{display:flex}
.hff-wtitle{display:flex;align-items:center;gap:8px;font-size:12.5px;font-weight:700;color:#ff6b6b}
.hff-wtitle .icon{width:15px;height:15px}
.hff-warn{display:flex;align-items:flex-start;gap:9px;font-size:12.5px;line-height:1.9;
  background:rgba(255,107,107,.09);border:1px solid rgba(255,107,107,.26);
  border-inline-start:3px solid #ff6b6b;border-radius:11px;padding:9px 12px;color:var(--muted)}
.hff-warn .icon{width:14px;height:14px;color:#ff6b6b;flex:none;margin-top:6px}
.hff-warn code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;
  background:var(--surface-2);border:1px solid var(--line);border-radius:6px;padding:1px 6px;color:var(--text)}
.hff-warn b{color:var(--cyan);font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
.hff-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(126px,1fr));gap:12px;margin-top:16px}
.hff-stat{background:var(--surface-2);border:1px solid var(--line);border-radius:13px;padding:13px 12px;text-align:center}
.hff-stat b{display:block;font-size:19px;font-weight:800;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;color:var(--text);line-height:1.4}
.hff-stat span{font-size:11.5px;color:var(--dim)}
.hff-stat.hff-ok b{color:var(--green)}
.hff-stat.hff-bad b{color:#ff6b6b}
.hff-note{display:flex;align-items:flex-start;gap:9px;font-size:12.5px;line-height:1.85;color:var(--dim);
  margin-top:16px;padding-top:14px;border-top:1px solid var(--line)}
.hff-note .icon{width:15px;height:15px;color:var(--green);flex:none;margin-top:4px}
html[data-theme="light"] .hff-warn{background:rgba(220,38,38,.07);border-color:rgba(220,38,38,.22);border-inline-start-color:#dc2626}
html[data-theme="light"] .hff-warn .icon{color:#dc2626}
html[data-theme="light"] .hff-wtitle{color:#b91c1c}
</style>

<div class="wt-io">
  <div class="wt-pane">
    <label>{{ __('ui.wt_input') }}</label>
    <textarea id="hff-in" class="wt-ta hff-ta" rows="16" dir="ltr" spellcheck="false"
      placeholder="&lt;div class=&quot;card&quot;&gt;&lt;p&gt;Hello &lt;b&gt;world&lt;/b&gt;&lt;/p&gt;&lt;/div&gt;"></textarea>
  </div>
  <div class="wt-pane">
    <label>{{ __('ui.wt_output') }}</label>
    <textarea id="hff-out" class="wt-ta hff-ta" rows="16" dir="ltr" wrap="off" readonly spellcheck="false"></textarea>
  </div>
</div>

<div class="hff-stats">
  <div class="hff-stat"><b id="hff-slines" dir="ltr">—</b><span>{{ __('ui.wt_hf_st_lines') }}</span></div>
  <div class="hff-stat"><b id="hff-sels" dir="ltr">—</b><span>{{ __('ui.wt_hf_st_els') }}</span></div>
  <div class="hff-stat"><b id="hff-sdepth" dir="ltr">—</b><span>{{ __('ui.wt_hf_st_depth') }}</span></div>
  <div class="hff-stat hff-ok" id="hff-sbox"><b id="hff-sissues" dir="ltr">—</b><span>{{ __('ui.wt_hf_st_issues') }}</span></div>
</div>

<div class="hff-warns" id="hff-warns">
  <div class="hff-wtitle"><svg class="icon"><use href="#i-shield"/></svg><span>{{ __('ui.wt_hf_wtitle') }}</span></div>
  <div id="hff-wlist"></div>
</div>

<div class="wt-fields">
  <label class="wt-range">{{ __('ui.wt_hf_indent') }}
    <select id="hff-ind" class="wt-select">
      <option value="2" selected>{{ __('ui.wt_hf_i2') }}</option>
      <option value="3">{{ __('ui.wt_hf_i3') }}</option>
      <option value="4">{{ __('ui.wt_hf_i4') }}</option>
      <option value="8">{{ __('ui.wt_hf_i8') }}</option>
      <option value="t">{{ __('ui.wt_hf_itab') }}</option>
    </select>
  </label>
  <label class="wt-chk"><input type="checkbox" id="hff-blank" checked> {{ __('ui.wt_hf_blank') }}</label>
  <label class="wt-chk"><input type="checkbox" id="hff-script" checked> {{ __('ui.wt_hf_script') }}</label>
  <label class="wt-chk"><input type="checkbox" id="hff-attrs"> {{ __('ui.wt_hf_attrs') }}</label>
</div>

<div class="hff-note">
  <svg class="icon"><use href="#i-shield"/></svg>
  <span>{{ __('ui.wt_hf_note') }}</span>
</div>

<div class="wt-bar">
  <button class="btn btn-primary" id="hff-run" type="button">{{ __('ui.wt_format') }}</button>
  <button class="btn btn-glass" id="hff-copy" type="button" data-done="{{ __('ui.wt_copied') }}">{{ __('ui.wt_copy') }}</button>
  <button class="btn btn-glass" id="hff-sample" type="button">{{ __('ui.wt_hf_sample') }}</button>
  <button class="btn btn-glass" id="hff-clear" type="button">{{ __('ui.wt_clear') }}</button>
  <span class="wt-status" id="hff-msg"></span>
</div>

<script>
(function () {
  var $ = function (id) { return document.getElementById(id); };

  var T = {
    ok:        @json(__('ui.wt_hf_ok')),
    kept:      @json(__('ui.wt_hf_kept')),
    issues:    @json(__('ui.wt_hf_issues')),
    toobig:    @json(__('ui.wt_hf_toobig')),
    deep:      @json(__('ui.wt_hf_deep')),
    wUnclosed: @json(__('ui.wt_hf_w_unclosed')),
    wStray:    @json(__('ui.wt_hf_w_stray')),
    wMismatch: @json(__('ui.wt_hf_w_mismatch')),
    wMore:     @json(__('ui.wt_hf_w_more'))
  };

  var LF = String.fromCharCode(10);
  var CR = String.fromCharCode(13);
  var TAB = String.fromCharCode(9);
  var BQ = String.fromCharCode(96);          // backtick — template literal marker
  var MAX = 800000;                          // hard cap on input length
  var WRAP = 120;                            // a line longer than this is broken up
  var ATTRWRAP = 100;                        // start tag longer than this may split its attributes
  var MAXDEPTH = 512;                        // nesting guard
  var MAXWARN = 25;

  function mk(list) {
    var o = {}, a = list.split(' ');
    for (var i = 0; i < a.length; i++) { if (a[i]) o[a[i]] = 1; }
    return o;
  }

  // Void elements: no closing tag, never a container.
  var VOID = mk('area base br col embed hr img input link meta param source track wbr');

  // Elements whose content is copied through byte for byte.
  var RAWEL = mk('pre textarea script style');

  // Phrasing elements that stay on the same line as the surrounding text.
  // Anything NOT listed here (including custom elements and SVG) is treated as
  // block level and gets its own line — the readable, conservative default.
  var INLINE = mk(
    'a abbr acronym b bdi bdo big button cite code data del dfn em font i img ins kbd ' +
    'label map mark meter nobr object output picture progress q rb rp rt ruby s samp ' +
    'select small span strike strong sub sup time tt u var wbr br input'
  );

  // Elements with an optional end tag — never reported as "unclosed".
  var OPTEND = mk('html head body p li dt dd td th tr thead tbody tfoot colgroup caption option optgroup rt rp');

  // A start tag that implicitly closes an open <p>.
  var PCLOSE = mk(
    'address article aside blockquote details dialog div dl fieldset figcaption figure ' +
    'footer form h1 h2 h3 h4 h5 h6 header hgroup hr li main menu nav ol p pre section ' +
    'search table ul dd dt td th tr tbody thead tfoot'
  );

  // A start tag that implicitly closes these open elements (repeatedly).
  var IMPLIED = {
    li: mk('li'),
    dt: mk('dt dd'), dd: mk('dt dd'),
    tr: mk('tr td th'),
    td: mk('td th'), th: mk('td th'),
    thead: mk('thead tbody tfoot tr td th caption colgroup'),
    tbody: mk('thead tbody tfoot tr td th caption colgroup'),
    tfoot: mk('thead tbody tfoot tr td th caption colgroup'),
    option: mk('option'), optgroup: mk('optgroup option'),
    rt: mk('rt rp'), rp: mk('rt rp')
  };

  function isWS(c) { return c === 32 || c === 10 || c === 9 || c === 13 || c === 12; }
  function isAlpha(c) { return (c >= 65 && c <= 90) || (c >= 97 && c <= 122); }

  function isBlank(s) {
    for (var i = 0; i < s.length; i++) { if (!isWS(s.charCodeAt(i))) return false; }
    return true;
  }
  function nlCount(s) {
    var n = 0;
    for (var i = 0; i < s.length; i++) { if (s.charCodeAt(i) === 10) n++; }
    return n;
  }
  function trimSp(s) {
    var a = 0, b = s.length;
    while (a < b && s.charCodeAt(a) === 32) a++;
    while (b > a && s.charCodeAt(b - 1) === 32) b--;
    return s.slice(a, b);
  }
  function collapse(t) {
    var o = '', pend = false;
    for (var i = 0; i < t.length; i++) {
      var c = t.charCodeAt(i);
      if (isWS(c)) { pend = true; continue; }   // nbsp (160) is deliberately NOT whitespace
      if (pend) { o += ' '; pend = false; }
      o += t.charAt(i);
    }
    if (pend) o += ' ';
    return o;
  }

  // ---- tokenizer -----------------------------------------------------------
  // kinds: text | comment | decl | keep | open | close | raw
  function tokenize(s) {
    var toks = [], i = 0, n = s.length, els = 0;

    function pushText(v) {
      if (!v) return;
      var last = toks[toks.length - 1];
      if (last && last.t === 'text') { last.v += v; } else { toks.push({ t: 'text', v: v, i: i }); }
    }

    while (i < n) {
      var lt = s.indexOf('<', i);
      if (lt < 0) { pushText(s.slice(i)); break; }
      if (lt > i) pushText(s.slice(i, lt));

      if (s.substr(lt, 4) === '<!--') {
        var ce = s.indexOf('-->', lt + 4);
        var cstop = ce < 0 ? n : ce + 3;
        toks.push({ t: 'comment', v: s.slice(lt, cstop), i: lt });
        i = cstop; continue;
      }

      var c1 = s.charCodeAt(lt + 1);

      if (c1 === 33) {                                    // "<!" doctype or CDATA
        if (s.substr(lt, 9) === '<![CDATA[') {
          var de = s.indexOf(']]>', lt);
          var dstop = de < 0 ? n : de + 3;
          toks.push({ t: 'keep', v: s.slice(lt, dstop), i: lt });
          i = dstop; continue;
        }
        var g1 = s.indexOf('>', lt);
        var g1s = g1 < 0 ? n : g1 + 1;
        toks.push({ t: 'decl', v: s.slice(lt, g1s), i: lt });
        i = g1s; continue;
      }

      if (c1 === 63) {                                    // "<?" processing instruction
        var g2 = s.indexOf('>', lt);
        var g2s = g2 < 0 ? n : g2 + 1;
        toks.push({ t: 'keep', v: s.slice(lt, g2s), i: lt });
        i = g2s; continue;
      }

      var close = false, p = lt + 1;
      if (c1 === 47) { close = true; p++; }               // "</"
      var ns = p;
      while (p < n) { var nc = s.charCodeAt(p); if (isWS(nc) || nc === 62 || nc === 47) break; p++; }
      var name = s.slice(ns, p);
      if (!name || !isAlpha(name.charCodeAt(0))) {        // a bare "<" inside text
        pushText('<'); i = lt + 1; continue;
      }

      // ---- attributes
      var attrs = [], self = false;
      while (p < n) {
        var c2 = s.charCodeAt(p);
        if (isWS(c2)) { p++; continue; }
        if (c2 === 62) { p++; break; }                    // ">"
        if (c2 === 47) {                                  // "/"
          if (s.charCodeAt(p + 1) === 62) { self = true; p += 2; break; }
          p++; continue;
        }
        var as = p;
        while (p < n) { var c3 = s.charCodeAt(p); if (isWS(c3) || c3 === 61 || c3 === 62 || c3 === 47) break; p++; }
        var an = s.slice(as, p);
        if (!an) { p++; continue; }
        var q = p;
        while (q < n && isWS(s.charCodeAt(q))) q++;
        if (s.charCodeAt(q) === 61) {                     // "="
          q++;
          while (q < n && isWS(s.charCodeAt(q))) q++;
          var qc = s.charCodeAt(q);
          if (qc === 34 || qc === 39) {                   // quoted value
            var quote = s.charAt(q);
            var ve = s.indexOf(quote, q + 1);
            if (ve < 0) { attrs.push({ n: an, v: s.slice(q + 1) }); p = n; }
            else { attrs.push({ n: an, v: s.slice(q + 1, ve) }); p = ve + 1; }
          } else {                                        // unquoted value: ends at WS or ">"
            var vs = q;
            while (q < n) { var c4 = s.charCodeAt(q); if (isWS(c4) || c4 === 62) break; q++; }
            attrs.push({ n: an, v: s.slice(vs, q) });
            p = q;
          }
        } else {
          attrs.push({ n: an, v: null });                 // valueless attribute
        }
      }

      var lname = name.toLowerCase();

      if (close) { toks.push({ t: 'close', name: name, i: lt }); i = p; continue; }

      // ---- raw-text element: its body survives untouched
      if (RAWEL[lname] && !self) {
        var k = findClose(s, p, lname), inner, after, closeName = name, closed = true;
        if (k < 0) { inner = s.slice(p); after = n; closed = false; }
        else {
          inner = s.slice(p, k);
          closeName = s.substr(k + 2, lname.length);
          var g3 = s.indexOf('>', k);
          after = g3 < 0 ? n : g3 + 1;
        }
        els++;
        toks.push({ t: 'raw', name: name, attrs: attrs, inner: inner, closeName: closeName, closed: closed, i: lt });
        i = after; continue;
      }

      els++;
      toks.push({ t: 'open', name: name, attrs: attrs, self: self, i: lt });
      i = p;
    }

    return { toks: toks, els: els };
  }

  function findClose(s, from, lname) {
    var p = from;
    while (true) {
      var k = s.indexOf('<', p);
      if (k < 0) return -1;
      if (s.charCodeAt(k + 1) === 47 && s.substr(k + 2, lname.length).toLowerCase() === lname) {
        var a = s.charCodeAt(k + 2 + lname.length);
        if (a === 62 || isWS(a) || isNaN(a)) return k;
      }
      p = k + 1;
    }
  }

  // ---- line numbers --------------------------------------------------------
  function lineIndex(s) {
    var arr = [];
    for (var i = 0; i < s.length; i++) { if (s.charCodeAt(i) === 10) arr.push(i); }
    return arr;
  }
  function lineOf(arr, idx) {
    var lo = 0, hi = arr.length;
    while (lo < hi) { var mid = (lo + hi) >> 1; if (arr[mid] < idx) lo = mid + 1; else hi = mid; }
    return lo + 1;
  }

  // ---- tree builder --------------------------------------------------------
  function build(toks, nl) {
    var root = { t: 'root', children: [] };
    var stack = [root], warns = [], maxd = 0;

    function top() { return stack[stack.length - 1]; }
    function add(x) { top().children.push(x); }
    function warn(w) { if (warns.length < 400) warns.push(w); }

    function autoClose(ln) {
      if (stack.length > 1 && top().lname === 'p' && PCLOSE[ln]) stack.pop();
      var set = IMPLIED[ln];
      if (!set) return;
      while (stack.length > 1 && set[top().lname]) stack.pop();
    }

    for (var i = 0; i < toks.length; i++) {
      var tk = toks[i];

      if (tk.t === 'open') {
        var ln = tk.name.toLowerCase();
        autoClose(ln);
        var el = {
          t: 'el', name: tk.name, lname: ln, attrs: tk.attrs, self: tk.self,
          vd: !!VOID[ln], children: [], line: lineOf(nl, tk.i)
        };
        if (stack.length > maxd) maxd = stack.length;
        add(el);
        if (!el.vd && !el.self) {
          if (stack.length >= MAXDEPTH) throw new Error('deep');
          stack.push(el);
        }
        continue;
      }

      if (tk.t === 'raw') {
        var lnr = tk.name.toLowerCase();
        autoClose(lnr);
        var rel = {
          t: 'el', name: tk.name, lname: lnr, attrs: tk.attrs, self: false, vd: false,
          children: [], raw: tk.inner, closeName: tk.closeName, line: lineOf(nl, tk.i)
        };
        if (stack.length > maxd) maxd = stack.length;
        add(rel);
        if (!tk.closed) warn({ k: 'u', tag: tk.name, line: rel.line });
        continue;
      }

      if (tk.t === 'close') {
        var cn = tk.name.toLowerCase(), d = -1;
        for (var s1 = stack.length - 1; s1 >= 1; s1--) { if (stack[s1].lname === cn) { d = s1; break; } }
        if (d < 0) { warn({ k: 's', tag: tk.name, line: lineOf(nl, tk.i) }); continue; }
        for (var s2 = stack.length - 1; s2 > d; s2--) {
          var o = stack[s2];
          if (!OPTEND[o.lname]) {
            warn({ k: 'm', tag: o.name, line: o.line, close: tk.name, cline: lineOf(nl, tk.i) });
          }
        }
        stack.length = d;
        continue;
      }

      add(tk);                                            // text / comment / decl / keep
    }

    for (var s3 = stack.length - 1; s3 >= 1; s3--) {
      var u = stack[s3];
      if (!OPTEND[u.lname]) warn({ k: 'u', tag: u.name, line: u.line });
    }

    return { root: root, warns: warns, depth: maxd };
  }

  // ---- inline classification ----------------------------------------------
  function isInline(n) {
    if (n.t === 'text') return true;
    if (n.t === 'comment') return n.v.indexOf(LF) < 0;
    if (n.t !== 'el') return false;
    if (n.raw !== undefined) return false;
    if (!INLINE[n.lname]) return false;
    if (n._i === undefined) { n._i = 1; n._i = hasBlockKid(n) ? 0 : 1; }
    return n._i === 1;
  }
  function hasBlockKid(el) {
    for (var i = 0; i < el.children.length; i++) {
      var c = el.children[i];
      if (c.t === 'text') continue;
      if (!isInline(c)) return true;
    }
    return false;
  }
  function allInline(ch) {
    for (var i = 0; i < ch.length; i++) {
      var c = ch[i];
      if (c.t === 'text') continue;
      if (!isInline(c)) return false;
    }
    return true;
  }

  // ---- serialisation -------------------------------------------------------
  function serAttr(a) {
    if (a.v === null) return a.n;
    var v = a.v;
    if (v.indexOf('"') < 0) return a.n + '="' + v + '"';
    if (v.indexOf("'") < 0) return a.n + "='" + v + "'";
    return a.n + '="' + v.split('"').join('&quot;') + '"';
  }
  function startStr(el) {
    var s = '<' + el.name;
    for (var i = 0; i < el.attrs.length; i++) s += ' ' + serAttr(el.attrs[i]);
    return s + (el.self ? ' />' : '>');
  }

  function format(root, opt) {
    var out = [], pend = false, kept = 0;
    var unit = opt.tab ? TAB : new Array(opt.size + 1).join(' ');
    var padc = [''];

    function pad(d) {
      while (padc.length <= d) padc.push(padc[padc.length - 1] + unit);
      return padc[d];
    }
    function emit(s) {
      if (pend) { if (out.length) out.push(''); pend = false; }
      out.push(s);
    }

    function inlineText(nodes, trim) {
      var s = '';
      for (var i = 0; i < nodes.length; i++) {
        var n = nodes[i];
        if (n.t === 'text') { s += collapse(n.v); continue; }
        if (n.t === 'comment' || n.t === 'keep' || n.t === 'decl') { s += n.v; continue; }
        if (n.t !== 'el') continue;
        s += startStr(n);
        if (!n.vd && !n.self) s += inlineText(n.children, false) + '</' + n.name + '>';
      }
      return trim ? trimSp(s) : s;
    }

    // Long start tag broken over several lines, or null when it fits.
    function startLines(el, d) {
      if (!opt.attrs || el.attrs.length < 2) return null;
      if ((pad(d) + startStr(el)).length <= ATTRWRAP) return null;
      var a = [pad(d) + '<' + el.name], q = pad(d + 1);
      for (var i = 0; i < el.attrs.length; i++) {
        var s = q + serAttr(el.attrs[i]);
        if (i === el.attrs.length - 1) s += (el.self ? ' />' : '>');
        a.push(s);
      }
      return a;
    }
    function emitAll(a) { for (var i = 0; i < a.length; i++) emit(a[i]); }

    function shift(body, ps) {
      var raw = body.split(CR + LF).join(LF).split(CR).join(LF).split(LF);
      while (raw.length && isBlank(raw[0])) raw.shift();
      while (raw.length && isBlank(raw[raw.length - 1])) raw.pop();
      var min = -1;
      for (var i = 0; i < raw.length; i++) {
        if (isBlank(raw[i])) continue;
        var k = 0;
        while (k < raw[i].length && (raw[i].charCodeAt(k) === 32 || raw[i].charCodeAt(k) === 9)) k++;
        if (min < 0 || k < min) min = k;
      }
      if (min < 0) min = 0;
      var o = [];
      for (var j = 0; j < raw.length; j++) {
        if (isBlank(raw[j])) { o.push(''); continue; }
        var t = raw[j].slice(min), e = t.length;
        while (e > 0 && isWS(t.charCodeAt(e - 1))) e--;      // drop insignificant trailing space
        o.push(ps + t.slice(0, e));
      }
      return o.join(LF);
    }

    function rawBlock(n, d) {
      var p = pad(d), open = startStr(n);
      var close = n.closeName ? ('</' + n.closeName + '>') : '';
      var body = n.raw || '';
      var verbatim = (n.lname === 'pre' || n.lname === 'textarea');
      if (verbatim) { kept++; emit(p + open + body + close); return; }
      if (isBlank(body)) { emit(p + open + close); return; }
      if (!opt.script || body.indexOf(BQ) >= 0) { kept++; emit(p + open + body + close); return; }
      emit(p + open);
      emit(shift(body, pad(d + 1)));
      emit(p + close);
    }

    function block(n, d) {
      if (n.t === 'text') { var c = trimSp(collapse(n.v)); if (c) emit(pad(d) + c); return; }
      if (n.t === 'comment' || n.t === 'decl' || n.t === 'keep') { emit(pad(d) + n.v); return; }
      if (n.t !== 'el') return;

      if (n.raw !== undefined) { rawBlock(n, d); return; }

      var p = pad(d), lines = startLines(n, d), open = startStr(n);

      if (n.vd || n.self) { if (lines) emitAll(lines); else emit(p + open); return; }

      var closeTag = '</' + n.name + '>';

      if (allInline(n.children)) {
        var s = inlineText(n.children, true);
        if (!lines) {
          var one = p + open + s + closeTag;
          if (one.length <= WRAP) { emit(one); return; }
        }
        if (lines) emitAll(lines); else emit(p + open);
        if (s) emit(pad(d + 1) + s);
        emit(p + closeTag);
        return;
      }

      if (lines) emitAll(lines); else emit(p + open);
      kids(n.children, d + 1);
      emit(p + closeTag);
    }

    function kids(ch, d) {
      var run = [];
      function flush() {
        if (!run.length) return;
        var s = inlineText(run, true);
        run = [];
        if (s) emit(pad(d) + s);
      }
      for (var i = 0; i < ch.length; i++) {
        var c = ch[i];
        if (c.t === 'text' && isBlank(c.v)) {
          if (opt.blank && nlCount(c.v) >= 2) { flush(); pend = true; }
          else if (run.length) run.push(c);
          continue;
        }
        if (isInline(c)) { run.push(c); continue; }
        flush();
        block(c, d);
      }
      flush();
    }

    kids(root.children, 0);
    return { text: out.join(LF), kept: kept };
  }

  // ---- warning rendering ---------------------------------------------------
  function warnFrag(w) {
    var tpl = w.k === 'u' ? T.wUnclosed : (w.k === 's' ? T.wStray : T.wMismatch);
    var parts = tpl.split(/(:cline|:close|:line|:tag)/);
    var frag = document.createDocumentFragment();
    for (var i = 0; i < parts.length; i++) {
      var s = parts[i];
      if (s === ':tag' || s === ':close') {
        var code = document.createElement('code');
        code.setAttribute('dir', 'ltr');
        code.textContent = (s === ':tag')
          ? (w.k === 's' ? '</' + w.tag + '>' : '<' + w.tag + '>')
          : '</' + w.close + '>';
        frag.appendChild(code);
      } else if (s === ':line' || s === ':cline') {
        var b = document.createElement('b');
        b.setAttribute('dir', 'ltr');
        b.textContent = String(s === ':line' ? w.line : w.cline);
        frag.appendChild(b);
      } else if (s) {
        frag.appendChild(document.createTextNode(s));
      }
    }
    return frag;
  }

  function showWarns(warns) {
    var box = $('hff-warns'), list = $('hff-wlist');
    list.textContent = '';
    if (!warns.length) { box.className = 'hff-warns'; return; }
    var shown = Math.min(warns.length, MAXWARN);
    for (var i = 0; i < shown; i++) {
      var row = document.createElement('div');
      row.className = 'hff-warn';
      row.innerHTML = '<svg class="icon"><use href="#i-x"/></svg>';
      var sp = document.createElement('span');
      sp.appendChild(warnFrag(warns[i]));
      row.appendChild(sp);
      list.appendChild(row);
    }
    if (warns.length > shown) {
      var more = document.createElement('div');
      more.className = 'hff-warn';
      more.textContent = T.wMore.split(':n').join(String(warns.length - shown));
      list.appendChild(more);
    }
    box.className = 'hff-warns on';
  }

  // ---- wiring --------------------------------------------------------------
  function setMsg(txt, cls) {
    var m = $('hff-msg');
    m.textContent = txt || '';
    m.className = 'wt-status' + (cls ? ' ' + cls : '');
  }
  function resetStats() {
    $('hff-slines').textContent = '—';
    $('hff-sels').textContent = '—';
    $('hff-sdepth').textContent = '—';
    $('hff-sissues').textContent = '—';
    $('hff-sbox').className = 'hff-stat hff-ok';
    showWarns([]);
  }

  function run() {
    var src = $('hff-in').value;
    if (!src || isBlank(src)) { $('hff-out').value = ''; resetStats(); setMsg(''); return; }
    if (src.length > MAX) { $('hff-out').value = ''; resetStats(); setMsg(T.toobig, 'err'); return; }

    var iv = $('hff-ind').value;
    var opt = {
      tab: iv === 't',
      size: iv === 't' ? 1 : parseInt(iv, 10),
      blank: $('hff-blank').checked,
      script: $('hff-script').checked,
      attrs: $('hff-attrs').checked
    };

    var res, tree, tk;
    try {
      tk = tokenize(src);
      tree = build(tk.toks, lineIndex(src));
      res = format(tree.root, opt);
    } catch (err) {
      $('hff-out').value = '';
      resetStats();
      setMsg(String(err && err.message) === 'deep' ? T.deep : String(err && err.message || err), 'err');
      return;
    }

    $('hff-out').value = res.text;
    $('hff-slines').textContent = String(res.text ? nlCount(res.text) + 1 : 0);
    $('hff-sels').textContent = String(tk.els);
    $('hff-sdepth').textContent = String(tree.depth);
    $('hff-sissues').textContent = String(tree.warns.length);
    $('hff-sbox').className = 'hff-stat ' + (tree.warns.length ? 'hff-bad' : 'hff-ok');
    showWarns(tree.warns);

    var parts = [T.ok.split(':n').join(String(res.text ? nlCount(res.text) + 1 : 0))];
    if (res.kept > 0) parts.push(T.kept.split(':n').join(String(res.kept)));
    if (tree.warns.length) parts.push(T.issues.split(':n').join(String(tree.warns.length)));
    setMsg(parts.join(' · '), tree.warns.length ? 'err' : 'ok');
  }

  var tmr = null;
  function later() { clearTimeout(tmr); tmr = setTimeout(run, 200); }

  $('hff-run').onclick = run;
  $('hff-copy').onclick = function (e) { wtCopy(e.currentTarget, $('hff-out').value); };
  $('hff-clear').onclick = function () {
    $('hff-in').value = ''; $('hff-out').value = ''; resetStats(); setMsg('');
  };
  $('hff-sample').onclick = function () {
    $('hff-in').value = [
      '<!doctype html>',
      '<html lang="en"><head><meta charset="utf-8"><title>ServerNet</title>',
      '<style>   body{margin:0}',
      '     a{color:teal}   </style></head>',
      '<body><div class="card"><p>Hello <b>world</b> and <a href="/docs">docs</a></p>',
      '<pre>   keep   me',
      '      exactly</pre>',
      '<ul><li>one<li>two</ul>',
      '<img src="a.png" alt=""><br />',
      '<textarea>  raw  text  </textarea></div></body></html>'
    ].join(LF);
    run();
  };

  $('hff-in').addEventListener('input', later);
  $('hff-ind').addEventListener('change', run);
  ['hff-blank', 'hff-script', 'hff-attrs'].forEach(function (id) {
    $(id).addEventListener('change', run);
  });
})();
</script>

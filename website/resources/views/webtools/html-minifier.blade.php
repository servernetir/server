<style>
.hm-meter{margin-top:18px;height:10px;border-radius:99px;background:var(--surface-2);border:1px solid var(--line);overflow:hidden}
.hm-meter span{display:block;height:100%;width:0;border-radius:99px;background:linear-gradient(90deg,#22d3ee,#34d399);transition:width .3s ease}
.hm-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(132px,1fr));gap:12px;margin-top:12px}
.hm-stat{background:var(--surface-2);border:1px solid var(--line);border-radius:13px;padding:14px 12px;text-align:center}
.hm-stat b{display:block;font-size:20px;font-weight:800;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;color:var(--text);line-height:1.35;word-break:break-all}
.hm-stat span{font-size:11.5px;color:var(--dim)}
.hm-stat.hm-win b{color:var(--green)}
.hm-stat.hm-big b{font-size:24px;background:linear-gradient(100deg,#34D399,#22D3EE);-webkit-background-clip:text;background-clip:text;color:transparent}
.hm-note{display:flex;align-items:flex-start;gap:9px;font-size:12.5px;line-height:1.85;color:var(--dim);margin-top:16px;padding-top:14px;border-top:1px solid var(--line)}
.hm-note .icon{width:15px;height:15px;color:var(--green);flex:none;margin-top:4px}
</style>

<div class="wt-io">
  <div class="wt-pane">
    <label>{{ __('ui.wt_input') }}</label>
    <textarea id="hm-in" class="wt-ta" rows="15" dir="ltr" spellcheck="false" placeholder="{{ __('ui.wt_hm_ph') }}"></textarea>
  </div>
  <div class="wt-pane">
    <label>{{ __('ui.wt_output') }}</label>
    <textarea id="hm-out" class="wt-ta" rows="15" dir="ltr" readonly spellcheck="false"></textarea>
  </div>
</div>

<div class="hm-meter"><span id="hm-fill"></span></div>

<div class="hm-stats">
  <div class="hm-stat"><b id="hm-so" dir="ltr">—</b><span>{{ __('ui.wt_hm_orig') }}</span></div>
  <div class="hm-stat"><b id="hm-sm" dir="ltr">—</b><span>{{ __('ui.wt_hm_min') }}</span></div>
  <div class="hm-stat hm-win"><b id="hm-ss" dir="ltr">—</b><span>{{ __('ui.wt_hm_saved') }}</span></div>
  <div class="hm-stat hm-win hm-big"><b id="hm-sp" dir="ltr">—</b><span>{{ __('ui.wt_hm_ratio') }}</span></div>
</div>

<div class="wt-fields">
  <label class="wt-chk"><input type="checkbox" id="hm-oc" checked> {{ __('ui.wt_hm_opt_comments') }}</label>
  <label class="wt-chk"><input type="checkbox" id="hm-ow" checked> {{ __('ui.wt_hm_opt_ws') }}</label>
  <label class="wt-chk"><input type="checkbox" id="hm-oa" checked> {{ __('ui.wt_hm_opt_attrs') }}</label>
  <label class="wt-chk"><input type="checkbox" id="hm-oq"> {{ __('ui.wt_hm_opt_quotes') }}</label>
</div>

<div class="hm-note">
  <svg class="icon"><use href="#i-shield"/></svg>
  <span>{{ __('ui.wt_hm_note') }}</span>
</div>

<div class="wt-bar">
  <button class="btn btn-primary" id="hm-run" type="button">{{ __('ui.wt_minify') }}</button>
  <button class="btn btn-glass" id="hm-copy" type="button" data-done="{{ __('ui.wt_copied') }}">{{ __('ui.wt_copy') }}</button>
  <button class="btn btn-glass" id="hm-sample" type="button">{{ __('ui.wt_hm_sample') }}</button>
  <button class="btn btn-glass" id="hm-clear" type="button">{{ __('ui.wt_clear') }}</button>
  <span class="wt-status" id="hm-msg"></span>
</div>

<script>
(function () {
  var $ = function (id) { return document.getElementById(id); };

  var T = {
    done:     @json(__('ui.wt_hm_done')),
    nochange: @json(__('ui.wt_hm_nochange')),
    prot:     @json(__('ui.wt_hm_protected')),
    removed:  @json(__('ui.wt_hm_removed')),
    verified: @json(__('ui.wt_hm_verified')),
    xhtml:    @json(__('ui.wt_hm_xhtml')),
    broken:   @json(__('ui.wt_hm_broken')),
    guard:    @json(__('ui.wt_hm_guard')),
    toobig:   @json(__('ui.wt_hm_toobig'))
  };

  var MAX = 2000000;                       // hard cap: 2M chars, keeps the tab responsive

  // Built by concatenation on purpose: a literal comment opener, or a closing
  // script marker typed out inside an inline script, flips the HTML tokenizer
  // state and would truncate this block.
  var LT    = String.fromCharCode(60);
  var GT    = String.fromCharCode(62);
  var LF    = String.fromCharCode(10);
  var CO    = LT + '!--';                  // comment open
  var CC    = '--' + GT;                   // comment close
  var CD    = LT + '![CDATA[';
  var ENDIF = LT + '![endif]';
  var SEP   = String.fromCharCode(1);      // signature field separator

  // HTML whitespace is exactly these five code points. U+00A0 (nbsp) is NOT one
  // of them and must never be collapsed.
  var WSC = String.fromCharCode(32) + String.fromCharCode(9) + String.fromCharCode(10) +
            String.fromCharCode(13) + String.fromCharCode(12);
  var RE_WS = new RegExp('[' + WSC + ']+', 'g');

  function collapseWS(s) { return s.replace(RE_WS, ' '); }
  function stripWS(s)    { return s.replace(RE_WS, ''); }

  function mk(list) {
    var o = {}, a = list.toLowerCase().split(' ');
    for (var i = 0; i < a.length; i++) { if (a[i]) o[a[i]] = 1; }
    return o;
  }

  // Void elements: never have a closing tag, so a trailing slash is pure noise.
  var VOID = mk('area base br col embed hr img input link meta param source track wbr');

  // Elements whose text content must survive byte for byte.
  var RAWEL = mk('script style pre textarea');

  // Elements next to which whitespace is NOT rendered, so it can be dropped.
  // Anything absent from this list — including custom and unknown elements — is
  // treated as inline and keeps one space, which is the conservative side.
  // script/style/noscript/template are deliberately absent: they render nothing
  // themselves, but the text nodes on either side still collapse to ONE space,
  // not zero, so "a" + a script element + "b" must not become "ab".
  var BLOCK = mk(
    'html head body base link meta title ' +
    'address article aside blockquote caption col colgroup dd details dialog div dl dt ' +
    'fieldset figcaption figure footer form h1 h2 h3 h4 h5 h6 header hgroup hr legend li ' +
    'main menu nav ol optgroup option p pre search section summary ' +
    'table tbody td tfoot th thead tr ul ' +
    'svg defs g path circle ellipse rect line polyline polygon symbol use desc metadata ' +
    'clippath mask filter marker pattern lineargradient radialgradient stop'
  );

  // Boolean attributes: the value may be dropped when it is empty or equals the name.
  var BOOL = mk(
    'allowfullscreen async autofocus autoplay checked controls default defer disabled ' +
    'formnovalidate inert ismap itemscope loop multiple muted nomodule novalidate open ' +
    'playsinline readonly required reversed selected'
  );

  function isWS(c) { return c === 32 || c === 10 || c === 9 || c === 13 || c === 12; }
  function isAlpha(c) { return (c >= 65 && c <= 90) || (c >= 97 && c <= 122); }
  function trimWS(s) {
    var a = 0, b = s.length;
    while (a < b && isWS(s.charCodeAt(a))) a++;
    while (b > a && isWS(s.charCodeAt(b - 1))) b--;
    return s.slice(a, b);
  }

  // Find the matching close tag of a raw-text element. Mirrors the HTML tokenizer:
  // the body ends at the first "</name" followed by whitespace, "/" or ">", even
  // when that sequence sits inside a JS string.
  function findClose(s, from, lname) {
    var p = from, n = s.length;
    while (true) {
      var k = s.indexOf(LT, p);
      if (k < 0) return -1;
      if (s.charCodeAt(k + 1) === 47 && s.substr(k + 2, lname.length).toLowerCase() === lname) {
        var e = k + 2 + lname.length, a = s.charCodeAt(e);
        if (e >= n || a === 62 || a === 47 || isWS(a)) return k;
      }
      p = k + 1;
    }
  }

  // ---- tokenizer -----------------------------------------------------------
  // token kinds: text | comment | decl (doctype) | keep (verbatim) | tag
  function tokenize(s) {
    var toks = [], i = 0, n = s.length, prot = 0, broken = false;

    function pushText(v) {
      if (!v) return;
      var last = toks[toks.length - 1];
      if (last && last.t === 'text') { last.v += v; } else { toks.push({ t: 'text', v: v }); }
    }

    while (i < n) {
      var lt = s.indexOf(LT, i);
      if (lt < 0) { pushText(s.slice(i)); break; }
      if (lt > i) pushText(s.slice(i, lt));

      if (s.substr(lt, 4) === CO) {                       // comment
        var ce = s.indexOf(CC, lt + 4);
        if (ce < 0) broken = true;
        var cstop = ce < 0 ? n : ce + 3;
        toks.push({ t: 'comment', v: s.slice(lt, cstop) });
        i = cstop; continue;
      }

      var c1 = s.charCodeAt(lt + 1);

      if (c1 === 33) {                                    // "<!" doctype or CDATA
        if (s.substr(lt, 9) === CD) {
          var de = s.indexOf(']]' + GT, lt);
          if (de < 0) broken = true;
          var dstop = de < 0 ? n : de + 3;
          toks.push({ t: 'keep', v: s.slice(lt, dstop) });
          i = dstop; continue;
        }
        var g1 = s.indexOf(GT, lt);
        if (g1 < 0) broken = true;
        var g1stop = g1 < 0 ? n : g1 + 1;
        toks.push({ t: 'decl', v: s.slice(lt, g1stop) });
        i = g1stop; continue;
      }

      if (c1 === 63) {                                    // processing instruction / PHP (question-mark tag)
        var g2 = s.indexOf(GT, lt);
        if (g2 < 0) broken = true;
        var g2stop = g2 < 0 ? n : g2 + 1;
        toks.push({ t: 'keep', v: s.slice(lt, g2stop) });
        i = g2stop; continue;
      }

      var close = false, p = lt + 1;
      if (c1 === 47) { close = true; p++; }               // "</"
      var ns = p;
      while (p < n) { var nc = s.charCodeAt(p); if (isWS(nc) || nc === 62 || nc === 47) break; p++; }
      var name = s.slice(ns, p);
      if (!name || !isAlpha(name.charCodeAt(0))) {        // a stray "<" sitting in text
        pushText(LT); i = lt + 1; continue;
      }

      // ---- attributes
      var attrs = [], self = false, closed = false;
      while (p < n) {
        var c2 = s.charCodeAt(p);
        if (isWS(c2)) { p++; continue; }
        if (c2 === 62) { p++; closed = true; break; }     // ">"
        if (c2 === 47) {                                  // "/"
          if (s.charCodeAt(p + 1) === 62) { self = true; p += 2; closed = true; break; }
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
            if (ve < 0) { attrs.push({ n: an, v: s.slice(q + 1) }); p = n; broken = true; }
            else { attrs.push({ n: an, v: s.slice(q + 1, ve) }); p = ve + 1; }
          } else {                                        // unquoted value: only WS and ">" end it
            var vs = q;
            while (q < n) { var c4 = s.charCodeAt(q); if (isWS(c4) || c4 === 62) break; q++; }
            attrs.push({ n: an, v: s.slice(vs, q) });
            p = q;
          }
        } else {
          attrs.push({ n: an, v: null });                 // valueless attribute
        }
      }
      if (!closed) broken = true;

      var lname = name.toLowerCase();

      // ---- raw-text element: copy the body through untouched
      if (!close && RAWEL[lname] && !self) {
        var k = findClose(s, p, lname), inner, after;
        if (k < 0) { inner = s.slice(p); after = n; broken = true; }
        else {
          inner = s.slice(p, k);
          var g3 = s.indexOf(GT, k);
          after = g3 < 0 ? n : g3 + 1;
        }
        toks.push({ t: 'tag', close: false, name: name, attrs: attrs, self: false });
        if (inner) toks.push({ t: 'keep', v: inner });
        prot++;
        if (k >= 0) toks.push({ t: 'tag', close: true, name: s.substr(k + 2, lname.length), attrs: [], self: false });
        i = after; continue;
      }

      toks.push({ t: 'tag', close: close, name: name, attrs: attrs, self: self });
      i = p;
    }

    return { toks: toks, prot: prot, broken: broken };
  }

  // ---- passes --------------------------------------------------------------
  function dropComments(toks) {
    var out = [], removed = 0;
    for (var i = 0; i < toks.length; i++) {
      var t = toks[i];
      if (t.t === 'comment') {
        var v = t.v, c = v.charAt(4);
        // downlevel/conditional comments and "bang" licence comments stay
        if (c === '[' || c === '!' || v.indexOf(ENDIF) >= 0) { out.push(t); }
        else { removed++; }
        continue;
      }
      if (t.t === 'text') {                               // texts around a dropped comment merge
        var last = out[out.length - 1];
        if (last && last.t === 'text') { last.v += t.v; continue; }
        out.push({ t: 'text', v: t.v });
        continue;
      }
      out.push(t);
    }
    return { toks: out, removed: removed };
  }

  // Is the neighbouring token a boundary where whitespace is not rendered?
  function blockSide(t) {
    if (!t) return true;                                  // start / end of document
    if (t.t === 'tag') return !!BLOCK[t.name.toLowerCase()];
    if (t.t === 'decl') return true;
    return false;                                         // comment / verbatim -> keep the space
  }

  function squeeze(toks) {
    var out = [];
    for (var i = 0; i < toks.length; i++) {
      var t = toks[i];
      if (t.t !== 'text') { out.push(t); continue; }
      var v = collapseWS(t.v);
      if (v.charCodeAt(0) === 32 && blockSide(toks[i - 1])) v = v.slice(1);
      if (v.length && v.charCodeAt(v.length - 1) === 32 && blockSide(toks[i + 1])) v = v.slice(0, v.length - 1);
      if (v) out.push({ t: 'text', v: v });
    }
    return out;
  }

  // Attributes whose value equals the browser default. input[type=text] is
  // deliberately NOT here: dropping it silently breaks input[type="text"] in CSS.
  function redundant(tag, a, attrs) {
    if (a.v === null) return false;
    var nm = a.n.toLowerCase(), v = trimWS(a.v).toLowerCase();
    if (tag === 'script') {
      if (nm === 'type') {
        return v === 'text/javascript' || v === 'application/javascript' ||
               v === 'text/ecmascript' || v === 'application/ecmascript' ||
               v === 'text/jscript'    || v === 'application/x-javascript';
      }
      if (nm === 'language') return v === 'javascript';
    }
    if (tag === 'style') {
      if (nm === 'type')  return v === 'text/css';
      if (nm === 'media') return v === 'all';
    }
    if (tag === 'link') {
      if (nm === 'media') return v === 'all';
      if (nm === 'type' && v === 'text/css') {            // only valid on a real stylesheet link
        for (var i = 0; i < attrs.length; i++) {
          if (attrs[i].n.toLowerCase() === 'rel' && attrs[i].v &&
              attrs[i].v.toLowerCase().indexOf('stylesheet') >= 0) return true;
        }
        return false;
      }
    }
    if (tag === 'form' && nm === 'method') return v === 'get';
    if (tag === 'area' && nm === 'shape')  return v === 'rect';
    if ((tag === 'td' || tag === 'th') && (nm === 'colspan' || nm === 'rowspan')) return v === '1';
    return false;
  }

  function canUnquote(v) {
    if (!v.length) return false;
    for (var i = 0; i < v.length; i++) {
      var c = v.charCodeAt(i);
      if (isWS(c) || c === 34 || c === 39 || c === 96 || c === 61 || c === 60 || c === 62) return false;
    }
    return v.charCodeAt(v.length - 1) !== 47;             // a trailing "/" would fuse with ">"
  }

  function serAttr(a, opt, box) {
    box.unq = false;
    if (a.v === null) return a.n;
    var nm = a.n.toLowerCase(), v = a.v;
    if (opt.attrs && nm === 'class') v = trimWS(collapseWS(v));   // class is token-separated
    if (opt.attrs && BOOL[nm] && (v === '' || v.toLowerCase() === nm)) return a.n;
    if (opt.quotes && canUnquote(v)) { box.unq = true; return a.n + '=' + v; }
    if (v.indexOf('"') < 0) return a.n + '="' + v + '"';
    if (v.indexOf("'") < 0) return a.n + "='" + v + "'";
    return a.n + '="' + v.split('"').join('&#34;') + '"';
  }

  function serialize(toks, opt, xhtml) {
    var buf = [], box = { unq: false };
    for (var i = 0; i < toks.length; i++) {
      var t = toks[i];

      if (t.t === 'text' || t.t === 'keep' || t.t === 'comment') { buf.push(t.v); continue; }

      if (t.t === 'decl') {
        var d = t.v;
        if (opt.ws) {
          d = collapseWS(d);
          if (d.length > 2 && d.charCodeAt(d.length - 2) === 32 && d.charCodeAt(d.length - 1) === 62) {
            d = d.slice(0, d.length - 2) + GT;
          }
        }
        buf.push(d); continue;
      }

      if (t.close) { buf.push(LT + '/' + t.name + GT); continue; }

      var tl = t.name.toLowerCase(), s = LT + t.name, unq = false;
      for (var j = 0; j < t.attrs.length; j++) {
        var a = t.attrs[j];
        if (opt.attrs && redundant(tl, a, t.attrs)) continue;
        s += ' ' + serAttr(a, opt, box);
        unq = box.unq;
      }
      // keep the self-closing slash for foreign content (svg/math) and for XHTML;
      // drop it on plain HTML void elements where it means nothing
      if (t.self && (xhtml || !VOID[tl])) s += (unq ? ' /' : '/');
      buf.push(s + GT);
    }
    return buf.join('');
  }

  function detectXhtml(s) {
    var head = s.slice(0, 4000);
    if (head.indexOf(LT + '?xml') >= 0) return true;
    var u = head.toUpperCase(), d = u.indexOf(LT + '!DOCTYPE');
    if (d < 0) return false;
    var e = u.indexOf(GT, d);
    return u.slice(d, e < 0 ? d + 300 : e).indexOf('XHTML') >= 0;
  }

  // ---- integrity guard -----------------------------------------------------
  // The output is re-parsed and compared with the input. Minifying may only ever
  // remove whitespace, comments and redundant attributes, so the tag sequence,
  // the non-whitespace text and every protected block must come back identical.
  function sig(toks) {
    var a = [], buf = '';
    function flush() { if (buf) { a.push('t' + buf); buf = ''; } }
    for (var i = 0; i < toks.length; i++) {
      var t = toks[i];
      if (t.t === 'comment') continue;                    // comments are removed on purpose
      if (t.t === 'text') { buf += stripWS(t.v); continue; }
      flush();
      if (t.t === 'keep')      a.push('k' + t.v);         // byte for byte
      else if (t.t === 'decl') a.push('d' + stripWS(t.v).toLowerCase());
      else                     a.push((t.close ? '/' : '<') + t.name.toLowerCase());
    }
    flush();
    return a.join(SEP);
  }

  function openTags(toks) {
    var a = [];
    for (var i = 0; i < toks.length; i++) { if (toks[i].t === 'tag' && !toks[i].close) a.push(toks[i]); }
    return a;
  }

  function normVal(nm, v) {
    var x = v.split('&#34;').join('"');
    return nm === 'class' ? trimWS(collapseWS(x)) : x;
  }

  // every attribute in the output must trace back to an identical one in the input
  function attrsOk(inT, outT) {
    var A = openTags(inT), B = openTags(outT);
    if (A.length !== B.length) return false;
    for (var i = 0; i < A.length; i++) {
      var src = A[i].attrs, dst = B[i].attrs;
      for (var j = 0; j < dst.length; j++) {
        var d = dst[j], nm = d.n.toLowerCase(), hit = false;
        for (var k = 0; k < src.length; k++) {
          var sa = src[k];
          if (sa.n.toLowerCase() !== nm) continue;
          if (d.v === null) {
            if (sa.v === null || sa.v === '' || sa.v.toLowerCase() === nm) hit = true;
          } else if (sa.v !== null && normVal(nm, sa.v) === normVal(nm, d.v)) {
            hit = true;
          }
          if (hit) break;
        }
        if (!hit) return false;
      }
    }
    return true;
  }

  function minify(src, opt) {
    var xhtml = detectXhtml(src);
    var r = tokenize(src);
    var toks = r.toks, removed = 0;
    if (opt.comments) { var dc = dropComments(toks); toks = dc.toks; removed = dc.removed; }
    if (opt.ws) toks = squeeze(toks);
    var out = serialize(toks, opt, xhtml);

    var back = tokenize(out);
    var ok = (sig(r.toks) === sig(back.toks)) && attrsOk(r.toks, back.toks);

    // guard: if anything drifted, hand back the untouched source rather than risk it
    return { out: ok ? out : src, prot: r.prot, removed: removed, xhtml: xhtml, broken: r.broken, ok: ok };
  }

  // ---- sizes ---------------------------------------------------------------
  var enc = (typeof TextEncoder !== 'undefined') ? new TextEncoder() : null;
  function bytes(s) {
    if (!s) return 0;
    if (enc) return enc.encode(s).length;
    try { return new Blob([s]).size; } catch (e) { return s.length; }
  }
  function fmtBytes(b) {
    if (b < 1024) return b + ' B';
    if (b < 1048576) return (b / 1024).toFixed(1) + ' KB';
    return (b / 1048576).toFixed(2) + ' MB';
  }
  function fmtPct(x) {
    var v = Math.round(x * 10) / 10;
    return ((v === Math.round(v)) ? String(Math.round(v)) : v.toFixed(1)) + '%';
  }

  // ---- wiring --------------------------------------------------------------
  function setMsg(txt, cls) {
    var m = $('hm-msg');
    m.textContent = txt || '';
    m.className = 'wt-status' + (cls ? ' ' + cls : '');
  }

  function reset() {
    $('hm-so').textContent = '—'; $('hm-sm').textContent = '—';
    $('hm-ss').textContent = '—'; $('hm-sp').textContent = '—';
    $('hm-fill').style.width = '0';
  }

  function fill(tpl, n) { return tpl.split(':n').join(String(n)); }

  function run() {
    var src = $('hm-in').value;
    if (!src) { $('hm-out').value = ''; reset(); setMsg(''); return; }
    if (src.length > MAX) { $('hm-out').value = ''; reset(); setMsg(T.toobig, 'err'); return; }

    var opt = {
      comments: $('hm-oc').checked,
      ws:       $('hm-ow').checked,
      attrs:    $('hm-oa').checked,
      quotes:   $('hm-oq').checked
    };

    var res;
    try { res = minify(src, opt); }
    catch (err) { $('hm-out').value = ''; reset(); setMsg(String((err && err.message) || err), 'err'); return; }

    $('hm-out').value = res.out;

    var o = bytes(src), m = bytes(res.out), saved = o - m;
    $('hm-so').textContent = fmtBytes(o);
    $('hm-sm').textContent = fmtBytes(m);
    $('hm-ss').textContent = (saved < 0 ? '+' : '') + fmtBytes(Math.abs(saved));
    var pct = (o > 0 && saved > 0) ? (saved / o * 100) : 0;
    $('hm-sp').textContent = fmtPct(pct);
    $('hm-fill').style.width = Math.max(0, Math.min(100, pct)) + '%';

    var parts = [];
    parts.push(saved > 0 ? fill(T.done, saved) : T.nochange);
    if (res.removed > 0) parts.push(fill(T.removed, res.removed));
    if (res.prot > 0)    parts.push(fill(T.prot, res.prot));
    if (res.xhtml)       parts.push(T.xhtml);
    if (res.ok)          parts.push(T.verified);
    else                 parts.push(T.guard);
    if (res.broken)      parts.push(T.broken);

    setMsg(parts.join(' · '), (!res.ok || res.broken) ? 'err' : (saved > 0 ? 'ok' : ''));
  }

  var tmr = null;
  function later() { clearTimeout(tmr); tmr = setTimeout(run, 180); }

  $('hm-run').onclick = run;
  $('hm-copy').onclick = function (e) { wtCopy(e.currentTarget, $('hm-out').value); };
  $('hm-clear').onclick = function () {
    $('hm-in').value = ''; $('hm-out').value = ''; reset(); setMsg('');
  };
  $('hm-sample').onclick = function () {
    $('hm-in').value = [
      CO + ' product card ' + CC,
      LT + 'div   class="  card   is-open  "    id="a" ' + GT,
      '    ' + LT + 'p' + GT + 'Alpha   ' + LT + 'b' + GT + 'Beta' + LT + '/b' + GT + '  Gamma' + LT + '/p' + GT,
      '    ' + LT + 'pre' + GT + '   keep',
      '     me   ' + LT + '/pre' + GT,
      '    ' + LT + 'p' + GT + LT + 'input type="checkbox" checked="checked"' + GT + ' ' +
        LT + 'label' + GT + 'Yes' + LT + '/label' + GT + LT + '/p' + GT,
      '    ' + LT + 'script type="text/javascript"' + GT,
      '      var   n  =  1;',
      '    ' + LT + '/script' + GT,
      LT + '/div' + GT
    ].join(LF);
    run();
  };
  $('hm-in').addEventListener('input', later);
  ['hm-oc', 'hm-ow', 'hm-oa', 'hm-oq'].forEach(function (id) { $(id).addEventListener('change', run); });
})();
</script>

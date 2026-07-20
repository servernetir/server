<style>
  .jt-toolrow{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:16px}
  .jt-find{display:flex;align-items:center;gap:8px;flex:1;min-width:240px;background:var(--surface-2);
    border:1px solid var(--line-2);border-radius:12px;padding:0 12px;transition:border-color .2s}
  .jt-find:focus-within{border-color:var(--cyan)}
  .jt-find .icon{width:16px;height:16px;color:var(--dim);flex:none}
  .jt-find input{flex:1;min-width:0;background:none;border:0;outline:none;color:var(--text);
    font-family:var(--font-body);font-size:14px;padding:11px 0}
  .jt-hitn{font-size:12px;color:var(--dim);font-family:ui-monospace,monospace;flex:none;white-space:nowrap}
  .jt-nav{display:flex;gap:4px;flex:none}
  .jt-nav button{width:28px;height:28px;display:grid;place-items:center;border-radius:8px;cursor:pointer;
    background:var(--surface);border:1px solid var(--line-2);color:var(--muted);padding:0}
  .jt-nav button:hover{border-color:var(--cyan);color:var(--cyan)}
  .jt-nav .icon{width:14px;height:14px}
  .jt-nav .up .icon{transform:rotate(180deg)}

  .jt-stats{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px}
  .jt-stat{display:inline-flex;align-items:baseline;gap:7px;background:var(--surface-2);border:1px solid var(--line);
    border-radius:99px;padding:5px 13px;font-size:12px;color:var(--dim)}
  .jt-stat b{font-family:ui-monospace,monospace;font-size:13px;color:var(--cyan);font-weight:700}
  .jt-stat.warn b{color:#f0a02a}

  .jt-tree{position:relative;margin-top:14px;background:#0B111C;border:1px solid var(--line-2);border-radius:14px;
    max-height:540px;min-height:220px;overflow:auto;padding:8px 0;outline:none;
    font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;line-height:1.55;
    --jt-str:#7EE7A0; --jt-num:#79C0FF; --jt-bool:#D2A8FF; --jt-null:#8B95A6;
    --jt-key:#E3EAF5; --jt-punc:#7E8899; --jt-idx:#9AA6BA; --jt-hover:rgba(255,255,255,.05);
    --jt-sel:rgba(34,211,238,.14); --jt-selb:rgba(34,211,238,.55); --jt-hit:rgba(240,160,42,.11)}
  .jt-tree:focus-visible{border-color:var(--cyan)}
  html[data-theme="light"] .jt-tree{background:#FCFDFF;border-color:var(--line);
    --jt-str:#0A7A34; --jt-num:#0B57AD; --jt-bool:#6D28D9; --jt-null:#64748B;
    --jt-key:#0B1220; --jt-punc:#7A869A; --jt-idx:#5B6478; --jt-hover:rgba(15,23,42,.05);
    --jt-sel:rgba(8,145,178,.14); --jt-selb:rgba(8,145,178,.6); --jt-hit:rgba(180,110,10,.10)}

  .jt-row{display:flex;align-items:center;gap:5px;width:max-content;min-width:100%;white-space:nowrap;
    padding-top:1px;padding-bottom:1px;padding-inline-end:14px;cursor:default;
    border-inline-start:2px solid transparent}
  .jt-row:hover{background:var(--jt-hover)}
  .jt-row.is-hit{background:var(--jt-hit)}
  .jt-row.is-sel{background:var(--jt-sel);border-inline-start-color:var(--jt-selb)}

  .jt-tw{width:15px;height:15px;flex:none;display:grid;place-items:center;color:var(--jt-punc);cursor:pointer;
    border-radius:4px;transition:color .15s}
  .jt-tw .icon{width:12px;height:12px;stroke-width:2.4;transform:rotate(-90deg);transition:transform .15s}
  .jt-tw.is-open .icon{transform:rotate(0deg)}
  .jt-tw:hover{color:var(--cyan)}
  .jt-tw-e{cursor:default}

  .jt-k{color:var(--jt-key)}
  .jt-i{color:var(--jt-idx)}
  .jt-pn{color:var(--jt-punc)}
  .jt-s{color:var(--jt-str)}
  .jt-n{color:var(--jt-num)}
  .jt-b{color:var(--jt-bool)}
  .jt-z{color:var(--jt-null);font-style:italic}
  .jt-cnt{color:var(--jt-punc);font-size:11.5px;opacity:.85;margin-inline-start:4px}
  .jt-tag{font-size:10px;font-weight:700;border-radius:5px;padding:1px 5px;margin-inline-start:5px;
    background:rgba(240,160,42,.18);color:#F0A02A;border:1px solid rgba(240,160,42,.35);font-family:var(--font-body)}
  .jt-mk{background:rgba(240,160,42,.42);color:inherit;border-radius:3px;padding:0 1px}
  html[data-theme="light"] .jt-mk{background:rgba(240,160,42,.55)}

  .jt-empty{padding:52px 20px;text-align:center;color:var(--jt-null);font-size:13px;
    font-family:var(--font-body);line-height:1.9}
  .jt-note{padding:9px 14px;color:#F0A02A;font-size:12px;font-family:var(--font-body)}
  .jt-hint{margin-top:9px;font-size:12px;color:var(--dim);line-height:1.8}

  .jt-sel-box{margin-top:14px;background:var(--surface-2);border:1px solid var(--line);border-radius:13px;padding:13px 15px}
  .jt-sel-h{display:block;font-size:12px;color:var(--dim);margin-bottom:9px}
  .jt-pathrow{display:flex;gap:9px;flex-wrap:wrap;align-items:center}
  .jt-path{flex:1;min-width:200px;background:var(--surface);border:1px solid var(--line-2);border-radius:10px;
    padding:10px 13px;color:var(--text);font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;outline:none}
  .jt-path:focus{border-color:var(--cyan)}
  .jt-pathrow .btn{padding:9px 15px;font-size:13px}
  .jt-meta{margin-top:10px;display:flex;flex-wrap:wrap;gap:7px 16px;font-size:12px;color:var(--muted)}
  .jt-meta b{font-family:ui-monospace,monospace;color:var(--text);font-weight:600}
</style>

<div class="wt-pane">
  <label for="jt-in">{{ __('ui.wt_input') }}</label>
  <textarea id="jt-in" class="wt-ta" rows="7" dir="ltr" spellcheck="false"
            placeholder="{{ __('ui.wt_jt_ph') }}"></textarea>
</div>

<div class="wt-bar">
  <button type="button" class="btn btn-primary" id="jt-sample"><svg class="icon"><use href="#i-box"/></svg>{{ __('ui.wt_jt_sample') }}</button>
  <button type="button" class="btn btn-glass" id="jt-exp"><svg class="icon"><use href="#i-plus"/></svg>{{ __('ui.wt_jt_expand') }}</button>
  <button type="button" class="btn btn-glass" id="jt-col"><svg class="icon"><use href="#i-list"/></svg>{{ __('ui.wt_jt_collapse') }}</button>
  <button type="button" class="btn btn-glass" id="jt-clear"><svg class="icon"><use href="#i-x"/></svg>{{ __('ui.wt_clear') }}</button>
  <span class="wt-status" id="jt-msg"></span>
</div>

<div class="jt-toolrow">
  <div class="jt-find">
    <svg class="icon"><use href="#i-search"/></svg>
    <input type="search" id="jt-q" autocomplete="off" spellcheck="false"
           placeholder="{{ __('ui.wt_jt_search_ph') }}">
    <span class="jt-hitn" id="jt-hitn"></span>
  </div>
  <div class="jt-nav">
    <button type="button" class="up" id="jt-prev" title="{{ __('ui.wt_jt_prev') }}"><svg class="icon"><use href="#i-chev"/></svg></button>
    <button type="button" id="jt-next" title="{{ __('ui.wt_jt_next') }}"><svg class="icon"><use href="#i-chev"/></svg></button>
  </div>
</div>

<div class="jt-stats" id="jt-stats"></div>

<div class="jt-tree" id="jt-tree" dir="ltr" tabindex="0"></div>
<p class="jt-hint">{{ __('ui.wt_jt_hint') }}</p>

<div class="jt-sel-box" id="jt-selbox" hidden>
  <label class="jt-sel-h" for="jt-path">{{ __('ui.wt_jt_path') }}</label>
  <div class="jt-pathrow">
    <input type="text" class="jt-path" id="jt-path" dir="ltr" readonly spellcheck="false">
    <button type="button" class="btn btn-glass" id="jt-cp-path" data-done="{{ __('ui.wt_copied') }}">{{ __('ui.wt_jt_copy_path') }}</button>
    <button type="button" class="btn btn-glass" id="jt-cp-val" data-done="{{ __('ui.wt_copied') }}">{{ __('ui.wt_jt_copy_val') }}</button>
  </div>
  <div class="jt-meta" id="jt-meta"></div>
</div>

<script>
(function () {
  var $ = function (id) { return document.getElementById(id); };

  var L = {
    valid:    @json(__('ui.wt_jt_valid')),
    keys:     @json(__('ui.wt_jt_keys')),
    items:    @json(__('ui.wt_jt_items')),
    empty:    @json(__('ui.wt_jt_empty')),
    dup:      @json(__('ui.wt_jt_dup')),
    unsafe:   @json(__('ui.wt_jt_unsafe')),
    len:      @json(__('ui.wt_jt_len')),
    hits:     @json(__('ui.wt_jt_hits')),
    nohits:   @json(__('ui.wt_jt_nohits')),
    trunc:    @json(__('ui.wt_jt_trunc')),
    root:     @json(__('ui.wt_jt_root')),
    sNodes:   @json(__('ui.wt_jt_s_nodes')),
    sDepth:   @json(__('ui.wt_jt_s_depth')),
    sKeys:    @json(__('ui.wt_jt_s_keys')),
    sArrays:  @json(__('ui.wt_jt_s_arrays')),
    sObjects: @json(__('ui.wt_jt_s_objects')),
    sDups:    @json(__('ui.wt_jt_s_dups')),
    sTime:    @json(__('ui.wt_jt_s_time')),
    mType:    @json(__('ui.wt_jt_m_type')),
    mChild:   @json(__('ui.wt_jt_m_children')),
    mLen:     @json(__('ui.wt_jt_m_length')),
    mRaw:     @json(__('ui.wt_jt_m_raw')),
    tObject:  @json(__('ui.wt_jt_t_object')),
    tArray:   @json(__('ui.wt_jt_t_array')),
    tString:  @json(__('ui.wt_jt_t_string')),
    tNumber:  @json(__('ui.wt_jt_t_number')),
    tBool:    @json(__('ui.wt_jt_t_bool')),
    tNull:    @json(__('ui.wt_jt_t_null')),
    eAt:      @json(__('ui.wt_jt_e_at')),
    eValue:   @json(__('ui.wt_jt_e_value')),
    eString:  @json(__('ui.wt_jt_e_string')),
    eEscape:  @json(__('ui.wt_jt_e_escape')),
    eCtrl:    @json(__('ui.wt_jt_e_ctrl')),
    eColon:   @json(__('ui.wt_jt_e_colon')),
    eComma:   @json(__('ui.wt_jt_e_comma')),
    eKey:     @json(__('ui.wt_jt_e_key')),
    eTrail:   @json(__('ui.wt_jt_e_trailcomma')),
    eExtra:   @json(__('ui.wt_jt_e_extra')),
    eEof:     @json(__('ui.wt_jt_e_eof')),
    eNumber:  @json(__('ui.wt_jt_e_number')),
    eDeep:    @json(__('ui.wt_jt_e_deep')),
    eBig:     @json(__('ui.wt_jt_e_big')),
    eSize:    @json(__('ui.wt_jt_e_size'))
  };

  var BS  = String.fromCharCode(92);   /* a lone backslash, built by code on purpose */
  var NL  = String.fromCharCode(10);
  var HEX = '0123456789abcdef';

  var MAX_CHARS = 2000000;   /* input characters accepted */
  var MAX_NODES = 150000;    /* value nodes accepted */
  var MAX_DEPTH = 200;       /* nesting levels, keeps the recursive parser off the stack limit */
  var MAX_ROWS  = 6000;      /* rows painted at once */
  var MAX_STR   = 160;       /* characters of a string shown inline */
  var MAX_HITS  = 5000;

  var tree = $('jt-tree'), msg = $('jt-msg'), statsEl = $('jt-stats');
  var root = null, nodes = [], stats = null;
  var expanded = Object.create(null);
  var visIds = [];           /* node ids currently painted, in visual order */
  var selId = -1, lq = '', hitIds = [], hitSet = Object.create(null), hitAt = -1;
  var timer = null;

  function fill(t, tok, val) { return String(t).split(tok).join(String(val)); }
  function esc(t) {
    return String(t).replace(/&/g, '&amp;').replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  /* ───────────────────────── JSON parser (RFC 8259, hand written) ─────────────────────────
     Written from scratch instead of leaning on JSON.parse so the tree can show what the
     native parser destroys: original member order for numeric-looking keys, duplicate
     members, and the exact digits of integers past 2^53.                                   */

  function ParseErr(msg, pos) { this.msg = msg; this.pos = pos; }

  function parse(s) {
    var i = 0, n = s.length, list = [];
    var st = { objects: 0, arrays: 0, keys: 0, dups: 0, depth: 0 };

    function fail(m) { throw new ParseErr(m, i); }
    function ws() {
      while (i < n) { var c = s.charCodeAt(i); if (c === 32 || c === 9 || c === 10 || c === 13) i++; else break; }
    }
    function isDig(p) { var c = s.charCodeAt(p); return p < n && c >= 48 && c <= 57; }

    function mk(t, parent, key, idx) {
      if (list.length >= MAX_NODES) fail(L.eBig);
      var nd = { id: list.length, t: t, parent: parent, key: key, idx: idx,
                 kids: null, v: null, raw: null, dup: false, unsafe: false };
      list.push(nd);
      return nd;
    }

    function readString() {
      i++;                                   /* step over the opening quote */
      var out = '', run = i;
      for (;;) {
        if (i >= n) fail(L.eString);
        var c = s.charCodeAt(i);
        if (c === 34) { out += s.slice(run, i); i++; return out; }
        if (c === 92) {
          out += s.slice(run, i);
          i++;
          if (i >= n) fail(L.eString);
          var e = s.charCodeAt(i);
          if (e === 34) out += '"';
          else if (e === 92) out += BS;
          else if (e === 47) out += '/';
          else if (e === 98) out += String.fromCharCode(8);
          else if (e === 102) out += String.fromCharCode(12);
          else if (e === 110) out += NL;
          else if (e === 114) out += String.fromCharCode(13);
          else if (e === 116) out += String.fromCharCode(9);
          else if (e === 117) {
            if (i + 4 >= n) fail(L.eEscape);
            var cp = 0;
            for (var h = 1; h <= 4; h++) {
              var d = HEX.indexOf(s.charAt(i + h).toLowerCase());
              if (d < 0) fail(L.eEscape);
              cp = cp * 16 + d;
            }
            out += String.fromCharCode(cp);   /* a surrogate pair arrives as two escapes and rejoins here */
            i += 4;
          } else fail(L.eEscape);
          i++;
          run = i;
        }
        else if (c < 32) fail(L.eCtrl);      /* raw newlines and tabs are illegal inside a JSON string */
        else i++;
      }
    }

    function readNumber() {
      var start = i;
      if (s.charCodeAt(i) === 45) i++;
      if (i >= n) fail(L.eEof);
      var c = s.charCodeAt(i);
      if (c === 48) i++;                                       /* a leading zero may not be followed by digits */
      else if (c >= 49 && c <= 57) { while (isDig(i)) i++; }
      else fail(L.eNumber);
      if (i < n && s.charCodeAt(i) === 46) {
        i++; if (!isDig(i)) fail(L.eNumber); while (isDig(i)) i++;
      }
      if (i < n && (s.charCodeAt(i) === 101 || s.charCodeAt(i) === 69)) {
        i++;
        var sg = s.charCodeAt(i);
        if (sg === 43 || sg === 45) i++;
        if (!isDig(i)) fail(L.eNumber);
        while (isDig(i)) i++;
      }
      return s.slice(start, i);
    }

    function lit(word) {
      if (s.substr(i, word.length) !== word) fail(L.eValue);
      i += word.length;
    }

    function value(parent, key, idx, depth) {
      if (depth > MAX_DEPTH) fail(L.eDeep);
      if (depth > st.depth) st.depth = depth;
      ws();
      if (i >= n) fail(L.eEof);
      var c = s.charCodeAt(i), nd;

      if (c === 123) {                                        /* opening brace */
        i++;
        nd = mk('object', parent, key, idx); nd.kids = []; st.objects++;
        ws();
        if (i < n && s.charCodeAt(i) === 125) { i++; return nd; }
        var seen = Object.create(null);
        for (;;) {
          ws();
          if (i >= n) fail(L.eEof);
          if (s.charCodeAt(i) !== 34) fail(L.eKey);
          var k = readString();
          ws();
          if (i >= n) fail(L.eEof);
          if (s.charCodeAt(i) !== 58) fail(L.eColon);
          i++;
          var ch = value(nd, k, null, depth + 1);
          st.keys++;
          if (seen[k]) { ch.dup = true; st.dups++; } else seen[k] = 1;
          nd.kids.push(ch);
          ws();
          if (i >= n) fail(L.eEof);
          var d = s.charCodeAt(i);
          if (d === 44) {
            i++; ws();
            if (i < n && s.charCodeAt(i) === 125) fail(L.eTrail);
            continue;
          }
          if (d === 125) { i++; return nd; }
          fail(L.eComma);
        }
      }

      if (c === 91) {                                         /* opening bracket */
        i++;
        nd = mk('array', parent, key, idx); nd.kids = []; st.arrays++;
        ws();
        if (i < n && s.charCodeAt(i) === 93) { i++; return nd; }
        var at = 0;
        for (;;) {
          nd.kids.push(value(nd, null, at, depth + 1));
          at++;
          ws();
          if (i >= n) fail(L.eEof);
          var e2 = s.charCodeAt(i);
          if (e2 === 44) {
            i++; ws();
            if (i < n && s.charCodeAt(i) === 93) fail(L.eTrail);
            continue;
          }
          if (e2 === 93) { i++; return nd; }
          fail(L.eComma);
        }
      }

      if (c === 34) { nd = mk('string', parent, key, idx); nd.v = readString(); return nd; }
      if (c === 116) { lit('true');  nd = mk('boolean', parent, key, idx); nd.v = true;  return nd; }
      if (c === 102) { lit('false'); nd = mk('boolean', parent, key, idx); nd.v = false; return nd; }
      if (c === 110) { lit('null');  nd = mk('null', parent, key, idx);    nd.v = null;  return nd; }
      if (c === 45 || (c >= 48 && c <= 57)) {
        var raw = readNumber();
        nd = mk('number', parent, key, idx);
        nd.raw = raw;
        nd.v = Number(raw);
        /* an integer literal outside the safe range cannot survive a double round trip */
        if (raw.indexOf('.') < 0 && raw.indexOf('e') < 0 && raw.indexOf('E') < 0
            && !Number.isSafeInteger(nd.v)) nd.unsafe = true;
        return nd;
      }
      fail(L.eValue);
    }

    var r = value(null, null, null, 0);
    ws();
    if (i < n) fail(L.eExtra);
    return { root: r, nodes: list, stats: st };
  }

  function lineCol(src, pos) {
    var line = 1, last = -1;
    for (var p = 0; p < pos && p < src.length; p++) if (src.charCodeAt(p) === 10) { line++; last = p; }
    return { line: line, col: pos - last };
  }

  /* ───────────────────────── display helpers ───────────────────────── */

  function escJson(t) {
    var need = false, i;
    for (i = 0; i < t.length; i++) {
      var c = t.charCodeAt(i);
      if (c < 32 || c === 34 || c === 92) { need = true; break; }
    }
    if (!need) return t;
    var o = '';
    for (i = 0; i < t.length; i++) {
      var d = t.charCodeAt(i);
      if (d === 34) o += BS + '"';
      else if (d === 92) o += BS + BS;
      else if (d === 10) o += BS + 'n';
      else if (d === 13) o += BS + 'r';
      else if (d === 9) o += BS + 't';
      else if (d === 8) o += BS + 'b';
      else if (d === 12) o += BS + 'f';
      else if (d < 32) o += BS + 'u00' + HEX.charAt((d >> 4) & 15) + HEX.charAt(d & 15);
      else o += t.charAt(i);
    }
    return o;
  }

  /* escape for html, wrapping every occurrence of the live query in a mark */
  function hl(t) {
    if (!lq) return esc(t);
    var low = t.toLowerCase(), out = '', from = 0, p, guard = 0;
    while ((p = low.indexOf(lq, from)) >= 0 && guard < 300) {
      guard++;
      out += esc(t.slice(from, p)) + '<mark class="jt-mk">' + esc(t.slice(p, p + lq.length)) + '</mark>';
      from = p + lq.length;
    }
    if (!guard) return esc(t);
    return out + esc(t.slice(from));
  }

  function valText(nd) {
    if (nd.t === 'string') return nd.v;
    if (nd.t === 'number') return nd.raw;
    if (nd.t === 'boolean') return nd.v ? 'true' : 'false';
    if (nd.t === 'null') return 'null';
    return null;
  }

  function typeName(t) {
    if (t === 'object') return L.tObject;
    if (t === 'array') return L.tArray;
    if (t === 'string') return L.tString;
    if (t === 'number') return L.tNumber;
    if (t === 'boolean') return L.tBool;
    return L.tNull;
  }

  /* ───────────────────────── JSONPath for the selected node ───────────────────────── */

  function isIdent(k) {
    if (!k.length) return false;
    var c = k.charCodeAt(0);
    if (!((c >= 65 && c <= 90) || (c >= 97 && c <= 122) || c === 95 || c === 36)) return false;
    for (var i = 1; i < k.length; i++) {
      var d = k.charCodeAt(i);
      if (!((d >= 65 && d <= 90) || (d >= 97 && d <= 122) || (d >= 48 && d <= 57) || d === 95 || d === 36)) return false;
    }
    return true;
  }

  function seg(k) {
    if (isIdent(k)) return '.' + k;
    var q = k.split(BS).join(BS + BS).split("'").join(BS + "'");
    return "['" + q + "']";
  }

  function pathOf(nd) {
    var parts = [], cur = nd;
    while (cur && cur.parent) {
      parts.push(cur.key !== null ? seg(cur.key) : '[' + cur.idx + ']');
      cur = cur.parent;
    }
    parts.reverse();
    return '$' + parts.join('');
  }

  /* ───────────────────────── re-serialise a node, digits intact ───────────────────────── */

  function toJson(nd, pad) {
    var ind = '  ', me = pad + ind, out, i;
    if (nd.t === 'object') {
      if (!nd.kids.length) return '{}';
      out = '{' + NL;
      for (i = 0; i < nd.kids.length; i++) {
        var ch = nd.kids[i];
        out += me + '"' + escJson(ch.key) + '": ' + toJson(ch, me) + (i < nd.kids.length - 1 ? ',' : '') + NL;
      }
      return out + pad + '}';
    }
    if (nd.t === 'array') {
      if (!nd.kids.length) return '[]';
      out = '[' + NL;
      for (i = 0; i < nd.kids.length; i++) {
        out += me + toJson(nd.kids[i], me) + (i < nd.kids.length - 1 ? ',' : '') + NL;
      }
      return out + pad + ']';
    }
    if (nd.t === 'string') return '"' + escJson(nd.v) + '"';
    if (nd.t === 'number') return nd.raw;                      /* the literal, not a rounded double */
    if (nd.t === 'boolean') return nd.v ? 'true' : 'false';
    return 'null';
  }

  /* ───────────────────────── rendering ───────────────────────── */

  function rowHtml(nd, depth) {
    var box = nd.kids !== null, cnt = box ? nd.kids.length : 0;
    var open = box && cnt > 0 && !!expanded[nd.id];
    var cls = 'jt-row';
    if (nd.id === selId) cls += ' is-sel';
    else if (hitSet[nd.id]) cls += ' is-hit';

    var h = '<div class="' + cls + '" data-id="' + nd.id + '" style="padding-inline-start:'
          + (8 + depth * 15) + 'px">';

    if (box && cnt > 0) h += '<span class="jt-tw' + (open ? ' is-open' : '') + '" data-tw="1"><svg class="icon"><use href="#i-chev"/></svg></span>';
    else h += '<span class="jt-tw jt-tw-e"></span>';

    if (nd.key !== null) {
      h += '<span class="jt-k">' + hl('"' + escJson(nd.key) + '"') + '</span>';
      if (nd.dup) h += '<span class="jt-tag">' + esc(L.dup) + '</span>';
      h += '<span class="jt-pn">:</span>';
    } else if (nd.idx !== null) {
      h += '<span class="jt-i">' + nd.idx + '</span><span class="jt-pn">:</span>';
    }

    if (nd.t === 'object' || nd.t === 'array') {
      var o = nd.t === 'object' ? '{' : '[';
      var c = nd.t === 'object' ? '}' : ']';
      if (cnt === 0) h += '<span class="jt-pn">' + o + c + '</span>';
      else if (open) h += '<span class="jt-pn">' + o + '</span>';
      else h += '<span class="jt-pn">' + o + ' … ' + c + '</span>';
      if (cnt > 0) {
        var label = nd.t === 'object' ? L.keys : L.items;
        h += '<span class="jt-cnt">' + esc(fill(label, ':n', cnt)) + '</span>';
      }
    }
    else if (nd.t === 'string') {
      var v = nd.v, cut = v.length > MAX_STR;
      var shown = cut ? v.slice(0, MAX_STR) : v;
      h += '<span class="jt-s">' + hl('"' + escJson(shown) + (cut ? '…' : '') + '"') + '</span>';
      if (cut) h += '<span class="jt-cnt">' + esc(fill(L.len, ':n', v.length)) + '</span>';
    }
    else if (nd.t === 'number') {
      h += '<span class="jt-n">' + hl(nd.raw) + '</span>';
      if (nd.unsafe) h += '<span class="jt-tag">' + esc(L.unsafe) + '</span>';
    }
    else if (nd.t === 'boolean') h += '<span class="jt-b">' + hl(nd.v ? 'true' : 'false') + '</span>';
    else h += '<span class="jt-z">' + hl('null') + '</span>';

    return h + '</div>';
  }

  function render() {
    visIds = [];
    if (!root) {
      tree.innerHTML = '<div class="jt-empty">' + esc(L.empty) + '</div>';
      return;
    }
    var out = [], cut = false;

    (function walk(nd, depth) {
      if (out.length >= MAX_ROWS) { cut = true; return; }
      out.push(rowHtml(nd, depth));
      visIds.push(nd.id);
      if (nd.kids && nd.kids.length && expanded[nd.id]) {
        for (var i = 0; i < nd.kids.length; i++) {
          if (out.length >= MAX_ROWS) { cut = true; return; }
          walk(nd.kids[i], depth + 1);
        }
      }
    })(root, 0);

    if (cut) out.push('<div class="jt-note">' + esc(fill(L.trunc, ':n', MAX_ROWS)) + '</div>');
    tree.innerHTML = out.join('');
  }

  function rowEl(id) { return tree.querySelector('[data-id="' + id + '"]'); }

  function scrollToId(id, center) {
    var el = rowEl(id);
    if (!el) return;
    var top = el.offsetTop, bot = top + el.offsetHeight;
    if (center) {
      var want = top - tree.clientHeight / 2 + el.offsetHeight / 2;
      tree.scrollTop = want < 0 ? 0 : want;
      return;
    }
    var vt = tree.scrollTop, vb = vt + tree.clientHeight;   /* keyboard moves scroll the minimum needed */
    if (top < vt + 8) tree.scrollTop = top - 8 < 0 ? 0 : top - 8;
    else if (bot > vb - 8) tree.scrollTop = bot - tree.clientHeight + 8;
  }

  /* ───────────────────────── selection panel ───────────────────────── */

  function updateSel() {
    var box = $('jt-selbox');
    if (selId < 0 || !nodes[selId]) { box.hidden = true; return; }
    var nd = nodes[selId];
    box.hidden = false;
    $('jt-path').value = pathOf(nd);

    var bits = [];
    bits.push('<span>' + esc(L.mType) + ' <b>' + esc(typeName(nd.t)) + '</b></span>');
    if (nd.kids) bits.push('<span>' + esc(L.mChild) + ' <b>' + nd.kids.length + '</b></span>');
    if (nd.t === 'string') bits.push('<span>' + esc(L.mLen) + ' <b>' + nd.v.length + '</b></span>');
    if (nd.t === 'number') bits.push('<span>' + esc(L.mRaw) + ' <b dir="ltr">' + esc(nd.raw) + '</b></span>');
    if (!nd.parent) bits.push('<span><b>' + esc(L.root) + '</b></span>');
    $('jt-meta').innerHTML = bits.join('');
  }

  /* moving the selection repaints two rows instead of the whole tree, which matters
     once a large payload has thousands of rows on screen */
  function setSel(id) {
    if (id === selId) { updateSel(); return; }
    var old = selId;
    selId = id;
    if (old >= 0) {
      var a = rowEl(old);
      if (a) { a.classList.remove('is-sel'); if (hitSet[old]) a.classList.add('is-hit'); }
    }
    if (id >= 0) {
      var b = rowEl(id);
      if (b) { b.classList.remove('is-hit'); b.classList.add('is-sel'); }
    }
    updateSel();
  }

  /* ───────────────────────── search ───────────────────────── */

  function updateHitLabel() {
    var el = $('jt-hitn');
    if (!lq) { el.textContent = ''; return; }
    if (!hitIds.length) { el.textContent = L.nohits; return; }
    el.textContent = fill(fill(L.hits, ':i', hitAt + 1), ':n', hitIds.length);
  }

  function applySearch(jump) {
    lq = $('jt-q').value.toLowerCase();
    hitIds = []; hitSet = Object.create(null); hitAt = -1;

    if (root && lq) {
      for (var i = 0; i < nodes.length && hitIds.length < MAX_HITS; i++) {
        var nd = nodes[i], m = false;
        if (nd.key !== null && nd.key.toLowerCase().indexOf(lq) >= 0) m = true;
        if (!m) {
          var vt = valText(nd);
          if (vt !== null && vt.toLowerCase().indexOf(lq) >= 0) m = true;
        }
        if (m) {
          hitSet[nd.id] = true;
          hitIds.push(nd.id);
          var p = nd.parent;
          while (p) { expanded[p.id] = true; p = p.parent; }   /* reveal every match */
        }
      }
      if (hitIds.length) hitAt = 0;
    }
    updateHitLabel();
    render();
    if (jump && hitAt >= 0) { setSel(hitIds[0]); scrollToId(hitIds[0], true); }
  }

  function navHit(dir) {
    if (!hitIds.length) return;
    hitAt = (hitAt + dir + hitIds.length) % hitIds.length;
    updateHitLabel();
    setSel(hitIds[hitAt]);
    scrollToId(selId, true);
  }

  /* ───────────────────────── stats ───────────────────────── */

  function pill(label, val, warn) {
    return '<span class="jt-stat' + (warn ? ' warn' : '') + '">' + esc(label)
         + ' <b dir="ltr">' + esc(String(val)) + '</b></span>';
  }

  function renderStats() {
    if (!stats) { statsEl.innerHTML = ''; return; }
    var h = pill(L.sNodes, nodes.length)
          + pill(L.sDepth, stats.depth)
          + pill(L.sKeys, stats.keys)
          + pill(L.sObjects, stats.objects)
          + pill(L.sArrays, stats.arrays)
          + pill(L.sTime, stats.ms + ' ms');
    if (stats.dups > 0) h += pill(L.sDups, stats.dups, true);
    statsEl.innerHTML = h;
  }

  /* ───────────────────────── pipeline ───────────────────────── */

  function reset() {
    root = null; nodes = []; stats = null;
    expanded = Object.create(null);
    selId = -1; hitIds = []; hitSet = Object.create(null); hitAt = -1;
    msg.textContent = ''; msg.className = 'wt-status';
    statsEl.innerHTML = '';
    $('jt-selbox').hidden = true;
    updateHitLabel();
    render();
  }

  function defaultExpand() {
    var budget = 900;
    (function w(nd, d) {
      if (!nd.kids || !nd.kids.length || d >= 2 || budget <= 0) return;
      expanded[nd.id] = true;
      budget -= nd.kids.length;
      for (var i = 0; i < nd.kids.length; i++) w(nd.kids[i], d + 1);
    })(root, 0);
  }

  function bail(text) {
    root = null; nodes = []; stats = null;
    selId = -1; hitIds = []; hitSet = Object.create(null); hitAt = -1;
    statsEl.innerHTML = '';
    $('jt-selbox').hidden = true;
    msg.textContent = text;
    msg.className = 'wt-status err';
    updateHitLabel();
    render();
  }

  function run() {
    var src = $('jt-in').value;
    if (!src.trim()) { reset(); return; }
    if (src.length > MAX_CHARS) { bail(L.eSize); return; }

    var t0 = (window.performance && performance.now) ? performance.now() : Date.now();
    var res;
    try {
      res = parse(src);
    } catch (e) {
      var at = lineCol(src, e instanceof ParseErr ? e.pos : 0);
      var text = e instanceof ParseErr ? e.msg : String(e.message || e);
      bail(fill(fill(fill(L.eAt, ':l', at.line), ':c', at.col), ':m', text));
      return;
    }

    root = res.root; nodes = res.nodes; stats = res.stats;
    stats.ms = Math.round(((window.performance && performance.now) ? performance.now() : Date.now()) - t0);
    expanded = Object.create(null);
    defaultExpand();
    selId = root.id;

    msg.textContent = L.valid;
    msg.className = 'wt-status ok';
    renderStats();
    applySearch(false);
    updateSel();
  }

  function expandAll() {
    if (!root) return;
    for (var i = 0; i < nodes.length; i++) if (nodes[i].kids && nodes[i].kids.length) expanded[nodes[i].id] = true;
    render();
  }

  function collapseAll() {
    if (!root) return;
    expanded = Object.create(null);
    if (root.kids && root.kids.length) expanded[root.id] = true;   /* keep the top level readable */
    render();
  }

  function toggle(id) {
    if (expanded[id]) delete expanded[id]; else expanded[id] = true;
    selId = id;
    render();
    updateSel();
  }

  /* ───────────────────────── events ───────────────────────── */

  tree.addEventListener('click', function (ev) {
    var row = ev.target.closest ? ev.target.closest('.jt-row') : null;
    if (!row) return;
    var id = +row.getAttribute('data-id');
    if (!nodes[id]) return;
    var tw = ev.target.closest('.jt-tw');
    if (tw && !tw.classList.contains('jt-tw-e')) { toggle(id); return; }
    setSel(id);
  });

  tree.addEventListener('dblclick', function (ev) {
    var row = ev.target.closest ? ev.target.closest('.jt-row') : null;
    if (!row) return;
    var id = +row.getAttribute('data-id');
    var nd = nodes[id];
    if (!nd || !nd.kids || !nd.kids.length) return;
    toggle(id);
  });

  function visIndex(id) {
    for (var i = 0; i < visIds.length; i++) if (visIds[i] === id) return i;
    return -1;
  }

  /* the tree is dir="ltr" on purpose, so left always means "towards the parent" */
  tree.addEventListener('keydown', function (ev) {
    if (!root) return;
    var k = ev.key;
    if (k !== 'ArrowDown' && k !== 'ArrowUp' && k !== 'ArrowLeft' && k !== 'ArrowRight'
        && k !== 'Enter' && k !== ' ') return;
    ev.preventDefault();
    if (selId < 0 || !nodes[selId]) { setSel(root.id); scrollToId(selId, false); return; }

    var nd = nodes[selId], at = visIndex(selId);
    var box = !!(nd.kids && nd.kids.length);

    if (k === 'ArrowDown') {
      if (at >= 0 && at + 1 < visIds.length) { setSel(visIds[at + 1]); scrollToId(selId, false); }
    } else if (k === 'ArrowUp') {
      if (at > 0) { setSel(visIds[at - 1]); scrollToId(selId, false); }
    } else if (k === 'ArrowRight') {
      if (box && !expanded[selId]) { toggle(selId); scrollToId(selId, false); }
      else if (box) { setSel(nd.kids[0].id); scrollToId(selId, false); }
    } else if (k === 'ArrowLeft') {
      if (box && expanded[selId]) { toggle(selId); scrollToId(selId, false); }
      else if (nd.parent) { setSel(nd.parent.id); scrollToId(selId, false); }
    } else if (box) {
      toggle(selId); scrollToId(selId, false);
    }
  });

  $('jt-in').addEventListener('input', function () {
    clearTimeout(timer);
    timer = setTimeout(run, 220);
  });

  $('jt-q').addEventListener('input', function () {
    clearTimeout(timer);
    timer = setTimeout(function () { applySearch(true); }, 160);
  });

  $('jt-q').addEventListener('keydown', function (ev) {
    if (ev.key === 'Enter') { ev.preventDefault(); navHit(ev.shiftKey ? -1 : 1); }
  });

  $('jt-next').addEventListener('click', function () { navHit(1); });
  $('jt-prev').addEventListener('click', function () { navHit(-1); });
  $('jt-exp').addEventListener('click', expandAll);
  $('jt-col').addEventListener('click', collapseAll);

  $('jt-clear').addEventListener('click', function () {
    $('jt-in').value = ''; $('jt-q').value = ''; lq = '';
    reset();
    $('jt-in').focus();
  });

  $('jt-cp-path').addEventListener('click', function () {
    if (selId >= 0 && nodes[selId] && window.wtCopy) window.wtCopy(this, pathOf(nodes[selId]));
  });

  $('jt-cp-val').addEventListener('click', function () {
    if (selId >= 0 && nodes[selId] && window.wtCopy) window.wtCopy(this, toJson(nodes[selId], ''));
  });

  var SAMPLE = [
    '{',
    '  "service": "servernet-cloud",',
    '  "region": "eu-central",',
    '  "active": true,',
    '  "quota": null,',
    '  "nodes": [',
    '    { "id": 1, "host": "vm-101", "cpu": 8, "ram_gb": 16, "tags": ["nvme", "backup"] },',
    '    { "id": 2, "host": "vm-102", "cpu": 4, "ram_gb": 8, "tags": [] }',
    '  ],',
    '  "limits": { "bandwidth_tb": 12.5, "snapshots": 30 },',
    '  "big_id": 9007199254740993',
    '}'
  ].join(NL);

  $('jt-sample').addEventListener('click', function () {
    $('jt-in').value = SAMPLE;
    clearTimeout(timer);
    run();
  });

  reset();
})();
</script>

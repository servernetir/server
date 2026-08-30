<style>
.wt-sp-stage{position:relative;min-height:170px;display:flex;align-items:center;justify-content:center;
  border:1px solid var(--line-2);border-radius:12px;padding:12px;overflow:auto;max-height:440px;
  background-color:var(--surface-2);
  background-image:linear-gradient(45deg,rgba(128,128,128,.16) 25%,transparent 25%,transparent 75%,rgba(128,128,128,.16) 75%),
                   linear-gradient(45deg,rgba(128,128,128,.16) 25%,transparent 25%,transparent 75%,rgba(128,128,128,.16) 75%);
  background-size:20px 20px;background-position:0 0,10px 10px}
.wt-sp-stage canvas{max-width:100%;height:auto;box-shadow:0 4px 20px rgba(0,0,0,.28)}
.wt-sp-empty{color:var(--dim);font-size:13px}
.wt-sp-file{cursor:pointer}
.wt-sp-file input{display:none}
.wt-sp-num{background:var(--surface-2);border:1px solid var(--line-2);border-radius:9px;color:var(--text);
  padding:6px 10px;font-family:var(--font-body);font-size:13px;width:110px}
</style>

<div class="wt-io">
  <div class="wt-pane">
    <label>{{ __('ui.wt_sp_paste') }}</label>
    <textarea id="sp-in" class="wt-ta" rows="11" dir="ltr" spellcheck="false" autocomplete="off"
      placeholder="{{ __('ui.wt_sp_paste_ph') }}"></textarea>
    <div class="wt-bar" style="margin-top:12px">
      <label class="btn btn-glass wt-sp-file">
        <svg class="icon"><use href="#i-plus"/></svg>{{ __('ui.wt_sp_upload') }}
        <input type="file" id="sp-file" accept=".svg,image/svg+xml">
      </label>
      <button class="btn btn-glass" id="sp-clear">{{ __('ui.wt_sp_clear') }}</button>
    </div>
  </div>
  <div class="wt-pane">
    <label>{{ __('ui.wt_sp_preview') }}</label>
    <div class="wt-sp-stage">
      <canvas id="sp-cv" width="1" height="1"></canvas>
    </div>
    <div class="wt-out-box" id="sp-info"></div>
  </div>
</div>

<div class="wt-fields">
  <label class="wt-range">{{ __('ui.wt_sp_size') }}
    <select id="sp-mode" class="wt-select">
      <option value="1">1x</option>
      <option value="2" selected>2x</option>
      <option value="4">4x</option>
      <option value="custom">{{ __('ui.wt_sp_custom') }}</option>
    </select>
  </label>
  <label class="wt-range" id="sp-wwrap" style="display:none">{{ __('ui.wt_sp_width') }}
    <input type="number" id="sp-w" class="wt-sp-num" min="1" max="6000" step="1" value="1024" dir="ltr">
  </label>
  <label class="wt-chk"><input type="checkbox" id="sp-bg"> {{ __('ui.wt_sp_bg') }}</label>
</div>

<div class="wt-bar">
  <button class="btn btn-primary" id="sp-dl"><svg class="icon"><use href="#i-arrow"/></svg>{{ __('ui.wt_sp_download') }}</button>
  <span class="wt-status" id="sp-msg"></span>
</div>

<script>
(function () {
  const $ = id => document.getElementById(id);
  const MAXDIM = 6000;
  const L = {
    empty:@json(__('ui.wt_sp_e_empty')), parse:@json(__('ui.wt_sp_e_parse')),
    ext:@json(__('ui.wt_sp_e_ext')), taint:@json(__('ui.wt_sp_e_taint')),
    capped:@json(__('ui.wt_sp_capped')),
    srcSize:@json(__('ui.wt_sp_src_size')), outSize:@json(__('ui.wt_sp_out_size')),
    vb:@json(__('ui.wt_sp_src_vb')), attr:@json(__('ui.wt_sp_src_attr')), def:@json(__('ui.wt_sp_src_def'))
  };
  const SAMPLE =
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 50">\n' +
    '  <rect width="100" height="50" fill="#22d3ee"/>\n' +
    '  <circle cx="50" cy="25" r="20" fill="#8b5cf6"/>\n' +
    '</svg>';

  const state = { ready:false, name:'image', w:0, h:0 };

  // ---- helpers ----------------------------------------------------------
  function err(msg){ const m=$('sp-msg'); m.textContent=msg; m.className='wt-status err'; state.ready=false; }
  function ok(msg){ const m=$('sp-msg'); m.textContent=msg||''; m.className='wt-status'; }

  function clearCanvas(){
    const cv=$('sp-cv'); cv.width=1; cv.height=1;
    cv.getContext('2d').clearRect(0,0,1,1);
    $('sp-info').innerHTML='';
    state.ready=false;
  }

  // add missing namespaces so DOMParser + <img> render reliably
  function normalize(svg){
    return svg.replace(/<svg\b([^>]*)>/i, function(full, attrs){
      let a=attrs;
      if(!/\bxmlns\s*=/i.test(a)) a+=' xmlns="http://www.w3.org/2000/svg"';
      if(/xlink:/i.test(svg) && !/\bxmlns:xlink\s*=/i.test(a)) a+=' xmlns:xlink="http://www.w3.org/1999/xlink"';
      return '<svg'+a+'>';
    });
  }

  // any reference that is NOT an internal (#id) or inline (data:) resource taints the canvas
  function externalRefs(svg){
    const out=[]; let m;
    const attrRe=/(?:^|[\s;"'])(?:xlink:href|href|src)\s*=\s*(['"])([\s\S]*?)\1/gi;
    while((m=attrRe.exec(svg))){
      const v=m[2].trim();
      if(v && v[0]!=='#' && !/^data:/i.test(v)) out.push(v);
    }
    const urlRe=/url\(\s*(['"]?)\s*([^)'"]+?)\s*\1\s*\)/gi;
    while((m=urlRe.exec(svg))){
      const v=m[2].trim();
      if(v && v[0]!=='#' && !/^data:/i.test(v)) out.push(v);
    }
    if(/@import/i.test(svg)) out.push('@import');
    return out;
  }

  function lenToPx(v){
    if(v==null) return null;
    v=String(v).trim();
    const m=v.match(/^([\d.]+)\s*(px|pt|pc|mm|cm|in)?$/i);
    if(!m) return null;                       // %, em, ex, calc(...) etc -> fall back to viewBox
    const n=parseFloat(m[1]); if(!isFinite(n)||n<=0) return null;
    const f={'':1,px:1,pt:96/72,pc:16,in:96,cm:96/2.54,mm:96/25.4}[(m[2]||'').toLowerCase()];
    return n*f;
  }

  function intrinsic(root){
    const aw=lenToPx(root.getAttribute('width')), ah=lenToPx(root.getAttribute('height'));
    const vb=(root.getAttribute('viewBox')||'').trim().split(/[\s,]+/).map(parseFloat).filter(n=>!isNaN(n));
    if(aw&&ah) return {w:aw,h:ah,src:'attr'};
    if(vb.length===4 && vb[2]>0 && vb[3]>0) return {w:vb[2],h:vb[3],src:'vb'};
    if(aw&&!ah) return {w:aw,h:aw,src:'attr'};
    if(ah&&!aw) return {w:ah,h:ah,src:'attr'};
    return {w:300,h:150,src:'def'};
  }

  function info(iw,ih,src,ow,oh){
    const label=src==='vb'?L.vb:src==='attr'?L.attr:L.def;
    $('sp-info').innerHTML=
      '<div class="wt-out-row"><span>'+L.srcSize+'</span><b dir="ltr">'+Math.round(iw)+' &times; '+Math.round(ih)+
        '  <span style="color:var(--dim);font-size:12px">&middot; '+label+'</span></b></div>'+
      '<div class="wt-out-row"><span>'+L.outSize+'</span><b dir="ltr">'+ow+' &times; '+oh+' px</b></div>';
  }

  // ---- core render ------------------------------------------------------
  function render(then){
    const raw=$('sp-in').value;
    if(!raw.trim()){ err(L.empty); clearCanvas(); return; }

    const svg=normalize(raw);

    const refs=externalRefs(svg);
    if(refs.length){ err(L.ext+' ('+refs[0]+')'); clearCanvas(); return; }

    const doc=new DOMParser().parseFromString(svg,'image/svg+xml');
    const rootEl=doc.documentElement;
    if(doc.getElementsByTagName('parsererror').length || !rootEl || rootEl.localName.toLowerCase()!=='svg'){
      err(L.parse); clearCanvas(); return;
    }

    const it=intrinsic(rootEl);
    let ow, oh;
    const mode=$('sp-mode').value;
    if(mode==='custom'){
      ow=Math.max(1, Math.round(parseFloat($('sp-w').value)||0));
      if(!ow){ err(L.parse); return; }
      oh=Math.max(1, Math.round(ow*it.h/it.w));
    } else {
      const k=parseFloat(mode);
      ow=Math.max(1, Math.round(it.w*k));
      oh=Math.max(1, Math.round(it.h*k));
    }
    let capped=false;
    if(ow>MAXDIM || oh>MAXDIM){
      const s=MAXDIM/Math.max(ow,oh);
      ow=Math.max(1,Math.round(ow*s)); oh=Math.max(1,Math.round(oh*s)); capped=true;
    }

    const blob=new Blob([svg],{type:'image/svg+xml;charset=utf-8'});
    const url=URL.createObjectURL(blob);
    const img=new Image();
    img.onload=function(){
      try{
        const cv=$('sp-cv'); cv.width=ow; cv.height=oh;
        const ctx=cv.getContext('2d'); ctx.clearRect(0,0,ow,oh);
        if($('sp-bg').checked){ ctx.fillStyle='#ffffff'; ctx.fillRect(0,0,ow,oh); }
        ctx.drawImage(img,0,0,ow,oh);
      }catch(e){ URL.revokeObjectURL(url); err(L.taint); return; }
      URL.revokeObjectURL(url);
      state.ready=true; state.w=ow; state.h=oh;
      info(it.w,it.h,it.src,ow,oh);
      ok(capped?L.capped:'');
      if(then) then();
    };
    img.onerror=function(){ URL.revokeObjectURL(url); err(L.parse); clearCanvas(); };
    img.src=url;
  }

  function exportPng(){
    if(!state.ready){ return; }
    const cv=$('sp-cv');
    try{
      cv.toBlob(function(b){
        if(!b){ err(L.taint); return; }
        const u=URL.createObjectURL(b);
        const a=document.createElement('a');
        a.href=u; a.download=(state.name||'image')+'.png';
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(function(){ URL.revokeObjectURL(u); }, 1500);
      },'image/png');
    }catch(e){ err(L.taint); }
  }

  // ---- wiring -----------------------------------------------------------
  let t=null;
  function debounced(){ clearTimeout(t); t=setTimeout(function(){ render(); }, 220); }

  function syncMode(){ $('sp-wwrap').style.display = $('sp-mode').value==='custom' ? 'inline-flex' : 'none'; }

  $('sp-in').addEventListener('input', debounced);
  $('sp-mode').addEventListener('change', function(){ syncMode(); render(); });
  $('sp-w').addEventListener('input', debounced);
  $('sp-bg').addEventListener('change', function(){ render(); });

  $('sp-file').addEventListener('change', function(e){
    const f=e.target.files && e.target.files[0];
    if(!f) return;
    state.name=(f.name||'image').replace(/\.svg$/i,'') || 'image';
    const rd=new FileReader();
    rd.onload=function(){ $('sp-in').value=String(rd.result||''); render(); };
    rd.onerror=function(){ err(L.parse); };
    rd.readAsText(f);
    e.target.value='';
  });

  $('sp-clear').addEventListener('click', function(){
    $('sp-in').value=''; state.name='image'; ok(''); clearCanvas();
  });

  $('sp-dl').addEventListener('click', function(){
    if(state.ready) exportPng(); else render(exportPng);
  });

  // ---- init -------------------------------------------------------------
  syncMode();
  $('sp-in').value=SAMPLE;
  render();
})();
</script>

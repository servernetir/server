<style>
.icp-drop{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;
  min-height:158px;border:2px dashed var(--line-2);border-radius:16px;background:var(--surface-2);
  padding:26px 22px;text-align:center;cursor:pointer;transition:border-color .2s,background .2s}
.icp-drop:hover,.icp-drop.drag{border-color:var(--cyan);background:rgba(34,211,238,.06)}
.icp-drop .icp-ic{width:38px;height:38px;color:var(--cyan)}
.icp-drop b{font-size:15px;color:var(--text)}
.icp-drop small{font-size:12.5px;color:var(--muted);max-width:52ch;line-height:1.75}
.icp-actions{display:flex;gap:10px;flex-wrap:wrap;justify-content:center;margin-top:4px}
.icp-stage{position:relative;margin-top:16px;border-radius:14px;overflow:hidden;border:1px solid var(--line-2);
  background:
    linear-gradient(45deg,rgba(128,128,128,.11) 25%,transparent 25%,transparent 75%,rgba(128,128,128,.11) 75%),
    linear-gradient(45deg,rgba(128,128,128,.11) 25%,transparent 25%,transparent 75%,rgba(128,128,128,.11) 75%),
    var(--surface-2);
  background-size:22px 22px;background-position:0 0,11px 11px;display:none}
.icp-stage.on{display:block}
.icp-canvas{display:block;max-width:100%;height:auto;margin-inline:auto;cursor:crosshair;touch-action:none}
.icp-lens{position:absolute;inset-inline-start:0;inset-block-start:0;pointer-events:none;display:none;z-index:5;
  border-radius:12px;overflow:hidden;border:2px solid var(--surface);box-shadow:0 8px 26px rgba(0,0,0,.45)}
.icp-lens.on{display:block}
.icp-lens canvas{display:block;background:var(--surface-2)}
.icp-lens span{display:block;font-family:ui-monospace,monospace;font-size:11px;text-align:center;
  padding:3px 4px;background:var(--surface);color:var(--text);letter-spacing:.6px}
.icp-current{display:none;gap:16px;align-items:stretch;margin-top:18px;flex-wrap:wrap}
.icp-sw{flex:0 0 96px;width:96px;min-height:96px;border-radius:14px;border:1px solid var(--line-2)}
.icp-rows{flex:1;min-width:220px;display:flex;flex-direction:column;gap:1px}
.icp-hist-h{display:flex;align-items:center;gap:9px;margin:22px 0 11px}
.icp-hist-h .icp-hic{width:15px;height:15px;color:var(--dim)}
.icp-hist-h b{font-size:13px;color:var(--dim);font-weight:600}
.icp-hist{display:grid;grid-template-columns:repeat(auto-fill,minmax(66px,1fr));gap:8px}
.icp-chip{border:1px solid var(--line-2);border-radius:10px;overflow:hidden;background:var(--surface-2);
  cursor:pointer;padding:0;font-family:var(--font-body)}
.icp-chip i{display:block;height:40px}
.icp-chip span{display:block;font-family:ui-monospace,monospace;font-size:10.5px;padding:5px 2px;color:var(--muted)}
.icp-chip:hover{border-color:var(--cyan)}
.icp-chip span.ok{color:var(--green)}
.icp-empty{font-size:12.5px;color:var(--dim);padding:5px 2px}
</style>

<div class="wt-pane">
  <div class="icp-drop" id="icp-drop" role="button" tabindex="0" aria-label="{{ __('ui.wt_icp_choose') }}">
    <svg class="icp-ic"><use href="#i-monitor"/></svg>
    <b>{{ __('ui.wt_icp_drop_t') }}</b>
    <small>{{ __('ui.wt_icp_drop_d') }}</small>
    <div class="icp-actions">
      <button type="button" class="btn btn-primary" id="icp-choose">{{ __('ui.wt_icp_choose') }}</button>
      <button type="button" class="btn btn-glass" id="icp-sample">{{ __('ui.wt_icp_sample') }}</button>
    </div>
    <input type="file" id="icp-file" accept="image/*" hidden>
  </div>
</div>

<label class="wt-chk" style="margin-top:14px">
  <input type="checkbox" id="icp-mag" checked> {{ __('ui.wt_icp_magnifier') }}
</label>

<div class="icp-stage" id="icp-stage">
  <canvas class="icp-canvas" id="icp-cv" width="400" height="140"></canvas>
  <div class="icp-lens" id="icp-lens">
    <canvas id="icp-lcv" width="128" height="128"></canvas>
    <span id="icp-lhex" dir="ltr">#000000</span>
  </div>
</div>

<div class="wt-status" id="icp-status" style="margin-top:12px">{{ __('ui.wt_icp_await') }}</div>

<div class="icp-current" id="icp-current">
  <div class="icp-sw" id="icp-cur-sw"></div>
  <div class="icp-rows" id="icp-cur-rows"></div>
</div>

<div class="icp-hist-h">
  <svg class="icp-hic"><use href="#i-restore"/></svg>
  <b>{{ __('ui.wt_icp_history') }}</b>
  <button type="button" class="wt-mini" id="icp-clear" style="margin-inline-start:auto">{{ __('ui.wt_icp_clear') }}</button>
</div>
<div class="icp-hist" id="icp-hist"></div>
<div class="icp-empty" id="icp-empty">{{ __('ui.wt_icp_none') }}</div>

<script>
(function () {
  const $ = id => document.getElementById(id);
  const COPY=@json(__('ui.wt_copy')), DONE=@json(__('ui.wt_copied'));
  const T={ready:@json(__('ui.wt_icp_ready')),await:@json(__('ui.wt_icp_await')),
           picked:@json(__('ui.wt_icp_picked')),err:@json(__('ui.wt_icp_err'))};

  const drop=$('icp-drop'), file=$('icp-file'), stage=$('icp-stage'),
        cv=$('icp-cv'), status=$('icp-status'),
        curBox=$('icp-current'), curSw=$('icp-cur-sw'), curRows=$('icp-cur-rows'),
        histEl=$('icp-hist'), emptyEl=$('icp-empty'), clearBtn=$('icp-clear'),
        magChk=$('icp-mag'), lens=$('icp-lens'), lcv=$('icp-lcv'), lhex=$('icp-lhex');
  const ctx=cv.getContext('2d',{willReadFrequently:true});
  const lctx=lcv.getContext('2d');

  const MAX=4096, LENS=128, ZOOM=8, REGION=LENS/ZOOM; // 16 source px
  let ready=false, hist=[];

  const clamp=(n,a,b)=>Math.min(b,Math.max(a,n));
  const hx=n=>clamp(Math.round(n),0,255).toString(16).padStart(2,'0');
  const toHex=(r,g,b)=>'#'+hx(r)+hx(g)+hx(b);

  function rgb2hsl(r,g,b){
    r/=255;g/=255;b/=255;
    const mx=Math.max(r,g,b),mn=Math.min(r,g,b),l=(mx+mn)/2;
    if(mx===mn)return [0,0,l*100];
    const d=mx-mn, s=l>0.5?d/(2-mx-mn):d/(mx+mn);
    let h;
    if(mx===r)h=((g-b)/d+(g<b?6:0));
    else if(mx===g)h=((b-r)/d+2);
    else h=((r-g)/d+4);
    return [h*60,s*100,l*100];
  }

  /* --- map a pointer/mouse event to integer canvas pixel coords --- */
  function toPx(e){
    const r=cv.getBoundingClientRect();
    const x=(e.clientX-r.left)*(cv.width/r.width);
    const y=(e.clientY-r.top)*(cv.height/r.height);
    return [clamp(Math.floor(x),0,cv.width-1), clamp(Math.floor(y),0,cv.height-1)];
  }
  function pixelAt(px,py){ const d=ctx.getImageData(px,py,1,1).data; return [d[0],d[1],d[2],d[3]]; }

  /* --- load / draw --- */
  function drawImage(img){
    let w=img.naturalWidth||img.width, h=img.naturalHeight||img.height;
    if(!w||!h){ fail(); return; }
    if(w>MAX||h>MAX){ const s=Math.min(MAX/w,MAX/h); w=Math.round(w*s); h=Math.round(h*s); }
    cv.width=w; cv.height=h;
    ctx.clearRect(0,0,w,h);
    ctx.drawImage(img,0,0,w,h);
    onReady();
  }
  function loadFile(f){
    if(!f || !/^image\//.test(f.type)){ fail(); return; }
    const url=URL.createObjectURL(f), img=new Image();
    img.onload=()=>{ drawImage(img); URL.revokeObjectURL(url); };
    img.onerror=()=>{ fail(); URL.revokeObjectURL(url); };
    img.src=url;
  }
  function loadSample(){
    cv.width=400; cv.height=140;
    const bands=[['#ff0000',0],['#00ff00',100],['#0000ff',200],['#808080',300]];
    bands.forEach(b=>{ ctx.fillStyle=b[0]; ctx.fillRect(b[1],0,100,140); });
    onReady();
  }
  function onReady(){
    ready=true; stage.classList.add('on');
    status.className='wt-status'; status.textContent=T.ready;
  }
  function fail(){
    status.className='wt-status err'; status.textContent=T.err;
  }

  /* --- pick + current readout --- */
  function pick(px,py){
    const d=pixelAt(px,py); const rgb=[d[0],d[1],d[2]];
    renderCurrent(rgb);
    status.className='wt-status ok';
    status.textContent=T.picked+' '+toHex(rgb[0],rgb[1],rgb[2])+'  ·  '+px+', '+py;
    addHistory(toHex(rgb[0],rgb[1],rgb[2]));
  }
  function renderCurrent(rgb){
    const [r,g,b]=rgb, hex=toHex(r,g,b), [h,s,l]=rgb2hsl(r,g,b);
    curBox.style.display='flex';
    curSw.style.background=hex;
    const rows=[['HEX',hex],
                ['RGB','rgb('+r+', '+g+', '+b+')'],
                ['HSL','hsl('+Math.round(h)+', '+Math.round(s)+'%, '+Math.round(l)+'%)']];
    curRows.innerHTML=rows.map(v=>
      '<div class="wt-out-row"><span>'+v[0]+'</span><b dir="ltr">'+v[1]+'</b>'+
      '<button class="wt-mini" type="button" data-v="'+v[1]+'" data-done="'+DONE+'">'+COPY+'</button></div>').join('');
    curRows.querySelectorAll('.wt-mini').forEach(btn=>btn.onclick=()=>wtCopy(btn,btn.dataset.v));
  }

  /* --- history --- */
  function addHistory(hex){
    hist=hist.filter(h=>h!==hex); hist.unshift(hex);
    if(hist.length>32) hist.length=32;
    renderHistory();
  }
  function renderHistory(){
    emptyEl.style.display=hist.length?'none':'block';
    histEl.innerHTML=hist.map(hex=>
      '<button type="button" class="icp-chip" data-hex="'+hex+'">'+
      '<i style="background:'+hex+'"></i>'+
      '<span dir="ltr" data-done="'+DONE+'">'+hex+'</span></button>').join('');
    histEl.querySelectorAll('.icp-chip').forEach(chip=>{
      const span=chip.querySelector('span');
      chip.onclick=()=>wtCopy(span,chip.dataset.hex);
    });
  }
  clearBtn.onclick=()=>{ hist=[]; renderHistory(); };

  /* --- magnifier lens --- */
  function drawLens(px,py){
    lctx.imageSmoothingEnabled=false;
    lctx.clearRect(0,0,LENS,LENS);
    lctx.fillStyle=getComputedStyle(document.body).getPropertyValue('--surface-2')||'#0e1116';
    lctx.fillRect(0,0,LENS,LENS);
    const half=Math.floor(REGION/2);
    lctx.drawImage(cv, px-half, py-half, REGION, REGION, 0, 0, LENS, LENS);
    const c=half*ZOOM;
    lctx.lineWidth=2; lctx.strokeStyle='rgba(0,0,0,.9)'; lctx.strokeRect(c-1,c-1,ZOOM+2,ZOOM+2);
    lctx.lineWidth=1; lctx.strokeStyle='rgba(255,255,255,.95)'; lctx.strokeRect(c,c,ZOOM,ZOOM);
  }
  function moveLens(e,px,py){
    const sr=stage.getBoundingClientRect();
    let lx=e.clientX-sr.left+16, ly=e.clientY-sr.top+16;
    const lw=lens.offsetWidth||LENS, lh=lens.offsetHeight||LENS+22;
    lx=Math.min(lx, stage.clientWidth-lw-4); if(lx<4) lx=4;
    ly=Math.min(ly, stage.clientHeight-lh-4); if(ly<4) ly=4;
    lens.style.insetInlineStart=lx+'px'; lens.style.insetBlockStart=ly+'px';
    const d=pixelAt(px,py); lhex.textContent=toHex(d[0],d[1],d[2]);
  }
  function hideLens(){ lens.classList.remove('on'); }

  cv.addEventListener('pointermove',e=>{
    if(!ready) return;
    const [px,py]=toPx(e);
    if(magChk.checked){ lens.classList.add('on'); drawLens(px,py); moveLens(e,px,py); }
    else hideLens();
  });
  cv.addEventListener('pointerleave',hideLens);
  magChk.addEventListener('change',()=>{ if(!magChk.checked) hideLens(); });

  cv.addEventListener('click',e=>{ if(!ready) return; const [px,py]=toPx(e); pick(px,py); });

  /* --- file input wiring + drag/drop --- */
  drop.addEventListener('click',e=>{ if(e.target.closest('button')) return; file.click(); });
  drop.addEventListener('keydown',e=>{ if(e.key==='Enter'||e.key===' '){ e.preventDefault(); file.click(); } });
  $('icp-choose').addEventListener('click',()=>file.click());
  $('icp-sample').addEventListener('click',loadSample);
  file.addEventListener('change',()=>{ if(file.files&&file.files[0]) loadFile(file.files[0]); file.value=''; });

  ['dragenter','dragover'].forEach(ev=>drop.addEventListener(ev,e=>{ e.preventDefault(); drop.classList.add('drag'); }));
  ['dragleave','dragend'].forEach(ev=>drop.addEventListener(ev,e=>{ e.preventDefault(); drop.classList.remove('drag'); }));
  drop.addEventListener('drop',e=>{
    e.preventDefault(); drop.classList.remove('drag');
    const f=e.dataTransfer&&e.dataTransfer.files&&e.dataTransfer.files[0];
    if(f) loadFile(f);
  });

  renderHistory();
})();
</script>

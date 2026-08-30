<style>
.imc-drop{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:11px;min-height:170px;border:2px dashed var(--line-2);border-radius:16px;background:var(--surface-2);cursor:pointer;text-align:center;padding:24px;transition:border-color .2s,background .2s}
.imc-drop:hover,.imc-drop.imc-over{border-color:var(--cyan);background:rgba(34,211,238,.06)}
.imc-drop .icon.imc-dropic{width:44px;height:44px;color:var(--cyan)}
.imc-drop-t{font-size:15px;font-weight:600;color:var(--text)}
.imc-drop-h{font-size:12.5px;color:var(--dim);max-width:380px;line-height:1.7}
.imc-controls{display:flex;flex-wrap:wrap;gap:16px 26px;align-items:center;margin-top:18px;padding-top:16px;border-top:1px solid var(--line)}
.imc-q{min-width:210px}
.imc-num{width:88px;background:var(--surface-2);border:1px solid var(--line-2);border-radius:9px;color:var(--text);padding:6px 9px;font-size:13px;font-family:ui-monospace,monospace;text-align:center;outline:none}
.imc-num:disabled{opacity:.4}
.imc-saved{margin-top:18px;padding:18px 22px;border-radius:16px;border:1px solid rgba(52,211,153,.4);background:linear-gradient(100deg,rgba(52,211,153,.16),rgba(34,211,238,.10));display:flex;align-items:center;gap:22px;flex-wrap:wrap}
.imc-saved.imc-worse{border-color:rgba(251,191,36,.45);background:linear-gradient(100deg,rgba(251,191,36,.16),rgba(255,107,107,.10))}
.imc-pct{font-size:44px;font-weight:800;font-family:var(--font-disp);line-height:.95;letter-spacing:-1px;background:linear-gradient(100deg,#34D399,#22D3EE);-webkit-background-clip:text;background-clip:text;color:transparent;flex:none}
.imc-saved.imc-worse .imc-pct{background:linear-gradient(100deg,#FBBF24,#FB7185);-webkit-background-clip:text;background-clip:text}
.imc-savemeta{display:flex;flex-direction:column;gap:5px;min-width:0}
.imc-sizes{font-size:17px;font-weight:700;color:var(--text)}
.imc-sizes b{font-family:ui-monospace,monospace}
.imc-arrow{color:var(--dim);margin-inline:10px}
.imc-sub{font-size:12.5px;color:var(--dim)}
.imc-io{margin-top:16px}
.imc-prev{background:var(--surface-2);border:1px solid var(--line);border-radius:14px;padding:12px;display:flex;flex-direction:column;gap:10px}
.imc-prev-h{display:flex;align-items:center;justify-content:space-between;gap:10px}
.imc-prev-h span:first-child{font-size:12.5px;font-weight:600;color:var(--dim)}
.imc-badge{font-size:11px;font-weight:700;padding:3px 9px;border-radius:999px;background:var(--surface);border:1px solid var(--line-2);color:var(--muted);font-family:ui-monospace,monospace}
.imc-imgwrap{border-radius:10px;overflow:hidden;display:grid;place-items:center;min-height:130px;background-color:var(--surface);background-image:linear-gradient(45deg,rgba(128,128,128,.14) 25%,transparent 25%,transparent 75%,rgba(128,128,128,.14) 75%),linear-gradient(45deg,rgba(128,128,128,.14) 25%,transparent 25%,transparent 75%,rgba(128,128,128,.14) 75%);background-size:18px 18px;background-position:0 0,9px 9px}
.imc-imgwrap img{max-width:100%;max-height:320px;display:block}
.imc-meta{font-size:12px;color:var(--muted);font-family:ui-monospace,monospace;text-align:center;word-break:break-all}
.imc-dl{text-decoration:none}
.imc-dl .icon{width:16px;height:16px}
</style>

<div class="imc-wrap">
  <div class="wt-pane">
    <label>{{ __('ui.wt_imc_source') }}</label>
    <div class="imc-drop" id="imc-drop" role="button" tabindex="0" aria-label="{{ __('ui.wt_imc_drop') }}">
      <svg class="icon imc-dropic"><use href="#i-cloud"/></svg>
      <div class="imc-drop-t">{{ __('ui.wt_imc_drop') }}</div>
      <div class="imc-drop-h">{{ __('ui.wt_imc_hint') }}</div>
    </div>
    <input type="file" id="imc-file" accept="image/*" hidden>
  </div>

  <div class="imc-controls" id="imc-controls" hidden>
    <label class="wt-range">{{ __('ui.wt_imc_format') }}
      <select id="imc-fmt" class="wt-select">
        <option value="image/jpeg">JPEG</option>
        <option value="image/webp">WebP</option>
      </select>
    </label>
    <label class="wt-range imc-q">{{ __('ui.wt_imc_quality') }}: <b id="imc-qv">75</b>%
      <input type="range" id="imc-q" min="5" max="100" value="75">
    </label>
    <label class="wt-chk"><input type="checkbox" id="imc-rs"> {{ __('ui.wt_imc_resize') }}</label>
    <label class="wt-range"><input type="number" id="imc-max" class="imc-num" value="1920" min="16" max="20000" step="1" dir="ltr" disabled> {{ __('ui.wt_imc_px') }}</label>
  </div>

  <div class="imc-saved" id="imc-saved" hidden>
    <div class="imc-pct" id="imc-pct" dir="ltr">—</div>
    <div class="imc-savemeta">
      <div class="imc-sizes"><b id="imc-o" dir="ltr"></b><span class="imc-arrow" dir="ltr">→</span><b id="imc-c" dir="ltr"></b></div>
      <div class="imc-sub" id="imc-sub"></div>
    </div>
  </div>

  <div class="wt-io imc-io" id="imc-io" hidden>
    <div class="imc-prev">
      <div class="imc-prev-h"><span>{{ __('ui.wt_imc_original') }}</span><span class="imc-badge" id="imc-ob" dir="ltr"></span></div>
      <div class="imc-imgwrap"><img id="imc-oimg" alt=""></div>
      <div class="imc-meta" id="imc-ometa" dir="ltr"></div>
    </div>
    <div class="imc-prev">
      <div class="imc-prev-h"><span>{{ __('ui.wt_imc_compressed') }}</span><span class="imc-badge" id="imc-cb" dir="ltr"></span></div>
      <div class="imc-imgwrap"><img id="imc-cimg" alt=""></div>
      <div class="imc-meta" id="imc-cmeta" dir="ltr"></div>
    </div>
  </div>

  <div class="wt-bar" id="imc-bar" hidden>
    <a class="btn btn-primary imc-dl" id="imc-dl" download>
      <svg class="icon"><use href="#i-arrow"/></svg><span>{{ __('ui.wt_imc_download') }}</span>
    </a>
    <button class="btn btn-glass" id="imc-reset" type="button">{{ __('ui.wt_imc_reset') }}</button>
    <span class="wt-status" id="imc-status"></span>
  </div>
</div>

<script>
(function(){
  const $=id=>document.getElementById(id);
  const T={
    smaller:@json(__('ui.wt_imc_smaller')),
    savedamt:@json(__('ui.wt_imc_savedamt')),
    errType:@json(__('ui.wt_imc_err_type')),
    errRead:@json(__('ui.wt_imc_err_read')),
    webpOff:@json(__('ui.wt_imc_webp_off')),
    worse:@json(__('ui.wt_imc_worse'))
  };
  const drop=$('imc-drop'), file=$('imc-file'), canvas=document.createElement('canvas');
  let srcImg=null, origW=0, origH=0, origSize=0, baseName='image', origURL=null, compURL=null, gen=0, tmr=null;

  // Can this browser export WebP from a canvas?
  let webpOK=false;
  try{ webpOK=canvas.toDataURL('image/webp').indexOf('data:image/webp')===0; }catch(e){}
  if(!webpOK){ const opt=$('imc-fmt').querySelector('option[value="image/webp"]'); if(opt){ opt.disabled=true; opt.textContent='WebP —'; } }

  function fmtBytes(n){
    if(n<1024) return n+' B';
    if(n<1048576) return (n/1024).toFixed(n<10240?1:0)+' KB';
    return (n/1048576).toFixed(2)+' MB';
  }
  function setStatus(msg,err){ const s=$('imc-status'); s.textContent=msg||''; s.className='wt-status'+(err?' err':''); }

  function openFile(f){
    if(!f) return;
    if(!/^image\//.test(f.type)){ setStatus(T.errType,true); return; }
    if(origURL) URL.revokeObjectURL(origURL);
    origURL=URL.createObjectURL(f);
    origSize=f.size;
    baseName=(f.name||'image').replace(/\.[^.]+$/,'') || 'image';
    const img=new Image();
    img.onload=function(){
      srcImg=img; origW=img.naturalWidth; origH=img.naturalHeight;
      ['imc-controls','imc-saved','imc-io','imc-bar'].forEach(id=>$(id).hidden=false);
      $('imc-oimg').src=origURL;
      $('imc-ob').textContent=origW+'×'+origH;
      $('imc-ometa').textContent=origW+'×'+origH+' · '+fmtBytes(origSize);
      setStatus('');
      compress();
    };
    img.onerror=function(){ setStatus(T.errRead,true); };
    img.src=origURL;
  }

  function compress(){
    if(!srcImg) return;
    const my=++gen;
    let type=$('imc-fmt').value;
    if(type==='image/webp' && !webpOK){ type='image/jpeg'; $('imc-fmt').value='image/jpeg'; setStatus(T.webpOff,true); }
    const q=(+$('imc-q').value)/100;
    let tw=origW, th=origH;
    if($('imc-rs').checked){
      const maxSide=Math.max(16,Math.min(20000,parseInt($('imc-max').value,10)||origW));
      const longest=Math.max(origW,origH);
      if(longest>maxSide){ const s=maxSide/longest; tw=Math.max(1,Math.round(origW*s)); th=Math.max(1,Math.round(origH*s)); }
    }
    const CEIL=8192; // guard against freezing the tab on very large images
    if(Math.max(tw,th)>CEIL){ const s=CEIL/Math.max(tw,th); tw=Math.max(1,Math.round(tw*s)); th=Math.max(1,Math.round(th*s)); }
    canvas.width=tw; canvas.height=th;
    const ctx=canvas.getContext('2d');
    ctx.clearRect(0,0,tw,th);
    if(type==='image/jpeg'){ ctx.fillStyle='#ffffff'; ctx.fillRect(0,0,tw,th); } // JPEG has no alpha
    ctx.imageSmoothingQuality='high';
    ctx.drawImage(srcImg,0,0,tw,th);
    canvas.toBlob(function(blob){
      if(my!==gen) return; // a newer run superseded this one
      if(!blob){ setStatus(T.errRead,true); return; }
      if(compURL) URL.revokeObjectURL(compURL);
      compURL=URL.createObjectURL(blob);
      const ext=blob.type==='image/webp'?'webp':(blob.type==='image/png'?'png':'jpg');
      $('imc-cimg').src=compURL;
      const dl=$('imc-dl'); dl.href=compURL; dl.download=baseName+'-min.'+ext;
      $('imc-cb').textContent=tw+'×'+th;
      $('imc-cmeta').textContent=tw+'×'+th+' · '+fmtBytes(blob.size)+' · '+ext.toUpperCase();
      render(origSize, blob.size);
      setStatus('');
    }, type, q);
  }

  function render(o,c){
    const banner=$('imc-saved'), pct=$('imc-pct');
    $('imc-o').textContent=fmtBytes(o);
    $('imc-c').textContent=fmtBytes(c);
    const diff=o-c;
    const p=o>0?Math.round(Math.abs(diff)/o*100):0;
    if(diff>=0){
      banner.classList.remove('imc-worse');
      pct.textContent='−'+p+'%';
      $('imc-sub').textContent=T.savedamt+' '+fmtBytes(diff)+' · '+p+'% '+T.smaller;
    }else{
      banner.classList.add('imc-worse');
      pct.textContent='+'+p+'%';
      $('imc-sub').textContent=T.worse;
    }
  }

  function compressLater(){ clearTimeout(tmr); tmr=setTimeout(compress,120); }

  drop.addEventListener('click',()=>file.click());
  drop.addEventListener('keydown',function(e){ if(e.key==='Enter'||e.key===' '){ e.preventDefault(); file.click(); } });
  file.addEventListener('change',function(e){ if(e.target.files&&e.target.files[0]) openFile(e.target.files[0]); });
  ['dragenter','dragover'].forEach(ev=>drop.addEventListener(ev,function(e){ e.preventDefault(); drop.classList.add('imc-over'); }));
  ['dragleave','drop'].forEach(ev=>drop.addEventListener(ev,function(e){ e.preventDefault(); drop.classList.remove('imc-over'); }));
  drop.addEventListener('drop',function(e){ const f=e.dataTransfer&&e.dataTransfer.files&&e.dataTransfer.files[0]; if(f) openFile(f); });

  $('imc-q').addEventListener('input',function(){ $('imc-qv').textContent=$('imc-q').value; compressLater(); });
  $('imc-fmt').addEventListener('change',compress);
  $('imc-rs').addEventListener('change',function(){ $('imc-max').disabled=!$('imc-rs').checked; compress(); });
  $('imc-max').addEventListener('input',function(){ if($('imc-rs').checked) compressLater(); });

  $('imc-reset').addEventListener('click',function(){
    srcImg=null; gen++;
    if(origURL){ URL.revokeObjectURL(origURL); origURL=null; }
    if(compURL){ URL.revokeObjectURL(compURL); compURL=null; }
    file.value='';
    ['imc-controls','imc-saved','imc-io','imc-bar'].forEach(id=>$(id).hidden=true);
    setStatus('');
  });
})();
</script>

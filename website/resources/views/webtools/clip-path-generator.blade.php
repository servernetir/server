<style>
.cpg-main{display:flex;gap:22px;flex-wrap:wrap;align-items:flex-start}
.cpg-stage-wrap{flex:0 0 auto;display:flex;flex-direction:column;gap:10px;align-items:center}
.cpg-stage{position:relative;width:min(78vw,420px);aspect-ratio:1;direction:ltr;border:1px solid var(--line);border-radius:var(--r);overflow:hidden;touch-action:none;user-select:none;-webkit-user-select:none;
  background-image:linear-gradient(45deg,var(--line) 25%,transparent 25%),linear-gradient(-45deg,var(--line) 25%,transparent 25%),linear-gradient(45deg,transparent 75%,var(--line) 75%),linear-gradient(-45deg,transparent 75%,var(--line) 75%);
  background-size:22px 22px;background-position:0 0,0 11px,11px -11px,-11px 0}
.cpg-shape{position:absolute;inset:0;background:linear-gradient(135deg,var(--cyan),var(--violet));background-size:cover;background-position:center;background-repeat:no-repeat;pointer-events:none}
.cpg-svg{position:absolute;inset:0;width:100%;height:100%;pointer-events:none;overflow:visible}
.cpg-svg polygon,.cpg-svg circle,.cpg-svg ellipse,.cpg-svg rect{fill:none;stroke:#fff;stroke-width:1.5;vector-effect:non-scaling-stroke;stroke-dasharray:5 4;mix-blend-mode:difference}
.cpg-handles{position:absolute;inset:0;pointer-events:none}
.cpg-h{position:absolute;width:15px;height:15px;margin-top:-7.5px;margin-left:-7.5px;border-radius:50%;background:#fff;border:2px solid var(--cyan);box-shadow:0 1px 5px rgba(0,0,0,.45);cursor:grab;touch-action:none;pointer-events:auto;z-index:3;box-sizing:border-box}
.cpg-h:active{cursor:grabbing}
.cpg-h.sel{background:var(--cyan);border-color:#fff;width:19px;height:19px;margin-top:-9.5px;margin-left:-9.5px}
.cpg-h.ctr{border-color:var(--violet)}
.cpg-h.rad{border-color:var(--green)}
.cpg-controls{flex:1 1 250px;min-width:230px;display:flex;flex-direction:column;gap:12px}
.cpg-seg{display:flex;gap:6px;flex-wrap:wrap}
.cpg-seg button{flex:1 1 auto;padding:8px 10px;border:1px solid var(--line);background:var(--surface-2);color:var(--text);border-radius:var(--r);cursor:pointer;font:inherit;font-size:.85rem;transition:border-color .15s,background .15s}
.cpg-seg button.on{border-color:var(--cyan);background:color-mix(in srgb,var(--cyan) 16%,transparent)}
.cpg-row{display:flex;flex-direction:column;gap:6px}
.cpg-row>span{font-size:.8rem;color:var(--muted)}
.cpg-note{font-size:.78rem;color:var(--muted);line-height:1.7}
.cpg-grp{display:none;flex-direction:column;gap:10px}
.cpg-grp.on{display:flex}
.cpg-opts{display:flex;flex-wrap:wrap;gap:12px;align-items:center;padding-top:6px;border-top:1px solid var(--line)}
</style>

<div class="cpg">
  <div class="cpg-main">
    <div class="cpg-stage-wrap">
      <div class="cpg-stage" id="cp-stage">
        <div class="cpg-shape" id="cp-shape"></div>
        <svg class="cpg-svg" id="cp-svg" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true"></svg>
        <div class="cpg-handles" id="cp-handles"></div>
      </div>
      <input type="file" id="cp-file" accept="image/*" hidden>
    </div>

    <div class="cpg-controls">
      <div class="cpg-seg" role="tablist" aria-label="{{ __('ui.wt_cp_mode') }}">
        <button type="button" id="cp-m-poly" class="on">{{ __('ui.wt_cp_poly') }}</button>
        <button type="button" id="cp-m-circle">{{ __('ui.wt_cp_circle') }}</button>
        <button type="button" id="cp-m-ellipse">{{ __('ui.wt_cp_ellipse') }}</button>
        <button type="button" id="cp-m-inset">{{ __('ui.wt_cp_inset') }}</button>
      </div>

      <label class="cpg-row"><span>{{ __('ui.wt_cp_preset') }}</span>
        <select id="cp-preset" class="wt-select">
          <option value="" selected>—</option>
          <option value="triangle">{{ __('ui.wt_cp_triangle') }}</option>
          <option value="trapezoid">{{ __('ui.wt_cp_trapezoid') }}</option>
          <option value="rhombus">{{ __('ui.wt_cp_rhombus') }}</option>
          <option value="pentagon">{{ __('ui.wt_cp_pentagon') }}</option>
          <option value="hexagon">{{ __('ui.wt_cp_hexagon') }}</option>
          <option value="star">{{ __('ui.wt_cp_star') }}</option>
          <option value="bubble">{{ __('ui.wt_cp_bubble') }}</option>
          <option value="arrow">{{ __('ui.wt_cp_arrow') }}</option>
        </select>
      </label>

      <div class="cpg-grp on" id="cp-g-poly">
        <div class="wt-bar" style="margin:0">
          <button type="button" class="btn btn-glass" id="cp-add">{{ __('ui.wt_cp_addpt') }}</button>
          <button type="button" class="btn btn-glass" id="cp-rm">{{ __('ui.wt_cp_rmpt') }}</button>
        </div>
        <p class="cpg-note">{{ __('ui.wt_cp_hint') }}</p>
      </div>

      <div class="cpg-grp" id="cp-g-circle">
        <label class="wt-range">{{ __('ui.wt_cp_radius') }}: <b id="cp-cr-n">50</b>%<input type="range" id="cp-cr" min="0" max="100" value="50"></label>
        <label class="wt-range">{{ __('ui.wt_cp_cx') }}: <b id="cp-ccx-n">50</b>%<input type="range" id="cp-ccx" min="0" max="100" value="50"></label>
        <label class="wt-range">{{ __('ui.wt_cp_cy') }}: <b id="cp-ccy-n">50</b>%<input type="range" id="cp-ccy" min="0" max="100" value="50"></label>
      </div>

      <div class="cpg-grp" id="cp-g-ellipse">
        <label class="wt-range">{{ __('ui.wt_cp_rx') }}: <b id="cp-erx-n">50</b>%<input type="range" id="cp-erx" min="0" max="100" value="50"></label>
        <label class="wt-range">{{ __('ui.wt_cp_ry') }}: <b id="cp-ery-n">35</b>%<input type="range" id="cp-ery" min="0" max="100" value="35"></label>
        <label class="wt-range">{{ __('ui.wt_cp_cx') }}: <b id="cp-ecx-n">50</b>%<input type="range" id="cp-ecx" min="0" max="100" value="50"></label>
        <label class="wt-range">{{ __('ui.wt_cp_cy') }}: <b id="cp-ecy-n">50</b>%<input type="range" id="cp-ecy" min="0" max="100" value="50"></label>
      </div>

      <div class="cpg-grp" id="cp-g-inset">
        <label class="wt-range">{{ __('ui.wt_cp_top') }}: <b id="cp-it-n">10</b>%<input type="range" id="cp-it" min="0" max="100" value="10"></label>
        <label class="wt-range">{{ __('ui.wt_cp_right') }}: <b id="cp-ir-n">10</b>%<input type="range" id="cp-ir" min="0" max="100" value="10"></label>
        <label class="wt-range">{{ __('ui.wt_cp_bottom') }}: <b id="cp-ib-n">10</b>%<input type="range" id="cp-ib" min="0" max="100" value="10"></label>
        <label class="wt-range">{{ __('ui.wt_cp_left') }}: <b id="cp-il-n">10</b>%<input type="range" id="cp-il" min="0" max="100" value="10"></label>
        <label class="wt-range">{{ __('ui.wt_cp_round') }}: <b id="cp-iround-n">0</b>%<input type="range" id="cp-iround" min="0" max="50" value="0"></label>
      </div>

      <div class="cpg-opts">
        <label class="wt-chk"><input type="checkbox" id="cp-outline" checked> {{ __('ui.wt_cp_outline') }}</label>
        <button type="button" class="btn btn-glass" id="cp-bg-grad">{{ __('ui.wt_cp_gradient') }}</button>
        <button type="button" class="btn btn-glass" id="cp-bg-img">{{ __('ui.wt_cp_image') }}</button>
        <button type="button" class="btn btn-glass" id="cp-reset">{{ __('ui.wt_cp_reset') }}</button>
      </div>
    </div>
  </div>

  <div class="wt-pane" style="margin-top:16px">
    <label>{{ __('ui.wt_cp_output') }}</label>
    <textarea id="cp-out" class="wt-ta" rows="2" readonly dir="ltr"></textarea>
  </div>
  <div class="wt-bar">
    <button type="button" class="btn btn-glass" id="cp-copy" data-done="{{ __('ui.wt_copied') }}">{{ __('ui.wt_copy') }}</button>
  </div>
</div>

<script>
(function () {
  const $ = id => document.getElementById(id);
  const stage=$('cp-stage'), shape=$('cp-shape'), svg=$('cp-svg'), hLayer=$('cp-handles'), out=$('cp-out');

  const clamp=(n,a,b)=>Math.min(b,Math.max(a,n));
  const cl=n=>clamp(n,0,100);
  const r1=n=>Math.round(n*10)/10;
  const fmt=n=>{n=Math.round(n*10)/10;return Number.isInteger(n)?String(n):n.toFixed(1);};

  const PRESETS={
    triangle:[[50,0],[0,100],[100,100]],
    trapezoid:[[20,0],[80,0],[100,100],[0,100]],
    rhombus:[[50,0],[100,50],[50,100],[0,50]],
    pentagon:[[50,0],[100,38],[82,100],[18,100],[0,38]],
    hexagon:[[25,0],[75,0],[100,50],[75,100],[25,100],[0,50]],
    star:[[50,0],[61,35],[98,35],[68,57],[79,91],[50,70],[21,91],[32,57],[2,35],[39,35]],
    bubble:[[0,0],[100,0],[100,75],[75,75],[75,100],[50,75],[0,75]],
    arrow:[[0,20],[60,20],[60,0],[100,50],[60,100],[60,80],[0,80]]
  };

  let mode='polygon';
  let poly=PRESETS.triangle.map(p=>p.slice());
  let circle={cx:50,cy:50,r:50,ra:0};
  let ellipse={cx:50,cy:50,rx:50,ry:35};
  let inset={t:10,r:10,b:10,l:10,round:0};
  let sel=-1;
  let showOutline=true;
  let drag=null;

  function buildValue(){
    if(mode==='polygon')
      return 'polygon('+poly.map(p=>fmt(p[0])+'% '+fmt(p[1])+'%').join(', ')+')';
    if(mode==='circle')
      return 'circle('+fmt(circle.r)+'% at '+fmt(circle.cx)+'% '+fmt(circle.cy)+'%)';
    if(mode==='ellipse')
      return 'ellipse('+fmt(ellipse.rx)+'% '+fmt(ellipse.ry)+'% at '+fmt(ellipse.cx)+'% '+fmt(ellipse.cy)+'%)';
    const rnd=inset.round>0?' round '+fmt(inset.round)+'%':'';
    return 'inset('+fmt(inset.t)+'% '+fmt(inset.r)+'% '+fmt(inset.b)+'% '+fmt(inset.l)+'%'+rnd+')';
  }

  function H(x,y,role,idx,extra){
    const d=document.createElement('div');
    d.className='cpg-h'+(extra?' '+extra:'');
    d.style.left=cl(x)+'%'; d.style.top=cl(y)+'%';
    d.dataset.role=role; if(idx!=null) d.dataset.idx=idx;
    hLayer.appendChild(d);
  }

  function buildHandles(){
    hLayer.innerHTML='';
    if(mode==='polygon'){
      poly.forEach((p,i)=>H(p[0],p[1],'vertex',i,i===sel?'sel':''));
    } else if(mode==='circle'){
      H(circle.cx,circle.cy,'center',null,'ctr');
      H(circle.cx+circle.r*Math.cos(circle.ra),circle.cy+circle.r*Math.sin(circle.ra),'radius',null,'rad');
    } else if(mode==='ellipse'){
      H(ellipse.cx,ellipse.cy,'center',null,'ctr');
      H(ellipse.cx+ellipse.rx,ellipse.cy,'rx',null,'rad');
      H(ellipse.cx,ellipse.cy-ellipse.ry,'ry',null,'rad');
    } else {
      H(50,inset.t,'t',null,'rad');
      H(100-inset.r,50,'r',null,'rad');
      H(50,100-inset.b,'b',null,'rad');
      H(inset.l,50,'l',null,'rad');
    }
  }

  function buildOutline(){
    if(!showOutline){svg.innerHTML='';return;}
    let g='';
    if(mode==='polygon')
      g='<polygon points="'+poly.map(p=>p[0]+','+p[1]).join(' ')+'"/>';
    else if(mode==='circle')
      g='<circle cx="'+circle.cx+'" cy="'+circle.cy+'" r="'+circle.r+'"/>';
    else if(mode==='ellipse')
      g='<ellipse cx="'+ellipse.cx+'" cy="'+ellipse.cy+'" rx="'+ellipse.rx+'" ry="'+ellipse.ry+'"/>';
    else {
      const w=Math.max(0,100-inset.l-inset.r), h=Math.max(0,100-inset.t-inset.b);
      g='<rect x="'+inset.l+'" y="'+inset.t+'" width="'+w+'" height="'+h+'" rx="'+inset.round+'" ry="'+inset.round+'"/>';
    }
    svg.innerHTML=g;
  }

  function setV(id,v){const el=$(id);if(!el)return;el.value=v;const b=$(id+'-n');if(b)b.textContent=fmt(v);}
  function syncControls(){
    setV('cp-cr',circle.r);setV('cp-ccx',circle.cx);setV('cp-ccy',circle.cy);
    setV('cp-erx',ellipse.rx);setV('cp-ery',ellipse.ry);setV('cp-ecx',ellipse.cx);setV('cp-ecy',ellipse.cy);
    setV('cp-it',inset.t);setV('cp-ir',inset.r);setV('cp-ib',inset.b);setV('cp-il',inset.l);setV('cp-iround',inset.round);
  }

  function render(){
    const v=buildValue();
    shape.style.webkitClipPath=v; shape.style.clipPath=v;
    out.value='clip-path: '+v+';';
    buildOutline(); buildHandles(); syncControls();
  }

  function stagePct(e){
    const rc=stage.getBoundingClientRect();
    return [cl((e.clientX-rc.left)/rc.width*100), cl((e.clientY-rc.top)/rc.height*100)];
  }

  function applyDrag(role,idx,x,y){
    if(mode==='polygon'&&role==='vertex'){ poly[idx]=[r1(x),r1(y)]; }
    else if(mode==='circle'){
      if(role==='center'){circle.cx=r1(x);circle.cy=r1(y);}
      else{const dx=x-circle.cx,dy=y-circle.cy;circle.r=clamp(r1(Math.hypot(dx,dy)),1,100);circle.ra=Math.atan2(dy,dx);}
    } else if(mode==='ellipse'){
      if(role==='center'){ellipse.cx=r1(x);ellipse.cy=r1(y);}
      else if(role==='rx'){ellipse.rx=clamp(r1(Math.abs(x-ellipse.cx)),1,100);}
      else {ellipse.ry=clamp(r1(Math.abs(ellipse.cy-y)),1,100);}
    } else {
      if(role==='t') inset.t=clamp(r1(y),0,100-inset.b-1);
      else if(role==='b') inset.b=clamp(r1(100-y),0,100-inset.t-1);
      else if(role==='l') inset.l=clamp(r1(x),0,100-inset.r-1);
      else inset.r=clamp(r1(100-x),0,100-inset.l-1);
    }
  }

  function onMove(e){ if(!drag)return; e.preventDefault(); const p=stagePct(e); applyDrag(drag.role,drag.idx,p[0],p[1]); render(); }
  function onUp(){ drag=null; document.removeEventListener('pointermove',onMove); document.removeEventListener('pointerup',onUp); }

  stage.addEventListener('pointerdown',e=>{
    const h=e.target.closest('.cpg-h'); if(!h)return;
    e.preventDefault();
    const role=h.dataset.role, idx=h.dataset.idx!=null?+h.dataset.idx:null;
    if(role==='vertex') sel=idx;
    drag={role,idx};
    document.addEventListener('pointermove',onMove);
    document.addEventListener('pointerup',onUp);
    render();
  });

  function segDist(px,py,x1,y1,x2,y2){
    const dx=x2-x1,dy=y2-y1,L=dx*dx+dy*dy||1;
    let t=clamp(((px-x1)*dx+(py-y1)*dy)/L,0,1);
    return Math.hypot(px-(x1+t*dx),py-(y1+t*dy));
  }
  function addAt(x,y){
    let bi=0,bd=Infinity;
    for(let i=0;i<poly.length;i++){
      const a=poly[i],b=poly[(i+1)%poly.length];
      const d=segDist(x,y,a[0],a[1],b[0],b[1]);
      if(d<bd){bd=d;bi=i;}
    }
    poly.splice(bi+1,0,[r1(x),r1(y)]); sel=bi+1; render();
  }
  function addPoint(){
    let bi=0,bd=-1;
    for(let i=0;i<poly.length;i++){
      const a=poly[i],b=poly[(i+1)%poly.length];
      const d=Math.hypot(a[0]-b[0],a[1]-b[1]);
      if(d>bd){bd=d;bi=i;}
    }
    const a=poly[bi],b=poly[(bi+1)%poly.length];
    poly.splice(bi+1,0,[r1((a[0]+b[0])/2),r1((a[1]+b[1])/2)]); sel=bi+1; render();
  }
  function removePoint(){
    if(mode!=='polygon'||poly.length<=3)return;
    const i=(sel>=0&&sel<poly.length)?sel:poly.length-1;
    poly.splice(i,1); sel=-1; render();
  }

  stage.addEventListener('dblclick',e=>{
    if(mode!=='polygon')return;
    if(e.target.closest('.cpg-h'))return;
    const p=stagePct(e); addAt(p[0],p[1]);
  });
  stage.addEventListener('contextmenu',e=>{
    const h=e.target.closest('.cpg-h');
    if(h&&h.dataset.role==='vertex'){ e.preventDefault(); if(poly.length>3){poly.splice(+h.dataset.idx,1);sel=-1;render();} }
  });

  const MK={polygon:'poly',circle:'circle',ellipse:'ellipse',inset:'inset'};
  function setMode(m){
    mode=m; sel=-1;
    document.querySelectorAll('.cpg-seg button').forEach(b=>b.classList.remove('on'));
    $('cp-m-'+MK[m]).classList.add('on');
    ['poly','circle','ellipse','inset'].forEach(g=>$('cp-g-'+g).classList.toggle('on', g===MK[m]));
    render();
  }
  [['cp-m-poly','polygon'],['cp-m-circle','circle'],['cp-m-ellipse','ellipse'],['cp-m-inset','inset']]
    .forEach(([id,m])=>$(id).addEventListener('click',()=>setMode(m)));

  $('cp-preset').addEventListener('change',()=>{
    const k=$('cp-preset').value;
    if(PRESETS[k]){ poly=PRESETS[k].map(p=>p.slice()); setMode('polygon'); }
  });

  const state={circle,ellipse,inset};
  [['cp-cr','circle','r'],['cp-ccx','circle','cx'],['cp-ccy','circle','cy'],
   ['cp-erx','ellipse','rx'],['cp-ery','ellipse','ry'],['cp-ecx','ellipse','cx'],['cp-ecy','ellipse','cy'],
   ['cp-it','inset','t'],['cp-ir','inset','r'],['cp-ib','inset','b'],['cp-il','inset','l'],['cp-iround','inset','round']]
   .forEach(([id,obj,key])=>{
     const el=$(id); if(!el)return;
     el.addEventListener('input',()=>{ state[obj][key]=+el.value; render(); });
   });

  $('cp-add').addEventListener('click',addPoint);
  $('cp-rm').addEventListener('click',removePoint);
  $('cp-outline').addEventListener('change',()=>{ showOutline=$('cp-outline').checked; render(); });

  $('cp-bg-grad').addEventListener('click',()=>{ shape.style.backgroundImage=''; });
  $('cp-bg-img').addEventListener('click',()=>$('cp-file').click());
  $('cp-file').addEventListener('change',e=>{
    const f=e.target.files&&e.target.files[0]; if(!f)return;
    const rd=new FileReader();
    rd.onload=()=>{ shape.style.backgroundImage='url('+JSON.stringify(rd.result)+')'; };
    rd.readAsDataURL(f);
    e.target.value='';
  });
  $('cp-reset').addEventListener('click',()=>{
    poly=PRESETS.triangle.map(p=>p.slice());
    circle.cx=50;circle.cy=50;circle.r=50;circle.ra=0;
    ellipse.cx=50;ellipse.cy=50;ellipse.rx=50;ellipse.ry=35;
    inset.t=10;inset.r=10;inset.b=10;inset.l=10;inset.round=0;
    shape.style.backgroundImage=''; $('cp-preset').value='';
    setMode('polygon');
  });

  $('cp-copy').addEventListener('click',e=>wtCopy(e.currentTarget,out.value));

  setMode('polygon');
})();
</script>

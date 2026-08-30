@php $pgLabels = [__('ui.wt_weak'), __('ui.wt_fair'), __('ui.wt_good'), __('ui.wt_strong')]; @endphp
<div class="wt-single">
  <div class="wt-result" id="p-out" dir="ltr">—</div>
  <div class="wt-strength"><span id="p-bar"></span></div>
  <p class="wt-strength-l" id="p-lbl"></p>
</div>
<div class="wt-fields">
  <label class="wt-range">{{ __('ui.wt_length') }}: <b id="p-n">20</b>
    <input type="range" id="p-len" min="8" max="64" value="20">
  </label>
  <label class="wt-chk"><input type="checkbox" id="p-up" checked> A-Z</label>
  <label class="wt-chk"><input type="checkbox" id="p-lo" checked> a-z</label>
  <label class="wt-chk"><input type="checkbox" id="p-di" checked> 0-9</label>
  <label class="wt-chk"><input type="checkbox" id="p-sy" checked> !@#$</label>
  <label class="wt-chk"><input type="checkbox" id="p-amb"> {{ __('ui.wt_no_ambiguous') }}</label>
</div>
<div class="wt-bar">
  <button class="btn btn-primary" id="p-gen">{{ __('ui.wt_generate') }}</button>
  <button class="btn btn-glass" id="p-copy" data-done="{{ __('ui.wt_copied') }}">{{ __('ui.wt_copy') }}</button>
</div>
<script>
(function(){
  const out=document.getElementById('p-out'), bar=document.getElementById('p-bar'), lbl=document.getElementById('p-lbl');
  const len=document.getElementById('p-len'), n=document.getElementById('p-n');
  const L={u:'ABCDEFGHIJKLMNOPQRSTUVWXYZ',l:'abcdefghijklmnopqrstuvwxyz',d:'0123456789',s:'!@#$%^&*()-_=+[]{};:,.?'};
  const AMB=/[Il1O0]/g;
  const labels=@json($pgLabels);
  function gen(){
    let pool='';
    if(document.getElementById('p-up').checked) pool+=L.u;
    if(document.getElementById('p-lo').checked) pool+=L.l;
    if(document.getElementById('p-di').checked) pool+=L.d;
    if(document.getElementById('p-sy').checked) pool+=L.s;
    if(document.getElementById('p-amb').checked) pool=pool.replace(AMB,'');
    if(!pool){ out.textContent='—'; return; }
    const size=+len.value, a=new Uint32Array(size);
    crypto.getRandomValues(a);                       // مولد امن مرورگر، نه Math.random
    let s=''; for(let i=0;i<size;i++) s+=pool[a[i]%pool.length];
    out.textContent=s;
    const bits=Math.round(size*Math.log2(pool.length));
    const lvl=bits<50?0:bits<80?1:bits<120?2:3;
    bar.style.width=Math.min(100,bits/1.6)+'%';
    bar.className='lv'+lvl;
    lbl.textContent=labels[lvl]+' · '+bits+' bit';
  }
  len.oninput=()=>{n.textContent=len.value;gen();};
  document.querySelectorAll('.wt-fields input').forEach(el=>el.onchange=gen);
  document.getElementById('p-gen').onclick=gen;
  document.getElementById('p-copy').onclick=(e)=>wtCopy(e.target,out.textContent);
  gen();
})();
</script>

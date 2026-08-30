<div class="wt-two">
  <div class="wt-pane">
    <label>{{ __('ui.wt_cc_pick') }}</label>
    <input type="color" id="k-pick" class="wt-color" value="#22d3ee">
  </div>
  <div class="wt-pane">
    <label>{{ __('ui.wt_cc_any') }}</label>
    <input type="text" id="k-in" class="wt-input-lg" dir="ltr" placeholder="#22d3ee / rgb(34,211,238) / hsl(187,80%,53%)">
  </div>
</div>
<div class="wt-swatch" id="k-sw"></div>
<div class="wt-out-box" id="k-out"></div>
<div class="wt-out-box" id="k-contrast" style="margin-top:12px"></div>
<script>
(function () {
  const $ = id => document.getElementById(id);
  const COPY=@json(__('ui.wt_copy')), DONE=@json(__('ui.wt_copied'));
  const L={onW:@json(__('ui.wt_cc_onwhite')),onB:@json(__('ui.wt_cc_onblack')),bad:@json(__('ui.wt_cc_bad'))};

  const clamp=(n,a,b)=>Math.min(b,Math.max(a,n));
  const hex2=n=>clamp(Math.round(n),0,255).toString(16).padStart(2,'0');

  function parse(s) {
    s=s.trim().toLowerCase();
    let m;
    if ((m=s.match(/^#?([0-9a-f]{3})$/))) {
      const h=m[1]; return [parseInt(h[0]+h[0],16),parseInt(h[1]+h[1],16),parseInt(h[2]+h[2],16)];
    }
    if ((m=s.match(/^#?([0-9a-f]{6})$/))) {
      const h=m[1]; return [parseInt(h.slice(0,2),16),parseInt(h.slice(2,4),16),parseInt(h.slice(4,6),16)];
    }
    if ((m=s.match(/^rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)/))) {
      return [+m[1],+m[2],+m[3]];
    }
    if ((m=s.match(/^hsla?\(\s*([\d.]+)[,\s]+([\d.]+)%[,\s]+([\d.]+)%/))) {
      return hsl2rgb(+m[1],+m[2]/100,+m[3]/100);
    }
    return null;
  }

  function hsl2rgb(h,s,l){
    h=((h%360)+360)%360/360;
    if(s===0){const v=l*255;return [v,v,v];}
    const q=l<0.5?l*(1+s):l+s-l*s, p=2*l-q;
    const f=t=>{t=(t+1)%1;
      if(t<1/6)return p+(q-p)*6*t;
      if(t<1/2)return q;
      if(t<2/3)return p+(q-p)*(2/3-t)*6;
      return p;};
    return [f(h+1/3)*255,f(h)*255,f(h-1/3)*255];
  }

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

  const lum=c=>{const a=c.map(v=>{v/=255;return v<=0.03928?v/12.92:Math.pow((v+0.055)/1.055,2.4);});
    return 0.2126*a[0]+0.7152*a[1]+0.0722*a[2];};
  const ratio=(a,b)=>{const l1=lum(a),l2=lum(b);return ((Math.max(l1,l2)+0.05)/(Math.min(l1,l2)+0.05));};
  const grade=r=>r>=7?'AAA':r>=4.5?'AA':r>=3?'AA Large':'✗';

  function show(rgb, syncPicker) {
    const [r,g,b]=rgb.map(v=>clamp(Math.round(v),0,255));
    const hex='#'+hex2(r)+hex2(g)+hex2(b);
    const [h,s,l]=rgb2hsl(r,g,b);
    if (syncPicker) $('k-pick').value=hex;
    $('k-sw').style.background=hex;

    const vals=[
      ['HEX',hex],
      ['RGB','rgb('+r+', '+g+', '+b+')'],
      ['HSL','hsl('+Math.round(h)+', '+Math.round(s)+'%, '+Math.round(l)+'%)'],
      ['CSS var','--color: '+hex+';'],
    ];
    $('k-out').innerHTML=vals.map(v=>
      '<div class="wt-out-row"><span>'+v[0]+'</span><b dir="ltr">'+v[1]+'</b>'
      +'<button class="wt-mini" data-v="'+v[1]+'" data-done="'+DONE+'">'+COPY+'</button></div>').join('');
    $('k-out').querySelectorAll('.wt-mini').forEach(btn=>btn.onclick=()=>wtCopy(btn,btn.dataset.v));

    const w=ratio([r,g,b],[255,255,255]), k=ratio([r,g,b],[0,0,0]);
    $('k-contrast').innerHTML=
       '<div class="wt-out-row"><span>'+L.onW+'</span><b dir="ltr">'+w.toFixed(2)+':1 — '+grade(w)+'</b></div>'
      +'<div class="wt-out-row"><span>'+L.onB+'</span><b dir="ltr">'+k.toFixed(2)+':1 — '+grade(k)+'</b></div>';
  }

  $('k-pick').addEventListener('input',()=>{ $('k-in').value=$('k-pick').value; show(parse($('k-pick').value),false); });
  $('k-in').addEventListener('input',()=>{
    const c=parse($('k-in').value);
    if(c) show(c,true);
  });
  $('k-in').value='#22d3ee';
  show([34,211,238],true);
})();
</script>

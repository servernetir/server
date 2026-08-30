<div class="wt-shadow-demo"><div id="x-box"></div></div>
<div class="wt-fields" style="border:0">
  <label class="wt-range">X: <b id="x-xn">0</b><input type="range" id="x-x" min="-60" max="60" value="0"></label>
  <label class="wt-range">Y: <b id="x-yn">14</b><input type="range" id="x-y" min="-60" max="60" value="14"></label>
  <label class="wt-range">Blur: <b id="x-bn">34</b><input type="range" id="x-b" min="0" max="120" value="34"></label>
  <label class="wt-range">Spread: <b id="x-sn">-8</b><input type="range" id="x-s" min="-60" max="60" value="-8"></label>
</div>
<div class="wt-fields">
  <label class="wt-range">{{ __('ui.wt_bs_color') }} <input type="color" id="x-c" value="#000000" class="wt-color sm"></label>
  <label class="wt-range">{{ __('ui.wt_bs_opacity') }}: <b id="x-on">45</b>%<input type="range" id="x-o" min="0" max="100" value="45"></label>
  <label class="wt-chk"><input type="checkbox" id="x-i"> inset</label>
</div>
<div class="wt-pane" style="margin-top:16px">
  <label>CSS</label>
  <textarea id="x-out" class="wt-ta" rows="3" readonly dir="ltr"></textarea>
</div>
<div class="wt-bar"><button class="btn btn-glass" id="x-copy" data-done="{{ __('ui.wt_copied') }}">{{ __('ui.wt_copy') }}</button></div>
<script>
(function () {
  const $ = id => document.getElementById(id);
  const hex2rgba=(h,a)=>{
    const n=h.replace('#','');
    const r=parseInt(n.slice(0,2),16),g=parseInt(n.slice(2,4),16),b=parseInt(n.slice(4,6),16);
    return 'rgba('+r+', '+g+', '+b+', '+(a/100).toFixed(2)+')';
  };
  function run(){
    ['x','y','b','s','o'].forEach(k=>$('x-'+k+'n').textContent=$('x-'+k).value);
    const css=($('x-i').checked?'inset ':'')+$('x-x').value+'px '+$('x-y').value+'px '
      +$('x-b').value+'px '+$('x-s').value+'px '+hex2rgba($('x-c').value,+$('x-o').value);
    $('x-box').style.boxShadow=css;
    $('x-out').value='box-shadow: '+css+';';
  }
  ['x-x','x-y','x-b','x-s','x-o','x-c','x-i'].forEach(id=>{
    $(id).addEventListener('input',run); $(id).addEventListener('change',run);
  });
  $('x-copy').onclick=e=>wtCopy(e.target,$('x-out').value);
  run();
})();
</script>

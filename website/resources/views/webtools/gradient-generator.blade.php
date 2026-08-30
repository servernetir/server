<div class="wt-grad-demo" id="g-demo"></div>
<div class="wt-fields" style="border:0">
  <label class="wt-range">{{ __('ui.wt_gr_type') }}
    <select id="g-type" class="wt-select"><option value="linear">linear</option><option value="radial">radial</option></select>
  </label>
  <label class="wt-range" id="g-angle-w">{{ __('ui.wt_gr_angle') }}: <b id="g-an">100</b>°<input type="range" id="g-a" min="0" max="360" value="100"></label>
  <label class="wt-range">{{ __('ui.wt_gr_c1') }} <input type="color" id="g-c1" value="#22d3ee" class="wt-color sm"></label>
  <label class="wt-range">{{ __('ui.wt_gr_c2') }} <input type="color" id="g-c2" value="#8b5cf6" class="wt-color sm"></label>
  <label class="wt-chk"><input type="checkbox" id="g-c3on"> {{ __('ui.wt_gr_c3') }}</label>
  <label class="wt-range"><input type="color" id="g-c3" value="#34d399" class="wt-color sm" disabled></label>
</div>
<div class="wt-pane" style="margin-top:16px">
  <label>CSS</label>
  <textarea id="g-out" class="wt-ta" rows="3" readonly dir="ltr"></textarea>
</div>
<div class="wt-bar">
  <button class="btn btn-primary" id="g-rand">{{ __('ui.wt_gr_random') }}</button>
  <button class="btn btn-glass" id="g-copy" data-done="{{ __('ui.wt_copied') }}">{{ __('ui.wt_copy') }}</button>
</div>
<script>
(function () {
  const $ = id => document.getElementById(id);
  function run(){
    $('g-an').textContent=$('g-a').value;
    $('g-c3').disabled=!$('g-c3on').checked;
    $('g-angle-w').style.opacity=$('g-type').value==='radial'?'.4':'1';
    const stops=[$('g-c1').value,$('g-c2').value];
    if($('g-c3on').checked) stops.push($('g-c3').value);
    const css=$('g-type').value==='linear'
      ? 'linear-gradient('+$('g-a').value+'deg, '+stops.join(', ')+')'
      : 'radial-gradient(circle at 50% 50%, '+stops.join(', ')+')';
    $('g-demo').style.background=css;
    $('g-out').value='background: '+css+';';
  }
  const rnd=()=>'#'+Array.from({length:3},()=>Math.floor(Math.random()*256).toString(16).padStart(2,'0')).join('');
  ['g-type','g-a','g-c1','g-c2','g-c3','g-c3on'].forEach(id=>{
    $(id).addEventListener('input',run); $(id).addEventListener('change',run);
  });
  $('g-rand').onclick=()=>{ $('g-c1').value=rnd(); $('g-c2').value=rnd(); $('g-c3').value=rnd();
    $('g-a').value=Math.floor(Math.random()*361); run(); };
  $('g-copy').onclick=e=>wtCopy(e.target,$('g-out').value);
  run();
})();
</script>

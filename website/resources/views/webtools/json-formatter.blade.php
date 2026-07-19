<div class="wt-io">
  <div class="wt-pane">
    <label>{{ __('ui.wt_input') }}</label>
    <textarea id="j-in" class="wt-ta" rows="14" spellcheck="false" placeholder='{"name":"servernet","active":true}'></textarea>
  </div>
  <div class="wt-pane">
    <label>{{ __('ui.wt_output') }}</label>
    <textarea id="j-out" class="wt-ta" rows="14" readonly spellcheck="false"></textarea>
  </div>
</div>
<div class="wt-bar">
  <button class="btn btn-primary" id="j-pretty">{{ __('ui.wt_format') }}</button>
  <button class="btn btn-glass" id="j-min">{{ __('ui.wt_minify') }}</button>
  <button class="btn btn-glass" id="j-copy" data-done="{{ __('ui.wt_copied') }}">{{ __('ui.wt_copy') }}</button>
  <button class="btn btn-glass" id="j-clear">{{ __('ui.wt_clear') }}</button>
  <span class="wt-status" id="j-msg"></span>
</div>
<script>
(function(){
  const i=document.getElementById('j-in'), o=document.getElementById('j-out'), m=document.getElementById('j-msg');
  const run=(space)=>{
    const raw=i.value.trim();
    if(!raw){ o.value=''; m.textContent=''; m.className='wt-status'; return; }
    try{
      o.value=JSON.stringify(JSON.parse(raw), null, space);
      const n=(raw.match(/[{[]/g)||[]).length;
      m.textContent=@json(__('ui.wt_json_ok')).replace(':n', n);
      m.className='wt-status ok';
    }catch(e){
      o.value='';
      m.textContent=e.message;
      m.className='wt-status err';
    }
  };
  document.getElementById('j-pretty').onclick=()=>run(2);
  document.getElementById('j-min').onclick=()=>run(0);
  document.getElementById('j-copy').onclick=(e)=>wtCopy(e.target,o.value);
  document.getElementById('j-clear').onclick=()=>{i.value='';o.value='';m.textContent='';m.className='wt-status';};
  i.addEventListener('input',()=>run(2));
})();
</script>

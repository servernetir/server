<div class="wt-io">
  <div class="wt-pane">
    <label>{{ __('ui.wt_input') }}</label>
    <textarea id="u-in" class="wt-ta" rows="8" spellcheck="false" placeholder="https://servernet.cloud/search?q=هاست ابری"></textarea>
  </div>
  <div class="wt-pane">
    <label>{{ __('ui.wt_output') }}</label>
    <textarea id="u-out" class="wt-ta" rows="8" readonly spellcheck="false"></textarea>
  </div>
</div>
<div class="wt-bar">
  <button class="btn btn-primary" id="u-enc">{{ __('ui.wt_encode') }}</button>
  <button class="btn btn-glass" id="u-dec">{{ __('ui.wt_decode') }}</button>
  <button class="btn btn-glass" id="u-comp">encodeURIComponent</button>
  <button class="btn btn-glass" id="u-copy" data-done="{{ __('ui.wt_copied') }}">{{ __('ui.wt_copy') }}</button>
  <span class="wt-status" id="u-msg"></span>
</div>
<script>
(function(){
  const i=document.getElementById('u-in'), o=document.getElementById('u-out'), m=document.getElementById('u-msg');
  const go=(fn)=>{ try{ o.value=i.value?fn(i.value):''; m.textContent=''; m.className='wt-status'; }
                   catch(e){ o.value=''; m.textContent=@json(__('ui.wt_url_err')); m.className='wt-status err'; } };
  document.getElementById('u-enc').onclick=()=>go(encodeURI);
  document.getElementById('u-comp').onclick=()=>go(encodeURIComponent);
  document.getElementById('u-dec').onclick=()=>go(decodeURIComponent);
  document.getElementById('u-copy').onclick=(e)=>wtCopy(e.target,o.value);
})();
</script>

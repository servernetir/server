<div class="wt-io">
  <div class="wt-pane">
    <label>{{ __('ui.wt_input') }}</label>
    <textarea id="b-in" class="wt-ta" rows="10" spellcheck="false"></textarea>
  </div>
  <div class="wt-pane">
    <label>{{ __('ui.wt_output') }}</label>
    <textarea id="b-out" class="wt-ta" rows="10" readonly spellcheck="false"></textarea>
  </div>
</div>
<div class="wt-bar">
  <button class="btn btn-primary" id="b-enc">{{ __('ui.wt_encode') }}</button>
  <button class="btn btn-glass" id="b-dec">{{ __('ui.wt_decode') }}</button>
  <button class="btn btn-glass" id="b-copy" data-done="{{ __('ui.wt_copied') }}">{{ __('ui.wt_copy') }}</button>
  <span class="wt-status" id="b-msg"></span>
</div>
<script>
(function(){
  const i=document.getElementById('b-in'), o=document.getElementById('b-out'), m=document.getElementById('b-msg');
  const enc=(s)=>btoa(String.fromCharCode(...new TextEncoder().encode(s)));
  const dec=(s)=>new TextDecoder().decode(Uint8Array.from(atob(s.replace(/\s+/g,'')), c=>c.charCodeAt(0)));
  const go=(fn)=>{ try{ o.value=i.value?fn(i.value):''; m.textContent=''; m.className='wt-status'; }
                   catch(e){ o.value=''; m.textContent=@json(__('ui.wt_b64_err')); m.className='wt-status err'; } };
  document.getElementById('b-enc').onclick=()=>go(enc);
  document.getElementById('b-dec').onclick=()=>go(dec);
  document.getElementById('b-copy').onclick=(e)=>wtCopy(e.target,o.value);
})();
</script>

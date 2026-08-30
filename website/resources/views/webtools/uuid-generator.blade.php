<div class="wt-bar" style="margin-bottom:14px">
  <label class="wt-range">{{ __('ui.wt_count') }}: <b id="q-n">5</b>
    <input type="range" id="q-c" min="1" max="50" value="5"></label>
  <button class="btn btn-primary" id="q-gen">{{ __('ui.wt_generate') }}</button>
  <button class="btn btn-glass" id="q-copy" data-done="{{ __('ui.wt_copied') }}">{{ __('ui.wt_copy_all') }}</button>
</div>
<textarea id="q-out" class="wt-ta" rows="12" readonly dir="ltr"></textarea>
<script>
(function(){
  const c=document.getElementById('q-c'), n=document.getElementById('q-n'), o=document.getElementById('q-out');
  const uuid=()=> (crypto.randomUUID ? crypto.randomUUID()
    : ('10000000-1000-4000-8000-100000000000').replace(/[018]/g, ch =>
        (ch ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> ch/4).toString(16)));
  const gen=()=>{ o.value=Array.from({length:+c.value},uuid).join('\n'); };
  c.oninput=()=>{n.textContent=c.value;gen();};
  document.getElementById('q-gen').onclick=gen;
  document.getElementById('q-copy').onclick=(e)=>wtCopy(e.target,o.value);
  gen();
})();
</script>

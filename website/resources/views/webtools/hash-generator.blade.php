<textarea id="h-in" class="wt-ta" rows="6" placeholder="{{ __('ui.wt_paste_here') }}"></textarea>
<div class="wt-out-box" id="h-out" style="margin-top:14px"></div>
<script>
(function(){
  const i=document.getElementById('h-in'), o=document.getElementById('h-out');
  const ALGS=['SHA-1','SHA-256','SHA-384','SHA-512'];
  async function run(){
    if(!i.value){ o.innerHTML=''; return; }
    const data=new TextEncoder().encode(i.value);
    const rows=await Promise.all(ALGS.map(async a=>{
      const buf=await crypto.subtle.digest(a,data);
      const hex=[...new Uint8Array(buf)].map(b=>b.toString(16).padStart(2,'0')).join('');
      return '<div class="wt-out-row hash"><span>'+a+'</span><b dir="ltr">'+hex+'</b></div>';
    }));
    o.innerHTML=rows.join('');
  }
  i.addEventListener('input',run);
})();
</script>

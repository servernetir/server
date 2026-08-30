@php $tcLabels = [__('ui.wt_words'), __('ui.wt_chars'), __('ui.wt_chars_nospace'), __('ui.wt_lines'), __('ui.wt_paragraphs'), __('ui.wt_reading')]; @endphp
<textarea id="t-in" class="wt-ta" rows="12" placeholder="{{ __('ui.wt_paste_here') }}"></textarea>
<div class="wt-stats" id="t-stats"></div>
<script>
(function(){
  const i=document.getElementById('t-in'), box=document.getElementById('t-stats');
  const T=@json($tcLabels);
  function run(){
    const s=i.value;
    const words=(s.match(/[\p{L}\p{N}]+/gu)||[]).length;   // با فارسی هم کار می‌کند
    const v=[words, s.length, s.replace(/\s/g,'').length,
             s?s.split(/\n/).length:0,
             s.trim()?s.trim().split(/\n\s*\n/).length:0,
             Math.max(1,Math.ceil(words/220))+' '+@json(__('ui.wt_min'))];
    box.innerHTML=T.map((t,k)=>'<div class="wt-stat"><b>'+v[k]+'</b><span>'+t+'</span></div>').join('');
  }
  i.addEventListener('input',run); run();
})();
</script>

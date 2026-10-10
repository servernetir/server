@php
  $pickerId = $id ?? 'customer-picker';
  $pickerName = $name ?? 'customer_ids[]';
  $pickerMax = (int) ($max ?? 200);
  $picked = collect($selected ?? [])->keyBy('id');
@endphp

<div class="cp" id="{{ $pickerId }}" data-name="{{ $pickerName }}" data-max="{{ $pickerMax }}">
  <div class="cp-picked" aria-live="polite">
    @foreach($picked as $customer)
      <span class="cp-chip" data-id="{{ $customer->id }}">
        <b>{{ $customer->displayName() }}</b>
        <small dir="ltr">{{ $customer->code }} · {{ $customer->email ?: $customer->phone }}</small>
        <button type="button" aria-label="حذف {{ $customer->code }}">×</button>
        <input type="hidden" name="{{ $pickerName }}" value="{{ $customer->id }}">
      </span>
    @endforeach
  </div>
  <div class="cp-search">
    <input type="search" autocomplete="off" placeholder="نام، کد مشتری، ایمیل یا موبایل را بنویسید…" aria-label="جستجوی مشتری">
    <div class="cp-results" hidden></div>
  </div>
  <div class="cp-foot"><span class="cp-count">{{ fa_num((string) $picked->count()) }} مشتری انتخاب شده</span><span>حداکثر {{ fa_num((string) $pickerMax) }} نفر</span></div>
</div>

@once
<style>
.cp{border:1px solid var(--line);background:var(--surface2);border-radius:10px;padding:10px}
.cp-picked{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:8px}.cp-picked:empty{margin:0}
.cp-chip{display:grid;grid-template-columns:1fr auto;gap:1px 9px;align-items:center;background:rgba(34,211,238,.09);border:1px solid rgba(34,211,238,.28);border-radius:9px;padding:6px 9px}
.cp-chip b{font-size:12px;font-weight:700}.cp-chip small{grid-column:1;color:var(--dim);font-size:10.5px}.cp-chip button{grid-column:2;grid-row:1/3;border:0;background:none;color:#ff7b7b;font-size:18px;cursor:pointer}
.cp-search{position:relative}.cp-search>input{width:100%;background:var(--surface);border:1px solid var(--line);border-radius:8px;color:var(--text);padding:9px 11px;font:inherit}
.cp-results{position:absolute;z-index:30;inset-inline:0;top:calc(100% + 5px);max-height:280px;overflow:auto;background:var(--surface);border:1px solid var(--line);border-radius:10px;box-shadow:0 16px 35px rgba(0,0,0,.3)}
.cp-result{width:100%;border:0;border-bottom:1px solid var(--line);background:none;color:var(--text);padding:10px 12px;text-align:start;cursor:pointer;display:grid;gap:3px}.cp-result:hover,.cp-result:focus{background:rgba(34,211,238,.08)}
.cp-result small,.cp-empty{color:var(--dim);font-size:11px}.cp-empty{padding:13px}.cp-foot{display:flex;justify-content:space-between;margin-top:7px;color:var(--dim);font-size:11px}
</style>
<script>
(function(){
  function fa(n){return String(n).replace(/\d/g,function(d){return '۰۱۲۳۴۵۶۷۸۹'[d]})}
  document.querySelectorAll('.cp').forEach(function(root){
    if(root.dataset.ready) return; root.dataset.ready='1';
    var input=root.querySelector('.cp-search>input'), box=root.querySelector('.cp-results'), picked=root.querySelector('.cp-picked');
    var timer=null, ctrl=null, max=parseInt(root.dataset.max||'200',10), name=root.dataset.name;
    function ids(){return Array.from(picked.querySelectorAll('.cp-chip')).map(function(x){return String(x.dataset.id)})}
    function sync(){root.querySelector('.cp-count').textContent=fa(ids().length)+' مشتری انتخاب شده'}
    function chip(c){
      if(ids().includes(String(c.id))||ids().length>=max)return;
      var el=document.createElement('span');el.className='cp-chip';el.dataset.id=c.id;
      var b=document.createElement('b');b.textContent=c.name||c.email||c.code;
      var s=document.createElement('small');s.dir='ltr';s.textContent=[c.code,c.email||c.phone].filter(Boolean).join(' · ');
      var x=document.createElement('button');x.type='button';x.textContent='×';x.setAttribute('aria-label','حذف '+c.code);
      var h=document.createElement('input');h.type='hidden';h.name=name;h.value=c.id;
      el.append(b,s,x,h);picked.appendChild(el);sync();
    }
    picked.addEventListener('click',function(e){if(e.target.tagName==='BUTTON'){e.target.closest('.cp-chip').remove();sync()}});
    input.addEventListener('input',function(){clearTimeout(timer);var q=input.value.trim();if(q.length<2){box.hidden=true;return}timer=setTimeout(function(){
      if(ctrl)ctrl.abort();ctrl=new AbortController();box.hidden=false;box.innerHTML='<div class="cp-empty">در حال جستجو…</div>';
      fetch('/admin/customers/search?q='+encodeURIComponent(q),{headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'},signal:ctrl.signal})
      .then(function(r){return r.ok?r.json():null}).then(function(data){
        if(!data||!data.ok)return;box.innerHTML='';var used=ids();var rows=data.results.filter(function(c){return !used.includes(String(c.id))});
        if(!rows.length){box.innerHTML='<div class="cp-empty">مشتری دیگری پیدا نشد.</div>';return}
        rows.forEach(function(c){var b=document.createElement('button');b.type='button';b.className='cp-result';
          var strong=document.createElement('strong');strong.textContent=c.name||c.email||c.code;
          var small=document.createElement('small');small.dir='ltr';small.textContent=[c.code,c.email,c.phone].filter(Boolean).join(' · ');
          b.append(strong,small);b.addEventListener('click',function(){chip(c);input.value='';box.hidden=true;input.focus()});box.appendChild(b)});
      }).catch(function(e){if(e.name!=='AbortError')box.innerHTML='<div class="cp-empty">جستجو انجام نشد؛ دوباره تلاش کنید.</div>'});
    },220)});
    document.addEventListener('click',function(e){if(!root.contains(e.target))box.hidden=true});
    input.addEventListener('keydown',function(e){if(e.key==='Escape')box.hidden=true});sync();
  });
})();
</script>
@endonce

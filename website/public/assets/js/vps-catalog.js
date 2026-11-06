(function () {
  'use strict';
  document.querySelectorAll('[data-vps-catalog]').forEach(function (root) {
    var form=root.querySelector('[data-vps-filters]'); if(!form)return;
    var rows=Array.from(root.querySelectorAll('[data-vps-row]')), tbody=root.querySelector('tbody');
    var search=root.querySelector('[data-vps-search]'),country=root.querySelector('[data-vps-country]'),city=root.querySelector('[data-vps-city]');
    var cpu=root.querySelector('[data-vps-cpu]'),ram=root.querySelector('[data-vps-ram]');
    var billing=root.querySelector('[data-vps-billing]'),sort=root.querySelector('[data-vps-sort]'),more=root.querySelector('[data-vps-more]');
    var count=root.querySelector('[data-vps-count]'),empty=root.querySelector('[data-vps-empty]'),limit=50;
    var text=new Map(rows.map(function(r){return [r,r.textContent.toLocaleLowerCase()];}));
    function draw(reset){
      if(reset)limit=50;
      var selectedCountry=country?country.value:'', q=search.value.trim().toLocaleLowerCase();
      Array.from(city.options).forEach(function(o){o.hidden=!!(o.value&&selectedCountry&&o.dataset.country!==selectedCountry);});
      if(city.selectedOptions[0]&&city.selectedOptions[0].hidden)city.value='';
      var matched=rows.filter(function(r){return (!selectedCountry||r.dataset.country===selectedCountry)&&(!city.value||r.dataset.city===city.value)&&(!q||text.get(r).includes(q))&&(!cpu.value||r.dataset.cpu===cpu.value)&&(!ram.value||r.dataset.ram===ram.value)&&(!billing.value||(billing.value==='hourly'?Number(r.dataset.hourly)>0:Number(r.dataset.hourly)===0));});
      var key=sort.value;
      matched.sort(function(a,b){var x=Number(a.dataset[key]),y=Number(b.dataset[key]);if(key==='hourly'){x=x||Infinity;y=y||Infinity;}return x-y||Number(a.dataset.monthly)-Number(b.dataset.monthly);});
      rows.forEach(function(r){r.hidden=true;});
      matched.forEach(function(r,i){tbody.appendChild(r);r.hidden=i>=limit;});
      var number=new Intl.NumberFormat(document.documentElement.lang||'fa');
      count.textContent=(count.getAttribute('data-vps-count-template')||':shown / :total').replace(':shown',number.format(matched.length)).replace(':total',number.format(rows.length));
      empty.hidden=matched.length>0;more.hidden=matched.length<=limit;
    }
    form.addEventListener('submit',function(e){e.preventDefault();});
    form.addEventListener('input',function(){draw(true);});form.addEventListener('change',function(){draw(true);});
    more.addEventListener('click',function(){limit+=50;draw(false);});draw(true);
  });
}());

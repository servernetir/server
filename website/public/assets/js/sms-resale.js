(function(root){
  'use strict';
  function segments(text){
    if(!text.length)return 0;
    const basic='@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞ ÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';
    const extended='^{}\\[~]|€\f';let units=0;
    for(const char of text){if(basic.includes(char))units++;else if(extended.includes(char))units+=2;else return text.length<=70?1:Math.ceil(text.length/67);}
    return units<=160?1:Math.ceil(units/153);
  }
  if(typeof module!=='undefined'&&module.exports)module.exports={segments};
  if(!root||!root.document)return;
  root.document.querySelectorAll('[data-sms-calculator]').forEach(function(el){
    const text=el.querySelector('[data-sms-text]'),count=el.querySelector('[data-sms-count]'),rows=Array.from(el.querySelectorAll('[data-sms-estimate]'));
    const labels=JSON.parse(el.querySelector('[data-sms-labels]').textContent),format=new Intl.NumberFormat(root.document.documentElement.lang||'fa',{maximumFractionDigits:1});
    function render(){const n=Number(count.value),parts=segments(text.value);const error=el.querySelector('[data-sms-error]');
      if(!count.value||!Number.isInteger(n)||n<0||n>1000000){error.textContent=labels.invalid;return;}error.textContent='';
      let best=null;rows.forEach(function(row){const total=Number(row.dataset.firstIrt)*10+Number(row.dataset.rateIrr)*n*parts*12;row.querySelector('[data-sms-total]').textContent=format.format(total/10)+' '+labels.currency;row.dataset.recommended='false';if(!best||total<best.total)best={row:row,total:total};});
      if(best){best.row.dataset.recommended='true';el.querySelector('[data-sms-recommendation]').textContent=labels.recommended+' '+best.row.cells[0].textContent;}
      el.querySelector('[data-sms-parts]').textContent=labels.parts+' '+format.format(parts);
    }
    text.addEventListener('input',render);count.addEventListener('input',render);render();
  });
})(typeof window==='undefined'?null:window);

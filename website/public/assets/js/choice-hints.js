(function () {
  'use strict';
  var form=document.getElementById('cvb-form');if(!form)return;
  var selector='.cvb-seg,.cvb-img,.cvb-image-card,.cvb-version-option';
  var tip=document.createElement('div');tip.className='cvb-choice-tooltip';tip.id='cvb-choice-tooltip';
  tip.setAttribute('role','tooltip');tip.hidden=true;document.body.appendChild(tip);
  var active=null, timer=null, scheduled=false;
  function disabled(el){return el.getAttribute('aria-disabled')==='true'||el.disabled===true;}
  function note(el){var reason=el.querySelector('.cvb-choice-reason,.cvb-cycle-reason,#cvb-hourly-reason');return (reason?reason.textContent:el.getAttribute('data-choice-hint')||el.title||'').trim();}
  function position(){
    if(!active)return;var r=active.getBoundingClientRect(),width=tip.getBoundingClientRect().width;
    tip.style.left=Math.max(12,Math.min(r.left+r.width/2-width/2,window.innerWidth-width-12))+'px';
    var h=tip.getBoundingClientRect().height,top=r.top-h-10;
    if(top<12)top=r.bottom+10;
    tip.style.top=Math.max(12,Math.min(top,window.innerHeight-h-12))+'px';
  }
  function hide(){clearTimeout(timer);if(active)active.removeAttribute('aria-describedby');active=null;tip.hidden=true;}
  function show(el){
    clearTimeout(timer);if(!disabled(el)||!note(el))return;
    if(active&&active!==el)active.removeAttribute('aria-describedby');
    active=el;tip.textContent=note(el);tip.dir=document.documentElement.dir||'rtl';tip.hidden=false;
    el.setAttribute('aria-describedby',tip.id);position();
  }
  function sync(){
    scheduled=false;
    document.querySelectorAll(selector).forEach(function(el){
      if(!form.contains(el)&&!el.classList.contains('cvb-version-option'))return;
      var off=disabled(el),reason=note(el);
      if(off&&reason){
        if(el.getAttribute('data-choice-hint')!==reason)el.setAttribute('data-choice-hint',reason);
        // A wrapper remains focusable even when its native input/button is disabled.
        if(!el.hasAttribute('data-choice-tabindex'))el.setAttribute('data-choice-tabindex',el.getAttribute('tabindex')||'');
        if(el.tabIndex!==0)el.tabIndex=0;
        if(el.title)el.removeAttribute('title');
      }else if(el.hasAttribute('data-choice-hint')){
        el.removeAttribute('data-choice-hint');
        var old=el.getAttribute('data-choice-tabindex');
        if(old)el.setAttribute('tabindex',old);else el.removeAttribute('tabindex');
        el.removeAttribute('data-choice-tabindex');
      }
    });
    if(active){if(!active.isConnected||!disabled(active)||!note(active))hide();else{var message=note(active);if(tip.textContent!==message)tip.textContent=message;position();}}
  }
  function target(event){return event.target.closest(selector);}
  document.addEventListener('pointerover',function(e){var el=target(e);if(el&&disabled(el))show(el);});
  document.addEventListener('pointerout',function(e){if(active&&!active.contains(e.relatedTarget)&&!tip.contains(e.relatedTarget))timer=setTimeout(hide,180);});
  tip.addEventListener('pointerenter',function(){clearTimeout(timer);});tip.addEventListener('pointerleave',hide);
  document.addEventListener('focusin',function(e){var el=target(e);if(el&&disabled(el))show(el);else if(active)hide();});
  document.addEventListener('focusout',function(e){if(active&&!active.contains(e.relatedTarget))hide();});
  document.addEventListener('click',function(e){var el=target(e);if(el&&disabled(el)){e.preventDefault();e.stopPropagation();show(el);}else if(active&&!tip.contains(e.target))hide();},true);
  document.addEventListener('keydown',function(e){if(e.key==='Escape')hide();});
  window.addEventListener('resize',hide);window.addEventListener('scroll',hide,true);
  new MutationObserver(function(){if(!scheduled){scheduled=true;requestAnimationFrame(sync);}}).observe(document.body,{subtree:true,childList:true,attributes:true,attributeFilter:['aria-disabled','disabled','title']});
  sync();
}());

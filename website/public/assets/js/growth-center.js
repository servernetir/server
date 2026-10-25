'use strict';
// Ordinary HTML forms remain usable when JavaScript is unavailable.
document.querySelectorAll('.gc form').forEach(function(form){
  form.addEventListener('submit',function(){
    if(!form.checkValidity())return;
    form.setAttribute('aria-busy','true');
    setTimeout(function(){form.querySelectorAll('button').forEach(function(button){button.disabled=true;});},0);
  });
});
window.addEventListener('pageshow',function(){
  document.querySelectorAll('.gc form').forEach(function(form){form.removeAttribute('aria-busy');form.querySelectorAll('button').forEach(function(button){button.disabled=false;});});
});

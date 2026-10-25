(async function(){
var results=[],errors=[];window.addEventListener('error',function(e){errors.push(e.message);});
function check(value,message){if(!value)throw Error(message);results.push(message);}
function wait(fn){return new Promise(function(resolve,reject){var n=0;function poll(){if(fn())return resolve();if(n++>500) return reject(Error('timeout'));setTimeout(poll,20);}poll();});}
function input(name){return document.querySelector('[name="'+name+'"]');}
function button(name){return input(name).previousElementSibling;}
function click(selector){var e=document.querySelector(selector);if(!e)throw Error('missing '+selector);e.click();}
function change(selector,value){var e=document.querySelector(selector);e.value=value;e.dispatchEvent(new Event('change',{bubbles:true}));}
try{
await wait(function(){return document.querySelectorAll('.jd-btn').length===5;});
check(button('from').textContent.includes('1405')||button('from').textContent.includes('۱۴۰۵'),'Initial value is Jalali');
button('from').click();await wait(function(){return document.querySelector('.jd-month');});
check(document.querySelector('.jd-month').value==='8','Opens the selected month');
click('[aria-label="ماه قبل"]');await wait(function(){return document.querySelector('.jd-month')?.value==='7';});
check(!!document.querySelector('.jd-pop'),'Previous month leaves popup open');
change('.jd-year','1403');await wait(function(){return document.querySelector('.jd-year')?.value==='1403';});
change('.jd-month','12');await wait(function(){return document.querySelector('.jd-month')?.value==='12';});
check(document.querySelectorAll('.jd-day').length===30,'Leap Esfand contains 30 days');
click('.jd-day[data-iso="2025-03-20"]');
check(input('from').value==='2025-03-20','Selection submits the canonical ISO date');
check(button('from').textContent==='۱۴۰۳/۱۲/۳۰','Selection label includes Jalali year and day');
button('from').click();await wait(function(){return document.querySelector('[data-jd-clear]');});click('[data-jd-clear]');
check(input('from').value==='','Optional filter clears');
var submitted=false;document.querySelector('#required').addEventListener('submit',function(e){submitted=!e.defaultPrevented;e.preventDefault();});
click('#submit-required');await wait(function(){return document.querySelector('.jd-month');});
check(!submitted,'Empty required date blocks submission');
click('.jd-day[data-iso="2026-10-25"]');
check(input('occurred_at').value==='2026-10-25','Required date accepts a valid selection');
var realFetch=window.fetch;window.fetch=function(){return Promise.reject(Error('offline fixture'));};
button('to').click();await wait(function(){return document.querySelector('.jd-err');});
check(document.querySelector('.jd-manual').type==='text'&&!document.querySelector('input[type="date"]'),'Failure fallback stays Jalali');
window.fetch=realFetch;Array.from(document.querySelectorAll('.jd-action')).find(function(e){return e.textContent==='تلاش دوباره';}).click();await wait(function(){return document.querySelector('.jd-month');});
var manual=document.querySelector('.jd-manual');manual.value='۱۴۰۵/۰۸/۰۲';manual.dispatchEvent(new Event('input',{bubbles:true}));
Array.from(document.querySelectorAll('.jd-action')).find(function(e){return e.textContent==='ثبت تاریخ';}).click();await wait(function(){return input('to').value==='2026-10-24';});
check(button('to').textContent==='۱۴۰۵/۰۸/۰۲','Manual Persian digits convert on the server');
button('flow_date').click();await wait(function(){return document.querySelector('.jd-month');});click('.jd-day[data-iso="2026-10-25"]');
document.querySelector('[data-flow-amount]').value='-1000';click('[data-flow-add]');
check(document.querySelector('[name="cash_flows"]').value==='1405/08/03,-1000','Cash-flow builder writes a Jalali dated flow');
button('next_due_at').click();await wait(function(){return document.querySelector('.jd-month');});
check(document.querySelector('.jd-day[data-iso="2026-10-24"]').disabled,'Existing service minimum date is enforced');
check(document.querySelector('.jd-day[data-iso="2026-10-28"]').disabled,'Maximum date is enforced');
click('.jd-day[data-iso="2026-10-26"]');check(input('next_due_at').value==='2026-10-26','Existing hidden service date remains compatible');
button('from').click();await wait(function(){return document.querySelector('.jd-month');});
var rect=document.querySelector('.jd-pop').getBoundingClientRect();
results.push('Viewport width '+innerWidth);
check(rect.left>=0&&rect.right<=innerWidth&&rect.top>=0&&rect.bottom<=innerHeight+1,'Popup fits the viewport');
check(errors.length===0,'No browser JavaScript errors');
document.documentElement.dataset.qa='passed';parent.document.getElementById('qa-result').textContent=JSON.stringify({status:'passed',checks:results});
}catch(e){document.documentElement.dataset.qa='failed';parent.document.getElementById('qa-result').textContent=JSON.stringify({status:'failed',error:e.message,checks:results,errors:errors});}
})();

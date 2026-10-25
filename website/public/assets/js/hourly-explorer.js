/* Progressive enhancement; public content and order links work without JS. */
(function (root, factory) {
  'use strict';
  var api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  else api.init(root.document, root);
})(typeof window === 'undefined' ? {} : window, function () {
  'use strict';
  function hours(value) {
    if (typeof value !== 'number' && typeof value !== 'string') return null;
    if (String(value).trim() === '') return null;
    var n = Number(value);
    return Number.isInteger(n) && n >= 1 && n <= 720 ? n : null;
  }
  function quote(plan, duration, minHours) {
    var h = hours(duration), rate = Number(plan.hourly), monthly = Number(plan.monthly);
    if (!Number.isSafeInteger(rate) || rate <= 0 || !Number.isSafeInteger(minHours) || minHours < 1) return null;
    return {total: h === null ? null : rate * h, credit: rate * minHours, monthly: monthly > 0 ? monthly : null, breakEven: monthly > 0 ? monthly / rate : null};
  }
  function matches(plan, country, ram) { return (!country || plan.country === country) && plan.ram >= Number(ram || 0); }
  function recommend(plans, ram, cpu) {
    return plans.filter(function (p) { return p.ram >= ram && p.cpu >= cpu && p.hourly > 0; }).sort(function (a, b) { return a.hourly - b.hourly || a.id - b.id; })[0] || null;
  }
  function normalize(value) { return String(value).normalize('NFKC').replace(/ي/g, 'ی').replace(/ك/g, 'ک').replace(/[\u200c\u200d]/g, '').trim().toLowerCase(); }
  function init(d, w) {
    if (!d || d.documentElement.dataset.hourlyExplorerReady) return;
    var configNode = d.getElementById('hourly-explorer-config');
    if (!configNode) return;
    var cfg;
    try { cfg = JSON.parse(configNode.textContent); } catch (_) { return; }
    if (!Array.isArray(cfg.plans) || !cfg.text) return;
    var plans = cfg.plans, text = cfg.text, min = cfg.minHours, state = {hours:24, selected:[]};
    var byId = new Map(plans.map(function (p) { return [String(p.id), p]; }));
    var get = function (id) { return d.getElementById(id); };
    var selector = get('hourly-plan'), number = get('hourly-hours'), range = get('hourly-range'), country = get('hourly-country'), ram = get('hourly-ram');
    var formatNumber = new Intl.NumberFormat(cfg.locale === 'fa' ? 'fa-IR' : cfg.locale === 'tr' ? 'tr-TR' : 'en-GB', {maximumFractionDigits:1});
    var moneyFormat = new Intl.NumberFormat(cfg.locale === 'fa' ? 'fa-IR' : cfg.locale === 'tr' ? 'tr-TR' : 'en-GB', {minimumFractionDigits:cfg.minor === 1 ? 0 : 2, maximumFractionDigits:cfg.minor === 1 ? 0 : 2});
    function money(n) { return n === null ? '—' : cfg.minor === 1 ? moneyFormat.format(n) + ' تومان' : '€' + moneyFormat.format(n / cfg.minor); }
    function track(action) {
      if (w.ServerNetAnalytics && typeof w.ServerNetAnalytics.track === 'function') w.ServerNetAnalytics.track('hourly_vps_interaction', {interaction_type:action, content_language:cfg.locale, product_line:'hourly_vps'});
    }
    function renderQuote() {
      if (!selector) return;
      var p = byId.get(selector.value), q = p && quote(p, state.hours, min);
      if (!q) return;
      get('hourly-total').textContent = money(q.total);
      get('hourly-credit').textContent = money(q.credit);
      get('hourly-monthly').textContent = money(q.monthly);
      get('hourly-monthly-row').hidden = q.monthly === null;
      get('hourly-break-row').hidden = q.breakEven === null;
      get('hourly-break-note').hidden = q.breakEven === null;
      get('hourly-break').textContent = q.breakEven === null ? '—' : formatNumber.format(q.breakEven) + ' ' + text.hours;
      var order = get('hourly-order');
      if (state.hours === null) { order.removeAttribute('href'); order.setAttribute('aria-disabled','true'); }
      else { order.href = p.url; order.removeAttribute('aria-disabled'); }
      d.querySelectorAll('[data-hourly-preset]').forEach(function (b) { b.setAttribute('aria-pressed',String(Number(b.dataset.hourlyPreset) === state.hours)); });
      renderComparison();
    }
    function setHours(value) {
      state.hours = hours(value);
      var invalid = state.hours === null;
      get('hourly-hours-error').hidden = !invalid;
      number.setAttribute('aria-invalid', String(invalid));
      if (!invalid) { number.value = String(state.hours); range.value = String(state.hours); }
      renderQuote();
    }
    function filterPlans() {
      if (!country || !ram) return;
      var count = 0;
      d.querySelectorAll('[data-hourly-plan]').forEach(function (card) {
        var p = byId.get(card.dataset.hourlyPlan);
        card.hidden = !p || !matches(p, country.value, ram.value);
        if (!card.hidden) count++;
      });
      get('hourly-plan-status').textContent = count ? formatNumber.format(count) + ' ' + text.results : text.no_match;
    }
    function el(tag, value) { var e = d.createElement(tag); if (value !== undefined) e.textContent = value; return e; }
    function renderComparison() {
      var target = get('hourly-compare-cards');
      if (!target) return;
      target.replaceChildren();
      get('hourly-compare-status').textContent = state.selected.length ? formatNumber.format(state.selected.length) + ' ' + text.results : text.compare_empty;
      state.selected.forEach(function (id) {
        var p = byId.get(id), q = quote(p, state.hours, min), card = el('article');
        card.append(el('h3',p.name),el('p',p.city + ' · ' + p.cpu + ' vCPU · ' + (p.ram/1024) + ' GB RAM · ' + p.disk + ' ' + p.diskType));
        var list = el('dl');
        [[text.price,money(p.hourly)],[text.estimate,money(q.total)],[text.monthly,money(q.monthly)]].forEach(function (row) { var line=el('div');line.append(el('dt',row[0]),el('dd',row[1]));list.append(line); });
        card.append(list);
        var link = el('a',text.choose); link.href=p.url;link.rel='nofollow';link.className='hv-buy';card.append(link);
        var remove = el('button',text.remove);remove.type='button';remove.setAttribute('aria-label',text.remove+' · '+p.name+' · '+p.city);remove.addEventListener('click',function () { state.selected=state.selected.filter(function (x) { return x!==id; }); var box=d.querySelector('[data-hourly-compare="'+id+'"]');if(box)box.checked=false;renderComparison();var next=d.querySelector('[data-hourly-compare="'+id+'"]');if(next&&!next.closest('[hidden]'))next.focus(); });card.append(remove);target.append(card);
      });
    }
    if (selector) {
      selector.addEventListener('change',function () {renderQuote();track('plan_select');});
      number.addEventListener('input',function () {setHours(number.value);});
      range.addEventListener('input',function () {setHours(range.value);});
      number.addEventListener('change',function () {if(state.hours!==null)track('duration_change');});
      range.addEventListener('change',function () {track('duration_change');});
      d.querySelectorAll('[data-hourly-preset]').forEach(function (b) { b.addEventListener('click',function () {setHours(b.dataset.hourlyPreset);track('duration_preset');}); });
      d.querySelectorAll('[data-hourly-scenario]').forEach(function (b) { b.addEventListener('click',function () {
        var p = recommend(plans, Number(b.dataset.ram), Number(b.dataset.cpu));
        d.querySelectorAll('[data-hourly-scenario]').forEach(function (s) {s.setAttribute('aria-pressed',String(s===b));});
        get('hourly-suggestion').textContent = p ? text.suggested + ' ' + p.city + ' · ' + p.name : text.no_match;
        if (p) {selector.value=String(p.id);renderQuote();}
        track('use_case');
      }); });
    }
    if (country && ram) {
      country.addEventListener('change',function () {filterPlans();track('country_filter');});
      ram.addEventListener('change',function () {filterPlans();track('resource_filter');});
      get('hourly-reset').addEventListener('click',function () {country.value='';ram.value='0';filterPlans();});
    }
    var search = get('hourly-country-search');
    if (search) search.addEventListener('input',function () {
      var query=normalize(search.value), count=0;
      d.querySelectorAll('[data-hourly-country]').forEach(function (row) {row.hidden=!normalize(row.dataset.hourlyCountry).includes(query);if(!row.hidden)count++;});
      get('hourly-country-status').textContent = count ? formatNumber.format(count) : text.no_country;
    });
    d.querySelectorAll('[data-hourly-compare]').forEach(function (box) { box.addEventListener('change',function () {
      var id=box.dataset.hourlyCompare;
      if (!byId.has(id)) {box.checked=false;return;}
      if (box.checked && state.selected.length>=3) {box.checked=false;get('hourly-compare-status').textContent=text.compare_limit;return;}
      state.selected=state.selected.filter(function (x) {return x!==id;});
      if (box.checked) state.selected.push(id);
      renderComparison();track('plan_compare');
    }); });
    d.querySelectorAll('[data-hourly-enhance]').forEach(function (e) {e.hidden=false;});
    d.documentElement.dataset.hourlyExplorerReady='true';
    renderQuote();filterPlans();
  }
  return {hours:hours,quote:quote,matches:matches,recommend:recommend,normalize:normalize,init:init};
});

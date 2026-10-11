/* All locations are server rendered; filtering is optional and never paginated. */
(function () {
  'use strict';
  document.querySelectorAll('[data-server-menu]').forEach(function (menu) {
    var buttons = Array.from(menu.querySelectorAll('[data-server-type]'));
    var panels = Array.from(menu.querySelectorAll('[data-server-panel]'));
    var search = menu.querySelector('[data-server-search]');
    var hourly = menu.querySelector('.sm-hourly');
    function select(type) {
      buttons.forEach(function (b) { b.setAttribute('aria-pressed', String(b.dataset.serverType === type)); });
      panels.forEach(function (p) { p.hidden = p.dataset.serverPanel !== type; });
      if (hourly) hourly.hidden = type !== 'cloud';
    }
    function normalize(value) { return value.toLocaleLowerCase().normalize('NFKD').replace(/[\u0300-\u036f]/g, '').replace(/ي/g, 'ی').replace(/ك/g, 'ک'); }
    buttons.forEach(function (b, index) {
      b.addEventListener('click', function () { select(b.dataset.serverType); });
      b.addEventListener('keydown', function (e) {
        if (['ArrowRight', 'ArrowLeft', 'Home', 'End'].indexOf(e.key) < 0) return;
        e.preventDefault();
        var next = e.key === 'Home' ? 0 : e.key === 'End' ? buttons.length - 1 : (index + (e.key === 'ArrowRight' ? 1 : -1) + buttons.length) % buttons.length;
        buttons[next].focus(); buttons[next].click();
      });
    });
    search.addEventListener('input', function () {
      var query = normalize(search.value.trim());
      panels.forEach(function (p) {
        var count = 0;
        p.querySelectorAll('[data-country]').forEach(function (a) {
          a.hidden = !normalize(a.dataset.countrySearch + ' ' + a.textContent).includes(query);
          if (!a.hidden) count++;
        });
        p.querySelector('.sm-empty').hidden = count > 0;
      });
    });
    menu.classList.add('sm-enhanced');
    if (buttons.length) select(buttons[0].dataset.serverType);
  });
})();

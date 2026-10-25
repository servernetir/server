(function () {
  'use strict';
  function init(root) {
    var panels = Array.from(root.querySelectorAll('fieldset'));
    var steps = Array.from(root.querySelectorAll('[data-steps] li'));
    var prev = root.querySelector('[data-prev]'), next = root.querySelector('[data-next]'), save = root.querySelector('[data-save]');
    var index = 0;
    function show(n) {
      index = Math.max(0, Math.min(panels.length - 1, n));
      panels.forEach(function (panel, i) { panel.hidden = i !== index; });
      steps.forEach(function (step, i) { if (i === index) step.setAttribute('aria-current', 'step'); else step.removeAttribute('aria-current'); });
      prev.hidden = index === 0; next.hidden = index === panels.length - 1; save.hidden = index !== panels.length - 1;
    }
    function invalid(panel) { return Array.from(panel.querySelectorAll('input,select,textarea')).find(function (input) { return !input.checkValidity(); }); }
    next.addEventListener('click', function () {
      var bad = invalid(panels[index]); if (bad) { bad.reportValidity(); return; }
      show(index + 1);
      panels[index].querySelector('legend').setAttribute('tabindex', '-1');
      panels[index].querySelector('legend').focus();
    });
    prev.addEventListener('click', function () { show(index - 1); });
    root.noValidate = true;
    root.addEventListener('submit', function (e) {
      for (var i = 0; i < panels.length; i++) {
        var bad = invalid(panels[i]);
        if (bad) { e.preventDefault(); show(i); bad.reportValidity(); return; }
      }
    });
    show(0);
  }
  if (typeof document !== 'undefined') document.querySelectorAll('[data-campaign-wizard]').forEach(init);
  if (typeof module !== 'undefined') module.exports = { init: init };
})();

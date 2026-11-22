(function () {
  'use strict';
  document.querySelectorAll('[data-studio-home]').forEach(function (root) {
    var modes = Array.from(root.querySelectorAll('[data-studio-mode]'));
    var panels = Array.from(root.querySelectorAll('[data-studio-panel]'));
    if (!modes.length || modes.length !== panels.length) return;
    modes.forEach(function (button) {
      button.hidden = false;
      button.addEventListener('click', function () {
        var selected = button.getAttribute('data-studio-mode');
        modes.forEach(function (mode) {
          mode.setAttribute('aria-pressed', String(mode === button));
        });
        panels.forEach(function (panel) {
          panel.hidden = panel.getAttribute('data-studio-panel') !== selected;
        });
      });
    });
  });
}());

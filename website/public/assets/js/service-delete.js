/* Progressive enhancement; the server also validates consent and ownership. */
(function () {
  'use strict';
  document.querySelectorAll('[data-service-delete]').forEach(function (dialog) {
    var form = dialog.querySelector('[data-delete-form]');
    var input = dialog.querySelector('[data-delete-input]');
    var phrase = dialog.querySelector('[data-delete-phrase]').textContent.trim();
    var submit = dialog.querySelector('[data-delete-submit]');
    var submitting = false;
    function matches() { return input.value === phrase; }
    function update() { submit.disabled = submitting || !matches(); }
    function cancel() {
      input.value = '';
      update();
      if (typeof dialog.close === 'function') dialog.close();
      else { dialog.removeAttribute('open'); dialog.hidden = true; }
    }
    dialog.querySelectorAll('[data-delete-cancel]').forEach(function (button) {
      button.addEventListener('click', function (event) { event.preventDefault(); cancel(); });
    });
    dialog.addEventListener('cancel', function (event) {
      event.preventDefault();
      cancel();
    });
    input.addEventListener('input', update);
    form.addEventListener('submit', function (event) {
      if (submitting || !matches()) {
        event.preventDefault();
        if (!submitting) input.focus();
        return;
      }
      submitting = true;
      update();
    });
    window.addEventListener('pageshow', function () { submitting = false; update(); });
    update();
    if (typeof dialog.showModal === 'function') {
      dialog.removeAttribute('open');
      dialog.showModal();
    }
    input.focus();
  });
})();

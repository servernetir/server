(() => {
  'use strict';
  for (const form of document.querySelectorAll('[data-madar-claim]')) {
    const select = form.querySelector('[name=mission_id]');
    const sync = () => {
      const option = select.selectedOptions[0];
      if (!option) return;
      const isPublic = option.dataset.public === '1';
      const disclosure = form.querySelector('[data-madar-disclosure]');
      disclosure.hidden = !isPublic;
      disclosure.querySelector('input').required = isPublic;
      if (!isPublic) disclosure.querySelector('input').checked = false;
      form.querySelector('[name=evidence_url]').required = isPublic;
      form.querySelector('[name=distribution_url]').required = option.dataset.distribution === '1';
      form.querySelector('[name=terms_version]').value = option.dataset.terms;
    };
    select.addEventListener('change', sync);
    sync();
  }
})();

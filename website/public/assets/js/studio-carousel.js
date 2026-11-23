(function () {
  'use strict';
  document.querySelectorAll('[data-hero-carousel]').forEach(function (root) {
    if (root.dataset.carouselReady) return;
    var slides = Array.from(root.querySelectorAll('[data-hero-slide]'));
    var selectors = Array.from(root.querySelectorAll('[data-hero-target]'));
    var toggle = root.querySelector('[data-hero-autoplay]');
    var announcement = root.querySelector('[data-hero-announcement]');
    if (slides.length < 2 || selectors.length !== slides.length || !toggle) return;
    if (selectors.some(function (button, i) { return button.dataset.heroTarget !== slides[i].dataset.heroSlide; })) return;
    var motion = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : {matches: false};
    var interval = Math.max(5000, Number(root.dataset.interval) || 5000);
    var active = 0, timer = null, transition = null;
    var explicitPause = motion.matches, focused = false, hovered = false, inView = true;
    root.style.setProperty('--hero-duration', interval + 'ms');
    root.dataset.carouselReady = 'true';

    function stopTimer() {
      if (timer !== null) window.clearTimeout(timer);
      timer = null;
      root.classList.remove('is-playing');
    }
    function schedule() {
      stopTimer();
      var paused = explicitPause || focused || hovered || !inView || document.hidden;
      toggle.setAttribute('aria-pressed', String(explicitPause));
      toggle.setAttribute('aria-label', toggle.dataset[explicitPause ? 'playLabel' : 'pauseLabel']);
      if (paused) return;
      root.classList.add('is-playing');
      timer = window.setTimeout(function () { show((active + 1) % slides.length, false); }, interval);
    }
    function show(index, manual) {
      active = index;
      slides.forEach(function (slide, i) {
        var selected = i === active;
        slide.classList.toggle('is-active', selected);
        slide.setAttribute('aria-hidden', String(!selected));
        slide.inert = !selected;
      });
      selectors.forEach(function (button, i) { button.setAttribute('aria-pressed', String(i === active)); });
      if (manual && announcement) announcement.textContent = selectors[active].getAttribute('aria-label') || selectors[active].textContent.trim();
      root.classList.remove('is-transitioning');
      if (transition !== null) window.clearTimeout(transition);
      if (!motion.matches) {
        // Restart the short light sweep only on a slide change.
        void root.offsetWidth;
        root.classList.add('is-transitioning');
        transition = window.setTimeout(function () { root.classList.remove('is-transitioning'); }, 1100);
      }
      schedule();
    }
    selectors.forEach(function (button, index) {
      button.addEventListener('click', function () {
        explicitPause = true;
        show(index, true);
      });
    });
    toggle.addEventListener('click', function () {
      explicitPause = !explicitPause;
      if (!explicitPause) { focused = false; hovered = false; }
      schedule();
    });
    root.addEventListener('focusin', function (event) { focused = event.target !== toggle; schedule(); });
    root.addEventListener('focusout', function (event) {
      if (!root.contains(event.relatedTarget)) { focused = false; schedule(); }
    });
    root.addEventListener('click', function (event) {
      if (event.target.closest('[data-studio-mode], [data-sn-region]')) { explicitPause = true; schedule(); }
    });
    root.addEventListener('pointerdown', function (event) {
      if (event.target.closest('[data-sn-globe]')) { explicitPause = true; schedule(); }
    });
    [root.querySelector('.sn-hero-controls'), root.querySelector('[data-studio-modes]')].forEach(function (area) {
      if (!area) return;
      area.addEventListener('pointerenter', function () { hovered = true; schedule(); });
      area.addEventListener('pointerleave', function () { hovered = false; schedule(); });
    });
    document.addEventListener('visibilitychange', schedule);
    if (motion.addEventListener) motion.addEventListener('change', function () {
      if (motion.matches) explicitPause = true;
      schedule();
    });
    if ('IntersectionObserver' in window) {
      new IntersectionObserver(function (entries) {
        inView = entries[0].isIntersecting;
        schedule();
      }, {threshold: 0}).observe(root);
    }
    // The infrastructure is always the first frame, including repeat visits.
    slides.forEach(function (slide, index) {
      slide.classList.toggle('is-active', index === 0);
      slide.setAttribute('aria-hidden', String(index !== 0));
      slide.inert = index !== 0;
    });
    schedule();
  });

  document.querySelectorAll('[data-studio-home]').forEach(function (root) {
    if (root.dataset.previewReady) return;
    var modes = Array.from(root.querySelectorAll('[data-studio-mode]'));
    var panels = Array.from(root.querySelectorAll('[data-studio-panel]'));
    if (!modes.length || modes.length !== panels.length) return;
    root.dataset.previewReady = 'true';
    modes.forEach(function (button) {
      button.hidden = false;
      button.addEventListener('click', function () {
        var selected = button.dataset.studioMode;
        modes.forEach(function (mode) { mode.setAttribute('aria-pressed', String(mode === button)); });
        panels.forEach(function (panel) { panel.hidden = panel.dataset.studioPanel !== selected; });
      });
    });
  });
}());

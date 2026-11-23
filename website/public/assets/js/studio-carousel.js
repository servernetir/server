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
    var interval = Math.max(5000, Number(root.dataset.interval) || 6000);
    var active = 0, timer = null, resumeTimer = null;
    var explicitPause = motion.matches, interactionPaused = false, focused = false, hovered = false, inView = true;
    var activePointers = new Set();
    var interactionDelay = 2000;
    root.style.setProperty('--hero-duration', interval + 'ms');
    root.dataset.carouselReady = 'true';

    function stopTimer() {
      if (timer !== null) window.clearTimeout(timer);
      timer = null;
      root.classList.remove('is-playing');
    }
    function schedule() {
      stopTimer();
      var controlPaused = explicitPause || interactionPaused;
      var paused = controlPaused || activePointers.size || focused || hovered || !inView || document.hidden;
      root.toggleAttribute('data-hero-motion-away', !inView || document.hidden);
      root.toggleAttribute('data-hero-motion-still', explicitPause);
      toggle.setAttribute('aria-pressed', String(controlPaused));
      toggle.setAttribute('aria-label', toggle.dataset[controlPaused ? 'playLabel' : 'pauseLabel']);
      if (paused) return;
      root.classList.add('is-playing');
      timer = window.setTimeout(function () { show((active + 1) % slides.length, false); }, interval);
    }
    function clearInteraction() {
      if (resumeTimer !== null) window.clearTimeout(resumeTimer);
      resumeTimer = null;
      interactionPaused = false;
    }
    function pauseForInteraction() {
      clearInteraction();
      // A click must not be held indefinitely by the pointer resting over the
      // same control. A new interaction restarts the two-second idle period.
      hovered = false;
      interactionPaused = true;
      schedule();
      if (activePointers.size) return;
      resumeTimer = window.setTimeout(function () {
        resumeTimer = null;
        interactionPaused = false;
        schedule();
      }, interactionDelay);
    }
    function preparePartsMotion(slide) {
      var chassis = slide.querySelector('[data-parts-chassis-art]');
      if (!chassis || slide.dataset.partsArt) return;
      slide.dataset.partsArt = 'pending';
      function finish() {
        slide.dataset.partsArt = chassis.naturalWidth ? 'ready' : 'unavailable';
      }
      function loaded() {
        // Wait for the shared image to decode so the extraction never starts
        // behind an empty placeholder on a slow connection.
        if (typeof chassis.decode !== 'function') { finish(); return; }
        Promise.all(Array.from(slide.querySelectorAll('img[data-hero-image-src]')).map(function (img) {
          return img.decode().catch(function () {});
        })).then(finish);
      }
      if (chassis.complete) {
        if (chassis.naturalWidth) loaded(); else finish();
      } else {
        chassis.addEventListener('load', loaded, {once: true});
        chassis.addEventListener('error', finish, {once: true});
      }
    }
    function show(index, manual) {
      active = index;
      slides.forEach(function (slide, i) {
        var selected = i === active;
        slide.classList.toggle('is-active', selected);
        slide.setAttribute('aria-hidden', String(!selected));
        slide.inert = !selected;
        // Decorative artwork for later slides must not consume first-fold bandwidth.
        if (selected) {
          slide.querySelectorAll('img[data-hero-image-src]').forEach(function (img) {
            if (!img.getAttribute('src')) img.src = img.dataset.heroImageSrc;
          });
          if (slide.dataset.heroSlide === 'parts') preparePartsMotion(slide);
        }
      });
      selectors.forEach(function (button, i) { button.setAttribute('aria-pressed', String(i === active)); });
      if (manual && announcement) announcement.textContent = selectors[active].getAttribute('aria-label') || selectors[active].textContent.trim();
      // The overlapping slides fade via CSS; there is no moving wipe or delayed
      // focus state. Rapid selections and reduced motion use the same path.
      schedule();
    }
    selectors.forEach(function (button, index) {
      button.addEventListener('click', function () {
        show(index, true);
      });
    });
    toggle.addEventListener('click', function () {
      // The dedicated Pause control is a deliberate, lasting preference; Play
      // can also dismiss a pending temporary pause immediately.
      explicitPause = !(explicitPause || interactionPaused);
      clearInteraction();
      if (!explicitPause) { focused = false; hovered = false; }
      schedule();
    });
    root.addEventListener('focusin', function (event) {
      focused = event.target !== toggle && !activePointers.size && event.target.matches(':focus-visible');
      schedule();
    });
    root.addEventListener('focusout', function (event) {
      if (focused && !root.contains(event.relatedTarget)) { focused = false; schedule(); }
    });
    root.addEventListener('keydown', function (event) {
      // Keep actual keyboard exploration safe even after a preceding click.
      if (event.target !== toggle && !focused) { focused = true; schedule(); }
    });
    root.addEventListener('click', function (event) {
      if (toggle.contains(event.target)) return;
      if (event.detail > 0) focused = false;
      pauseForInteraction();
    }, true);
    root.addEventListener('pointerdown', function (event) {
      if (toggle.contains(event.target)) return;
      focused = false;
      activePointers.add(event.pointerId);
      pauseForInteraction();
    }, true);
    function releasePointer(event) {
      if (activePointers.delete(event.pointerId)) pauseForInteraction();
    }
    document.addEventListener('pointerup', releasePointer, true);
    document.addEventListener('pointercancel', releasePointer, true);
    root.addEventListener('lostpointercapture', releasePointer);
    window.addEventListener('blur', function () {
      if (activePointers.size) { activePointers.clear(); pauseForInteraction(); }
    });
    [root.querySelector('.sn-hero-controls'), root.querySelector('[data-studio-modes]'), root.querySelector('[data-parts-explore]')].forEach(function (area) {
      if (!area) return;
      area.addEventListener('pointerenter', function () {
        if (!interactionPaused) { hovered = true; schedule(); }
      });
      area.addEventListener('pointerleave', function () {
        if (hovered) { hovered = false; schedule(); }
      });
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

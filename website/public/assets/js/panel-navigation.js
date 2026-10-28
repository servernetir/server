/* Account navigation: a normal desktop sidebar and a modal mobile drawer. */
(function () {
  'use strict';

  var button = document.getElementById('pnl-menu');
  var side = document.getElementById('pnl-side');
  var backdrop = document.getElementById('pnl-bd');
  var closeButton = document.getElementById('pnl-close');
  if (!button || !side || !backdrop || !closeButton) return;

  var media = window.matchMedia('(max-width:1000px)');
  var opened = false, previousFocus = null, isolated = [];
  var previousOverflow = '', previousOverflowPriority = '';
  var original = {};
  ['role', 'aria-modal', 'aria-hidden', 'inert'].forEach(function (name) {
    original[name] = side.getAttribute(name);
  });

  function restoreAttribute(name) {
    if (original[name] === null) side.removeAttribute(name);
    else side.setAttribute(name, original[name]);
  }

  function visible(element) {
    return element && element.isConnected && !element.disabled &&
      !element.closest('[hidden],[inert]') &&
      element.getClientRects().length > 0 &&
      getComputedStyle(element).visibility !== 'hidden';
  }

  function focusable() {
    return Array.from(side.querySelectorAll(
      'a[href],button,input,select,textarea,[tabindex]'
    )).filter(function (element) {
      return element.tabIndex >= 0 && visible(element);
    });
  }

  function focusInside() {
    var target = visible(closeButton) ? closeButton : focusable()[0] || side;
    target.focus({preventScroll:true});
  }

  function recoverFocus() {
    if (opened && !side.contains(document.activeElement)) focusInside();
  }

  function focusMain() {
    var main = document.querySelector('.pnl-main');
    var target = main && (main.querySelector('h1') || main);
    if (!visible(target)) return;
    var temporary = !target.hasAttribute('tabindex');
    if (temporary) target.setAttribute('tabindex', '-1');
    target.focus({preventScroll:true});
    if (temporary) target.addEventListener('blur', function () {
      target.removeAttribute('tabindex');
    }, {once:true});
  }

  function isolateBackground() {
    isolated = [];
    var branch = side;
    while (branch && branch !== document.body) {
      var parent = branch.parentElement;
      if (!parent) break;
      Array.from(parent.children).forEach(function (element) {
        if (element === branch || element === backdrop ||
            element.contains(backdrop) ||
            /^(SCRIPT|STYLE|LINK)$/.test(element.tagName)) return;
        isolated.push([element, element.hasAttribute('inert')]);
        element.setAttribute('inert', '');
      });
      branch = parent;
    }
  }

  function releaseBackground() {
    isolated.forEach(function (entry) {
      if (!entry[1]) entry[0].removeAttribute('inert');
    });
    isolated = [];
    if (previousOverflow) {
      document.body.style.setProperty(
        'overflow', previousOverflow, previousOverflowPriority
      );
    } else {
      document.body.style.removeProperty('overflow');
    }
  }

  function closedSemantics() {
    restoreAttribute('role');
    restoreAttribute('aria-modal');
    if (media.matches) {
      side.setAttribute('aria-hidden', 'true');
      side.setAttribute('inert', '');
    } else {
      restoreAttribute('aria-hidden');
      restoreAttribute('inert');
    }
  }

  function close(restoreFocus) {
    if (!opened) return;
    opened = false;
    side.classList.remove('open');
    backdrop.classList.remove('open');
    button.setAttribute('aria-expanded', 'false');
    releaseBackground();

    if (restoreFocus !== false) {
      var target = visible(previousFocus) && previousFocus !== document.body &&
        previousFocus !== document.documentElement ? previousFocus : button;
      if (visible(target)) target.focus({preventScroll:true});
      if (side.contains(document.activeElement)) {
        if (visible(button)) button.focus({preventScroll:true});
        else focusMain();
      }
    } else if (side.contains(document.activeElement) &&
               !visible(document.activeElement)) {
      focusMain();
    }
    closedSemantics();
  }

  function open() {
    if (opened || !media.matches) return;
    previousFocus = document.activeElement;
    previousOverflow = document.body.style.getPropertyValue('overflow');
    previousOverflowPriority =
      document.body.style.getPropertyPriority('overflow');

    if (window.ServerNetSelects && typeof window.ServerNetSelects.close === 'function') {
      window.ServerNetSelects.close();
    }
    opened = true;
    side.removeAttribute('inert');
    side.setAttribute('aria-hidden', 'false');
    side.setAttribute('role', 'dialog');
    side.setAttribute('aria-modal', 'true');
    side.classList.add('open');
    backdrop.classList.add('open');
    button.setAttribute('aria-expanded', 'true');

    focusInside();
    isolateBackground();
    document.body.style.setProperty('overflow', 'hidden');
    requestAnimationFrame(recoverFocus);
  }

  function syncMode() {
    if (!media.matches && opened) close(false);
    if (!opened) {
      if (media.matches && side.contains(document.activeElement) &&
          visible(button)) button.focus({preventScroll:true});
      closedSemantics();
      if (!media.matches && side.contains(document.activeElement) &&
          !visible(document.activeElement)) focusMain();
    }
  }

  button.addEventListener('click', function () {
    if (opened) close(true);
    else open();
  });
  closeButton.addEventListener('click', function () { close(true); });
  backdrop.addEventListener('click', function () { close(true); });
  side.querySelectorAll('a[href]').forEach(function (link) {
    link.addEventListener('click', function () { close(true); });
  });
  document.addEventListener('keydown', function (event) {
    if (!opened) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      close(true);
      return;
    }
    if (event.key !== 'Tab') return;
    var items = focusable(), first = items[0], last = items[items.length - 1];
    if (!first) {
      event.preventDefault();
      side.focus({preventScroll:true});
    } else if (!side.contains(document.activeElement)) {
      event.preventDefault();
      (event.shiftKey ? last : first).focus();
    } else if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  });
  document.addEventListener('focusin', recoverFocus);
  side.addEventListener('transitionend', function (event) {
    if (event.target === side) recoverFocus();
  });
  if (media.addEventListener) media.addEventListener('change', syncMode);
  else media.addListener(syncMode);
  window.addEventListener('resize', syncMode, {passive:true});
  syncMode();
})();

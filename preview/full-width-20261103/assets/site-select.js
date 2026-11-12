/* Native fields own values/validation; the presentation stays out of the form payload. */
(function () {
  'use strict';
  var states = new Map(), active = null, sequence = 0, started = false;
  var excluded = '.cvb-image-version,.jd-month,.jd-year,[data-native-select],[data-snet-select="native"]';
  function eligible(select) {
    return select.tagName === 'SELECT' && !select.multiple && select.size <= 1 &&
      !select.matches(excluded) && !select.hidden &&
      !select.closest('.jd-pop,.jd-picker,[data-select-owner]');
  }
  function node(tag, cls, text) {
    var element = document.createElement(tag);
    if (cls) element.className = cls;
    if (text !== undefined) element.textContent = text;
    return element;
  }
  function disabled(option) {
    return option.disabled || (option.parentElement.tagName === 'OPTGROUP' && option.parentElement.disabled);
  }
  function visible(option) {
    return !option.hidden && !(option.parentElement.tagName === 'OPTGROUP' && option.parentElement.hidden);
  }
  function label(select) {
    var named = select.getAttribute('aria-label');
    if (named) return named;
    var labelled = select.getAttribute('aria-labelledby');
    if (labelled) return labelled.split(/\s+/).map(function(id) {
      var element = document.getElementById(id); return element ? element.textContent.trim() : '';
    }).join(' ').trim();
    return Array.from(select.labels || []).map(function(item) {
      var copy = item.cloneNode(true);
      copy.querySelectorAll('select,button,.sn-select,.sn-select-error,input,textarea').forEach(function(child) { child.remove(); });
      return copy.textContent.trim();
    }).filter(Boolean).join(' ') || select.getAttribute('title') || select.name || '';
  }
  function clearError(state) {
    if (state.error) state.error.remove();
    state.error = null;
    state.button.removeAttribute('aria-description');
    state.button.removeAttribute('title');
  }
  function close() {
    if (!active) return;
    var old = active; active = null;
    old.state.button.setAttribute('aria-expanded', 'false');
    old.state.button.removeAttribute('aria-activedescendant');
    old.state.button.classList.remove('is-open');
    old.menu.remove();
  }
  function focusOption(index, scroll) {
    if (!active || !active.items[index] || active.items[index].disabled) return;
    active.focused = index;
    active.items.forEach(function(item, i) { item.element.classList.toggle('is-focused', i === index); });
    var item = active.items[index].element;
    active.state.button.setAttribute('aria-activedescendant', item.id);
    if (scroll && typeof item.scrollIntoView === 'function') item.scrollIntoView({block:'nearest'});
  }
  function place() {
    if (!active) return;
    var rect = active.state.button.getBoundingClientRect(), viewport = window.visualViewport;
    var leftEdge = viewport ? viewport.offsetLeft : 0, topEdge = viewport ? viewport.offsetTop : 0;
    var width = viewport ? viewport.width : window.innerWidth, height = viewport ? viewport.height : window.innerHeight;
    if (!rect.width || rect.bottom <= topEdge || rect.top >= topEdge + height) { close(); return; }
    var popup = active.menu, below = topEdge + height - rect.bottom - 10, above = rect.top - topEdge - 10;
    var up = below < Math.min(280, popup.scrollHeight) && above > below;
    popup.style.width = Math.min(rect.width, Math.max(0, width - 16)) + 'px';
    popup.style.left = Math.max(leftEdge + 8, Math.min(rect.left, leftEdge + width - popup.offsetWidth - 8)) + 'px';
    popup.style.maxHeight = Math.max(0, Math.min(320, up ? above : below)) + 'px';
    popup.style.top = (up ? Math.max(topEdge + 8, rect.top - popup.offsetHeight - 5) : rect.bottom + 5) + 'px';
    popup.classList.toggle('opens-up', up);
  }
  function commit(index) {
    if (!active) return;
    var state = active.state, choice = active.items[index], select = state.select;
    if (!choice || choice.disabled || !visible(choice.option) || disabled(choice.option) ||
        select.matches(':disabled') || !select.isConnected) return;
    var changed = select.selectedIndex !== choice.index;
    close();
    select.selectedIndex = choice.index;
    clearError(state);
    refresh(select);
    state.button.focus({preventScroll:true});
    if (changed) {
      select.dispatchEvent(new Event('input', {bubbles:true}));
      select.dispatchEvent(new Event('change', {bubbles:true}));
    }
  }
  function open(state) {
    refresh(state.select);
    if (states.get(state.select) !== state || state.button.disabled || state.wrapper.hidden || state.select.closest('[hidden]')) return;
    state.button.focus({preventScroll:true});
    close();
    var popup = node('div', 'sn-select-menu'), items = [], lastGroup = null;
    popup.id = state.id + '-menu';
    popup.setAttribute('role', 'listbox');
    popup.setAttribute('aria-label', label(state.select));
    popup.dir = state.select.dir || getComputedStyle(state.select).direction;
    Array.from(state.select.options).forEach(function(option, index) {
      if (!visible(option)) return;
      var group = option.parentElement.tagName === 'OPTGROUP' ? option.parentElement : null;
      if (group && group !== lastGroup) popup.appendChild(node('div', 'sn-select-group', group.label));
      lastGroup = group;
      var item = node('button', 'sn-select-option');
      item.type = 'button'; item.tabIndex = -1; item.id = state.id + '-option-' + index;
      item.dataset.index = String(index);
      item.setAttribute('role', 'option');
      item.setAttribute('aria-selected', String(index === state.select.selectedIndex));
      item.setAttribute('aria-disabled', String(disabled(option)));
      var check = node('span', 'sn-select-check', '✓'); check.setAttribute('aria-hidden', 'true');
      var copy = node('span', 'sn-select-option-label', option.label); copy.dir = 'auto';
      item.appendChild(check); item.appendChild(copy);
      item.addEventListener('pointerdown', function(event) { event.preventDefault(); });
      var position = items.length;
      item.addEventListener('click', function() { commit(position); });
      item.addEventListener('pointermove', function() { if (active && active.state === state) focusOption(position, false); });
      items.push({element:item, option:option, index:index, disabled:disabled(option)});
      popup.appendChild(item);
    });
    if (!items.length) return;
    document.body.appendChild(popup);
    if (typeof popup.showPopover === 'function') {
      popup.setAttribute('popover', 'manual');
      try { popup.showPopover(); } catch (error) { popup.removeAttribute('popover'); }
    }
    active = {state:state, menu:popup, items:items, focused:-1};
    state.button.setAttribute('aria-expanded', 'true');
    state.button.classList.add('is-open');
    place();
    if (!active) return;
    var initial = items.findIndex(function(item) { return !item.disabled && item.index === state.select.selectedIndex; });
    if (initial < 0) initial = items.findIndex(function(item) { return !item.disabled; });
    if (initial >= 0) focusOption(initial, true);
  }
  function refresh(select) {
    var state = states.get(select);
    if (!state) return;
    if (select.multiple || select.size > 1 || select.matches(excluded)) { destroy(state); return; }
    if (select.validity.valid && state.error) clearError(state);
    var selected = select.options[select.selectedIndex];
    state.value.textContent = selected ? selected.label : '';
    state.button.disabled = select.matches(':disabled');
    state.button.dir = select.dir || getComputedStyle(select).direction;
    state.wrapper.hidden = select.hidden;
    var name = label(select);
    state.button.setAttribute('aria-label', name ? name + (selected ? ' — ' + selected.label : '') : state.value.textContent);
    state.button.setAttribute('aria-required', String(select.required));
    state.button.setAttribute('aria-invalid', String(select.getAttribute('aria-invalid') === 'true' || !!state.error));
    var descriptions = [select.getAttribute('aria-describedby') || '', state.error ? state.error.id : ''].filter(Boolean).join(' ');
    if (descriptions) state.button.setAttribute('aria-describedby', descriptions);
    else state.button.removeAttribute('aria-describedby');
    if (select.validity.valid && state.error) { clearError(state); state.button.setAttribute('aria-invalid', 'false'); }
    if (active && active.state === state && (state.button.disabled || select.hidden)) close();
  }
  function destroy(state) {
    if (active && active.state === state) close();
    state.observer.disconnect();
    state.select.classList.remove('sn-select-native');
    if (state.tabIndex === null) state.select.removeAttribute('tabindex');
    else state.select.setAttribute('tabindex', state.tabIndex);
    if (state.ariaHidden === null) state.select.removeAttribute('aria-hidden');
    else state.select.setAttribute('aria-hidden', state.ariaHidden);
    state.wrapper.replaceWith(state.select);
    states.delete(state.select);
  }
  function mount(select) {
    if (states.has(select) || !eligible(select)) return;
    var wrapper = node('span', 'sn-select'), button = node('button', 'sn-select-trigger');
    var text = node('span', 'sn-select-value'), arrow = node('span', 'sn-select-arrow');
    button.type = 'button'; arrow.setAttribute('aria-hidden', 'true'); text.dir = 'auto';
    arrow.appendChild(node('i')); arrow.appendChild(node('i'));
    button.appendChild(text); button.appendChild(arrow);
    var id = 'sn-select-' + (++sequence);
    button.id = id; button.setAttribute('role', 'combobox');
    button.setAttribute('aria-haspopup', 'listbox'); button.setAttribute('aria-expanded', 'false');
    button.setAttribute('aria-controls', id + '-menu');
    var state = {select:select, wrapper:wrapper, button:button, value:text, id:id,
      tabIndex:select.getAttribute('tabindex'), ariaHidden:select.getAttribute('aria-hidden'), error:null};
    if (select.style.maxWidth) wrapper.style.maxWidth = select.style.maxWidth;
    if (select.style.width) wrapper.style.width = select.style.width;
    select.parentNode.insertBefore(wrapper, select);
    wrapper.appendChild(select); wrapper.appendChild(button);
    select.classList.add('sn-select-native'); select.tabIndex = -1; select.setAttribute('aria-hidden', 'true');
    states.set(select, state);
    var buffer = '', lastKey = 0;
    button.addEventListener('click', function() {
      if (active && active.state === state) close(); else open(state);
    });
    button.addEventListener('focus', function() { refresh(select); });
    button.addEventListener('keydown', function(event) {
      var key = event.key, isOpen = active && active.state === state;
      if (key === 'Escape' || (event.altKey && key === 'ArrowUp')) { if (isOpen) { event.preventDefault(); close(); } return; }
      if (key === 'Tab') { if (isOpen) close(); return; }
      if (['ArrowDown','ArrowUp','Home','End','Enter',' '].includes(key)) {
        event.preventDefault();
        if (!isOpen) { open(state); if (key !== 'Home' && key !== 'End') return; }
        if (!active || active.state !== state) return;
        if (key === 'Enter' || key === ' ') { commit(active.focused); return; }
        var available = active.items.map(function(item, i) { return item.disabled ? -1 : i; }).filter(function(i) { return i >= 0; });
        if (!available.length) return;
        var at = available.indexOf(active.focused), next;
        if (key === 'Home') next = available[0];
        else if (key === 'End') next = available[available.length - 1];
        else next = available[(at + (key === 'ArrowUp' ? -1 : 1) + available.length) % available.length];
        focusOption(next, true); return;
      }
      if (key.length !== 1 || event.ctrlKey || event.metaKey || event.altKey) return;
      event.preventDefault();
      if (!isOpen) open(state);
      if (!active || active.state !== state) return;
      var now = Date.now();
      buffer = now - lastKey > 700 ? key : buffer + key; lastKey = now;
      var fold = function(value) { return value.normalize('NFKD').replace(/[\u0300-\u036f]/g, '').replace(/[يى]/g, 'ی').replace(/ك/g, 'ک').toLocaleLowerCase(); };
      var query = fold(buffer);
      if (Array.from(query).every(function(c) { return c === query[0]; })) query = query[0];
      var current = active.focused, found = -1;
      for (var n = 1; n <= active.items.length; n++) {
        var i = (current + n) % active.items.length, option = active.items[i];
        if (!option.disabled && fold(option.option.label).startsWith(query)) { found = i; break; }
      }
      if (found >= 0) focusOption(found, true);
    });
    select.addEventListener('change', function() { if (states.get(select) !== state) return; clearError(state); refresh(select); if (active && active.state === state) close(); });
    select.addEventListener('input', function() { if (states.get(select) === state) refresh(select); });
    select.addEventListener('focus', function() { if (states.get(select) === state) button.focus(); });
    select.addEventListener('invalid', function(event) {
      if (states.get(select) !== state) return;
      event.preventDefault(); close(); clearError(state);
      state.error = node('span', 'sn-select-error', select.validationMessage);
      state.error.id = id + '-error'; state.error.setAttribute('role', 'alert');
      wrapper.appendChild(state.error); refresh(select); button.focus();
    });
    state.observer = new MutationObserver(function() {
      var reopen = active && active.state === state;
      refresh(select);
      if (reopen && states.has(select) && !button.disabled && !select.hidden) open(state);
    });
    state.observer.observe(select, {subtree:true, childList:true, characterData:true, attributes:true,
      attributeFilter:['disabled','hidden','selected','label','value','required','aria-invalid','aria-describedby','dir','multiple','size']});
    refresh(select);
  }
  function enhance(root) {
    root = root || document;
    if (root.matches && root.matches('select')) mount(root);
    if (root.querySelectorAll) root.querySelectorAll('select').forEach(mount);
  }
  function start() {
    if (started || getComputedStyle(document.documentElement).getPropertyValue('--sn-select-ready').trim() !== '1') return;
    started = true; enhance(document);
    document.addEventListener('pointerdown', function(event) {
      if (active && !active.menu.contains(event.target) && !active.state.wrapper.contains(event.target)) close();
    }, true);
    document.addEventListener('focusin', function(event) {
      if (active && event.target !== active.state.button && !active.menu.contains(event.target)) close();
    });
    document.addEventListener('scroll', function(event) { if (active && !active.menu.contains(event.target)) close(); }, true);
    document.addEventListener('reset', function(event) {
      if (active && active.state.select.form === event.target) close();
      setTimeout(function() { states.forEach(function(state) { if (state.select.form === event.target) { clearError(state); refresh(state.select); } }); }, 0);
    }, true);
    document.addEventListener('toggle', function(event) {
      if (active && event.target.tagName === 'DETAILS' && !event.target.open && event.target.contains(active.state.button)) close();
    }, true);
    window.addEventListener('resize', place);
    if (window.visualViewport) { window.visualViewport.addEventListener('resize', place); window.visualViewport.addEventListener('scroll', place); }
    new MutationObserver(function(records) {
      records.forEach(function(record) {
        record.addedNodes.forEach(function(added) { if (added.nodeType === 1) enhance(added); });
        if (record.type === 'attributes' && record.target.tagName === 'FIELDSET') states.forEach(function(state) { refresh(state.select); });
      });
      states.forEach(function(state, select) {
        if (!select.isConnected) { if (active && active.state === state) close(); state.observer.disconnect(); states.delete(select); }
      });
    }).observe(document.body, {childList:true, subtree:true, attributes:true, attributeFilter:['disabled']});
  }
  window.ServerNetSelects = {enhance:enhance, refresh:refresh, close:close};
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, {once:true});
  else start();
})();
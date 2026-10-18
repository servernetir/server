/* The server remains the source of answers. No simulated responses or search progress. */
(() => {
  'use strict';
  const panel = document.querySelector('#chat-panel[data-morph-chat]');
  const fab = document.getElementById('chat-fab');
  const body = document.getElementById('chat-body');
  const form = document.getElementById('chat-form');
  const input = document.getElementById('chat-text');
  const status = document.getElementById('chat-status');
  if (!panel || !fab || !body || !form || !input || !status || panel.dataset.morphReady) return;
  panel.dataset.morphReady = 'true';
  const scroll = document.getElementById('chat-scroll');
  const welcome = document.getElementById('chat-welcome');
  const thinking = document.getElementById('chat-thinking');
  const label = document.getElementById('chat-think-label');
  const send = form.querySelector('button[type="submit"]');
  const copy = status.dataset;
  let busy = false, closeTimer, session;
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const scrollBottom = () => { scroll.scrollTop = scroll.scrollHeight; };
  const isOpen = () => fab.getAttribute('aria-expanded') === 'true';
  const ready = () => {
    send.toggleAttribute('data-ready', Boolean(input.value.trim()));
    input.dir = input.value.trim() ? 'auto' : panel.dir;
  };
  const phase = (value) => { panel.dataset.phase = value; };
  const announce = (text) => {
    if (label.textContent !== text) { label.textContent = text; status.textContent = text; }
  };
  function chatSession() {
    if (session) return session;
    try { session = sessionStorage.getItem('sn_chat_sid'); } catch (_) { /* Storage can be blocked. */ }
    if (!session) {
      session = 'web-' + (globalThis.crypto?.randomUUID?.() || Math.random().toString(36).slice(2) + Date.now().toString(36));
      try { sessionStorage.setItem('sn_chat_sid', session); } catch (_) { /* Keep it in memory. */ }
    }
    return session;
  }
  function open() {
    clearTimeout(closeTimer);
    panel.removeAttribute('inert');
    panel.classList.add('open');
    fab.setAttribute('aria-expanded', 'true');
    requestAnimationFrame(() => { if (isOpen()) panel.classList.add('in'); });
    (busy ? document.getElementById('chat-close') : input).focus({ preventScroll: true });
  }
  function close() {
    panel.classList.remove('in');
    panel.setAttribute('inert', '');
    fab.setAttribute('aria-expanded', 'false');
    fab.focus({ preventScroll: true });
    closeTimer = setTimeout(() => { if (!isOpen()) panel.classList.remove('open'); }, 280);
  }
  fab.addEventListener('click', () => isOpen() ? close() : open());
  document.getElementById('chat-close').addEventListener('click', close);
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && isOpen() && panel.contains(document.activeElement)) {
      event.preventDefault(); close();
    }
  });
  input.addEventListener('input', ready);
  function addMessage(text, who) {
    const message = document.createElement('div');
    message.className = 'chat-msg ' + who;
    message.dir = 'auto';
    message.textContent = text;
    body.appendChild(message);
    scrollBottom();
    return message;
  }
  function addActions(actions) {
    if (!Array.isArray(actions)) return;
    const wrap = document.createElement('div');
    wrap.className = 'chat-actions';
    for (const action of actions) {
      if (!action || typeof action.url !== 'string' || !action.url.trim() || typeof action.label !== 'string') continue;
      let url;
      try { url = new URL(action.url, location.href); } catch (_) { continue; }
      if (!['https:', 'http:', 'mailto:', 'tel:'].includes(url.protocol)) continue;
      const link = document.createElement('a');
      link.textContent = action.label;
      link.href = url.href;
      if (action.url.startsWith('#')) link.addEventListener('click', close);
      else { link.target = '_blank'; link.rel = 'noopener noreferrer'; }
      wrap.appendChild(link);
    }
    if (wrap.childElementCount) body.appendChild(wrap);
    scrollBottom();
  }
  async function submit(text, retry = false) {
    const message = text.trim();
    if (!message || message.length > input.maxLength || busy) return;
    busy = true;
    welcome.hidden = true;
    if (!retry) { addMessage(message, 'user'); input.value = ''; }
    ready();
    input.disabled = true;
    send.disabled = true;
    if (isOpen()) document.getElementById('chat-close').focus({ preventScroll: true });
    form.setAttribute('inert', '');
    panel.querySelectorAll('[data-chat-prompt]').forEach((button) => { button.disabled = true; });
    thinking.hidden = false;
    phase('thinking');
    announce(copy.thinking);
    const started = Date.now();
    // These are wait labels, never claims that a search/tool has run.
    const labels = setInterval(() => {
      const elapsed = Date.now() - started;
      announce(elapsed > 20000 ? copy.waiting : elapsed > 6500 ? copy.composing : copy.reviewing);
    }, 3300);
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 65000);
    try {
      const response = await fetch(body.dataset.endpoint, {
        method: 'POST', credentials: 'same-origin', signal: controller.signal,
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({ message, session: chatSession() }),
      });
      if (!response.ok) throw new Error('Chat HTTP ' + response.status);
      const data = await response.json();
      if (typeof data.reply !== 'string' || !data.reply.trim()) throw new Error('Empty chat response');
      clearInterval(labels);
      phase('resolve');
      announce(copy.done);
      addMessage(data.reply, 'bot');
      addActions(data.actions);
      if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        await new Promise((resolve) => setTimeout(resolve, 420));
      }
    } catch (_) {
      clearInterval(labels);
      const error = addMessage(body.dataset.error, 'bot error');
      const button = document.createElement('button');
      button.type = 'button'; button.className = 'chat-retry'; button.textContent = copy.retry;
      button.addEventListener('click', () => {
        if (!busy) { error.remove(); submit(message, true); }
      });
      error.appendChild(button);
      status.textContent = body.dataset.error;
    } finally {
      clearInterval(labels); clearTimeout(timeout);
      busy = false;
      input.disabled = false; send.disabled = false;
      form.removeAttribute('inert');
      thinking.hidden = true;
      phase('idle'); ready(); scrollBottom();
      panel.querySelectorAll('[data-chat-prompt]').forEach((button) => { button.disabled = false; });
      if (isOpen()) input.focus({ preventScroll: true });
    }
  }
  form.addEventListener('submit', (event) => { event.preventDefault(); submit(input.value); });
  input.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && (event.isComposing || event.shiftKey)) event.preventDefault();
  });
  panel.querySelectorAll('[data-chat-prompt]').forEach((button) => {
    button.addEventListener('click', () => submit(button.dataset.chatPrompt));
  });
  ready();
})();

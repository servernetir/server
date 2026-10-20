const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../../public/assets/js/service-delete.js'), 'utf8');
function widget(phrase, native = true) {
  const events = {}, pageEvents = {}, inputEvents = {}, formEvents = {};
  const input = { value: '', focus() { this.focused = true; }, addEventListener(k, f) { inputEvents[k] = f; } };
  const submit = { disabled: false };
  const form = { addEventListener(k, f) { formEvents[k] = f; } };
  const cancel = { addEventListener(k, f) { events.click = f; } };
  const dialog = {
    open: true,
    querySelector(s) { return { '[data-delete-form]': form, '[data-delete-input]': input, '[data-delete-phrase]': { textContent: phrase }, '[data-delete-submit]': submit }[s]; },
    querySelectorAll() { return [cancel]; },
    addEventListener(k, f) { events[k] = f; },
    removeAttribute(k) { if (k === 'open') this.open = false; }
  };
  if (native) { dialog.showModal = function () { this.open = true; this.modal = true; }; dialog.close = function () { this.open = false; }; }
  vm.runInNewContext(source, { document: { querySelectorAll: () => [dialog] }, window: { addEventListener(k, f) { pageEvents[k] = f; } } });
  const send = () => { const e = { prevented: false, preventDefault() { this.prevented = true; } }; formEvents.submit(e); return e; };
  return { input, submit, dialog, events, pageEvents, set(value) { input.value = value; inputEvents.input(); }, send };
}
for (const phrase of ['I understand that the money spent on this service will be lost', 'می‌دانم که حذف این سرویس دائمی است و هزینهٔ مصرف‌شده بازگردانده نمی‌شود.', 'Bu hizmet için harcanan tutarın iade edilmeyeceğini anlıyorum']) {
  test('exact consent gates submit: '+phrase, () => {
    const w = widget(phrase); assert.equal(w.submit.disabled, true); assert.equal(w.dialog.modal, true);
    w.set(phrase.slice(0, -1)); assert.equal(w.submit.disabled, true); assert.equal(w.send().prevented, true);
    w.set(phrase); assert.equal(w.submit.disabled, false); assert.equal(w.send().prevented, false);
    assert.equal(w.submit.disabled, true); assert.equal(w.send().prevented, true);
    w.pageEvents.pageshow(); assert.equal(w.submit.disabled, false);
    w.set(''); assert.equal(w.submit.disabled, true);
  });
}
test('cancel clears consent and closes; Escape uses the same behavior', () => {
  const w = widget('confirm'); w.set('confirm'); let prevented = false;
  w.events.cancel({ preventDefault() { prevented = true; } });
  assert.equal(prevented, true); assert.equal(w.dialog.open, false); assert.equal(w.input.value, ''); assert.equal(w.submit.disabled, true);
});
test('fallback keeps form usable and closes without the dialog API', () => {
  const w = widget('confirm', false); assert.equal(w.dialog.open, true); w.set('confirm'); assert.equal(w.submit.disabled, false);
  w.events.click({ preventDefault() {} }); assert.equal(w.dialog.open, false);
});

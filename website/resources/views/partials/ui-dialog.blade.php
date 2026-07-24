{{--
  دیالوگ و توستِ برنددارِ سرورنت — جایگزینِ alert()/confirm() زشتِ مرورگر.

  استفادهٔ اعلامی (بدون JS):
    <form ... data-confirm="متن سؤال" data-confirm-danger data-confirm-ok="بله">
    <a href="..." data-confirm="متن" data-confirm-danger>

  استفادهٔ برنامه‌ای:
    snConfirm('متن', {danger:true, title:'...', ok:'...'}).then(ok => { if(ok) ... })
    snToast('پیام', 'ok'|'err')

  یکبار در انتهای پوستهٔ پنل و ادمین include می‌شود؛ خودش DOM لازم را می‌سازد.
--}}
<style>
.sn-ov{position:fixed;inset:0;z-index:1000;display:flex;align-items:center;justify-content:center;padding:20px;
  background:rgba(3,5,12,.66);backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px);
  opacity:0;pointer-events:none;transition:opacity .18s}
.sn-ov.on{opacity:1;pointer-events:auto}
.sn-dlg{width:100%;max-width:400px;background:#111827;color:#e7edf7;border:1px solid rgba(255,255,255,.1);
  border-radius:18px;padding:24px 22px;box-shadow:0 26px 74px rgba(0,0,0,.6);text-align:center;font-family:inherit;
  transform:translateY(10px) scale(.96);transition:transform .2s ease}
.sn-ov.on .sn-dlg{transform:none}
html[data-theme="light"] .sn-dlg{background:#fff;color:#1a2233;border-color:rgba(15,23,42,.1)}
.sn-dlg-ic{width:54px;height:54px;border-radius:50%;display:grid;place-items:center;margin:0 auto 14px}
.sn-dlg-ic svg{width:26px;height:26px}
.sn-dlg.danger .sn-dlg-ic{background:rgba(255,107,107,.14);color:#ff6b6b}
.sn-dlg.ask .sn-dlg-ic{background:rgba(34,211,238,.14);color:#22d3ee}
.sn-dlg h3{margin:0 0 8px;font-size:16.5px;font-weight:800}
.sn-dlg p{margin:0 0 20px;font-size:13.5px;line-height:1.95;color:#96a3ba}
html[data-theme="light"] .sn-dlg p{color:#5b6577}
.sn-dlg-row{display:flex;gap:10px;flex-direction:row-reverse}
.sn-dlg-row button{flex:1;border:0;border-radius:12px;padding:12px;font:inherit;font-size:13.5px;font-weight:700;cursor:pointer;transition:filter .15s}
.sn-dlg-row button:hover{filter:brightness(1.08)}
.sn-b-ok{background:#22d3ee;color:#04121a}
.sn-dlg.danger .sn-b-ok{background:#ff6b6b;color:#180a0a}
.sn-b-no{background:rgba(255,255,255,.08);color:#e7edf7}
html[data-theme="light"] .sn-b-no{background:rgba(15,23,42,.06);color:#1a2233}
.sn-toasts{position:fixed;top:16px;left:50%;transform:translateX(-50%);z-index:1001;display:flex;flex-direction:column;
  gap:8px;pointer-events:none;width:calc(100% - 32px);max-width:380px}
.sn-toast{background:#111827;color:#e7edf7;border:1px solid rgba(255,255,255,.1);border-radius:13px;padding:12px 15px;
  font-size:13px;line-height:1.7;box-shadow:0 12px 34px rgba(0,0,0,.42);opacity:0;transform:translateY(-8px);transition:.22s}
.sn-toast.on{opacity:1;transform:none}
.sn-toast.ok{border-inline-start:3px solid #34d399}
.sn-toast.err{border-inline-start:3px solid #ff6b6b}
html[data-theme="light"] .sn-toast{background:#fff;color:#1a2233;border-color:rgba(15,23,42,.1)}
</style>
<script>
(function () {
  if (window.snConfirm) return;
  var ov, dlg, icon, h, p, ok, no, resolver;
  var ICON_WARN = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
  var ICON_ASK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';

  function build() {
    ov = document.createElement('div');
    ov.className = 'sn-ov';
    ov.innerHTML = '<div class="sn-dlg" role="dialog" aria-modal="true"><div class="sn-dlg-ic"></div>'
      + '<h3></h3><p></p><div class="sn-dlg-row"><button type="button" class="sn-b-ok"></button>'
      + '<button type="button" class="sn-b-no"></button></div></div>';
    document.body.appendChild(ov);
    dlg = ov.querySelector('.sn-dlg'); icon = ov.querySelector('.sn-dlg-ic');
    h = ov.querySelector('h3'); p = ov.querySelector('p');
    ok = ov.querySelector('.sn-b-ok'); no = ov.querySelector('.sn-b-no');
    ok.addEventListener('click', function () { close(true); });
    no.addEventListener('click', function () { close(false); });
    ov.addEventListener('click', function (e) { if (e.target === ov) close(false); });
    document.addEventListener('keydown', function (e) {
      if (ov.classList.contains('on') && e.key === 'Escape') close(false);
    });
  }
  function close(v) { ov.classList.remove('on'); var r = resolver; resolver = null; if (r) r(v); }

  window.snConfirm = function (message, opts) {
    opts = opts || {};
    if (!ov) build();
    var danger = !!opts.danger;
    dlg.className = 'sn-dlg ' + (danger ? 'danger' : 'ask');
    icon.innerHTML = danger ? ICON_WARN : ICON_ASK;
    h.textContent = opts.title || (danger ? 'تأیید عملیات' : 'تأیید');
    p.textContent = message || '';
    ok.textContent = opts.ok || (danger ? 'بله، انجام بده' : 'تأیید');
    no.textContent = opts.cancel || 'انصراف';
    ov.classList.add('on');
    setTimeout(function () { no.focus(); }, 30);
    return new Promise(function (res) { resolver = res; });
  };

  var tc;
  window.snToast = function (message, type) {
    if (!tc) { tc = document.createElement('div'); tc.className = 'sn-toasts'; document.body.appendChild(tc); }
    var t = document.createElement('div');
    t.className = 'sn-toast ' + (type || '');
    t.textContent = message;
    tc.appendChild(t);
    requestAnimationFrame(function () { t.classList.add('on'); });
    setTimeout(function () { t.classList.remove('on'); setTimeout(function () { t.remove(); }, 260); }, 4200);
  };

  // اتصالِ خودکار: هر فرمِ data-confirm پیش از ارسال، دیالوگ می‌گیرد
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (f && f.matches && f.matches('form[data-confirm]') && !f.__snok) {
      e.preventDefault();
      snConfirm(f.getAttribute('data-confirm'), {
        title: f.getAttribute('data-confirm-title') || undefined,
        ok: f.getAttribute('data-confirm-ok') || undefined,
        danger: f.hasAttribute('data-confirm-danger'),
      }).then(function (v) { if (v) { f.__snok = true; f.submit(); } });
    }
  }, true);

  // و هر لینکِ data-confirm
  document.addEventListener('click', function (e) {
    var a = e.target.closest ? e.target.closest('a[data-confirm]') : null;
    if (a) {
      e.preventDefault();
      snConfirm(a.getAttribute('data-confirm'), { danger: a.hasAttribute('data-confirm-danger') })
        .then(function (v) { if (v) window.location = a.href; });
    }
  }, true);
})();
</script>

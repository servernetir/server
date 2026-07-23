<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>آماده‌سازی دیتابیس — سرورنت</title>
<style>
  :root{--bg:#05070F;--card:#0d1220;--line:#1e2637;--text:#E6EAF3;--muted:#8A94AC;
        --cyan:#22D3EE;--ok:#34D399;--warn:#FBBF24;--danger:#F87171}
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--text);
       font-family:system-ui,'Segoe UI',Tahoma,sans-serif;line-height:1.9;padding:28px 16px}
  .wrap{max-width:760px;margin:0 auto}
  h1{font-size:21px;margin:0 0 6px}
  .sub{color:var(--muted);font-size:13.5px;margin-bottom:22px}
  .card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:20px;margin-bottom:16px}
  label{display:block;font-size:13px;color:var(--muted);margin-bottom:7px}
  input[type=password],input[type=text]{width:100%;background:#070b14;border:1px solid var(--line);
    border-radius:10px;color:var(--text);font-size:14px;padding:11px 13px;outline:none;font-family:inherit}
  input:focus{border-color:var(--cyan)}
  .steps{display:flex;gap:8px;flex-wrap:wrap;margin:16px 0 4px}
  button{background:#12203a;border:1px solid var(--line);border-radius:10px;color:var(--text);
    font-family:inherit;font-size:13.5px;padding:10px 16px;cursor:pointer;transition:.15s}
  button:hover:not(:disabled){border-color:var(--cyan);color:var(--cyan)}
  button:disabled{opacity:.45;cursor:not-allowed}
  button.go{background:linear-gradient(100deg,#3B82F6,#22D3EE);border-color:transparent;color:#fff;font-weight:600}
  pre{background:#070b14;border:1px solid var(--line);border-radius:12px;padding:16px;
      white-space:pre-wrap;word-break:break-word;font-size:12.8px;line-height:1.85;
      max-height:460px;overflow:auto;margin:0}
  .note{font-size:12.5px;color:var(--muted);margin-top:12px}
  .warn{color:var(--warn)}
  .order{font-size:13px;color:var(--muted);margin-top:14px}
  .order b{color:var(--text)}
</style>
</head>
<body>
<div class="wrap">

  <h1>آماده‌سازی دیتابیس MariaDB</h1>
  <p class="sub">
    این ابزار روی یک اتصال <b>جداگانه</b> کار می‌کند. تا وقتی خودتان
    <code>DB_CONNECTION</code> را عوض نکرده‌اید، سایت زنده دست‌نخورده می‌ماند.
  </p>

  <div class="card">
    <label for="tok">توکن (DEPLOY_TOKEN از فایل .env)</label>
    <input type="password" id="tok" autocomplete="off" spellcheck="false"
           placeholder="مقدار روبه‌روی DEPLOY_TOKEN را اینجا بچسبانید">

    <div class="steps">
      <button data-step="check"   class="go">۱ — بررسی اتصال</button>
      <button data-step="migrate">۲ — ساخت جدول‌ها</button>
      <button data-step="port">۳ — انتقال داده</button>
      <button data-step="verify">۴ — تأیید نهایی</button>
    </div>

    <p class="order">
      به همین ترتیب پیش بروید. <b>اگر گام ۱ خطا داد، جلوتر نروید.</b>
    </p>
    <p class="note warn">
      توکن با POST فرستاده می‌شود، پس در لاگ سرور و تاریخچهٔ مرورگر ثبت نمی‌شود.
    </p>
  </div>

  <div class="card">
    <pre id="out">آماده. توکن را بگذارید و گام ۱ را بزنید.</pre>
  </div>

</div>

<script>
(function () {
  var out = document.getElementById('out'),
      tok = document.getElementById('tok'),
      btns = document.querySelectorAll('[data-step]');

  function lock(on) { btns.forEach(function (b) { b.disabled = on; }); }

  async function run(step) {
    if (!tok.value.trim()) { out.textContent = 'اول توکن را وارد کنید.'; tok.focus(); return; }
    lock(true);
    out.textContent = 'در حال اجرای گام «' + step + '»…';
    try {
      var res = await fetch(@json(url('/system/setup')), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content
        },
        body: JSON.stringify({ token: tok.value.trim(), step: step })
      });
      var d = await res.json();
      out.textContent = d.output || d.message || '(خروجی خالی)';
    } catch (e) {
      out.textContent = 'ارتباط برقرار نشد: ' + e.message;
    } finally {
      lock(false);
    }
  }

  btns.forEach(function (b) {
    b.addEventListener('click', function () { run(b.dataset.step); });
  });
})();
</script>
</body>
</html>

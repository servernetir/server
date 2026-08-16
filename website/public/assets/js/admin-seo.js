/* ============================================================================
   /admin/seo — بررسی سایت و ارسال گزارش
   ============================================================================ */
(function () {
  'use strict';
  var SX = window.SX;
  if (!SX) return;

  function post(url, body) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': SX.csrf, 'Accept': 'application/json' },
      body: JSON.stringify(body || {}),
    }).then(function (r) {
      /* 🔴 روی /admin/* خطای اعتبارسنجی می‌تواند HTML باشد نه JSON (ریدایرکت).
         اگر کورکورانه r.json() بزنیم، پیامِ خطا می‌شود «Unexpected token <» که
         هیچ‌چیز به مدیر نمی‌گوید. */
      return r.text().then(function (t) {
        try { return { http: r.status, data: JSON.parse(t) }; }
        catch (e) { return { http: r.status, data: { ok: false, error: 'bad_response' } }; }
      });
    });
  }

  function say(el, msg, tone) {
    var n = document.getElementById(el);
    if (!n) return;
    n.textContent = msg;
    n.className = 'sx-status' + (tone ? ' ' + tone : '');
  }

  function busy(btn, on) {
    if (!btn) return;
    btn.disabled = !!on;
    btn.style.opacity = on ? '.6' : '';
  }

  /* ───────── گزارشِ ردشده‌ها ─────────
     🔴 سرور دلیل را **ساختاری** می‌دهد (`why`) و متنِ فارسی این‌جاست، کنارِ
     بقیهٔ متن‌های این صفحه. تا امروز فقط «رد شده: ۹۰» چاپ می‌شد که به مدیر
     هیچ نمی‌گفت — و ۹۰ سطرِ افتاده از یک فهرستِ ۲۵۰تایی دقیقاً همان چیزی است
     که باید ببیند، نه چیزی که باید حدس بزند. */
  var WHY = {
    unsub:  'قبلاً لغو اشتراک کرده',
    dup:    'از قبل در فهرست هست',
    nosite: 'ایمیل بود ولی سایتش معلوم نشد',
  };

  function showSkips(boxId, skipped, total) {
    var box = document.getElementById(boxId);
    if (!box) return;
    box.innerHTML = '';
    if (!skipped || !skipped.length) return;

    var groups = {};
    skipped.forEach(function (s) {
      (groups[s.why] = groups[s.why] || []).push(s.what);
    });

    var html = '';
    Object.keys(groups).forEach(function (why) {
      var items = groups[why];
      html += '<div class="sx-skip-g"><b>' + (WHY[why] || why) + ' — ' + items.length + ' ردیف</b>' +
              '<div class="sx-skip-l" dir="ltr">' + items.slice(0, 20).map(esc).join('، ') +
              (items.length > 20 ? ' …' : '') + '</div></div>';
    });
    if (total && total > skipped.length) {
      html += '<div class="sx-skip-g"><small>' + (total - skipped.length) + ' ردیفِ دیگر هم رد شد.</small></div>';
    }
    box.innerHTML = html;
  }

  function esc(s) {
    var d = document.createElement('span');
    d.textContent = String(s);
    return d.innerHTML;
  }

  /* ───────── ۱) ارسال به یک نفر ───────── */
  var oneBtn = document.getElementById('sx-send-one');
  oneBtn && oneBtn.addEventListener('click', function () {
    var url = (document.getElementById('sx-url').value || '').trim();
    var email = (document.getElementById('sx-email').value || '').trim();
    var note = (document.getElementById('sx-note').value || '').trim();
    if (!url || !email) { say('sx-one-status', 'آدرس سایت و ایمیل لازم است.', 'bad'); return; }

    busy(oneBtn, true);
    say('sx-one-status', 'در حال بررسی سایت… (چند ثانیه)');
    post(SX.urls.one, { url: url, email: email, note: note }).then(function (r) {
      busy(oneBtn, false);
      var d = r.data;
      if (d.ok) {
        say('sx-one-status', 'فرستاده شد — ' + d.host + ' · نمره ' + d.score, 'ok');
        document.getElementById('sx-note').value = '';
      } else if (d.error === 'unsubscribed') {
        say('sx-one-status', 'این نشانی قبلاً لغو اشتراک کرده؛ ارسال نشد.', 'bad');
      } else if (d.error === 'unreachable' || d.error === 'invalid_url') {
        say('sx-one-status', 'سایت در دسترس نبود یا آدرس معتبر نیست — چیزی فرستاده نشد.', 'bad');
      } else if (d.messages) {
        say('sx-one-status', d.messages.join(' · '), 'bad');
      } else {
        say('sx-one-status', 'ارسال نشد: ' + (d.detail || d.error || 'خطای نامشخص'), 'bad');
      }
    });
  });

  /* ───────── ۲‑الف) افزودن فهرست ───────── */
  var impBtn = document.getElementById('sx-import');
  impBtn && impBtn.addEventListener('click', function () {
    var list = (document.getElementById('sx-list').value || '').trim();
    if (!list) { say('sx-import-status', 'فهرست خالی است.', 'bad'); return; }

    busy(impBtn, true);
    say('sx-import-status', 'در حال خواندن فهرست…');
    showSkips('sx-import-detail', []);

    post(SX.urls.list, { list: list }).then(function (r) {
      busy(impBtn, false);
      var d = r.data;
      if (!d.ok) { say('sx-import-status', (d.messages || ['افزوده نشد']).join(' · '), 'bad'); return; }

      var msg = d.added + ' ردیف افزوده شد';
      if (d.found > d.added) msg += ' (از ' + d.found + ' جفتِ پیداشده)';
      if (d.over) msg += ' — به سقفِ ' + d.max + ' رسید، بقیه وارد نشد';
      say('sx-import-status', msg, d.added ? 'ok' : 'bad');
      showSkips('sx-import-detail', d.skipped, d.skippedTotal);

      /* 🔴 وقتی چیزی برای خواندن هست، صفحه را تازه **نمی‌کنیم** — بارگذاریِ
         دوباره همان گزارشی را که تازه ساختیم پاک می‌کند. جدولِ پایین با یک
         تازه‌سازیِ دستی می‌آید و مدیر خودش تصمیم می‌گیرد کِی. */
      if (d.added && !(d.skipped && d.skipped.length)) {
        setTimeout(function () { location.reload(); }, 900);
      }
    });
  });

  /* ───────── ۲‑الف‑۲) ساختِ فهرست از دیتای خودمان ───────── */
  var ownBtn = document.getElementById('sx-import-own');
  ownBtn && ownBtn.addEventListener('click', function () {
    busy(ownBtn, true);
    say('sx-own-status', 'در حال گشتن در دیتای خودتان…');
    post(SX.urls.listOwn, {}).then(function (r) {
      busy(ownBtn, false);
      var d = r.data;
      if (!d.ok) {
        say('sx-own-status', d.error === 'no_source'
          ? 'جدول دامنه‌ها روی این سرور نیست.'
          : (d.messages || ['ساخته نشد']).join(' · '), 'bad');
        return;
      }
      if (!d.added) {
        /* صفر بودن یک **یافته** است نه خطا: یعنی هر کسی که دامنه‌اش پیش ماست
           سرویس فعال هم دارد. گفتنش بهتر از یک پیام مبهم است. */
        say('sx-own-status', 'موردی پیدا نشد — از ' + d.candidates +
            ' دامنهٔ بررسی‌شده، همه صاحبشان سرویس فعال دارد یا قبلاً در فهرست است.', 'ok');
        return;
      }
      say('sx-own-status', d.added + ' مورد از دیتای خودتان اضافه شد. حالا «بررسی سایت‌های بررسی‌نشده» را بزنید.', 'ok');
      showSkips('sx-import-detail', d.skipped, d.skippedTotal);
      if (!(d.skipped && d.skipped.length)) {
        setTimeout(function () { location.reload(); }, 1400);
      }
    });
  });

  /* ───────── ۲‑ب) حلقهٔ بررسی ─────────
     یکی‌یکی، چون هر بررسی چند ثانیه است و یک درخواستِ طولانی پشتِ Cloudflare
     قطع می‌شود. حلقه این‌جاست تا پیشرفت دیده شود و قطع‌شدن فقط یک ردیف را
     عقب بیندازد. */
  var scanBtn = document.getElementById('sx-scan');
  var stop = false;

  scanBtn && scanBtn.addEventListener('click', function () {
    if (scanBtn.dataset.running === '1') { stop = true; return; }
    stop = false;
    scanBtn.dataset.running = '1';
    scanBtn.textContent = 'توقف بررسی';
    var n = 0;

    (function step() {
      if (stop) { finish(n); return; }
      post(SX.urls.scan, {}).then(function (r) {
        var d = r.data;
        if (!d.ok) { say('sx-bulk-status', 'خطا در بررسی.', 'bad'); finish(n); return; }
        if (d.done) { finish(n, true); return; }
        n++;
        var left = document.getElementById('sx-toscan');
        if (left) left.textContent = Math.max(0, (parseInt(left.textContent, 10) || 0) - 1);
        say('sx-bulk-status', 'بررسی شد: ' + d.host + (d.status === 'failed' ? ' — در دسترس نبود' : ' · نمره ' + d.score));
        step();
      });
    })();

    function finish(count, all) {
      scanBtn.dataset.running = '';
      scanBtn.textContent = 'بررسی سایت‌های بررسی‌نشده';
      say('sx-bulk-status', all ? (count + ' سایت بررسی شد. صفحه تازه می‌شود…') : (count + ' سایت بررسی شد (متوقف شد).'), 'ok');
      if (all && count) setTimeout(function () { location.reload(); }, 1200);
    }
  });

  /* ───────── انتخاب ───────── */
  var all = document.getElementById('sx-all');
  all && all.addEventListener('change', function () {
    document.querySelectorAll('.sx-pick:not(:disabled)').forEach(function (c) { c.checked = all.checked; });
  });

  function picked() {
    return Array.prototype.map.call(
      document.querySelectorAll('.sx-pick:checked'), function (c) { return parseInt(c.value, 10); }
    );
  }

  /* ───────── ۲‑ج) حلقهٔ ارسال ───────── */
  var sendBtn = document.getElementById('sx-send');
  sendBtn && sendBtn.addEventListener('click', function () {
    var ids = picked();
    if (!ids.length) { say('sx-bulk-status', 'هیچ ردیفی انتخاب نشده.', 'bad'); return; }
    if (!confirm('به ' + ids.length + ' نشانی ایمیل فرستاده می‌شود. ادامه؟')) return;

    busy(sendBtn, true);
    var sent = 0, failed = 0;

    (function step() {
      post(SX.urls.send, { ids: ids }).then(function (r) {
        var d = r.data;
        if (!d.ok) { say('sx-bulk-status', (d.messages || ['ارسال نشد']).join(' · '), 'bad'); busy(sendBtn, false); return; }
        if (d.done) {
          busy(sendBtn, false);
          say('sx-bulk-status', 'ارسال تمام شد — موفق: ' + sent + (failed ? ' · ناموفق: ' + failed : ''), 'ok');
          setTimeout(function () { location.reload(); }, 1200);
          return;
        }
        if (d.status === 'sent') sent++; else failed++;
        say('sx-bulk-status', d.email + ' → ' + (d.status === 'sent' ? 'فرستاده شد' : d.status));
        step();
      });
    })();
  });
})();

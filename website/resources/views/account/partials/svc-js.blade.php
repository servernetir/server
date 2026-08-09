{{--
  رفتارهای مشترکِ چهار صفحهٔ سرویس: نمایش/پنهانِ رمز، کپی با یک کلیک، و ویجتِ
  آمارِ زندهٔ WHM.

  ⚠️ استایلی این‌جا نیست — قاعدهٔ پروژه: CSS در panel.css تا نگهبانِ متغیرها
  ببیندش. این فایل فقط رفتار است.
--}}
<script>
document.querySelectorAll('.pw-eye').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var box = this.closest('.svc-pw'), mask = box.querySelector('.pw-mask'), val = box.querySelector('.pw-val');
    var show = val.hidden;
    val.hidden = !show; mask.hidden = show; this.textContent = this.getAttribute(show ? 'data-hide' : 'data-show');
  });
});
document.querySelectorAll('.copyable').forEach(function (el) {
  el.addEventListener('click', function () {
    var t = (this.textContent || '').trim();
    if (navigator.clipboard) navigator.clipboard.writeText(t);
    if (window.snToast) snToast(@json(__('ui.svc_copied')) + ' ✓', 'ok');
  });
});

// آمارِ زندهٔ هر سرویس را از پنل بگیر و نشان بده (بدونِ ورود به cPanel)
//
// 🔴 همهٔ رشته‌ها و ارقام از `window.T` می‌آیند، نه سخت‌کد.
// این ویجت تنها بخشِ صفحه بود که فارسیِ سخت‌کد داشت، پس مشتریِ انگلیسی/ترکی
// یک صفحهٔ کاملاً ترجمه‌شده می‌دید که وسطش یک کارتِ فارسی بود — و ارقامش هم
// به‌زور فارسی می‌شد. همان الگوی `window.T`ِ صفحهٔ سرورِ ابری.
(function () {
  var T = {
    disk:   @json(__('ui.svc_disk')),
    status: @json(__('ui.svc_status')),
    active: @json(__('ui.svc_on')),
    susp:   @json(__('ui.svc_suspended')),
    ip:     @json(__('ui.svc_ip')),
    unl:    @json(__('ui.svc_unlimited')),
    off:    @json(__('ui.svc_stats_off')),
    bw:     @json(__('ui.pt_traffic')),
    gb:     @json(__('ui.unit_gb')),
    mb:     @json(__('ui.unit_mb')),
    fa:     @json(app()->getLocale() === 'fa')
  };

  // رقمِ فارسی فقط برای فارسی — قبلاً بی‌قید اعمال می‌شد
  var n = function (x) {
    return T.fa ? String(x).replace(/[0-9]/g, function (g) { return '۰۱۲۳۴۵۶۷۸۹'[g]; }) : String(x);
  };
  var fmt = function (mb) {
    return mb >= 1024 ? n((mb / 1024).toFixed(mb >= 10240 ? 0 : 1)) + ' ' + T.gb : n(mb) + ' ' + T.mb;
  };
  var stat = function (label, value, extra) {
    return '<div class="svc-stat"><div class="lbl">' + label + '</div><div class="val">' + value + '</div>' + (extra || '') + '</div>';
  };

  document.querySelectorAll('.svc-usage').forEach(function (box) {
    var url = box.getAttribute('data-stats');
    if (!url) return;
    fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok) { box.innerHTML = '<div class="svc-usage-load">' + T.off + '</div>'; return; }

        var used = d.disk_used || 0, lim = d.disk_limit;
        var pct = lim ? Math.min(100, Math.round(used / lim * 100)) : null;
        var bar = pct !== null ? '<div class="svc-bar"><i class="' + (pct >= 85 ? 'hot' : '') + '" style="width:' + pct + '%"></i></div>' : '';
        var disk = fmt(used) + (lim ? ' / ' + fmt(lim) : ' (' + T.unl + ')') + (pct !== null ? ' · ' + n(pct) + (T.fa ? '٪' : '%') : '');

        var h = '<div class="svc-usage-grid">' + stat(T.disk, disk, bar);

        // پهنای‌باند: پرتکرارترین پرسشِ پشتیبانی. بایت → مگابایت.
        if (d.bw_used !== null && d.bw_used !== undefined) {
          var bu = Math.round(d.bw_used / 1048576), bl = d.bw_limit ? Math.round(d.bw_limit / 1048576) : null;
          var bp = bl ? Math.min(100, Math.round(bu / bl * 100)) : null;
          h += stat(T.bw, fmt(bu) + (bl ? ' / ' + fmt(bl) : ' (' + T.unl + ')'),
                bp !== null ? '<div class="svc-bar"><i class="' + (bp >= 85 ? 'hot' : '') + '" style="width:' + bp + '%"></i></div>' : '');
        }

        h += '<div class="svc-stat"><div class="lbl">' + T.status + '</div><div class="val ' + (d.suspended ? 'is-bad' : 'is-good') + '">'
           + (d.suspended ? T.susp : T.active) + '</div></div>';

        if (d.ip) {
          h += '<div class="svc-stat"><div class="lbl">' + T.ip + '</div><div class="val val-ip" dir="ltr">' + d.ip + '</div></div>';
        }

        box.innerHTML = h + '</div>';
      })
      .catch(function () { box.innerHTML = '<div class="svc-usage-load">' + T.off + '</div>'; });
  });
})();
</script>

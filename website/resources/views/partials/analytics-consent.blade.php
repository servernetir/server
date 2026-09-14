@if((bool) config('services.gtm.enabled') && preg_match('/^GTM-[A-Z0-9]+$/', (string) config('services.gtm.id')))
<aside id="analytics-consent" hidden role="dialog" aria-modal="false"
       aria-label="{{ app()->isLocale('fa') ? 'تنظیمات آمار بازدید' : 'Analytics preferences' }}"
       style="position:fixed;z-index:9999;inset-inline:18px;bottom:18px;max-width:620px;margin:auto;padding:16px 18px;border:1px solid var(--line);border-radius:14px;background:var(--surface);box-shadow:0 16px 50px #0008">
  <p style="margin:0 0 12px;line-height:1.8;color:var(--muted)">
    {{ app()->isLocale('fa')
        ? 'برای بهبود تجربه و سنجش عملکرد، فقط با اجازهٔ شما آمار ناشناس بازدید را ثبت می‌کنیم.'
        : 'With your permission, we use anonymous analytics to improve the service.' }}
  </p>
  <div style="display:flex;gap:10px;flex-wrap:wrap">
    <button type="button" class="btn btn-primary" data-analytics-consent="granted">{{ app()->isLocale('fa') ? 'می‌پذیرم' : 'Allow' }}</button>
    <button type="button" class="btn btn-ghost" data-analytics-consent="denied">{{ app()->isLocale('fa') ? 'فعلاً نه' : 'Not now' }}</button>
    <a href="{{ lroute('privacy') }}" style="align-self:center;color:var(--muted);font-size:12px">{{ app()->isLocale('fa') ? 'جزئیات حریم خصوصی' : 'Privacy details' }}</a>
  </div>
</aside>
<script>
(function(w,d){
  var box=d.getElementById('analytics-consent');if(!box)return;
  var decided=/(?:^|;\s*)snet_analytics_consent=(?:granted|denied)(?:;|$)/.test(d.cookie);
  if(!decided)box.hidden=false;
  box.addEventListener('click',function(e){
    var button=e.target.closest('[data-analytics-consent]');if(!button)return;
    w.ServerNetAnalyticsConsent.set(button.dataset.analyticsConsent);box.hidden=true;
  });
  w.addEventListener('servernet:analytics-open',function(){box.hidden=false;});
})(window,document);
</script>
@endif

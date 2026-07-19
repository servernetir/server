<div class="wt-fields" style="border:0;padding:0;margin-bottom:16px">
  <label class="wt-chk"><input type="checkbox" id="h-https" checked> {{ __('ui.wt_ht_https') }}</label>
  <label class="wt-chk"><input type="checkbox" id="h-www"> {{ __('ui.wt_ht_www') }}</label>
  <label class="wt-chk"><input type="checkbox" id="h-nowww" checked> {{ __('ui.wt_ht_nowww') }}</label>
  <label class="wt-chk"><input type="checkbox" id="h-slash"> {{ __('ui.wt_ht_slash') }}</label>
</div>
<div class="wt-pane">
  <label>{{ __('ui.wt_ht_pages') }}</label>
  <textarea id="h-pages" class="wt-ta" rows="4" dir="ltr" placeholder="/old-page  /new-page&#10;/blog/post-1  https://example.com/new"></textarea>
  <span class="wt-status">{{ __('ui.wt_ht_pages_hint') }}</span>
</div>
<div class="wt-pane" style="margin-top:16px">
  <label>.htaccess</label>
  <textarea id="h-out" class="wt-ta" rows="14" readonly dir="ltr"></textarea>
</div>
<div class="wt-bar">
  <button class="btn btn-glass" id="h-copy" data-done="{{ __('ui.wt_copied') }}">{{ __('ui.wt_copy') }}</button>
  <span class="wt-status err" id="h-warn"></span>
</div>
<script>
(function () {
  const $ = id => document.getElementById(id);
  const WARN = @json(__('ui.wt_ht_conflict'));
  function run(){
    const out=['RewriteEngine On',''];
    let warn='';
    if($('h-www').checked && $('h-nowww').checked) warn=WARN;

    if($('h-https').checked){
      out.push('# '+@json(__('ui.wt_ht_https')));
      out.push('RewriteCond %{HTTPS} off');
      out.push('RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]','');
    }
    if($('h-www').checked && !$('h-nowww').checked){
      out.push('# www');
      out.push('RewriteCond %{HTTP_HOST} !^www' + String.fromCharCode(92) + '. [NC]');
      out.push('RewriteRule ^(.*)$ https://www.%{HTTP_HOST}%{REQUEST_URI} [L,R=301]','');
    }
    if($('h-nowww').checked && !$('h-www').checked){
      out.push('# non-www');
      out.push('RewriteCond %{HTTP_HOST} ^www' + String.fromCharCode(92) + '.(.+)$ [NC]');
      out.push('RewriteRule ^(.*)$ https://%1%{REQUEST_URI} [L,R=301]','');
    }
    if($('h-slash').checked){
      out.push('# '+@json(__('ui.wt_ht_slash')));
      out.push('RewriteCond %{REQUEST_FILENAME} !-d');
      out.push('RewriteCond %{REQUEST_URI} (.+)/$');
      out.push('RewriteRule ^ %1 [L,R=301]','');
    }
    const pages=$('h-pages').value.split('\n').map(l=>l.trim()).filter(Boolean);
    if(pages.length){
      out.push('# '+@json(__('ui.wt_ht_pages')));
      pages.forEach(l=>{
        const p=l.split(/\s+/);
        if(p.length>=2) out.push('Redirect 301 '+p[0]+' '+p[1]);
      });
    }
    $('h-out').value=out.join('\n').replace(/\n{3,}/g,'\n\n').trim();
    $('h-warn').textContent=warn;
  }
  ['h-https','h-www','h-nowww','h-slash','h-pages'].forEach(id=>{
    $(id).addEventListener('input',run); $(id).addEventListener('change',run);
  });
  $('h-copy').onclick=e=>wtCopy(e.target,$('h-out').value);
  run();
})();
</script>

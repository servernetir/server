<div class="wt-pane">
  <label>{{ __('ui.wt_utm_url') }} <b style="color:var(--cyan)">*</b></label>
  <input type="text" id="v-u" class="wt-input-lg" dir="ltr" placeholder="https://servernet.cloud/hosting/wordpress">
</div>
<div class="wt-two" style="margin-top:14px">
  <div class="wt-pane"><label>utm_source <b style="color:var(--cyan)">*</b></label><input type="text" id="v-s" class="wt-input-lg" dir="ltr" placeholder="instagram"></div>
  <div class="wt-pane"><label>utm_medium <b style="color:var(--cyan)">*</b></label><input type="text" id="v-m" class="wt-input-lg" dir="ltr" placeholder="social"></div>
</div>
<div class="wt-two" style="margin-top:14px">
  <div class="wt-pane"><label>utm_campaign <b style="color:var(--cyan)">*</b></label><input type="text" id="v-c" class="wt-input-lg" dir="ltr" placeholder="nowruz-1405"></div>
  <div class="wt-pane"><label>utm_term</label><input type="text" id="v-t" class="wt-input-lg" dir="ltr"></div>
</div>
<div class="wt-pane" style="margin-top:14px"><label>utm_content</label><input type="text" id="v-n" class="wt-input-lg" dir="ltr"></div>
<div class="wt-pane" style="margin-top:16px">
  <label>{{ __('ui.wt_output') }}</label>
  <textarea id="v-out" class="wt-ta" rows="4" readonly dir="ltr"></textarea>
</div>
<div class="wt-bar">
  <button class="btn btn-glass" id="v-copy" data-done="{{ __('ui.wt_copied') }}">{{ __('ui.wt_copy') }}</button>
  <span class="wt-status" id="v-msg"></span>
</div>
<script>
(function () {
  const $ = id => document.getElementById(id);
  const L = { need: @json(__('ui.wt_utm_need')), bad: @json(__('ui.wt_utm_badurl')), ok: @json(__('ui.wt_utm_ready')) };
  const FIELDS = [['v-s','utm_source'],['v-m','utm_medium'],['v-c','utm_campaign'],['v-t','utm_term'],['v-n','utm_content']];

  function run() {
    const base = $('v-u').value.trim();
    const msg = $('v-msg');
    if (!base) { $('v-out').value=''; msg.textContent=''; msg.className='wt-status'; return; }

    let u;
    try { u = new URL(base); }
    catch (e) { $('v-out').value=''; msg.textContent=L.bad; msg.className='wt-status err'; return; }

    let missing = 0;
    FIELDS.forEach(([id, key], i) => {
      const v = $(id).value.trim();
      if (v) u.searchParams.set(key, v);
      else { u.searchParams.delete(key); if (i < 3) missing++; }
    });

    $('v-out').value = u.toString();
    if (missing) { msg.textContent = L.need; msg.className = 'wt-status err'; }
    else { msg.textContent = L.ok; msg.className = 'wt-status ok'; }
  }
  ['v-u','v-s','v-m','v-c','v-t','v-n'].forEach(id => $(id).addEventListener('input', run));
  $('v-copy').onclick = e => wtCopy(e.target, $('v-out').value);
})();
</script>

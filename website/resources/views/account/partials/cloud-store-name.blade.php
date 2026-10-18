<div class="cvx-name-fields" id="cvx-name-fields">
  @error('label')<div class="dm-note danger">{{ \App\Support\PanelText::text($message) }}</div>@enderror
  <label class="cvb-field" for="cvb-label">
    <span>{{ __('ui.cvb_label') }}</span>
    <input type="text" name="label" id="cvb-label" dir="ltr" value="{{ $curLabel }}" placeholder="{{ $autoLabel }}" maxlength="64" autocapitalize="off" autocomplete="off" spellcheck="false">
  </label>
  <p class="cvx-name-hint">{{ __('cloud-store.name_hint') }}</p>
</div>

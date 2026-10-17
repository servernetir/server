@if(($identityApplications ?? collect())->isNotEmpty())
<fieldset><legend>{{ __('identity.menu') }}</legend>
@foreach(\App\Models\CustomerApiToken::IDENTITY_ABILITIES as $ability=>$label)
<label class="sec-chk"><input type="checkbox" name="abilities[]" value="{{ $ability }}"><span><code dir="ltr">{{ $ability }}</code> — {{ __($label) }}</span></label>
@endforeach
<label>{{ __('identity.application') }}<select name="identity_application_id"><option value="">—</option>@foreach($identityApplications as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></label>
@if(!$aiProjects->contains('status','active'))
<label>{{ __('identity.daily_cap') }}<input type="number" name="daily_spend_cap_irt" min="1" max="9000000000000"></label>
@endif
<p>{{ __('identity.key_help') }}</p>
</fieldset>
@endif

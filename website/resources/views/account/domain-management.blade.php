@if(($pendingDomainManagement ?? collect())->isNotEmpty())
  <div class="pnl-alert" role="status">{{ __('domain-management.pending') }}</div>
@endif
@if(in_array('dnssec', $managementCaps ?? [], true))
<section class="pnl-sec">
  <div class="pnl-sec-h"><h2>{{ __('domain-management.dnssec') }}</h2></div>
  <div class="pnl-sec-b">
    <p>{{ __('domain-management.dnssec_note') }}</p>
    <form method="post" action="{{ lroute('account.domain.dnssec', $domain) }}">
      @csrf
      <label><input type="checkbox" name="enabled" value="1" @checked(!empty(data_get($domain->meta, 'dnssec_keys', [])))> {{ __('domain-management.enabled') }}</label>
      @for($i = 0; $i < 6; $i++)
        @php($key = data_get($domain->meta, 'dnssec_keys.'.$i, []))
        <fieldset style="margin-top:12px;border:1px solid var(--line);padding:12px">
          <label>{{ __('domain-management.record_type') }} <select name="keys[{{ $i }}][record_type]"><option value="DS">DS</option><option value="DNSKEY" @selected(($key['record_type'] ?? '') === 'DNSKEY')>DNSKEY</option></select></label>
          <label>{{ __('domain-management.tag') }} <input type="number" name="keys[{{ $i }}][tag]" min="0" max="65535" value="{{ $key['tag'] ?? '' }}"></label>
          <label>{{ __('domain-management.algorithm') }} <input type="number" name="keys[{{ $i }}][algorithm]" min="1" max="255" value="{{ $key['algorithm'] ?? '' }}"></label>
          <label>{{ __('domain-management.digest_type') }} <select name="keys[{{ $i }}][digest_type]"><option value="2">SHA-256</option><option value="4" @selected(($key['digest_type'] ?? null) === 4)>SHA-384</option><option value="1" @selected(($key['digest_type'] ?? null) === 1)>SHA-1</option></select></label>
          <label>{{ __('domain-management.digest') }} <input name="keys[{{ $i }}][digest]" dir="ltr" maxlength="96" value="{{ $key['digest'] ?? '' }}" autocomplete="off"></label>
          <label>{{ __('domain-management.flags') }} <select name="keys[{{ $i }}][flags]"><option value="257">257 (KSK)</option><option value="256" @selected(($key['flags'] ?? 257) === 256)>256 (ZSK)</option></select></label>
          <input type="hidden" name="keys[{{ $i }}][protocol]" value="3">
          <label>{{ __('domain-management.public_key') }} <input name="keys[{{ $i }}][public_key]" dir="ltr" maxlength="4096" value="{{ $key['public_key'] ?? '' }}" autocomplete="off"></label>
        </fieldset>
      @endfor
      <button class="pnl-btn" type="submit" style="margin-top:12px">{{ __('domain-management.save_dnssec') }}</button>
    </form>
  </div>
</section>
@endif
@if(in_array('contacts', $managementCaps ?? [], true) && ($verifiedContacts ?? collect())->isNotEmpty())
<section class="pnl-sec">
  <div class="pnl-sec-h"><h2>{{ __('domain-management.contacts') }}</h2></div>
  <div class="pnl-sec-b">
    <p>{{ __('domain-management.contacts_note') }}</p>
    <form method="post" action="{{ lroute('account.domain.contacts', $domain) }}">
      @csrf
      @foreach(['admin', 'tech', 'billing'] as $role)
        <label>{{ __('domain-management.'.$role) }}
          <select name="contacts[{{ $role }}]">
            <option value="">{{ __('domain-management.contact_default') }}</option>
            @foreach($verifiedContacts as $contact)
              @if(in_array($contact->role, [$role, 'registrant'], true))
                <option value="{{ $contact->id }}">{{ __('domain-management.contact') }} #{{ $contact->id }}</option>
              @endif
            @endforeach
          </select>
        </label>
      @endforeach
      <button class="pnl-btn" type="submit">{{ __('domain-management.save_contacts') }}</button>
    </form>
  </div>
</section>
@endif

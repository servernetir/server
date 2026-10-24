{{--
  فرمِ رکورد — مشترکِ «افزودن» و «ویرایش». ورودی: $zone، $types، $ttls، $formKey، $action، $rec (فقط ویرایش).
  old() فقط وقتی خوانده می‌شود که خطا مالِ همین فرم باشد (session dns_form).
  TXT فیلدِ جدای content_txt دارد: دو فیلدِ هم‌نام یعنی PHP آخری را می‌گیرد.
--}}
@php
  $mine = session('dns_form') === $formKey;
  $val = fn (string $f, $fallback = '') => $mine ? old($f, $fallback) : $fallback;
  $err = fn (string $f) => $mine && $errors->has($f) ? $errors->first($f) : null;

  $type = $mine ? old('type', $rec->type ?? 'A') : ($rec->type ?? 'A');
  $name = $rec ? \App\Services\Dns\DomainName::relative($rec->name, $zone->domain) : '';
  $content = $rec->content ?? '';
  $srv = ['weight' => '', 'port' => '', 'target' => ''];
  $caa = ['flags' => '0', 'tag' => 'issue', 'value' => ''];
  if ($rec && $rec->type === 'SRV') {
      $p = preg_split('~\s+~', trim($rec->content)) ?: [];
      $srv = ['weight' => $p[0] ?? '', 'port' => $p[1] ?? '', 'target' => $p[2] ?? ''];
  }
  if ($rec && $rec->type === 'CAA' && preg_match('~^(\d+)\s+(\w+)\s+"(.*)"$~', $rec->content, $m)) {
      $caa = ['flags' => $m[1], 'tag' => $m[2], 'value' => $m[3]];
  }
  $txt = $rec && $rec->type === 'TXT' ? \App\Services\Dns\TxtCodec::forEdit($rec->content) : '';
  $id = 'dnsf-'.$formKey;
  $labelMap = collect(['A', 'AAAA', 'CNAME', 'MX', 'NS'])->map(fn ($t) => $t.':'.__('dns.form.value_'.strtolower($t)))->implode('|');
  $phMap = 'A:185.10.20.30|AAAA:2a01:4f8::1|CNAME:target.example.net|MX:mail.'.$zone->domain.'|NS:ns1.example.net';
@endphp

<form method="POST" action="{{ $action }}" class="dns-form" data-dns-form>
  @csrf

  <div class="dns-f w2">
    <label for="{{ $id }}-type">{{ __('dns.form.type') }}</label>
    <select id="{{ $id }}-type" name="type" data-dns-type>
      @foreach($types as $t)
        <option value="{{ $t }}" @selected($type === $t)>{{ $t }}</option>
      @endforeach
    </select>
  </div>

  <div class="dns-f w4">
    <label for="{{ $id }}-name">{{ __('dns.form.name') }}</label>
    <div class="dns-suffix">
      <input id="{{ $id }}-name" class="dns-input dns-ltr {{ $err('name') ? 'is-bad' : '' }}" name="name"
             value="{{ $val('name', $name) }}" placeholder="@" autocomplete="off" spellcheck="false" dir="ltr">
      <span dir="ltr">.{{ $zone->domain }}</span>
    </div>
    <span class="dns-hint">{{ __('dns.form.name_hint') }}</span>
    @if($err('name'))<span class="dns-err" role="alert">{{ $err('name') }}</span>@endif
  </div>

  <div class="dns-f w4" data-types="A AAAA CNAME MX NS">
    <label for="{{ $id }}-content" data-label-for="{{ $labelMap }}">{{ __('dns.form.value') }}</label>
    <input id="{{ $id }}-content" class="dns-input dns-ltr {{ $err('content') && $type !== 'TXT' ? 'is-bad' : '' }}" name="content" dir="ltr"
           value="{{ $val('content', in_array($type, ['A', 'AAAA', 'CNAME', 'MX', 'NS'], true) ? $content : '') }}"
           autocomplete="off" spellcheck="false" data-ph="{{ $phMap }}">
    @if($err('content') && $type !== 'TXT')<span class="dns-err" role="alert">{{ $err('content') }}</span>@endif
  </div>

  <div class="dns-f w12" data-types="TXT">
    <label for="{{ $id }}-txt">{{ __('dns.form.txt_value') }}</label>
    <textarea id="{{ $id }}-txt" class="dns-ltr {{ $err('content') && $type === 'TXT' ? 'is-bad' : '' }}" name="content_txt" dir="ltr" spellcheck="false"
              placeholder="v=spf1 include:_spf.example.com ~all">{{ $val('content_txt', $txt) }}</textarea>
    <span class="dns-hint">{{ __('dns.form.txt_hint') }}</span>
    @if($err('content') && $type === 'TXT')<span class="dns-err" role="alert">{{ $err('content') }}</span>@endif
  </div>

  <div class="dns-f w2" data-types="MX SRV">
    <label for="{{ $id }}-prio">{{ __('dns.form.priority') }}</label>
    <input id="{{ $id }}-prio" class="dns-input dns-ltr {{ $err('prio') ? 'is-bad' : '' }}" name="prio" inputmode="numeric" dir="ltr" value="{{ $val('prio', $rec->prio ?? 10) }}">
    @if($err('prio'))<span class="dns-err" role="alert">{{ $err('prio') }}</span>@endif
  </div>

  <div class="dns-f w2" data-types="SRV">
    <label for="{{ $id }}-weight">{{ __('dns.form.weight') }}</label>
    <input id="{{ $id }}-weight" class="dns-input dns-ltr {{ $err('weight') ? 'is-bad' : '' }}" name="weight" inputmode="numeric" dir="ltr" value="{{ $val('weight', $srv['weight'] !== '' ? $srv['weight'] : 5) }}">
    @if($err('weight'))<span class="dns-err" role="alert">{{ $err('weight') }}</span>@endif
  </div>
  <div class="dns-f w2" data-types="SRV">
    <label for="{{ $id }}-port">{{ __('dns.form.port') }}</label>
    <input id="{{ $id }}-port" class="dns-input dns-ltr {{ $err('port') ? 'is-bad' : '' }}" name="port" inputmode="numeric" dir="ltr" value="{{ $val('port', $srv['port']) }}" placeholder="5060">
    @if($err('port'))<span class="dns-err" role="alert">{{ $err('port') }}</span>@endif
  </div>
  <div class="dns-f w4" data-types="SRV">
    <label for="{{ $id }}-target">{{ __('dns.form.target') }}</label>
    <input id="{{ $id }}-target" class="dns-input dns-ltr {{ $err('target') ? 'is-bad' : '' }}" name="target" dir="ltr" spellcheck="false" value="{{ $val('target', $srv['target']) }}" placeholder="sip.{{ $zone->domain }}">
    @if($err('target'))<span class="dns-err" role="alert">{{ $err('target') }}</span>@endif
  </div>

  <div class="dns-f w2" data-types="CAA">
    <label for="{{ $id }}-flags">{{ __('dns.form.flags') }}</label>
    <select id="{{ $id }}-flags" name="flags">
      @foreach(['0', '128'] as $f)<option value="{{ $f }}" @selected((string) $val('flags', $caa['flags']) === $f)>{{ $f }}</option>@endforeach
    </select>
  </div>
  <div class="dns-f w2" data-types="CAA">
    <label for="{{ $id }}-tag">{{ __('dns.form.tag') }}</label>
    <select id="{{ $id }}-tag" name="tag">
      @foreach(['issue', 'issuewild', 'iodef'] as $t)<option value="{{ $t }}" @selected($val('tag', $caa['tag']) === $t)>{{ $t }}</option>@endforeach
    </select>
    @if($err('tag'))<span class="dns-err" role="alert">{{ $err('tag') }}</span>@endif
  </div>
  <div class="dns-f w4" data-types="CAA">
    <label for="{{ $id }}-value">{{ __('dns.form.caa_value') }}</label>
    <input id="{{ $id }}-value" class="dns-input dns-ltr {{ $err('value') ? 'is-bad' : '' }}" name="value" dir="ltr" spellcheck="false" value="{{ $val('value', $caa['value']) }}" placeholder="letsencrypt.org">
    @if($err('value'))<span class="dns-err" role="alert">{{ $err('value') }}</span>@endif
  </div>

  <div class="dns-f w2">
    <label for="{{ $id }}-ttl">TTL</label>
    @php $curTtl = (int) $val('ttl', $rec->ttl ?? 3600); @endphp
    <select id="{{ $id }}-ttl" name="ttl">
      @foreach($ttls as $t)
        <option value="{{ $t }}" @selected($curTtl === (int) $t)>{{ $t < 3600 ? __('dns.ui.minutes', ['n' => intdiv($t, 60)]) : __('dns.ui.hours', ['n' => intdiv($t, 3600)]) }}</option>
      @endforeach
      @unless(in_array($curTtl, $ttls, true))
        <option value="{{ $curTtl }}" selected>{{ __('dns.ui.seconds', ['n' => $curTtl]) }}</option>
      @endunless
    </select>
    @if($err('ttl'))<span class="dns-err" role="alert">{{ $err('ttl') }}</span>@endif
  </div>

  <div class="dns-f w6">
    <label for="{{ $id }}-comment">{{ __('dns.form.comment') }}</label>
    <input id="{{ $id }}-comment" class="dns-input" name="comment" maxlength="120" value="{{ $val('comment', $rec->comment ?? '') }}">
  </div>

  @if($mine && ($errors->has('record') || $errors->has('zone')))
    <div class="dns-f w12"><span class="dns-err" role="alert">{{ $errors->first('record') ?: $errors->first('zone') }}</span></div>
  @endif

  <div class="dns-actions">
    <button class="pnl-btn primary" type="submit"><svg class="icon"><use href="#i-check"/></svg>{{ $rec ? __('dns.form.save') : __('dns.form.add') }}</button>
    <button class="pnl-btn" type="button" data-dns-cancel>{{ __('dns.form.cancel') }}</button>
    <span class="dns-hint">{{ __('dns.form.ttl_rrset_note') }}</span>
  </div>
</form>

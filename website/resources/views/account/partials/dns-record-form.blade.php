{{--
  فرمِ رکورد — مشترک بینِ «افزودن» و «ویرایش».

  ورودی‌ها: $zone، $types، $ttls، $formKey ('new' یا 'edit-{id}')، $action،
  و $rec (فقط در ویرایش).

  ⚠️ مقدارِ old() فقط وقتی خوانده می‌شود که خطا مالِ **همین** فرم باشد
  (session('dns_form') === $formKey). بی‌این شرط، خطای فرمِ «افزودن» مقدارهایش
  را در همهٔ فرم‌های ویرایشِ صفحه هم می‌ریخت و کاربر رکوردِ دیگری را با
  داده‌های اشتباه ذخیره می‌کرد.

  ⚠️ TXT فیلدِ جدای `content_txt` دارد، نه `content`: دو فیلدِ هم‌نام در یک فرم
  یعنی PHP آخری را می‌گیرد — و textareaِ خالیِ TXT مقدارِ رکوردِ A را پاک می‌کرد.

  ⚠️ فیلدها به ازای نوع با `data-types` نمایش داده می‌شوند (dns.js). بی‌جاوااسکریپت
  همه دیده می‌شوند و فرم باز هم کار می‌کند — سرور فیلدهای بی‌ربط را نادیده می‌گیرد.
--}}
@php
  $mine = session('dns_form') === $formKey;
  $val = function (string $field, $fallback = '') use ($mine) {
      return $mine ? old($field, $fallback) : $fallback;
  };
  $err = fn (string $field) => $mine && $errors->has($field) ? $errors->first($field) : null;

  $type = $rec->type ?? $val('type', 'A');
  $name = $rec ? $rec->shortName($zone->domain) : '';
  $content = $rec->content ?? '';
  $srv = ['weight' => '', 'port' => '', 'target' => ''];
  $caa = ['flags' => '0', 'tag' => 'issue', 'value' => ''];

  if ($rec && $rec->type === 'SRV') {
      $p = preg_split('~\s+~', trim($rec->content)) ?: [];
      $srv = ['weight' => $p[0] ?? '', 'port' => $p[1] ?? '', 'target' => $p[2] ?? ''];
  }
  if ($rec && $rec->type === 'CAA' && preg_match('~^(\d+)\s+(\w+)\s+"?([^"]*)"?$~', $rec->content, $m)) {
      $caa = ['flags' => $m[1], 'tag' => $m[2], 'value' => $m[3]];
  }

  $id = 'dnsf-'.$formKey;
@endphp

<form method="POST" action="{{ $action }}" class="dns-form" data-dns-form>
  @csrf

  @if($rec)
    <input type="hidden" name="type" value="{{ $rec->type }}">
    <div class="dns-f w2">
      <label>نوع</label>
      <div><span class="dns-type t-{{ $rec->type }}">{{ $rec->type }}</span></div>
    </div>
  @else
    <div class="dns-f w2">
      <label for="{{ $id }}-type">نوع</label>
      <select id="{{ $id }}-type" name="type" data-dns-type>
        @foreach($types as $t)
          <option value="{{ $t }}" @selected($type === $t)>{{ $t }}</option>
        @endforeach
      </select>
    </div>
  @endif

  <div class="dns-f w4">
    <label for="{{ $id }}-name">نام</label>
    <div class="dns-suffix">
      <input id="{{ $id }}-name" class="dns-input dns-ltr {{ $err('name') ? 'is-bad' : '' }}" name="name"
             value="{{ $val('name', $name) }}" placeholder="@" autocomplete="off" spellcheck="false">
      <span>.{{ $zone->domain }}</span>
    </div>
    <span class="dns-hint">برای خودِ دامنه <b class="dns-ltr">@</b> یا خالی؛ برای زیردامنه مثلاً <b class="dns-ltr">www</b> یا <b class="dns-ltr">*</b></span>
    @if($err('name'))<span class="dns-err">{{ $err('name') }}</span>@endif
  </div>

  {{-- مقدارِ ساده: A، AAAA، CNAME، MX، NS --}}
  <div class="dns-f w4" data-types="A AAAA CNAME MX NS">
    <label for="{{ $id }}-content" data-label-for="A:آدرسِ IPv4|AAAA:آدرسِ IPv6|CNAME:مقصد (نامِ میزبان)|MX:سرورِ ایمیل|NS:نیم‌سرور">مقدار</label>
    <input id="{{ $id }}-content" class="dns-input dns-ltr {{ $err('content') ? 'is-bad' : '' }}" name="content"
           value="{{ $val('content', in_array($type, ['A','AAAA','CNAME','MX','NS'], true) ? $content : '') }}"
           autocomplete="off" spellcheck="false"
           data-ph="A:185.10.20.30|AAAA:2a01:4f8::1|CNAME:example.net|MX:mail.{{ $zone->domain }}|NS:ns1.example.net">
    @if($err('content') && $type !== 'TXT')<span class="dns-err">{{ $err('content') }}</span>@endif
  </div>

  {{-- TXT: متنِ بلند --}}
  <div class="dns-f w12" data-types="TXT">
    <label for="{{ $id }}-txt">مقدارِ TXT</label>
    <textarea id="{{ $id }}-txt" class="dns-ltr {{ $err('content') ? 'is-bad' : '' }}" name="content_txt" spellcheck="false"
              placeholder="v=spf1 include:_spf.example.com ~all">{{ $val('content_txt', $type === 'TXT' ? $content : '') }}</textarea>
    <span class="dns-hint">بدونِ گیومه بنویسید. مقدارهای بلند (مثل کلیدِ DKIM) خودکار به تکه‌های مجاز تقسیم می‌شوند.</span>
    @if($err('content') && $type === 'TXT')<span class="dns-err">{{ $err('content') }}</span>@endif
  </div>

  {{-- اولویت: MX و SRV --}}
  <div class="dns-f w2" data-types="MX SRV">
    <label for="{{ $id }}-prio">اولویت</label>
    <input id="{{ $id }}-prio" class="dns-input dns-ltr {{ $err('prio') ? 'is-bad' : '' }}" name="prio" inputmode="numeric"
           value="{{ $val('prio', $rec->prio ?? 10) }}">
    @if($err('prio'))<span class="dns-err">{{ $err('prio') }}</span>@endif
  </div>

  {{-- SRV --}}
  <div class="dns-f w2" data-types="SRV">
    <label for="{{ $id }}-weight">وزن</label>
    <input id="{{ $id }}-weight" class="dns-input dns-ltr {{ $err('weight') ? 'is-bad' : '' }}" name="weight" inputmode="numeric" value="{{ $val('weight', $srv['weight'] ?: 5) }}">
    @if($err('weight'))<span class="dns-err">{{ $err('weight') }}</span>@endif
  </div>
  <div class="dns-f w2" data-types="SRV">
    <label for="{{ $id }}-port">پورت</label>
    <input id="{{ $id }}-port" class="dns-input dns-ltr {{ $err('port') ? 'is-bad' : '' }}" name="port" inputmode="numeric" value="{{ $val('port', $srv['port']) }}" placeholder="5060">
    @if($err('port'))<span class="dns-err">{{ $err('port') }}</span>@endif
  </div>
  <div class="dns-f w4" data-types="SRV">
    <label for="{{ $id }}-target">مقصد</label>
    <input id="{{ $id }}-target" class="dns-input dns-ltr {{ $err('target') ? 'is-bad' : '' }}" name="target" value="{{ $val('target', $srv['target']) }}" placeholder="sip.{{ $zone->domain }}" spellcheck="false">
    @if($err('target'))<span class="dns-err">{{ $err('target') }}</span>@endif
  </div>

  {{-- CAA --}}
  <div class="dns-f w2" data-types="CAA">
    <label for="{{ $id }}-flags">پرچم</label>
    <select id="{{ $id }}-flags" name="flags">
      @foreach(['0', '128'] as $f)<option value="{{ $f }}" @selected((string) $val('flags', $caa['flags']) === $f)>{{ $f }}</option>@endforeach
    </select>
  </div>
  <div class="dns-f w2" data-types="CAA">
    <label for="{{ $id }}-tag">برچسب</label>
    <select id="{{ $id }}-tag" name="tag">
      @foreach(['issue', 'issuewild', 'iodef'] as $t)<option value="{{ $t }}" @selected($val('tag', $caa['tag']) === $t)>{{ $t }}</option>@endforeach
    </select>
    @if($err('tag'))<span class="dns-err">{{ $err('tag') }}</span>@endif
  </div>
  <div class="dns-f w4" data-types="CAA">
    <label for="{{ $id }}-value">مقدار</label>
    <input id="{{ $id }}-value" class="dns-input dns-ltr {{ $err('value') ? 'is-bad' : '' }}" name="value" value="{{ $val('value', $caa['value']) }}" placeholder="letsencrypt.org" spellcheck="false">
    @if($err('value'))<span class="dns-err">{{ $err('value') }}</span>@endif
  </div>

  <div class="dns-f w2">
    <label for="{{ $id }}-ttl">TTL</label>
    <select id="{{ $id }}-ttl" name="ttl">
      @php $curTtl = (int) $val('ttl', $rec->ttl ?? config('edge.default_ttl', 3600)); @endphp
      @foreach($ttls as $t)
        <option value="{{ $t }}" @selected($curTtl === (int) $t)>{{ $t < 3600 ? fa_num($t / 60).' دقیقه' : fa_num($t / 3600).' ساعت' }}</option>
      @endforeach
      @unless(in_array($curTtl, array_map('intval', $ttls), true))
        <option value="{{ $curTtl }}" selected>{{ fa_num($curTtl) }} ثانیه</option>
      @endunless
    </select>
    @if($err('ttl'))<span class="dns-err">{{ $err('ttl') }}</span>@endif
  </div>

  <div class="dns-f w6">
    <label for="{{ $id }}-comment">یادداشت (اختیاری)</label>
    <input id="{{ $id }}-comment" class="dns-input" name="comment" maxlength="120" value="{{ $val('comment', $rec->comment ?? '') }}" placeholder="مثلاً: سرورِ ایمیلِ گوگل">
  </div>

  @if($mine && $errors->has('record'))
    <div class="dns-f w12"><span class="dns-err">{{ $errors->first('record') }}</span></div>
  @endif

  <div class="dns-actions">
    <button class="pnl-btn primary" type="submit"><svg class="icon"><use href="#i-check"/></svg>{{ $rec ? 'ذخیرهٔ تغییرات' : 'افزودنِ رکورد' }}</button>
    <button class="pnl-btn" type="button" data-dns-cancel>انصراف</button>
  </div>
</form>

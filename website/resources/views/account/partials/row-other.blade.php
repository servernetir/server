{{--
  خدمات — عمداً کوتاه‌ترین شکل.

  بیشتر این ردیف‌ها دادهٔ فنی ندارند: نام، توضیح، دوره، مبلغ، سررسید و
  وضعیت. اتصالِ تأییدشدهٔ فضای بکاپ فقط حقایق ثبت‌شدهٔ خودش را اضافه می‌کند.
  ریختن همه در یک جدولِ شش‌ستونه یعنی پنج ستونِ خالی، و همان
  ستون‌های خالی بزرگ‌ترین سهم را در «همه چی توهم» داشتند.

  ورودی: $s (Service)
--}}
@php
  $recurring = $s->isRecurring();
  $storageMeta = (array) $s->provision_meta;
  $binding = $storageMeta['existing_storage_binding'] ?? [];
  $storageUser = $s->username;
  $storageHost = $storageMeta['host'] ?? null;
  $storageId = $storageMeta['hetzner_box_id'] ?? null;
  $storageBound = $s->server?->type === 'hetzner_storage'
      && ($storageMeta['driver'] ?? null) === 'hetzner_storage'
      && (is_int($storageId) || is_string($storageId))
      && preg_match('/\A[1-9][0-9]*\z/D', (string) $storageId)
      && is_string($storageUser) && preg_match('/\Au[0-9]+\z/D', $storageUser)
      && is_string($storageHost) && $storageHost === $storageUser.'.your-storagebox.de'
      && is_array($binding) && ($binding['version'] ?? null) === 1
      && in_array($binding['customer_id'] ?? null, [(int) $s->customer_id, (string) $s->customer_id], true)
      && in_array($binding['service_id'] ?? null, [(int) $s->id, (string) $s->id], true)
      && in_array($binding['server_id'] ?? null, [(int) $s->server_id, (string) $s->server_id], true)
      && in_array($binding['box_id'] ?? null, [(int) $storageId, (string) $storageId], true);
  $storageAccess = $storageBound ? (array) ($binding['access_settings'] ?? []) : [];
  $storageReachable = ($storageAccess['reachable_externally'] ?? null) === true;
@endphp

<li class="svc-row">
  <div class="svc-row-main">
    <span class="pnl-svc-ic"><svg class="icon"><use href="#i-wrench"/></svg></span>

    <span class="svc-row-t">
      <b>{{ \App\Support\PanelText::text($s->name) }}</b>
      @if($s->description)<small>{{ \Illuminate\Support\Str::limit(\App\Support\PanelText::text($s->description), 90) }}</small>@endif
    </span>

    <span class="svc-row-m">
      {{-- ⚠️ سرویسِ «یک‌بار» سررسیدِ بعدی **ندارد** (`nextDueFrom()` عمداً برای
           `monthsIn()===0` نال می‌دهد)، پس ردیفِ قبلی یک خط‌تیرهٔ همیشگی نشان
           می‌داد. حالا حقیقت را می‌گوید. --}}
      @if($recurring)
        <small>{{ __('ui.oth_renews') }} · {{ sdate($s->next_due_at) }}</small>
      @else
        <small>{{ __('ui.oth_once') }}</small>
      @endif
      <b class="pnl-num">{{ invoice_money($s->total(), $s->currency_code) }}</b>
    </span>

    {{-- قرصِ وضعیت فقط وقتی چیزی برای گفتن هست: یک خدمتِ فعالِ سالم نشان لازم ندارد --}}
    @if($s->status !== 'active' || $s->provision_status === 'failed')
      @include('account.partials.status-pill', ['s' => $s])
    @endif
  </div>

  @if($storageBound)
    {{-- Recorded connection facts only; rendering never contacts the provider. --}}
    <div class="svc-cred" data-storage-connection>
      <div><span>{{ __('ui.srv_hostname') }}</span><b dir="ltr" class="copyable">{{ $storageHost }}</b></div>
      <div><span>{{ __('ui.svc_cred_username') }}</span><b dir="ltr" class="copyable">{{ $storageUser }}</b></div>
      @if($storageReachable && ($storageAccess['ssh_enabled'] ?? null) === true)
        <div><span>SSH / SFTP</span><b dir="ltr" class="copyable">sftp -P 23 {{ $storageUser }}{{ '@' }}{{ $storageHost }}</b></div>
      @endif
      @if($storageReachable && ($storageAccess['webdav_enabled'] ?? null) === true)
        <div><span>WebDAV</span><a href="{{ 'https://'.$storageHost }}" target="_blank" rel="noopener noreferrer" dir="ltr">{{ 'https://'.$storageHost }}</a></div>
      @endif
    </div>
  @endif

  <div class="svc-row-a">
    @include('account.partials.svc-actions', ['s' => $s, 'kind' => 'other'])
  </div>
</li>

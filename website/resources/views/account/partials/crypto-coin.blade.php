{{-- نشانِ دارایی — درون‌خطی، چون CSP هر تصویرِ بیرونی را بی‌صدا بلاک می‌کند. --}}
@php $sz = $size ?? 28; @endphp
@switch($code)
  @case('USDT')
    <svg class="cyx-coin-ic" width="{{ $sz }}" height="{{ $sz }}" viewBox="0 0 32 32" aria-hidden="true">
      <circle cx="16" cy="16" r="16" fill="#26A17B"/>
      <path fill="#fff" d="M17.9 17.4v0c-.1 0-.7.1-1.9.1-1 0-1.6 0-1.9-.1v0c-3.6-.2-6.3-.8-6.3-1.6s2.7-1.4 6.3-1.6v2.5c.2 0 .9.1 1.9.1 1.2 0 1.8-.1 1.9-.1v-2.5c3.6.2 6.3.8 6.3 1.6s-2.7 1.4-6.3 1.6zm0-3.3v-2.2h5V8.6H9.1v3.3h5v2.2c-4.1.2-7.1 1-7.1 2s3 1.8 7.1 2v7.1h3.8v-7.1c4-.2 7.1-1 7.1-2s-3.1-1.8-7.1-2z"/>
    </svg>
    @break
  @case('TRX')
    <svg class="cyx-coin-ic" width="{{ $sz }}" height="{{ $sz }}" viewBox="0 0 32 32" aria-hidden="true">
      <circle cx="16" cy="16" r="16" fill="#EB0029"/>
      <path fill="none" stroke="#fff" stroke-width="1.6" stroke-linejoin="round" d="M8 8.5l15.5 3 1.7 2.6L14.6 25 8 8.5zm0 0l6.6 16.5 2.2-9.6L8 8.5zm8.8 6.9l6.7-3.9m-6.7 3.9l8.4-1.3"/>
    </svg>
    @break
  @default
    <span class="cyx-coin-ic cyx-coin-fallback" style="width:{{ $sz }}px;height:{{ $sz }}px" aria-hidden="true">{{ mb_substr($code, 0, 1) }}</span>
@endswitch

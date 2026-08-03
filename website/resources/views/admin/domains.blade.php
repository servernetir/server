@extends('admin.layout')
@section('title', 'دامنه‌ها')
@section('nav_domains', 'active')
@section('content')

<div class="ad-panel">
  <div class="ad-panel-h"><h2>دامنه‌ها</h2></div>

  <p style="padding:0 18px 14px;color:var(--muted);font-size:13px;line-height:1.9">
    ثبت‌شده، در صف، و آن‌هایی که <b>گیر کرده‌اند</b>. دامنه‌ای که به «صف دستی» رفته
    یعنی مشتری پولش را داده و ثبت نشده — تا کسی این‌جا سراغش نرود، همان‌جا می‌مانَد.
  </p>

  @if(session('ok'))
    <div class="ad-flash" style="margin:0 18px 12px">{{ session('ok') }}</div>
  @endif
  @if($errors->any())
    <div class="ad-flash err" style="margin:0 18px 12px">{{ $errors->first() }}</div>
  @endif

  {{-- ⚠️ پیش‌فرض «نیازمند رسیدگی» است نه «همه»: ردیفِ گیرکرده نباید در انبوهِ
       ردیف‌های سالم گم شود — همان ردیف است که پول رویش خوابیده. --}}
  <div class="ad-tabs" style="padding:0 18px 12px">
    @foreach([
      'attention' => ['نیازمند رسیدگی', $counts['manual'] + $counts['pending']],
      'manual'    => ['صف دستی', $counts['manual']],
      'pending'   => ['در انتظار ثبت', $counts['pending']],
      'expiring'  => ['نزدیک انقضا', $counts['expiring']],
      'active'    => ['فعال', $counts['active']],
      'all'       => ['همه', null],
    ] as $key => [$label, $n])
      <a href="{{ route('admin.domains') }}?f={{ $key }}"
         class="ad-lang-tab {{ $filter === $key ? 'on' : '' }}">
        {{ $label }}@if($n !== null) ({{ fa_num($n) }})@endif
      </a>
    @endforeach
  </div>

  @if($truncated)
    <div class="ad-flash err" style="margin:0 18px 12px">
      فهرست بریده شد. برای دیدن بقیه از فیلترها استفاده کنید.
    </div>
  @endif

  @if($rows->isEmpty())
    <p style="padding:0 18px 18px;color:var(--muted);font-size:13px">در این دسته چیزی نیست.</p>
  @else
  <div style="padding:0 18px 18px">
    <table class="ad-table">
      <thead>
        <tr>
          <th>دامنه</th>
          <th>مشتری</th>
          <th>وضعیت</th>
          <th>تحویل</th>
          <th>انقضا</th>
          <th>خطا</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        @foreach($rows as $d)
        <tr>
          <td><b dir="ltr">{{ $d->domain }}</b></td>
          <td>
            {{ $d->customer?->code ?? '—' }}
            <br><small style="color:var(--muted)">{{ $d->customer?->email }}</small>
          </td>
          <td>
            @if($d->status === 'active')
              <span class="ad-pill">فعال</span>
            @elseif($d->status === 'pending')
              <span class="ad-pill">در انتظار</span>
            @else
              <span class="ad-pill">{{ $d->status }}</span>
            @endif
          </td>
          <td>
            {{ $d->provision_status === 'manual' ? 'دستی' : ($d->provision_status === 'done' ? 'انجام شد' : $d->provision_status) }}
            @if($d->provision_tries)
              <br><small style="color:var(--muted)">{{ fa_num($d->provision_tries) }} تلاش</small>
            @endif
          </td>
          <td>{{ $d->expires_at ? sdate($d->expires_at) : '—' }}</td>
          <td style="color:var(--muted);font-size:12px;max-width:280px">
            {{ $d->provision_error ? mb_substr($d->provision_error, 0, 110) : '—' }}
          </td>
          <td style="white-space:nowrap">
            @if($d->provision_status !== 'done' && ! $d->isDead())
              <form method="post" action="{{ route('admin.domains.retry', $d) }}" style="display:inline">
                @csrf<button type="submit">صف دوباره</button>
              </form>
              <form method="post" action="{{ route('admin.domains.register', $d) }}" style="display:inline">
                @csrf<button type="submit">ثبت فوری</button>
              </form>
            @endif
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>
  </div>
  @endif
</div>

@endsection

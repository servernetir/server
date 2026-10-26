@php
  $infrastructure = app(\App\Support\AdminServiceInfrastructure::class)->describe($s);
@endphp
<div class="svc-infrastructure">
  <b>{{ $infrastructure['label'] }}</b>
  @if($infrastructure['server_name'])
    <small>{{ $infrastructure['server_name'] }}</small>
  @endif
  @if($infrastructure['reference'])
    <small>شناسه مبدا: <bdi dir="ltr" class="svc-origin-ref">{{ $infrastructure['reference'] }}</bdi></small>
  @endif
  @if($infrastructure['location'])
    <small>مکان: <bdi>{{ $infrastructure['location'] }}</bdi></small>
  @endif
  @if($infrastructure['awaiting_resource'])
    <small>زیرساخت سفارش؛ هنوز تحویل نشده</small>
  @endif
  @if($infrastructure['url'])
    <a href="{{ $infrastructure['url'] }}" target="_blank" rel="noopener noreferrer"
       referrerpolicy="no-referrer" class="svc-infrastructure-link">
      {{ $infrastructure['direct'] ? 'مشاهده سرویس در زیرساخت ↗' : 'باز کردن پنل زیرساخت ↗' }}
    </a>
  @endif
</div>

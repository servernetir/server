@extends('admin.layout')
@section('title', 'تیکت‌ها')
@section('nav_tickets', 'on')
@section('content')

<div class="ad-toolbar">
  <div class="ad-tabs">
    <a href="/admin/tickets?status=open"     class="{{ $filter === 'open' ? 'on' : '' }}">در انتظار پاسخ ({{ $counts['open'] }})</a>
    <a href="/admin/tickets?status=answered" class="{{ $filter === 'answered' ? 'on' : '' }}">پاسخ داده‌شده ({{ $counts['answered'] }})</a>
    <a href="/admin/tickets?status=closed"   class="{{ $filter === 'closed' ? 'on' : '' }}">بسته ({{ $counts['closed'] }})</a>
    <a href="/admin/tickets?status=all"      class="{{ $filter === 'all' ? 'on' : '' }}">همه</a>
  </div>
</div>

@if(session('ok'))<div class="ad-note ok">{{ session('ok') }}</div>@endif

<div class="ad-panel">
  <div class="ad-panel-h"><h2>تیکت‌ها</h2></div>
  @if($tickets->isEmpty())
    <p style="padding:20px;color:#96a3ba">تیکتی در این وضعیت نیست.</p>
  @else
    <table class="ad-table">
      <thead>
        <tr><th>شماره</th><th>موضوع</th><th>مشتری</th><th>بخش</th><th>اولویت</th><th>وضعیت</th><th>آخرین پاسخ</th></tr>
      </thead>
      <tbody>
        @foreach($tickets as $t)
          <tr onclick="location='/admin/tickets/{{ $t->id }}'" style="cursor:pointer">
            <td dir="ltr">{{ $t->number }}</td>
            <td>{{ $t->subject }}</td>
            <td>{{ $t->customer?->displayName() }} <small style="color:#5f6c82" dir="ltr">{{ $t->customer?->code }}</small></td>
            <td>{{ ['technical'=>'فنی','billing'=>'مالی','sales'=>'فروش'][$t->department] ?? $t->department }}</td>
            <td>
              @php $pr = ['low'=>['کم','#5f6c82'],'normal'=>['عادی','#96a3ba'],'high'=>['زیاد','#fbbf24'],'urgent'=>['فوری','#ff6b6b']][$t->priority] ?? ['—','#5f6c82']; @endphp
              <span style="color:{{ $pr[1] }}">{{ $pr[0] }}</span>
            </td>
            <td>
              @if($t->status === 'open')<span class="ad-badge" style="background:rgba(251,191,36,.15);color:#fbbf24">در انتظار</span>
              @elseif($t->status === 'answered')<span class="ad-badge" style="background:rgba(52,211,153,.15);color:#34d399">پاسخ‌داده</span>
              @else<span class="ad-badge" style="background:rgba(95,108,130,.15);color:#96a3ba">بسته</span>@endif
            </td>
            <td dir="ltr">{{ optional($t->last_reply_at)->format('Y/m/d H:i') }} <small style="color:#5f6c82">{{ $t->last_reply_role === 'staff' ? '(ما)' : '(مشتری)' }}</small></td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif
</div>

{{ $tickets->links() }}

@endsection

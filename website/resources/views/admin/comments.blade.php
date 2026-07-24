@extends('admin.layout')
@section('title', 'کامنت‌ها')
@section('nav_comments', 'on')
@section('content')

<div class="ad-toolbar">
  <div class="ad-tabs">
    <a href="/admin/comments?f=pending"  class="{{ $filter === 'pending' ? 'on' : '' }}">در انتظار بررسی ({{ $counts['pending'] }})</a>
    <a href="/admin/comments?f=approved" class="{{ $filter === 'approved' ? 'on' : '' }}">منتشرشده ({{ $counts['approved'] }})</a>
    <a href="/admin/comments?f=all"      class="{{ $filter === 'all' ? 'on' : '' }}">همه</a>
  </div>
</div>


<div class="ad-panel">
  @forelse($comments as $c)
  <div class="ad-cm">
    <div class="ad-cm-head">
      <span class="ad-cm-av">{{ mb_substr($c->name, 0, 1) }}</span>
      <div class="ad-cm-who">
        <b>{{ $c->name }}</b>
        <small dir="ltr">{{ $c->email ?: '—' }} · {{ $c->created_at->diffForHumans() }} · {{ strtoupper($c->locale) }}</small>
      </div>

      {{-- داوری هوش مصنوعی --}}
      @if($c->ai_verdict)
        @php $vc = ['approve' => 'ok', 'review' => 'warn', 'spam' => 'bad'][$c->ai_verdict] ?? 'warn'; @endphp
        <span class="ad-verdict {{ $vc }}">
          <svg class="icon"><use href="#i-{{ $c->ai_verdict === 'approve' ? 'check' : ($c->ai_verdict === 'spam' ? 'x' : 'shield') }}"/></svg>
          {{ ['approve' => 'تأیید هوش مصنوعی', 'review' => 'نیاز به بررسی', 'spam' => 'اسپم'][$c->ai_verdict] ?? $c->ai_verdict }}
          @if($c->ai_score !== null)<i>اسپم {{ $c->ai_score }}٪</i>@endif
        </span>
      @else
        <span class="ad-verdict none">بدون داوری خودکار</span>
      @endif

      <span class="ad-badge {{ $c->approved ? 'pub' : 'draft' }}">{{ $c->approved ? 'منتشر' : 'در انتظار' }}</span>
    </div>

    @if($c->ai_reason)<p class="ad-cm-reason"><svg class="icon"><use href="#i-sparkles"/></svg>{{ $c->ai_reason }}</p>@endif

    <p class="ad-cm-body">{{ $c->body }}</p>

    @if($c->translations)
      <details class="ad-cm-tr">
        <summary>ترجمه‌ها ({{ implode('، ', array_map('strtoupper', array_keys($c->translations))) }})</summary>
        @foreach($c->translations as $l => $t)
          @if($l !== $c->locale)<p><b>{{ strtoupper($l) }}</b> {{ $t }}</p>@endif
        @endforeach
      </details>
    @endif

    @if($c->reply)
      <div class="ad-cm-reply">
        <div class="ad-cm-reply-h"><svg class="icon"><use href="#i-headset"/></svg><b>پاسخ خودکار</b>
          <form method="post" action="/admin/comments/{{ $c->id }}/drop-reply" onsubmit="return confirm('پاسخ هوش مصنوعی حذف شود؟')">@csrf<button type="submit">حذف پاسخ</button></form>
        </div>
        <p>{{ $c->reply }}</p>
      </div>
    @endif

    <div class="ad-cm-act">
      <a href="/blog/{{ $c->post_slug }}#comments" target="_blank">مشاهده‌ی پست</a>
      @unless($c->approved)
      <form method="post" action="/admin/comments/{{ $c->id }}/approve">@csrf<button class="ok" type="submit">تأیید و انتشار</button></form>
      @endunless
      <form method="post" action="/admin/comments/{{ $c->id }}/delete" onsubmit="return confirm('این کامنت حذف شود؟')">@csrf<button class="del" type="submit">حذف</button></form>
    </div>
  </div>
  @empty
  <p style="text-align:center;color:var(--dim);padding:40px">کامنتی در این بخش نیست.</p>
  @endforelse
</div>

<div style="margin-top:16px">{{ $comments->links() }}</div>
@endsection

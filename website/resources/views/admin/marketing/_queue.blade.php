{{-- صفِ تأیید — میزِ کارِ روزانه --}}

@if($pending->isEmpty())
  <div class="mk-empty">
    <svg class="icon"><use href="#i-check"/></svg>
    <b>چیزی منتظرِ تو نیست</b>
    <p>هر وقت سرنخی سررسید شود و مشاهده‌ای برایش ثبت شده باشد، پیامش همین‌جا می‌آید.</p>
  </div>
@else
  <div class="mk-note info" style="margin-top:16px">
    <svg class="icon"><use href="#i-info"/></svg>
    <div>هر پیام را بخوان. اگر جمله‌ای هست که خودت جلوی مشتری نمی‌گفتی، <b>ردش کن</b> — رد کردن رایگان است، ایمیلِ بد شهرتِ دامنه را می‌سوزاند و آن گران است.</div>
  </div>

  @foreach($pending as $m)
    <div class="mk-draft">
      <div class="mk-draft-h">
        <div>
          <b>{{ $m->lead?->company ?? '—' }}</b>
          <small style="color:var(--dim);display:block" dir="ltr">{{ $m->lead?->email }}</small>
        </div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <span class="mk-tag">پیام {{ $m->sequence + 1 }} از {{ \App\Models\CrmMessage::MAX_SEQUENCE + 1 }}</span>
          <a class="mk-btn" href="/admin/marketing/{{ $m->lead_id }}">پرونده</a>
        </div>
      </div>
      <div class="mk-draft-b mk-ltr">
        <div class="s">{{ $m->subject }}</div>
        <pre>{{ $m->body }}</pre>
      </div>
      <div class="mk-draft-f">
        <form method="post" action="/admin/marketing/message/{{ $m->id }}/approve" data-confirm="ارسالِ این ایمیل؟">@csrf
          <button class="btn btn-primary" type="submit"><svg class="icon"><use href="#i-send"/></svg>تأیید و ارسال</button>
        </form>
        <form method="post" action="/admin/marketing/message/{{ $m->id }}/reject">@csrf
          <button class="mk-btn danger" type="submit">رد کن</button>
        </form>
      </div>
    </div>
  @endforeach
@endif

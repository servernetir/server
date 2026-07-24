@extends('admin.layout')
@section('title', $ticket->number)
@section('nav_tickets', 'on')
@section('content')

<div class="ad-toolbar">
  <a href="/admin/tickets" style="color:#96a3ba">← بازگشت به فهرست</a>
</div>

@if(session('ok'))<div class="ad-note ok">{{ session('ok') }}</div>@endif
@if($errors->any())<div class="ad-note" style="border-color:#ff6b6b;color:#ff6b6b">{{ $errors->first() }}</div>@endif

<div class="tka-grid">

  {{-- ستون گفتگو --}}
  <div>
    <div class="ad-panel">
      <div class="ad-panel-h">
        <h2>{{ $ticket->subject }}</h2>
        <span dir="ltr" style="color:#5f6c82">{{ $ticket->number }}</span>
      </div>

      <div class="tka-thread">
        @foreach($messages as $m)
          <div class="tka-msg {{ $m->is_internal ? 'internal' : ($m->fromStaff() ? 'staff' : 'me') }}">
            <div class="tka-msg-h">
              <b>{{ $m->author_name ?: ($m->fromStaff() ? 'کارمند' : 'مشتری') }}</b>
              @if($m->is_internal)<span class="ad-badge" style="background:rgba(251,191,36,.15);color:#fbbf24">یادداشت داخلی</span>@endif
              <span dir="ltr" style="color:#5f6c82;font-size:11px">{{ stime($m->created_at) }}</span>
            </div>
            <div class="tka-msg-b">{!! nl2br(e($m->body)) !!}</div>
            @if($m->relationLoaded('attachments') && $m->attachments->isNotEmpty())
              <div class="tka-atts">
                @foreach($m->attachments as $att)
                  @php $url = '/admin/tickets/'.$ticket->id.'/attachments/'.$att->id; @endphp
                  @if($att->isImage())
                    <a class="tka-att-img" href="{{ $url }}" target="_blank" title="{{ $att->original_name }}"><img src="{{ $url }}" alt="" loading="lazy"></a>
                  @else
                    <a class="tka-att" href="{{ $url }}" target="_blank"><svg class="icon"><use href="#i-file"/></svg><span>{{ $att->original_name }}</span><i>{{ $att->humanSize() }}</i></a>
                  @endif
                @endforeach
              </div>
            @endif
          </div>
        @endforeach
      </div>
    </div>

    {{-- پاسخ --}}
    <div class="ad-panel" style="margin-top:16px">
      <div class="ad-panel-h"><h2>پاسخ</h2></div>
      <form method="post" action="/admin/tickets/{{ $ticket->id }}/reply" enctype="multipart/form-data" style="padding:16px;display:flex;flex-direction:column;gap:12px">
        @csrf
        <textarea name="body" rows="6" required class="ad-input" style="resize:vertical" placeholder="پاسخ به مشتری…">{{ old('body') }}</textarea>
        <label class="tka-file">
          <svg class="icon"><use href="#i-paperclip"/></svg>
          <span>افزودن تصویر یا PDF (حداکثر ۵ فایل، هرکدام تا ۵ مگابایت)</span>
          <input type="file" name="attachments[]" multiple accept="image/*,application/pdf" onchange="var b=document.getElementById('tka-fl');b.innerHTML='';for(var i=0;i&lt;this.files.length;i++){var s=document.createElement('span');s.className='tka-fchip';s.textContent=this.files[i].name;b.appendChild(s);}">
        </label>
        <div class="tka-file-list" id="tka-fl"></div>
        <label style="display:flex;align-items:center;gap:8px;color:#96a3ba;font-size:13px">
          <input type="checkbox" name="internal" value="1"> یادداشت داخلی (مشتری نمی‌بیند)
        </label>
        <div style="display:flex;gap:10px">
          <button type="submit" class="ad-badge" style="background:#22d3ee;color:#04121f;border:0;padding:10px 18px;cursor:pointer;font:inherit">ارسال</button>
          <button type="submit" name="close" value="1" class="ad-badge" style="background:rgba(95,108,130,.2);color:#e7edf7;border:0;padding:10px 18px;cursor:pointer;font:inherit">پاسخ و بستن</button>
        </div>
      </form>
    </div>
  </div>

  {{-- ستون مشخصات و کنترل --}}
  <div class="ad-panel tka-side">
    <div class="ad-panel-h"><h2>مشخصات</h2></div>
    <div style="padding:16px;display:flex;flex-direction:column;gap:14px;font-size:13px">
      <div><span style="color:#5f6c82">مشتری</span><br><b>{{ $ticket->customer?->displayName() }}</b>
        <small style="color:#5f6c82;font-size:12px" dir="ltr">{{ $ticket->customer?->code }}</small></div>
      <div><span style="color:#5f6c82">بخش</span><br>{{ ['technical'=>'فنی','billing'=>'مالی','sales'=>'فروش'][$ticket->department] ?? $ticket->department }}</div>
      <div><span style="color:#5f6c82">ساخته‌شده</span><br dir="ltr">{{ stime($ticket->created_at) }}</div>

      <form method="post" action="/admin/tickets/{{ $ticket->id }}/update" style="display:flex;flex-direction:column;gap:10px;border-top:1px solid #1e2637;padding-top:14px">
        @csrf
        <label style="color:#5f6c82">وضعیت</label>
        <select name="status" class="ad-input">
          @foreach(['open'=>'در انتظار پاسخ','answered'=>'پاسخ داده‌شده','closed'=>'بسته'] as $v=>$t)
            <option value="{{ $v }}" @selected($ticket->status===$v)>{{ $t }}</option>
          @endforeach
        </select>
        <label style="color:#5f6c82">اولویت</label>
        <select name="priority" class="ad-input">
          @foreach(['low'=>'کم','normal'=>'عادی','high'=>'زیاد','urgent'=>'فوری'] as $v=>$t)
            <option value="{{ $v }}" @selected($ticket->priority===$v)>{{ $t }}</option>
          @endforeach
        </select>
        <button type="submit" class="ad-badge" style="background:rgba(95,108,130,.2);color:#e7edf7;border:0;padding:9px;cursor:pointer;font:inherit">به‌روزرسانی</button>
      </form>
    </div>
  </div>

</div>

<style>
.tka-grid{ display:grid; grid-template-columns:1fr 300px; gap:16px; align-items:start }
@media(max-width:900px){ .tka-grid{ grid-template-columns:1fr } }
.tka-thread{ display:flex; flex-direction:column; gap:12px; padding:16px }
.tka-msg{ border:1px solid #1e2637; border-radius:12px; padding:12px 14px; background:#0d1320; max-width:85% }
.tka-msg.me{ align-self:flex-start }
.tka-msg.staff{ align-self:flex-end; background:rgba(34,211,238,.07); border-color:rgba(34,211,238,.25) }
.tka-msg.internal{ align-self:stretch; max-width:100%; background:rgba(251,191,36,.07); border-color:rgba(251,191,36,.3) }
.tka-msg-h{ display:flex; align-items:center; gap:8px; margin-bottom:6px; font-size:12px }
.tka-msg-b{ font-size:13.5px; line-height:1.95; color:#e7edf7; word-break:break-word }
.tka-atts{ display:flex; flex-wrap:wrap; gap:8px; margin-top:10px }
.tka-att{ display:inline-flex; align-items:center; gap:7px; text-decoration:none; background:#0f1522; border:1px solid #1e2637; border-radius:9px; padding:6px 10px; font-size:12px; color:#e7edf7; max-width:200px }
.tka-att .icon{ width:15px; height:15px; color:#96a3ba; flex:0 0 auto }
.tka-att span{ overflow:hidden; text-overflow:ellipsis; white-space:nowrap }
.tka-att i{ font-style:normal; color:#5f6c82; font-size:11px }
.tka-att-img{ display:block; border-radius:10px; overflow:hidden; border:1px solid #1e2637; line-height:0 }
.tka-att-img img{ max-width:170px; max-height:140px; object-fit:cover; display:block }
.tka-file{ display:flex; align-items:center; gap:8px; cursor:pointer; border:1px dashed #2b3548; border-radius:10px; padding:10px 12px; font-size:12.5px; color:#96a3ba; background:#0f1522 }
.tka-file:hover{ border-color:#22d3ee; color:#e7edf7 }
.tka-file .icon{ width:16px; height:16px }
.tka-file input[type=file]{ display:none }
.tka-file-list{ display:flex; flex-wrap:wrap; gap:6px }
.tka-fchip{ background:rgba(34,211,238,.15); color:#22d3ee; border-radius:7px; padding:3px 8px; font-size:11px }
</style>

@endsection

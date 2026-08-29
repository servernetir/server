@extends('admin.layout')
@section('title', $ticket->number)
@section('nav_tickets', 'on')
@section('content')

<div class="ad-toolbar">
  <a href="/admin/tickets" style="color:var(--muted)">← بازگشت به فهرست</a>
</div>

@if($errors->any())<div class="ad-note" style="border-color:#ff6b6b;color:#ff6b6b">{{ $errors->first() }}</div>@endif

<div class="tka-grid">

  {{-- ستون گفتگو --}}
  <div>
    <div class="ad-panel">
      <div class="ad-panel-h">
        <h2>{{ $ticket->subject }}</h2>
        <span dir="ltr" style="color:var(--dim)">{{ $ticket->number }}</span>
      </div>

      <div class="tka-thread">
        @foreach($messages as $m)
          <div class="tka-msg {{ $m->is_internal ? 'internal' : ($m->fromStaff() ? 'staff' : 'me') }}">
            <div class="tka-msg-h">
              <b>{{ $m->author_name ?: ($m->fromStaff() ? 'کارمند' : 'مشتری') }}</b>
              @if($m->is_internal)<span class="ad-badge" style="background:rgba(251,191,36,.15);color:#fbbf24">یادداشت داخلی</span>@endif
              <span dir="ltr" style="color:var(--dim);font-size:11px">{{ stime($m->created_at) }}</span>
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
        <label style="display:flex;align-items:center;gap:8px;color:var(--muted);font-size:13px">
          <input type="checkbox" name="internal" value="1"> یادداشت داخلی (مشتری نمی‌بیند)
        </label>
        {{-- ══ پاسخ به نامِ چه کسی ══
             فقط برای مدیر رندر می‌شود — کنترلر برای پشتیبان `staff` را خالی
             می‌فرستد و **دوباره** هم می‌سنجد؛ ویو هیچ‌وقت محافظ نیست. --}}
        @if($staff->isNotEmpty())
          <label style="display:flex;align-items:center;gap:8px;color:var(--muted);font-size:13px">
            <span>پاسخ به نامِ</span>
            <select name="as_user" id="tk-as-user" class="ad-input" style="width:auto;padding:6px 9px;font-size:12px">
              @foreach($staff as $s)
                <option value="{{ $s->id }}" @selected($s->id === auth()->id())>{{ $s->name }}@if($s->id === auth()->id()) — خودم @endif</option>
              @endforeach
            </select>
          </label>
        @endif
        {{-- ══ تصحیح نگارش با AI ══
             🔴 هیچ‌چیز ارسال نمی‌شود. متنِ صیقل‌خورده در همان کادر می‌نشیند و
             کارفرما می‌تواند با «بازگردانی» به نوشتهٔ خودش برگردد.

             ⚠️ دکمه `type="button"` است. بی‌آن، داخلِ `<form>` پیش‌فرضش submit
             می‌شود و کلیک روی «تصحیح» پاسخ را **می‌فرستد** — همان اشتباهی که
             متنِ خام را به مشتری می‌رسانْد. --}}
        <div class="tk-polish">
          {{-- ══ پیشنهادِ پاسخ با AI — همان موتورِ رباتِ بله ══
               🔴 پیش‌نویس در کادر می‌نشیند؛ هیچ‌چیز ارسال نمی‌شود. «بازگردانی»
               متنِ قبلی کادر را برمی‌گرداند. --}}
          <button type="button" class="tk-polish-btn" id="tk-draft">
            <svg class="icon"><use href="#i-bot"/></svg>
            <span>پیشنهاد پاسخ</span>
          </button>
          <select id="tk-draft-tone" class="ad-input" style="width:auto;padding:6px 9px;font-size:12px"
                  title="لحنِ پیش‌نویس">
            @foreach(\App\Services\Ticket\TicketDraftWriter::TONES as $k => $t)
              <option value="{{ $k }}">{{ $t }}</option>
            @endforeach
          </select>
          <button type="button" class="tk-polish-btn" id="tk-polish">
            <svg class="icon"><use href="#i-sparkles"/></svg>
            <span>تصحیح نگارش با AI</span>
          </button>
          <button type="button" class="tk-polish-undo" id="tk-polish-undo" hidden>بازگردانی</button>
          <span class="tk-polish-msg" id="tk-polish-msg"></span>
        </div>

        <div style="display:flex;gap:10px">
          <button type="submit" class="ad-badge" style="background:#22d3ee;color:#04121f;border:0;padding:10px 18px;cursor:pointer;font:inherit">ارسال</button>
          <button type="submit" name="close" value="1" class="ad-badge" style="background:rgba(95,108,130,.2);color:var(--text);border:0;padding:10px 18px;cursor:pointer;font:inherit">پاسخ و بستن</button>
        </div>
      </form>
    </div>
  </div>

  {{-- ستون مشخصات و کنترل --}}
  <div class="ad-panel tka-side">
    <div class="ad-panel-h"><h2>مشخصات</h2></div>
    <div style="padding:16px;display:flex;flex-direction:column;gap:14px;font-size:13px">
      {{-- نامِ مشتری به پروندهٔ کاملش می‌رود: سرویس‌ها، فاکتورها، تراکنش‌ها و
           تاریخچه. بی‌این لینک، پاسخ‌دادن به تیکت یعنی یک جستجوی دستی در
           فهرستِ مشتریان، هر بار. --}}
      <div><span style="color:var(--dim)">مشتری</span><br>
        @if($ticket->customer)
          <a href="/admin/customers/{{ $ticket->customer->id }}" style="color:#22d3ee;font-weight:700">{{ $ticket->customer->displayName() }}</a>
          <small style="color:var(--dim);font-size:12px" dir="ltr">{{ $ticket->customer->code }}</small>
        @else
          <b style="color:var(--dim)">— بی‌مشتری</b>
        @endif
      </div>
      <div><span style="color:var(--dim)">بخش</span><br>{{ ['technical'=>'فنی','billing'=>'مالی','sales'=>'فروش'][$ticket->department] ?? $ticket->department }}</div>
      <div><span style="color:var(--dim)">ساخته‌شده</span><br dir="ltr">{{ stime($ticket->created_at) }}</div>

      <form method="post" action="/admin/tickets/{{ $ticket->id }}/update" style="display:flex;flex-direction:column;gap:10px;border-top:1px solid var(--line);padding-top:14px">
        @csrf
        <label style="color:var(--dim)">وضعیت</label>
        <select name="status" class="ad-input">
          @foreach(\App\Models\Ticket::STATUSES as $v=>$t)
            <option value="{{ $v }}" @selected($ticket->status===$v)>{{ $t }}</option>
          @endforeach
        </select>
        <label style="color:var(--dim)">اولویت</label>
        <select name="priority" class="ad-input">
          @foreach(['low'=>'کم','normal'=>'عادی','high'=>'زیاد','urgent'=>'فوری'] as $v=>$t)
            <option value="{{ $v }}" @selected($ticket->priority===$v)>{{ $t }}</option>
          @endforeach
        </select>
        <button type="submit" class="ad-badge" style="background:rgba(95,108,130,.2);color:var(--text);border:0;padding:9px;cursor:pointer;font:inherit">به‌روزرسانی</button>
      </form>
    </div>
  </div>

</div>

<style>
.tka-grid{ display:grid; grid-template-columns:1fr 300px; gap:16px; align-items:start }
@media(max-width:900px){ .tka-grid{ grid-template-columns:1fr } }
.tka-thread{ display:flex; flex-direction:column; gap:12px; padding:16px }
.tka-msg{ border:1px solid var(--line); border-radius:12px; padding:12px 14px; background:var(--surface2); max-width:85% }
.tka-msg.me{ align-self:flex-start }
.tka-msg.staff{ align-self:flex-end; background:rgba(34,211,238,.07); border-color:rgba(34,211,238,.25) }
.tka-msg.internal{ align-self:stretch; max-width:100%; background:rgba(251,191,36,.07); border-color:rgba(251,191,36,.3) }
.tka-msg-h{ display:flex; align-items:center; gap:8px; margin-bottom:6px; font-size:12px }
.tka-msg-b{ font-size:13.5px; line-height:1.95; color:var(--text); word-break:break-word }
.tka-atts{ display:flex; flex-wrap:wrap; gap:8px; margin-top:10px }
.tka-att{ display:inline-flex; align-items:center; gap:7px; text-decoration:none; background:var(--surface2); border:1px solid var(--line); border-radius:9px; padding:6px 10px; font-size:12px; color:var(--text); max-width:200px }
.tka-att .icon{ width:15px; height:15px; color:var(--muted); flex:0 0 auto }
.tka-att span{ overflow:hidden; text-overflow:ellipsis; white-space:nowrap }
.tka-att i{ font-style:normal; color:var(--dim); font-size:11px }
.tka-att-img{ display:block; border-radius:10px; overflow:hidden; border:1px solid var(--line); line-height:0 }
.tka-att-img img{ max-width:170px; max-height:140px; object-fit:cover; display:block }
.tka-file{ display:flex; align-items:center; gap:8px; cursor:pointer; border:1px dashed var(--line2); border-radius:10px; padding:10px 12px; font-size:12.5px; color:var(--muted); background:var(--surface2) }
.tka-file:hover{ border-color:#22d3ee; color:var(--text) }
.tka-file .icon{ width:16px; height:16px }
.tka-file input[type=file]{ display:none }
.tka-file-list{ display:flex; flex-wrap:wrap; gap:6px }
.tka-fchip{ background:rgba(34,211,238,.15); color:#22d3ee; border-radius:7px; padding:3px 8px; font-size:11px }
</style>

{{-- ⚠️ اسکریپت مستقیم داخلِ همین `@section` است، نه `@push('scripts')`.
     لایوتِ ادمین هیچ `@stack` ندارد، پس هر چیزی که push شود بی‌صدا دور
     ریخته می‌شود — دکمه رندر می‌شد و هیچ‌وقت کار نمی‌کرد، بی‌هیچ خطایی. --}}
<script>
/*
 | تصحیحِ نگارش — فقط متنِ کادر را عوض می‌کند.
 |
 | 🔴 نسخهٔ اصلیِ کارفرما نگه داشته می‌شود تا «بازگردانی» ممکن باشد. بی‌آن،
 | یک تصحیحِ بد یعنی نوشتهٔ او از دست رفته و باید از نو بنویسد.
 */
(function () {
  var btn  = document.getElementById('tk-polish');
  var undo = document.getElementById('tk-polish-undo');
  var msg  = document.getElementById('tk-polish-msg');
  var ta   = document.querySelector('textarea[name="body"]');
  if (!btn || !ta) return;

  var original = null;

  function say(t, bad) {
    msg.textContent = t || '';
    msg.className = 'tk-polish-msg' + (bad ? ' bad' : '');
  }

  btn.addEventListener('click', async function () {
    var body = ta.value.trim();
    if (body.length < 12) { say('اول متنِ پاسخ را بنویسید.', true); return; }

    btn.disabled = true;
    say('در حال تصحیح…');

    try {
      var r = await fetch('/admin/tickets/{{ $ticket->id }}/polish', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
          'Accept': 'application/json'
        },
        body: JSON.stringify({ body: body })
      });

      /* ⚠️ متن را اول می‌خوانیم و بعد پارس می‌کنیم: اگر روزی مسیر ۳۰۲ِ HTML
         برگرداند، `r.json()` با خطای پارس می‌میرد و کاربر هیچ پیامی نمی‌بیند. */
      var raw = await r.text();
      var j;
      try { j = JSON.parse(raw); }
      catch (e) { say('پاسخِ نامعتبر از سرور.', true); return; }

      if (!j.ok) { say(j.error || 'تصحیح انجام نشد.', true); return; }

      original = ta.value;
      ta.value = j.text;
      undo.hidden = false;
      say('تصحیح شد — پیش از ارسال یک بار بخوانید.');
    } catch (e) {
      say('ارتباط برقرار نشد.', true);
    } finally {
      btn.disabled = false;
    }
  });

  undo.addEventListener('click', function () {
    if (original === null) return;
    ta.value = original;
    original = null;
    undo.hidden = true;
    say('به نوشتهٔ خودتان برگشت.');
  });

  /*
   | پیشنهادِ پاسخ — برخلافِ «تصحیح» به متنِ موجود نیاز ندارد؛ از خودِ
   | گفتگو می‌نویسد. همان `original`/`undo` مشترک است تا اگر کارفرما وسطِ
   | نوشتن دکمه را زد، متنش با یک کلیک برگردد.
   */
  var draftBtn = document.getElementById('tk-draft');
  var toneSel  = document.getElementById('tk-draft-tone');

  if (draftBtn) draftBtn.addEventListener('click', async function () {
    draftBtn.disabled = true;
    say('در حال نوشتنِ پیش‌نویس…');

    try {
      var r = await fetch('/admin/tickets/{{ $ticket->id }}/draft', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
          'Accept': 'application/json'
        },
        body: JSON.stringify({ tone: toneSel ? toneSel.value : 'n' })
      });

      var raw = await r.text();
      var j;
      try { j = JSON.parse(raw); }
      catch (e) { say('پاسخِ نامعتبر از سرور.', true); return; }

      if (!j.ok) { say(j.error || 'پیش‌نویس ساخته نشد.', true); return; }

      original = ta.value;
      ta.value = j.text;
      undo.hidden = false;
      say('پیش‌نویس آماده است — پیش از ارسال حتماً بخوانید و در صورت نیاز ویرایش کنید.');
      ta.focus();
    } catch (e) {
      say('ارتباط برقرار نشد.', true);
    } finally {
      draftBtn.disabled = false;
    }
  });
})();
</script>
@endsection


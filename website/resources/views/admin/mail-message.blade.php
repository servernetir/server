@extends('admin.layout')
@section('title', 'نامه — '.($m->subject ?: 'بدون موضوع'))
@section('nav_mail', 'on')
@section('head')<link rel="stylesheet" href="{{ asset_ver('assets/css/marketing.css') }}"><meta name="robots" content="noindex,nofollow">@endsection
@section('content')

<div class="mk">

  {{-- ══ سرآیند + ناوبری ══ --}}
  <div class="mk-head">
    <div>
      <h2>{{ $m->subject ?: '(بدون موضوع)' }}</h2>
      <p>
        {{ $m->from_name ?: $m->from_email }}
        · <span class="mk-ltr">{{ $m->from_email }}</span>
        · {{ $m->accountLabel() }}
        · <span class="mk-ltr">{{ $m->received_at?->format('Y-m-d H:i') }}</span>
        @if($fromCount > 0)
          ·
          <a href="/admin/mail?show=all&from={{ urlencode($m->from_email) }}">
            {{ fa_num($fromCount) }} نامهٔ دیگر از این فرستنده
          </a>
        @endif
      </p>
    </div>
    <div class="mk-nav">
      {{--
        ⚠️ «بعدی» یعنی قدیمی‌تر، چون فهرست از تازه به کهنه است. دکمهٔ نبود
        غیرفعال نشان داده می‌شود نه پنهان — جایِ خالیِ ناگهانی، کاربر را
        وامی‌دارد دنبالِ دکمه‌ای بگردد که خودش جابه‌جا شده.
      --}}
      @if($newer)
        <a class="mk-btn" href="/admin/mail/{{ $newer->id }}" title="{{ $newer->subject }}">تازه‌تر ›</a>
      @else
        <span class="mk-btn is-off">تازه‌تر ›</span>
      @endif

      @if($older)
        <a class="mk-btn" href="/admin/mail/{{ $older->id }}" title="{{ $older->subject }}">‹ قدیمی‌تر</a>
      @else
        <span class="mk-btn is-off">‹ قدیمی‌تر</span>
      @endif

      <a class="mk-btn" href="/admin/mail?box={{ $m->account }}">صندوق</a>
    </div>
  </div>

  {{-- ══ نوارِ کارها ══ --}}
  <div class="mk-acts">
    @if(! $m->handled_at)
      <form method="post" action="/admin/mail/{{ $m->id }}/handled">@csrf<button class="mk-btn" type="submit">رسیدگی شد</button></form>
    @else
      <form method="post" action="/admin/mail/{{ $m->id }}/reopen">@csrf<button class="mk-btn" type="submit">دوباره باز کن</button></form>
    @endif

    <form method="post" action="/admin/mail/{{ $m->id }}/move/archive"
          data-confirm="این نامه به بایگانیِ صندوق می‌رود.">
      @csrf<button class="mk-btn" type="submit">بایگانی</button>
    </form>

    <form method="post" action="/admin/mail/{{ $m->id }}/move/junk"
          data-confirm="این نامه به پوشهٔ هرزنامه می‌رود و «اسپم» علامت می‌خورد.">
      @csrf<button class="mk-btn" type="submit">هرزنامه است</button>
    </form>

    {{--
      🔴 متنِ تأیید دقیقاً همان کاری را می‌گوید که کد می‌کند: **سطلِ زباله**،
      نه نابودی. اگر روزی رفتار به حذفِ قطعی عوض شد، این متن هم باید عوض شود —
      وگرنه کاربر بر اساسِ یک وعدهٔ غلط کلیک می‌کند.
    --}}
    <form method="post" action="/admin/mail/{{ $m->id }}/move/trash"
          data-confirm="نامه به سطلِ زباله می‌رود. از وب‌میل قابلِ برگرداندن است." data-confirm-danger>
      @csrf<button class="mk-btn is-bad" type="submit">حذف</button>
    </form>
  </div>

  {{--
    ══ چرا نامه باز نشد ══

    🔴 بدنه در دیتابیس نیست و هر بار زنده از IMAP می‌آید. پس «باز نشد» یک
    حالتِ عادی است، نه خرابیِ نادر: رمز عوض شده، نامه در وب‌میل پاک شده، یا
    سرور جواب نداده. متنِ واقعیِ خطا این‌جا می‌آید — همان قاعدهٔ نوارِ خطای
    فهرست، چون laravel.logِ سرور از پنل خوانده نمی‌شود.
  --}}
  @if(! $ok)
    <div class="mk-alert">
      <b>متنِ این نامه خوانده نشد</b>
      <span class="mk-ltr">{{ $error }}</span>
      <small>
        سرآیندی که این‌جا می‌بینید از پنل می‌آید و سرِ جایش است؛ فقط بدنه‌اش که
        زنده از صندوق خوانده می‌شود نیامد. اگر رمزِ صندوق عوض شده، در
        <code dir="ltr">.env</code> سرور به‌روزش کنید.
      </small>
    </div>
  @endif

  @if($truncated)
    <div class="mk-note">
      <div>
        <b>این نامه بزرگ‌تر از سقفِ خواندن است</b> و بریده نشان داده می‌شود
        (حجمِ واقعی: {{ fa_num(number_format($size / 1024)) }} کیلوبایت).
        پیوستِ نامهٔ بریده هم ناقص است و دانلود نمی‌شود؛ برای کامل‌ش وب‌میل.
      </div>
    </div>
  @endif

  @if($ok)

    {{-- گیرنده‌ها، اگر نامه بیش از یک نفر داشته --}}
    @if(trim((string) ($mail['to'] ?? '')) !== '')
      <div class="mk-meta"><b>به:</b> <span class="mk-ltr">{{ $mail['to'] }}</span></div>
    @endif

    {{-- ══ تصویرهای بسته ══ --}}
    @if($blocked > 0 && ! $images)
      <div class="mk-note">
        <div>
          <b>{{ fa_num($blocked) }} تصویر بسته شد.</b>
          تصویرِ داخلِ نامه در لحظهٔ باز شدن به فرستنده خبر می‌دهد که این نشانی
          زنده است و نامه خوانده شد — برای اسپمر تأییدِ طلا. اگر فرستنده را
          می‌شناسید، بازشان کنید.
          <a class="mk-btn" style="margin-inline-start:8px" href="/admin/mail/{{ $m->id }}?images=1" rel="nofollow">تصاویر را نشان بده</a>
        </div>
      </div>
    @elseif($images)
      <div class="mk-note info">
        <div>
          تصویرهای بیرونی برای این نامه باز شده‌اند.
          <a class="mk-btn" style="margin-inline-start:8px" href="/admin/mail/{{ $m->id }}" rel="nofollow">دوباره ببند</a>
        </div>
      </div>
    @endif

    {{-- ══ متنِ نامه ══ --}}
    <div class="mk-draft">
      <div class="mk-draft-h">
        <b>متنِ نامه</b>
        <span class="mk-tag c">{{ $html !== '' ? 'HTML' : 'متنِ ساده' }}</span>
      </div>
      <div class="mk-draft-b">
        @if($html !== '')
          {{--
            🔴 `{!! !!}` این‌جا عمدی و **فقط** بعد از MailHtmlSanitizer است.
            آن کلاس روی HtmlSanitizer سوار می‌شود (script/style/on*/javascript:)
            و بالایش تصویرِ بیرونی و لینک را هم مهار می‌کند. اگر روزی این خط را
            جابه‌جا کردی، پاک‌سازی باید همراهش برود — نامهٔ ورودی را دشمن
            می‌نویسد.
          --}}
          <div class="mk-mail-body">{!! $html !!}</div>
        @elseif(trim($text) !== '')
          <pre>{{ $text }}</pre>
        @else
          <p style="color:var(--dim)">این نامه متنی برای نمایش ندارد.</p>
        @endif
      </div>
    </div>

    {{-- ══ پیوست‌ها ══ --}}
    @if(! empty($mail['attachments']))
      <div class="mk-draft">
        <div class="mk-draft-h">
          <b>پیوست‌ها</b>
          <span class="mk-tag">{{ fa_num(count($mail['attachments'])) }} فایل</span>
        </div>
        <div class="mk-draft-f">
          @foreach($mail['attachments'] as $i => $a)
            @php $label = $a['name'].' · '.fa_num(number_format(($a['size'] ?? 0) / 1024, 1)).' KB'; @endphp
            {{--
              ⚠️ روی نامهٔ بریده اصلاً لینک ساخته نمی‌شود. بایت‌های پیوستِ آن
              نامه ناقص‌اند و مسیرِ دانلود صریح ردش می‌کند — پس لینکی که
              همیشه ۴۰۴ می‌دهد، فقط کاربر را سرگردان می‌کند.
            --}}
            @if($truncated)
              <span class="mk-tag">{{ $label }} — ناقص</span>
            @else
              <a class="mk-btn" rel="nofollow" href="/admin/mail/{{ $m->id }}/attachment/{{ $i }}">{{ $label }}</a>
            @endif
          @endforeach
        </div>
      </div>
    @endif

  @endif

  {{-- ══ یادآوری در تقویم ══ --}}
  <div class="mk-draft">
    <div class="mk-draft-h">
      <b>یادآوری در تقویمِ کسب‌وکار</b>
      <span class="mk-tag">پیگیری یادت نرود</span>
    </div>
    <form method="post" action="/admin/mail/{{ $m->id }}/remind">
      @csrf
      <div class="mk-draft-b mk-remind">
        <div>
          <label>کِی یادت بیاورم؟</label>
          <select name="when">
            @foreach($reminders as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
          </select>
        </div>
        <div style="flex:1;min-width:220px">
          <label>یادداشت (اختیاری)</label>
          <input type="text" name="note" maxlength="500" placeholder="مثلاً: قیمت را بفرست">
        </div>
        <div style="align-self:end"><button class="mk-btn" type="submit">در تقویم بگذار</button></div>
      </div>
    </form>
    <div class="mk-draft-f">
      <small style="color:var(--dim)">
        فقط عنوان و نشانیِ فرستنده و یک لینکِ برگشت در تقویم می‌نشیند — متنِ
        نامه کپی نمی‌شود.
      </small>
    </div>
  </div>

  {{-- ══ پاسخ ══ --}}
  <div class="mk-draft">
    <div class="mk-draft-h">
      <b>پاسخ</b>
      <span class="mk-tag {{ $canReply ? 'g' : 'r' }}">
        {{ $canReply ? 'از '.$m->accountLabel() : 'ممکن نیست' }}
      </span>
    </div>

    @if($canReply)
      {{-- ⚠️ `enctype` لازم است وگرنه پیوست‌ها بی‌هیچ خطایی به سرور نمی‌رسند. --}}
      <form method="post" action="/admin/mail/{{ $m->id }}/reply" enctype="multipart/form-data" id="mail-reply">
        @csrf
        <div class="mk-draft-b">
          {{-- همان ویرایشگرِ خودمیزبانِ پنل: بی‌CDN، پس CSP بلاکش نمی‌کند --}}
          <div class="wysiwyg-tb">
            <button type="button" data-cmd="bold" title="پررنگ"><b>B</b></button>
            <button type="button" data-cmd="italic" title="کج"><i>I</i></button>
            <button type="button" data-cmd="underline" title="زیرخط"><u>U</u></button>
            <button type="button" data-cmd="h3" title="عنوان">H</button>
            <button type="button" data-cmd="ul" title="فهرست">••</button>
            <button type="button" data-cmd="ol" title="فهرست شماره‌دار">1.</button>
            <button type="button" data-cmd="quote" title="نقلِ‌قول">❝</button>
            <button type="button" data-cmd="link" title="پیوند">🔗</button>
            <button type="button" data-cmd="clear" title="برداشتنِ قالب">✕</button>
          </div>
          <div class="wysiwyg" contenteditable="true" id="reply-ed" style="min-height:190px" dir="rtl"></div>
          <textarea name="body" id="reply-body" hidden>{{ old('body') }}</textarea>
          @error('body')<div class="mk-err">{{ $message }}</div>@enderror

          <div class="mk-files">
            <label for="reply-files" class="mk-btn">افزودن پیوست</label>
            <input type="file" name="files[]" id="reply-files" multiple hidden>
            <span id="reply-files-list" style="color:var(--dim);font-size:12.5px">فایلی انتخاب نشده</span>
          </div>
          @error('files')<div class="mk-err">{{ $message }}</div>@enderror
          @error('files.*')<div class="mk-err">{{ $message }}</div>@enderror
        </div>
        <div class="mk-draft-f">
          <button class="mk-btn" type="submit">بفرست</button>
          <small style="color:var(--dim)">
            امضا و نقلِ نامهٔ اصلی خودکار اضافه می‌شوند. نقلِ‌قول از پیش‌نمایشِ
            ذخیره‌شده است، نه کلِ متن. حداکثر {{ fa_num(5) }} فایل و {{ fa_num(10) }} مگابایت.
          </small>
        </div>
      </form>
    @else
      <div class="mk-draft-b">
        <p style="color:var(--muted);line-height:1.95">
          {{ $replyBlock }}
          <br>
          پاسخ فقط از نشانیِ خودِ همان صندوق فرستاده می‌شود. اگر از نشانیِ دیگری
          برود، هم رشتهٔ گفتگو دو تکه می‌شود و هم SPF/DKIM ردش می‌کند و نامه
          بی‌هیچ خطایی در اسپمِ گیرنده می‌نشیند.
        </p>
      </div>
    @endif
  </div>

</div>

@if($canReply)
<script>
(function () {
  var ed = document.getElementById('reply-ed');
  var box = document.getElementById('reply-body');
  var form = document.getElementById('mail-reply');
  if (!ed || !box || !form) { return; }

  // پیش‌نویسِ برگشتی بعد از خطای اعتبارسنجی — وگرنه کاربر همه را دوباره می‌نویسد.
  if (box.value.trim() !== '') { ed.innerHTML = box.value; }

  document.querySelectorAll('.wysiwyg-tb button').forEach(function (b) {
    b.addEventListener('click', function () {
      var c = b.dataset.cmd;
      ed.focus();
      if (c === 'bold') document.execCommand('bold');
      else if (c === 'italic') document.execCommand('italic');
      else if (c === 'underline') document.execCommand('underline');
      else if (c === 'h3') document.execCommand('formatBlock', false, 'h3');
      else if (c === 'ul') document.execCommand('insertUnorderedList');
      else if (c === 'ol') document.execCommand('insertOrderedList');
      else if (c === 'quote') document.execCommand('formatBlock', false, 'blockquote');
      else if (c === 'clear') document.execCommand('removeFormat');
      else if (c === 'link') {
        // ⚠️ prompt() مرورگر یک دیالوگِ مسدودکننده است ولی این‌جا تنها راهِ
        // بی‌وابستگی است؛ کاربر خودش زده و انتظارش را دارد.
        var u = window.prompt('نشانی پیوند:', 'https://');
        if (u) { document.execCommand('createLink', false, u); }
      }
    });
  });

  // فهرستِ فایل‌های انتخابی — بی‌این، کاربر نمی‌داند پیوستش سوار شده یا نه.
  var input = document.getElementById('reply-files');
  var list = document.getElementById('reply-files-list');
  if (input && list) {
    input.addEventListener('change', function () {
      if (!input.files || !input.files.length) { list.textContent = 'فایلی انتخاب نشده'; return; }
      var names = [];
      for (var i = 0; i < input.files.length; i++) {
        names.push(input.files[i].name + ' (' + Math.round(input.files[i].size / 1024) + ' KB)');
      }
      list.textContent = names.join(' · ');
    });
  }

  // ⚠️ محتوای contenteditable خودش ارسال نمی‌شود؛ باید پیش از submit در
  // textarea ریخته شود وگرنه پاسخ بی‌صدا خالی می‌رود.
  form.addEventListener('submit', function () { box.value = ed.innerHTML.trim(); });
})();
</script>
@endif
@endsection

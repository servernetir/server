@extends('admin.layout')
@section('title', 'ویرایش پیام — '.$t->title)
@section('nav_templates', 'active')
@section('content')

<div class="ad-panel">
  <div class="ad-panel-h"><h2>{{ $t->title }}</h2></div>

  @if(session('ok'))<div class="ad-flash ok" style="margin:0 18px 14px">{{ session('ok') }}</div>@endif

  @unless($t->isWired())
    <div class="ad-flash err" style="margin:0 18px 14px">
      این الگو هنوز به هیچ رویدادی در کد وصل نیست، پس ویرایشش <b>به مشتری نمی‌رسد</b>.
      متن را می‌توانید آماده کنید، ولی تا وصل‌شدن، پیام از جای دیگری می‌رود.
    </div>
  @else
    {{-- ⚠️ هشدار روی **کانال** است نه روی خالی‌بودنِ فیلد. سیدر برای بعضی
         رویدادها متنِ ایمیل هم می‌گذارد، ولی فراخوانشان فقط بله/اعلان می‌فرستد؛
         بدونِ این هشدار، مدیر ایمیلی را ویرایش می‌کرد که هرگز ارسال نمی‌شود. --}}
    @unless(in_array('email', $t->wiredChannels()))
      <div class="ad-flash" style="margin:0 18px 14px;background:#fbbf2422;color:#fbbf24">
        این رویداد فقط <b>بله و اعلان پنل</b> دارد. متن ایمیلِ پایین ذخیره می‌شود
        ولی <b>ارسال نمی‌شود</b> — ایمیلِ این رویداد یا اصلاً وجود ندارد یا قالبِ
        اختصاصیِ خودش را دارد.
      </div>
    @elseif(blank($t->email_body))
      <div class="ad-flash" style="margin:0 18px 14px;background:#fbbf2422;color:#fbbf24">
        متن ایمیل خالی است، پس فعلاً ایمیلی فرستاده نمی‌شود — اگر متنی بنویسید، از همین‌جا می‌رود.
      </div>
    @endunless
  @endunless
  @if($errors->any())
    <div class="ad-flash err" style="margin:0 18px 14px">
      @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
    </div>
  @endif

  <form method="post" action="/admin/templates/{{ $t->id }}" style="padding:0 18px 18px" id="tpl-form">
    @csrf

    {{-- ── متغیرها ── --}}
    @if(!empty($t->variables))
      <div class="ad-field">
        <label>متغیرهای در دسترس — روی هرکدام کلیک کنید تا در محل مکان‌نما درج شود</label>
        <div style="display:flex;gap:7px;flex-wrap:wrap">
          @foreach($t->variables as $v)
            <button type="button" class="btn btn-glass tpl-var"
                    style="font-size:12px;padding:5px 11px" data-var="{{ '{'.$v['name'].'}' }}"
                    title="{{ $v['desc'] ?? '' }}">
              <span dir="ltr">{{ '{'.$v['name'].'}' }}</span> — {{ $v['desc'] ?? '' }}
            </button>
          @endforeach
        </div>
        <small style="color:var(--muted);font-size:11.5px;display:block;margin-top:7px">
          اگر متغیری را بنویسید که کد نمی‌فرستد، آن پیام <b>با متن قدیمی</b> می‌رود نه با متن ناقص.
        </small>
      </div>
    @endif

    {{-- ── پیامک: فقط اطلاع‌رسانی ── --}}
    <div class="ad-field">
      <label>پیامک</label>
      <div style="background:var(--surface2);border:1px solid var(--line2);border-radius:11px;padding:12px 14px;font-size:12.5px;color:var(--muted);line-height:1.9">
        @if($t->sms_event)
          این پیام با الگوی <b dir="ltr">{{ $t->sms_event }}</b> فرستاده می‌شود.
          <br>متن الگو در پنل اپراتور پیامک نگهداری و تأیید می‌شود، پس از این‌جا ویرایش نمی‌شود؛
          ما فقط کد الگو و متغیرها را می‌فرستیم.
        @else
          برای این رویداد الگوی پیامکی تعریف نشده — متن کوتاهِ پایین به‌عنوان پیام آزاد می‌رود.
        @endif
      </div>
    </div>

    {{-- ── متن کوتاه: بله و اعلان ── --}}
    <div class="ad-field">
      <label>متن کوتاه — بله و اعلان پنل (و پیامکِ آزاد)</label>
      <textarea class="ad-input" name="bale_body" rows="3" maxlength="4000"
                dir="rtl">{{ old('bale_body', $t->bale_body) }}</textarea>
    </div>

    {{-- ── ایمیل ── --}}
    <div class="ad-field">
      <label>موضوع ایمیل</label>
      <input class="ad-input" type="text" name="email_subject" maxlength="200"
             value="{{ old('email_subject', $t->email_subject) }}">
    </div>

    <div class="ad-field">
      <label>متن ایمیل</label>
      {{-- همان ویرایشگرِ خودمیزبانِ بلاگ: بی‌CDN، پس CSP بلاکش نمی‌کند --}}
      <div class="wysiwyg-tb">
        <button type="button" data-cmd="bold" title="پررنگ">B</button>
        <button type="button" data-cmd="italic" title="کج"><i>I</i></button>
        <button type="button" data-cmd="h3" title="عنوان">H3</button>
        <button type="button" data-cmd="ul" title="فهرست">••</button>
        <button type="button" data-cmd="ol" title="فهرست شماره‌دار">1.</button>
        <button type="button" data-cmd="p" title="پاراگراف">¶</button>
      </div>
      <div class="wysiwyg" contenteditable="true" id="tpl-ed" style="min-height:200px">{!! old('email_body', $t->email_body) !!}</div>
      <textarea name="email_body" id="tpl-body" hidden></textarea>
    </div>

    <div class="ad-field">
      <label style="display:flex;align-items:center;gap:9px;cursor:pointer">
        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $t->is_active))>
        <span>این اعلان فعال باشد</span>
      </label>
      <small style="color:var(--muted);font-size:11.5px">خاموش که باشد، متنِ کد فرستاده می‌شود نه این الگو.</small>
    </div>

    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <button class="btn btn-primary" style="font-size:13px">ذخیره</button>
      <a class="btn btn-glass" style="font-size:12.5px" href="/admin/templates">بازگشت</a>
    </div>
  </form>

  <form method="post" action="/admin/templates/{{ $t->id }}/test" style="padding:0 18px 18px">
    @csrf
    <button class="btn btn-glass" style="font-size:12.5px">ارسال آزمایشی به ایمیل خودم</button>
    <small style="color:var(--muted);font-size:11.5px;display:block;margin-top:7px">
      متغیرها با مقدار نمونه پر می‌شوند تا پیام نهایی را همان‌طور ببینید که مشتری می‌بیند.
    </small>
  </form>
</div>

<script>
(function () {
  var ed = document.getElementById('tpl-ed');
  var box = document.getElementById('tpl-body');
  var form = document.getElementById('tpl-form');
  if (!ed || !box || !form) { return; }

  // نوار ابزار — همان execCommand ویرایشگر بلاگ
  document.querySelectorAll('.wysiwyg-tb button').forEach(function (b) {
    b.addEventListener('click', function () {
      var c = b.dataset.cmd;
      ed.focus();
      if (c === 'bold') document.execCommand('bold');
      else if (c === 'italic') document.execCommand('italic');
      else if (c === 'h3') document.execCommand('formatBlock', false, 'h3');
      else if (c === 'p') document.execCommand('formatBlock', false, 'p');
      else if (c === 'ul') document.execCommand('insertUnorderedList');
      else if (c === 'ol') document.execCommand('insertOrderedList');
    });
  });

  // درجِ متغیر در آخرین کادری که کاربر داشت تایپ می‌کرد — نه همیشه ایمیل،
  // چون متغیر معمولاً برای متن کوتاه هم لازم است.
  var last = ed;
  var short = form.querySelector('textarea[name="bale_body"]');
  var subj = form.querySelector('input[name="email_subject"]');
  [ed, short, subj].forEach(function (el) {
    if (el) el.addEventListener('focus', function () { last = el; });
  });

  document.querySelectorAll('.tpl-var').forEach(function (b) {
    b.addEventListener('click', function () {
      var v = b.dataset.var;
      if (last === ed) { ed.focus(); document.execCommand('insertText', false, v); return; }
      var s = last.selectionStart || 0, e = last.selectionEnd || 0;
      last.value = last.value.slice(0, s) + v + last.value.slice(e);
      last.focus();
      last.selectionStart = last.selectionEnd = s + v.length;
    });
  });

  // ⚠️ محتوای contenteditable خودش ارسال نمی‌شود؛ باید پیش از submit در
  // textarea ریخته شود وگرنه متن ایمیل بی‌صدا خالی ذخیره می‌شود.
  form.addEventListener('submit', function () { box.value = ed.innerHTML.trim(); });
})();
</script>
@endsection

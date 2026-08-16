@extends('admin.layout')
@section('title', 'نامه — '.($m->subject ?: 'بدون موضوع'))
@section('nav_mail', 'on')
@section('head')<link rel="stylesheet" href="{{ asset_ver('assets/css/marketing.css') }}"><meta name="robots" content="noindex,nofollow">@endsection
@section('content')

<div class="mk">

  <div class="mk-head">
    <div>
      <h2>{{ $m->subject ?: '(بدون موضوع)' }}</h2>
      <p>
        {{ $m->from_name ?: $m->from_email }}
        · <span class="mk-ltr">{{ $m->from_email }}</span>
        · {{ $m->accountLabel() }}
        · <span class="mk-ltr">{{ $m->received_at?->format('Y-m-d H:i') }}</span>
      </p>
    </div>
    <div>
      <a class="mk-btn" href="/admin/mail?box={{ $m->account }}">بازگشت به صندوق</a>
    </div>
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

  {{-- ══ پاسخ ══ --}}
  <div class="mk-draft">
    <div class="mk-draft-h">
      <b>پاسخ</b>
      <span class="mk-tag {{ $canReply ? 'g' : 'r' }}">
        {{ $canReply ? 'از '.$m->accountLabel() : 'ممکن نیست' }}
      </span>
    </div>

    @if($canReply)
      <form method="post" action="/admin/mail/{{ $m->id }}/reply">
        @csrf
        <div class="mk-draft-b">
          <textarea name="body" rows="9" required minlength="2" maxlength="8000"
                    placeholder="متنِ پاسخ…"
                    style="width:100%;background:transparent;border:1px solid var(--line2);border-radius:10px;padding:12px;color:inherit;font:inherit;line-height:1.95"
          >{{ old('body') }}</textarea>
          @error('body')<div style="color:#ff6b6b;font-size:13px;margin-top:8px">{{ $message }}</div>@enderror
        </div>
        <div class="mk-draft-f">
          <button class="mk-btn" type="submit">بفرست</button>
          <small style="color:var(--dim)">
            امضا و نقلِ نامهٔ اصلی خودکار اضافه می‌شوند. نقلِ‌قول از پیش‌نمایشِ
            ذخیره‌شده است، نه کلِ متن — و خودِ نامه همین را می‌گوید.
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
@endsection

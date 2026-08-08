@extends('admin.layout')

@section('title', 'کیف‌های رمزارز')
@section('nav_crypto', 'on')

@section('content')

@if(session('ok'))<div class="ad-flash">{{ session('ok') }}</div>@endif
@if($errors->any())<div class="ad-flash" style="background:rgba(255,107,107,.12);color:#ff6b6b">{{ $errors->first() }}</div>@endif

<div class="ad-panel">
  <div class="ad-panel-h"><h2>استخر آدرس‌های دریافت</h2><span class="ad-pill">{{ $wallets->count() }}</span></div>

  <p style="padding:0 18px;color:var(--muted);font-size:13.5px;line-height:1.9">
    آدرس‌ها را در کیف خودتان (TronLink، تراست‌ولت) بسازید و فقط <b>خودِ آدرس</b> را
    این‌جا بچسبانید. سیستم به هر پرداخت یکی می‌دهد و بعد از تسویه یا انقضا آزادش
    می‌کند. <b style="color:#fbbf24">هرگز کلید خصوصی یا عبارت بازیابی را این‌جا
    وارد نکنید</b> — کل ایمنی این طراحی بر این است که سرور توان خرج‌کردن ندارد.
  </p>

  @if($notReady)
    <p style="padding:18px;color:#fbbf24">جدول‌های رمزارز هنوز روی این سرور ساخته نشده‌اند. پس از اجرای مهاجرت فعال می‌شود.</p>
  @elseif($wallets->isEmpty())
    <p style="padding:16px;color:var(--dim)">هنوز آدرسی اضافه نکرده‌اید. تا وقتی استخر خالی باشد، گزینهٔ رمزارز به مشتری <b>نشان داده نمی‌شود</b>.</p>
  @else
    <table class="ad-table">
      <thead><tr><th>زنجیره</th><th>آدرس</th><th>وضعیت</th><th></th></tr></thead>
      <tbody>
      @foreach($wallets as $w)
        <tr>
          <td><span class="ad-badge" style="background:rgba(239,68,68,.12);color:#ef4444">{{ strtoupper($w->chain) }}</span></td>
          <td dir="ltr" style="font-family:ui-monospace,monospace;font-size:12.5px;overflow-wrap:anywhere">{{ $w->address }}</td>
          <td>
            @if(! $w->is_active)
              <span class="ad-badge" style="background:rgba(148,163,184,.12);color:var(--muted)">غیرفعال</span>
            @elseif($w->busy_payment_id)
              <span class="ad-badge" style="background:rgba(251,191,36,.14);color:#fbbf24">در حال استفاده</span>
            @elseif($w->cooldown_until && $w->cooldown_until->isFuture())
              <span class="ad-badge" style="background:rgba(56,189,248,.14);color:#38bdf8"
                    title="پس از آزادسازی، برای جلوگیری از پرداخت دیرهنگام مدتی رزرو می‌مانَد">در دورهٔ خنک‌شدن</span>
            @else
              <span class="ad-badge" style="background:rgba(52,211,153,.12);color:#34d399">آزاد</span>
            @endif
          </td>
          <td style="text-align:left">
            <form method="post" action="/admin/crypto-wallets/{{ $w->id }}/toggle" style="display:inline">
              @csrf<button class="btn btn-glass" style="padding:6px 12px;font-size:12.5px">{{ $w->is_active ? 'غیرفعال کن' : 'فعال کن' }}</button>
            </form>
          </td>
        </tr>
      @endforeach
      </tbody>
    </table>
  @endif
</div>

@if(! $notReady)
<div class="ad-panel">
  <div class="ad-panel-h"><h2>افزودن آدرس</h2></div>
  <div style="padding:16px 18px">
    <form method="post" action="/admin/crypto-wallets">
      @csrf
      <label style="display:block;font-size:12.5px;color:var(--muted);margin-bottom:6px">زنجیره</label>
      <select name="chain" class="ad-input" style="max-width:220px"><option value="tron">Tron (TRC20)</option></select>

      <label style="display:block;font-size:12.5px;color:var(--muted);margin:14px 0 6px">
        آدرس‌ها — هر خط یکی (یا با کاما جدا کنید)
      </label>
      <textarea name="addresses" class="ad-input" dir="ltr" rows="7"
                style="font-family:ui-monospace,monospace;font-size:12.5px"
                placeholder="TXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX"></textarea>

      <p style="font-size:12px;color:var(--muted);line-height:1.9;margin:10px 0 0">
        آدرس ترون با <code>T</code> شروع می‌شود و ۳۴ نویسه است؛ موارد نامعتبر یا
        تکراری بی‌سروصدا رد می‌شوند. ۱۰ تا ۲۰ آدرس برای مدت‌ها کافی است، چون هر
        آدرس بعد از تسویه دوباره به استخر برمی‌گردد.
      </p>

      <button class="btn btn-primary" style="margin-top:14px;font-size:13px;padding:9px 20px">افزودن</button>
    </form>
  </div>
</div>

{{-- پرداخت‌های در جریان — چرا یک آدرس «مشغول» است و چه چیزی روی هواست --}}
<div class="ad-panel">
  <div class="ad-panel-h"><h2>پرداخت‌های در جریان</h2><span class="ad-pill">{{ $inflight->count() }}</span></div>

  @if($inflight->isEmpty())
    <p style="padding:16px;color:var(--dim)">الان هیچ پرداخت رمزارزی باز نیست.</p>
  @else
    <p style="padding:0 18px;color:var(--muted);font-size:13px;line-height:1.9">
      هر ردیف یک آدرس را <b>قفل</b> نگه داشته است. تا وقتی همهٔ آدرس‌های یک زنجیره
      مشغول باشند، آن گزینه به مشتری با برچسب «موقتاً در دسترس نیست» نشان داده
      می‌شود. آدرسِ پرداختِ منقضی هم بلافاصله برنمی‌گردد؛ چند ساعت در دورهٔ
      خنک‌شدن می‌مانَد تا واریزِ دیرهنگام به فاکتور نفر بعد ننشیند.
    </p>
    <table class="ad-table">
      <thead><tr><th>فاکتور</th><th>دارایی</th><th>مبلغ</th><th>آدرس</th><th>مهلت</th><th>وضعیت</th></tr></thead>
      <tbody>
      @foreach($inflight as $p)
        <tr>
          <td><a href="/admin/invoices/{{ $p->invoice_id }}" style="color:#22d3ee">#{{ $p->invoice_id }}</a></td>
          <td dir="ltr">{{ $p->asset }}</td>
          <td dir="ltr" style="font-variant-numeric:tabular-nums">{{ $p->amountHuman() }}</td>
          <td dir="ltr" style="font-family:ui-monospace,monospace;font-size:11.5px;overflow-wrap:anywhere;max-width:220px">{{ $p->address ?: '—' }}</td>
          <td dir="ltr" style="font-variant-numeric:tabular-nums">
            @if($p->isOpen() && ! $p->isExpired())
              {{ (int) ceil($p->secondsLeft() / 60) }} دقیقه
            @else
              —
            @endif
          </td>
          <td>
            @if($p->status === 'seen')
              <span class="ad-badge" style="background:rgba(56,189,248,.14);color:#38bdf8">واریز دیده شد</span>
            @elseif($p->status === 'expired')
              <span class="ad-badge" style="background:rgba(148,163,184,.12);color:var(--muted)">منقضی</span>
            @elseif($p->isExpired())
              <span class="ad-badge" style="background:rgba(251,191,36,.14);color:#fbbf24">مهلت تمام — در انتظار پایش</span>
            @else
              <span class="ad-badge" style="background:rgba(52,211,153,.12);color:#34d399">در انتظار واریز</span>
            @endif
          </td>
        </tr>
      @endforeach
      </tbody>
    </table>
  @endif
</div>

{{-- 🔴 پولی که خودکار تسویه نشد اینجاست و **نباید نادیده بماند** --}}
<div class="ad-panel">
  <div class="ad-panel-h"><h2>نیازمند بازبینی</h2><span class="ad-pill">{{ $review->count() }}</span></div>

  @if($review->isEmpty())
    <p style="padding:16px;color:var(--dim)">چیزی در انتظار بازبینی نیست.</p>
  @else
    <p style="padding:0 18px;color:var(--muted);font-size:13px;line-height:1.9">
      این‌ها پرداخت‌هایی هستند که سیستم <b>عمداً</b> خودکار تأییدشان نکرد — کم‌پرداخت،
      یا فاکتوری که دیگر قابل پرداخت نبود. پول رسیده و گم نشده؛ تصمیمش با شماست.
    </p>
    <table class="ad-table">
      <thead><tr><th>فاکتور</th><th>دارایی</th><th>رسیده / انتظار</th><th>تراکنش</th><th>توضیح</th></tr></thead>
      <tbody>
      @foreach($review as $p)
        <tr>
          <td><a href="/admin/invoices/{{ $p->invoice_id }}" style="color:#22d3ee">#{{ $p->invoice_id }}</a></td>
          <td dir="ltr">{{ $p->asset }}</td>
          <td dir="ltr" style="font-variant-numeric:tabular-nums">
            {{ number_format($p->received_atomic / (10 ** $p->decimals), 6) }} /
            {{ number_format($p->amount_atomic / (10 ** $p->decimals), 6) }}
          </td>
          <td dir="ltr" style="font-size:11.5px;overflow-wrap:anywhere;max-width:220px">{{ $p->txid ?: '—' }}</td>
          <td style="color:var(--muted);font-size:12.5px">{{ $p->note ?: '—' }}</td>
        </tr>
      @endforeach
      </tbody>
    </table>
  @endif
</div>
@endif
@endsection

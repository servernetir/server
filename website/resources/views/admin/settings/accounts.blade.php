{{--
  تبِ حساب‌ها — **همهٔ** مقصدهای دریافتِ پول در یک جا.

  تا امروز سه منویِ جدا بود: حسابِ بانکیِ ایران در «تنظیمات»، حساب‌های ارزی در
  «حساب‌های ارزی و رمزارز»، و استخرِ آدرس در «کیف‌های رمزارز». سه صفحه برای یک
  پرسش («پول از کجا می‌آید؟») یعنی مدیر هر بار باید یادش بماند کدام کجاست.

  ⚠️ سه فرمِ **مستقل** این‌جاست که به سه مسیرِ متفاوت POST می‌کنند. تودرتو
  نیستند و نباید بشوند.
--}}

{{-- ═══ ۱) حسابِ بانکیِ ایران — تنها بخشی که به /admin/settings می‌رود ═══ --}}
<form method="post" action="/admin/settings">
  @csrf
  <input type="hidden" name="tab" value="accounts">

  <div class="ad-panel">
    <div class="ad-panel-h"><h2>حساب بانکی شرکت (ریالی)</h2></div>
    <p class="set-lead">
      این مشخصات به مشتری نشان داده می‌شود تا واریز کند. تا وقتی شبا یا شمارهٔ حساب
      را وارد نکنید، گزینهٔ «واریز به حساب» در صفحهٔ پرداخت نمایش داده نمی‌شود.
    </p>
    <div class="set-grid" style="padding:0 18px 18px">
      <label class="set-f full">نام صاحب حساب
        <input type="text" name="bank_holder" value="{{ $bank['bank_holder'] }}" maxlength="120" placeholder="اطمینان داده‌پردازان دانش"></label>
      <label class="set-f">نام بانک
        <input type="text" name="bank_name" value="{{ $bank['bank_name'] }}" maxlength="80" placeholder="ملت / سامان / …"></label>
      <label class="set-f">شمارهٔ کارت
        <input type="text" name="bank_card" value="{{ $bank['bank_card'] }}" maxlength="20" dir="ltr" placeholder="6104-****-****-****"></label>
      <label class="set-f">شبا (بدون IR)
        <input type="text" name="bank_sheba" value="{{ $bank['bank_sheba'] }}" maxlength="34" dir="ltr" placeholder="000000000000000000000000"></label>
      <label class="set-f">شمارهٔ حساب
        <input type="text" name="bank_account" value="{{ $bank['bank_account'] }}" maxlength="40" dir="ltr"></label>
      <label class="set-f full">توضیح (اختیاری)
        <input type="text" name="bank_note" value="{{ $bank['bank_note'] }}" maxlength="300" placeholder="مثلاً: پس از واریز، شناسهٔ پرداخت را ثبت کنید"></label>
    </div>
  </div>

  @include('admin.settings._save')
</form>

{{-- ═══ ۲) حساب‌های ارزی و رمزارزِ دریافت ═══
     ⚠️ این‌ها با «واریز به حساب» ریالیِ بالا فرق دارند، ولی جریانِ تأیید
     **یکی** است — هر دو رسید می‌سازند و در /admin/bank-transfers تأیید
     می‌شوند، پس منطقِ تسویهٔ موازی وجود ندارد. --}}
<div class="ad-panel">
  <div class="ad-panel-h">
    <h2>حساب‌های ارزی و رمزارز</h2>
    <span class="ad-pill">{{ $accounts->count() }}</span>
  </div>

  <p class="set-lead">
    مقصدهایی که مشتریِ خارجی می‌تواند به آن‌ها حواله کند. هر حسابِ فعال در صفحهٔ
    فاکتورِ <b>همان ارز</b> دیده می‌شود؛ کیفِ رمزارز در <b>همهٔ</b> فاکتورها.
    پرداخت آفلاین است: مشتری می‌فرستد و شناسه ثبت می‌کند، شما در
    <a href="/admin/bank-transfers" style="color:#22d3ee">واریز به حساب</a> تأیید می‌کنید.
  </p>

  @if($paNotReady)
    <p style="padding:18px;color:#fbbf24">جدول <code>payment_accounts</code> هنوز روی این سرور ساخته نشده. پس از اجرای مهاجرت فعال می‌شود.</p>
  @elseif($accounts->isEmpty())
    <p style="padding:16px 18px;color:var(--dim)">هنوز حسابی ثبت نشده. تا وقتی هیچ حسابی نباشد، مشتریِ انگلیسی/ترک در صفحهٔ فاکتور «به‌زودی» می‌بیند.</p>
  @else
    <div style="padding:0 18px 18px">
      <table class="ad-table">
        <thead><tr><th>نوع</th><th>ارز</th><th>عنوان</th><th>مقصد</th><th>وضعیت</th><th>ترتیب</th></tr></thead>
        <tbody>
        @foreach($accounts as $a)
          <tr>
            <td>
              @if($a->isCrypto())
                <span class="ad-badge" style="background:rgba(38,161,123,.14);color:#26a17b">رمزارز</span>
              @else
                <span class="ad-badge" style="background:rgba(56,189,248,.14);color:#38bdf8">بانکی</span>
              @endif
            </td>
            <td dir="ltr"><b>{{ strtoupper($a->currency_code) }}</b></td>
            <td>{{ $a->displayLabel() }}</td>
            <td dir="ltr" style="color:var(--muted);max-width:300px;overflow-wrap:anywhere;font-size:12.5px">
              @if($a->isCrypto()){{ $a->network }} · {{ $a->address }}
              @else{{ $a->iban ?: $a->account_no }}@if($a->swift)<br><small>{{ $a->swift }}</small>@endif
              @endif
            </td>
            <td>
              @if(! $a->is_active)
                <span class="ad-badge" style="background:rgba(148,163,184,.12);color:var(--muted)">بایگانی</span>
              @elseif(! $a->isUsable())
                <span class="ad-badge" style="background:rgba(251,191,36,.14);color:#fbbf24"
                      title="ناقص است، پس در صفحهٔ فاکتور نمایش داده نمی‌شود">ناقص — نامرئی</span>
              @else
                <span class="ad-badge" style="background:rgba(52,211,153,.12);color:#34d399">فعال</span>
              @endif
            </td>
            <td dir="ltr" style="color:var(--muted)">{{ $a->sort }}</td>
          </tr>
          {{-- ردیفِ دنباله: ارتقای عمومیِ جدول‌ها این را به ردیفِ بالا چسبیده
               نگه می‌دارد، پس مرتب‌سازی فرمِ ویرایش را از حسابش جدا نمی‌کند. --}}
          <tr><td colspan="6" style="padding:0 12px 12px">
            <details>
              <summary style="cursor:pointer;color:#22d3ee;font-size:12.5px;padding:6px 0">ویرایش</summary>
              <form method="post" action="/admin/payment-accounts/{{ $a->id }}" class="pa-form">
                @csrf
                @include('admin.partials.payment-account-fields', ['a' => $a])
                <button class="btn btn-primary" style="font-size:12.5px;padding:8px 16px">ذخیره</button>
              </form>
              @if($a->is_active)
                <form method="post" action="/admin/payment-accounts/{{ $a->id }}/archive" style="margin-top:10px"
                      data-confirm="حساب «{{ $a->displayLabel() }}» بایگانی شود؟ دیگر به مشتری نشان داده نمی‌شود.">
                  @csrf<button class="btn" style="background:#ff6b6b;color:var(--bg);font-size:12.5px;padding:7px 13px">بایگانی</button>
                </form>
              @endif
            </details>
          </td></tr>
        @endforeach
        </tbody>
      </table>
    </div>
  @endif

  @unless($paNotReady)
    <div style="padding:0 18px 18px;border-top:1px solid var(--line);margin-top:4px;padding-top:16px">
      <h3 style="font-size:13.5px;color:var(--cyan);margin-bottom:12px">افزودن حساب</h3>
      <form method="post" action="/admin/payment-accounts" class="pa-form">
        @csrf
        @include('admin.partials.payment-account-fields', ['a' => null])
        <button class="btn btn-primary" style="font-size:13px;padding:9px 20px">افزودن</button>
      </form>
    </div>
  @endunless
</div>

{{-- ═══ ۳) استخرِ آدرسِ رمزارز ═══ --}}
<div class="ad-panel">
  <div class="ad-panel-h"><h2>استخر آدرس‌های دریافت رمزارز</h2><span class="ad-pill">{{ $wallets->count() }}</span></div>

  <p class="set-lead">
    آدرس‌ها را در کیف خودتان (TronLink، تراست‌ولت) بسازید و فقط <b>خودِ آدرس</b> را
    این‌جا بچسبانید. سیستم به هر پرداخت یکی می‌دهد و بعد از تسویه یا انقضا آزادش
    می‌کند. <b style="color:#fbbf24">هرگز کلید خصوصی یا عبارت بازیابی را این‌جا
    وارد نکنید</b> — کل ایمنی این طراحی بر این است که سرور توان خرج‌کردن ندارد.
  </p>

  @if($cwNotReady)
    <p style="padding:18px;color:#fbbf24">جدول‌های رمزارز هنوز روی این سرور ساخته نشده‌اند. پس از اجرای مهاجرت فعال می‌شود.</p>
  @else
    @if($wallets->isEmpty())
      <p style="padding:16px 18px;color:var(--dim)">هنوز آدرسی اضافه نکرده‌اید. تا وقتی استخر خالی باشد، گزینهٔ رمزارز به مشتری <b>نشان داده نمی‌شود</b>.</p>
    @else
      <div style="padding:0 18px 18px">
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
      </div>
    @endif

    <div style="padding:0 18px 18px;border-top:1px solid var(--line);margin-top:4px;padding-top:16px">
      <h3 style="font-size:13.5px;color:var(--cyan);margin-bottom:12px">افزودن آدرس</h3>
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
  @endif
</div>

@unless($cwNotReady)
  {{-- پرداخت‌های در جریان — چرا یک آدرس «مشغول» است و چه چیزی روی هواست.

       ⚠️ عمداً تاشو **نیست**. یک بار همین فهرست نبود و نتیجه‌اش این شد که مدیر
       هیچ راهی نداشت بفهمد چرا گزینهٔ رمزارز به مشتریِ بعدی نشان داده نمی‌شود —
       و آن سکوت به «قابلیت اصلاً کار نمی‌کند» تعبیر شد. پنهان‌کردنش پشتِ یک
       کلیک، همان سکوت را با هزینهٔ کمتر برمی‌گرداند. --}}
  <div class="ad-panel">
    <div class="ad-panel-h"><h2>پرداخت‌های در جریان</h2><span class="ad-pill">{{ $inflight->count() }}</span></div>
    @if($inflight->isEmpty())
      <p style="padding:16px 18px;color:var(--dim)">الان هیچ پرداخت رمزارزی باز نیست.</p>
    @else
      <p class="set-lead">
        هر ردیف یک آدرس را <b>قفل</b> نگه داشته است. تا وقتی همهٔ آدرس‌های یک زنجیره
        مشغول باشند، آن گزینه به مشتری با برچسب «موقتاً در دسترس نیست» نشان داده
        می‌شود. آدرسِ پرداختِ منقضی هم بلافاصله برنمی‌گردد؛ چند ساعت در دورهٔ
        خنک‌شدن می‌مانَد تا واریزِ دیرهنگام به فاکتور نفر بعد ننشیند.
      </p>
      <div style="padding:0 18px 18px">
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
              @if($p->isOpen() && ! $p->isExpired()){{ (int) ceil($p->secondsLeft() / 60) }} دقیقه @else — @endif
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
      </div>
    @endif
  </div>

  {{-- 🔴 پولی که خودکار تسویه نشد اینجاست و **نباید نادیده بماند**.
       عمداً تاشو نیست وقتی چیزی داخلش هست — شمارش در عنوان است. --}}
  <div class="ad-panel">
    <div class="ad-panel-h"><h2>رمزارز — نیازمند بازبینی</h2><span class="ad-pill">{{ $review->count() }}</span></div>
    @if($review->isEmpty())
      <p style="padding:16px 18px;color:var(--dim)">چیزی در انتظار بازبینی نیست.</p>
    @else
      <p class="set-lead">
        این‌ها پرداخت‌هایی هستند که سیستم <b>عمداً</b> خودکار تأییدشان نکرد — کم‌پرداخت،
        یا فاکتوری که دیگر قابل پرداخت نبود. پول رسیده و گم نشده؛ تصمیمش با شماست.
      </p>
      <div style="padding:0 18px 18px">
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
      </div>
    @endif
  </div>
@endunless

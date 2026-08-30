{{-- تبِ «منوی سایت» — هدر و فوتر، هر دو از یک‌جا.

     🔴 گره‌های **خاموش** هم در این فهرست هستند. اگر فقط چیزی را نشان می‌داد که
     در سایت دیده می‌شود، خاموش‌کردنِ یک لینک آن را از همین صفحه هم پاک می‌کرد و
     دیگر هیچ راهی برای روشن‌کردنش نبود — یک درِ یک‌طرفه. --}}

<div class="ad-panel">
  <div class="ad-panel-h"><h2>منوی سایت</h2></div>

  <p class="set-lead">
    منوی بالای سایت (مگامنوی محصولات، خدمات، ابزارها، پایگاه دانش) و لینک‌های
    فوتر را از این‌جا مدیریت کنید: متنِ هر سه زبان، ترتیب، روشن/خاموش، و افزودنِ
    لینکِ تازه.
    <br>⚠️ <b>خالی‌گذاشتنِ متن یعنی «همان پیش‌فرضِ سایت»</b> — پس با پاک‌کردنِ
    ویرایش، همه‌چیز به حالتِ اول برمی‌گردد.
    <br>⚠️ <b>ترتیب:</b> عددِ کوچک‌تر بالاتر. ردیف‌های بی‌عدد سرِ جای خودشان
    می‌مانند و بعد از عددخورده‌ها می‌آیند.
    <br>🔴 <b>فوتر روی همهٔ صفحاتِ سایت است.</b> مقصدِ نامعتبر همان‌جا رد می‌شود
    و لینک نمایش داده نمی‌شود — سایت نمی‌افتد، ولی لینک هم دیده نمی‌شود.
  </p>

  @if($menusNotReady)
    <p style="padding:0 18px 18px;color:var(--muted);font-size:13px">
      جدولِ منو هنوز ساخته نشده. پس از اجرای مهاجرت، این صفحه فعال می‌شود.
    </p>
  @else

  {{-- ══ افزودنِ لینکِ تازه ══ --}}
  <div style="padding:0 18px 16px">
    <details>
      <summary style="cursor:pointer;font-size:13.5px;color:var(--cyan);padding:8px 0">
        ＋ افزودنِ لینکِ تازه
      </summary>

      <form method="post" action="/admin/menus/add" style="margin-top:10px">
        @csrf
        <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end">
          <label style="font-size:12px">کجا
            <select name="menu" class="ad-input" style="display:block;min-width:150px">
              <option value="footer">فوترِ سایت</option>
              <option value="services">منوی خدمات</option>
              <option value="tools">منوی ابزارها</option>
              <option value="knowledge">پایگاه دانش</option>
            </select>
          </label>

          <label style="font-size:12px">ستونِ فوتر
            <select name="scope" class="ad-input" style="display:block;min-width:130px">
              <option value="">—</option>
              @foreach($footerColumns as $c)
                <option value="{{ $c }}">{{ $c }}</option>
              @endforeach
            </select>
          </label>

          <label style="font-size:12px">متنِ فارسی
            <input name="label_fa" class="ad-input" required style="display:block;min-width:170px">
          </label>
          <label style="font-size:12px">English
            <input name="label_en" class="ad-input" dir="ltr" style="display:block;min-width:150px">
          </label>
          <label style="font-size:12px">Türkçe
            <input name="label_tr" class="ad-input" dir="ltr" style="display:block;min-width:150px">
          </label>

          <label style="font-size:12px">مقصد
            <input name="target" class="ad-input" dir="ltr" required
                   placeholder="/blog یا https://… یا نامِ روت" style="display:block;min-width:220px">
          </label>

          <label style="font-size:12px">ترتیب
            <input name="sort" type="number" class="ad-input" style="display:block;width:80px">
          </label>

          <button class="ad-btn" type="submit">افزودن</button>
        </div>

        <p style="font-size:11.5px;color:var(--dim);margin:8px 0 0">
          مقصد یا نشانیِ کامل است (<code dir="ltr">https://…</code>)، یا مسیرِ داخلی
          (<code dir="ltr">/blog</code>)، یا نامِ یک روتِ موجود
          (<code dir="ltr">blog.index</code>). نامِ روتِ ناموجود پذیرفته نمی‌شود.
        </p>
      </form>
    </details>
  </div>

  {{-- ══ گره‌ها، منو به منو ══ --}}
  @foreach($menuTree as $menuKey => $section)
    <div style="padding:0 18px 6px">
      <h3 style="font-size:13.5px;color:var(--cyan);margin:14px 0 10px">
        {{ $section['label'] }}
        <span style="color:var(--dim);font-weight:400">({{ count($section['nodes']) }})</span>
      </h3>
    </div>

    <div style="padding:0 18px 10px;overflow-x:auto">
      <table class="ad-table">
        <thead><tr>
          <th style="min-width:210px">گره</th>
          <th>فارسی</th><th>English</th><th>Türkçe</th>
          <th style="width:70px">ترتیب</th>
          <th style="width:150px"></th>
        </tr></thead>
        <tbody>
        @foreach($section['nodes'] as $n)
          @php($row = $n['row'])
          @php($off = $row && ! $row->visible)
          <tr @if($off) style="opacity:.45" @endif>
            <td style="padding-right:{{ 8 + $n['depth'] * 16 }}px">
              <b style="font-size:12.5px">{{ $n['default']['fa'] ?: ($row?->label('fa') ?? '—') }}</b>
              <div style="font-size:11px;color:var(--dim)" dir="ltr">{{ $n['path'] }}</div>
              <div style="font-size:11px;color:var(--muted)">
                {{ $n['kind'] }}@if($n['note']) · فقط {{ $n['note'] }}@endif
                @if($n['custom']) · افزودهٔ شما @endif
              </div>
            </td>

            {{-- ⚠️ هر ردیف فرمِ خودش را دارد. یک فرمِ بزرگ برای همهٔ ۱۲۷ گره یعنی
                 هر ذخیره همه‌چیز را می‌نویسد و یک خطای کوچک، کلِ منو را عوض
                 می‌کرد. --}}
            <td colspan="4">
              <form method="post" action="/admin/menus/save"
                    style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                @csrf
                <input type="hidden" name="path" value="{{ $n['path'] }}">
                <input type="hidden" name="menu" value="{{ $menuKey }}">

                <input name="label_fa" class="ad-input" style="min-width:150px"
                       value="{{ $row?->label_fa }}"
                       placeholder="{{ $n['default']['fa'] ?: '—' }}">
                <input name="label_en" class="ad-input" dir="ltr" style="min-width:140px"
                       value="{{ $row?->label_en }}"
                       placeholder="{{ $n['default']['en'] ?: '—' }}">
                <input name="label_tr" class="ad-input" dir="ltr" style="min-width:140px"
                       value="{{ $row?->label_tr }}"
                       placeholder="{{ $n['default']['tr'] ?: '—' }}">
                <input name="sort" type="number" class="ad-input" style="width:70px"
                       value="{{ $row?->sort }}" placeholder="—">

                <button class="ad-btn" type="submit">ذخیره</button>
              </form>
            </td>

            <td style="white-space:nowrap">
              <form method="post" action="/admin/menus/hide" style="display:inline">
                @csrf
                <input type="hidden" name="path" value="{{ $n['path'] }}">
                <input type="hidden" name="menu" value="{{ $menuKey }}">
                <button class="ad-btn" type="submit">{{ $off ? 'روشن' : 'خاموش' }}</button>
              </form>

              @if($row)
                {{-- ⚠️ `data-confirm` و نه دیالوگِ خامِ مرورگر — که در پنلِ برندشده
                     هم زشت است هم روی موبایل قابلِ اطمینان نیست.

                     🔴 و نامِ آن تابعِ بومی عمداً این‌جا نوشته نشده: گاردش
                     (`BrandedDialogTest`) کلِ متنِ فایل را می‌گردد و کامنت را
                     از کد تشخیص نمی‌دهد. نسخهٔ اولِ همین توضیح نامش را داشت و
                     تست را قرمز کرد — دقیقاً همان تله‌ای که در فوترِ سایت هم
                     یک بار خورده‌ایم. --}}
                <form method="post" action="/admin/menus/{{ $row->id }}" style="display:inline"
                      data-confirm="{{ $n['custom'] ? 'این لینک برای همیشه حذف شود؟' : 'ویرایش‌های شما پاک شود و این گره به متنِ پیش‌فرضِ سایت برگردد؟' }}"
                      data-confirm-title="{{ $n['custom'] ? 'حذفِ لینک' : 'بازگشت به پیش‌فرض' }}"
                      data-confirm-ok="{{ $n['custom'] ? 'بله، حذف کن' : 'بله، برگردان' }}">
                  @csrf @method('DELETE')
                  <button class="ad-btn btn-danger" type="submit">{{ $n['custom'] ? 'حذف' : 'پیش‌فرض' }}</button>
                </form>
              @endif
            </td>
          </tr>
        @endforeach
        </tbody>
      </table>
    </div>
  @endforeach

  @endif
</div>

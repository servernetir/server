@extends('admin.layout')
@section('title', 'دروازهٔ AI — قیمت')
@section('nav_ai_pricing', 'on')
@section('content')

<div class="ad-panel">
  <div class="ad-panel-h">
    <h2>قیمتِ واحد — نسخه‌بندیِ فقط-افزودنی</h2>
    <a href="/admin/ai/models" class="btn btn-glass" style="font-size:13px">مدل‌ها</a>
  </div>
  <p style="padding:0 18px;color:var(--muted);font-size:13.5px;line-height:1.9">
    قیمت‌ها به <b>میکرو-واحد</b> (یک‌میلیونیومِ ارزِ خودِ سطر، در ستونِ ارز)
    و برای توکن‌ها به ازایِ هر یک میلیون توکن نگه‌داری می‌شوند —
    دقیق و بدون گردِ زودهنگام. نسخهٔ تازه‌سازی بدونِ ویرایشِ گذشته: هر تعویضِ قیمت، سطرِ قبلی را
    بسته و سطرِ جدیدی باز می‌کند، پس هیچ محاسبهٔ مالیِ گذشته جا نمی‌افتد.
    منتظرِ سینکِ خودکار از ارائه‌دهنده **نباشید** — تمام قیمت‌ها ورودِ دستیِ ادمین‌اند.
  </p>

  @if(session('ok'))
    <p style="padding:6px 18px;color:#34d399;font-size:13px">{{ session('ok') }}</p>
  @endif

  <form method="get" action="/admin/ai/pricing" style="padding:10px 18px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <select name="model" class="ad-input" style="padding:6px 8px">
      @foreach($models as $m)
        <option value="{{ $m->id }}" @selected($model && $m->id === $model->id) dir="ltr">{{ $m->category }} · {{ $m->name }}</option>
      @endforeach
    </select>
    <button class="btn btn-glass" style="font-size:13px">نمایش</button>
  </form>

  @if($model === null)
    <p style="padding:16px;color:var(--dim)">مدلی ثبت نشده است.</p>
  @else
    @include('admin.ai._price-preview', ['preview' => $preview, 'model' => $model])

    <div style="padding:12px 18px">
      <h3 style="color:var(--muted);font-size:13px;margin-bottom:6px">ساختِ نسخهٔ تازه برای
        <span dir="ltr">{{ $model->slug }}</span>
      </h3>
      <form method="post" action="/admin/ai/pricing/supersede" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap">
        @csrf
        <input type="hidden" name="model" value="{{ $model->id }}">
        <label style="display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--muted)">واحدِ مصرف
          <select name="unit" class="ad-input" style="padding:6px 8px">
            @foreach(['input' => 'توکن ورودی (میکرو-واحد / ۱M توکن)', 'output' => 'توکن خروجی', 'cached_input' => 'توکن ورودیِ کش‌شده',
                     'reasoning_input' => 'توکنِ استدلال', 'image' => 'تصویر', 'audio' => 'صدا (دقیقه)',
                     'request' => 'به ازایِ درخواست', 'embedding' => 'امبِدینگ', 'rerank' => 'رِیتِنگ'] as $val => $label)
              <option value="{{ $val }}" @selected($selectedUnit === $val)>{{ $label }}</option>
            @endforeach
          </select>
        </label>
        <label style="display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--muted)">قیمت (میکرو-واحد · عددِ صحیح)
          <input type="number" name="micros" min="1" max="100000000000" required class="ad-input" style="padding:8px" placeholder="مثال: ۲۲۰٬۰۰۰">
        </label>
        <label style="display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--muted)">ارز
          <select name="currency" class="ad-input" style="padding:6px 8px" dir="ltr">
            @foreach(['USD', 'EUR'] as $c)
              <option value="{{ $c }}" @selected($model->provider?->billing_currency_code === $c)>{{ $c }}</option>
            @endforeach
          </select>
        </label>
        <label style="display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--muted)">یادداشت
          <input type="text" name="note" maxlength="255" class="ad-input" style="padding:8px">
        </label>
        <button class="btn btn-primary">ایجادِ نسخهٔ تازه</button>
      </form>

      <h3 style="color:var(--muted);font-size:13px;margin:16px 0 6px">تاریخِ نسخه‌ها (۱۰۰ نسخهٔ اخیر)</h3>
      @if($history->isEmpty())
        <p style="color:var(--dim);font-size:13px">هیچ نسخه‌ای ثبت نشده.</p>
      @else
        <table class="ad-table">
          <thead><tr><th>#</th><th>واحد</th><th>یکای صورت‌حساب</th><th>میکرو-واحد به ازای یکا</th><th>ارز</th><th>فعال</th><th>موثر از</th><th>بسته در</th><th>یادداشت</th></tr></thead>
          <tbody>
            @foreach($history as $h)
            <tr>
              <td>{{ fa_num($h->version) }}</td>
              <td dir="ltr">{{ $h->unit }}</td>
              <td dir="ltr">{{ $h->billing_unit }}</td>
              <td dir="ltr">{{ $h->price_micro_units }}</td>
              <td dir="ltr">{{ $h->currency_code }}</td>
              <td>
                @if(! $h->isSuperseded())<span class="ad-badge" style="background:rgba(52,211,153,.12);color:#34d399">فعال</span>
                @else<span class="ad-badge" style="background:rgba(148,163,184,.12);color:var(--muted)">بسته‌شده</span>@endif
              </td>
              <td>{{ $h->effective_from?->format('Y-m-d H:i') }}</td>
              <td>{{ $h->superseded_at?->format('Y-m-d H:i') ?? '—' }}</td>
              <td style="color:var(--dim);max-width:200px">{{ $h->note }}</td>
            </tr>
            @endforeach
          </tbody>
        </table>
      @endif
    </div>
  @endif
</div>
@endsection

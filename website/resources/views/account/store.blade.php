@extends('panel.layout')
@section('title', 'خرید سرویس — سرورنت')

@section('panel')

<div class="pnl-head">
  <div>
    <h1 class="dash-h">خرید سرویس</h1>
    <p>یک پکیج را انتخاب و همین‌جا آنلاین سفارش دهید؛ پس از پرداخت، سرویس خودکار ساخته و تحویل می‌شود.</p>
  </div>
</div>

@if(session('ok'))
  <div class="pnl-sec" style="border-color:var(--ok-line)"><div class="pnl-sec-b" style="color:var(--ok);font-size:13.5px;line-height:2">{{ session('ok') }}</div></div>
@endif
@if($errors->any())
  <div class="pnl-sec" style="border-color:var(--danger-line)"><div class="pnl-sec-b" style="color:var(--danger);font-size:13.5px;line-height:2">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div></div>
@endif

@if($byCategory->isEmpty())
  <section class="pnl-sec"><div class="pnl-sec-b">
    <p style="font-size:13.5px;color:var(--muted);line-height:2;margin:0">در حال حاضر پکیجی برای فروش تعریف نشده است. به‌زودی اضافه می‌شود.</p>
  </div></section>
@else
  @foreach($byCategory as $cat => $items)
    <section class="pnl-sec">
      <div class="pnl-sec-h"><h2>{{ \App\Models\Product::CATEGORIES[$cat] ?? $cat }}</h2></div>
      <div class="pnl-sec-b">
        <div class="store-grid">
          @foreach($items as $p)
            <form method="POST" action="{{ lroute('account.order', $p) }}" class="store-card" id="pkg-{{ $p->slug }}">
              @csrf
              <div class="store-card-h">
                <b>{{ $p->name }}</b>
                @if($p->description)<small>{{ \Illuminate\Support\Str::limit($p->description, 90) }}</small>@endif
              </div>

              @if(!empty($p->specs))
                <ul class="store-specs">
                  @foreach($p->specs as $spec)
                    <li><svg class="icon"><use href="#i-check"/></svg><span>{{ $spec['label'] }}@if(!empty($spec['value'])): <b>{{ $spec['value'] }}</b>@endif</span></li>
                  @endforeach
                </ul>
              @endif

              <div class="store-price">
                <b class="pnl-num">{{ fa_num(number_format($p->recurringTotal())) }}</b>
                <span>تومان / {{ $p->cycleLabel() }}</span>
              </div>
              @if($p->setup_fee > 0)
                <div class="store-setup">+ راه‌اندازی {{ fa_num(number_format($p->setup_fee)) }} تومان (یک‌بار)</div>
              @endif

              @if($p->requires_domain)
                <label class="store-dom">دامنه
                  <input type="text" name="domain" dir="ltr" required placeholder="your-domain.com" value="{{ old('domain') }}">
                </label>
              @endif

              <button type="submit" class="pnl-btn primary" style="justify-content:center;margin-top:auto">سفارش و پرداخت</button>
              @if($p->server_id)<div class="store-auto"><svg class="icon"><use href="#i-zap"/></svg> تحویلِ خودکار پس از پرداخت</div>@endif
            </form>
          @endforeach
        </div>
      </div>
    </section>
  @endforeach
@endif

<style>
.store-grid{ display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:14px; }
.store-card{ display:flex; flex-direction:column; gap:12px; border:1px solid var(--line); border-radius:16px;
  padding:18px; background:var(--surface-2); transition:border-color .18s, transform .12s, box-shadow .18s; }
.store-card:hover{ transform:translateY(-2px); border-color:var(--brand); box-shadow:0 8px 24px rgba(34,211,238,.08); }
.store-card:target{ border-color:var(--brand); box-shadow:0 0 0 2px rgba(34,211,238,.35); scroll-margin-top:90px; }
.store-card-h b{ font-size:15px; color:var(--text); }
.store-card-h small{ display:block; font-size:12px; color:var(--muted); margin-top:4px; line-height:1.8; }
.store-specs{ list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:7px; }
.store-specs li{ display:flex; align-items:flex-start; gap:8px; font-size:12.5px; color:var(--muted); }
.store-specs .icon{ width:14px; height:14px; color:var(--ok); flex:0 0 auto; margin-top:2px; }
.store-specs b{ color:var(--text); }
.store-price{ display:flex; align-items:baseline; gap:6px; margin-top:4px; padding-top:12px; border-top:1px solid var(--line); }
.store-price b{ font-size:20px; color:var(--text); }
.store-price span{ font-size:12px; color:var(--muted); }
.store-setup{ font-size:11.5px; color:var(--muted); }
.store-dom{ display:flex; flex-direction:column; gap:5px; font-size:12px; color:var(--muted); }
.store-dom input{ background:var(--surface); border:1px solid var(--line); border-radius:10px; padding:9px 12px; font:inherit; font-size:13px; color:var(--text); }
.store-auto{ display:flex; align-items:center; gap:6px; font-size:11px; color:var(--ok); justify-content:center; }
.store-auto .icon{ width:13px; height:13px; }
</style>
@endsection

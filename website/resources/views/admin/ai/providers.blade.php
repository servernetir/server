@extends('admin.layout')
@section('title', 'دروازهٔ AI — ارائه‌دهنده‌ها')
@section('nav_ai_providers', 'on')
@section('content')

<div class="ad-panel">
  <div class="ad-panel-h">
    <h2>ارائه‌دهنده‌های AI</h2>
    <a href="/admin/ai/models" class="btn btn-glass" style="font-size:13px">مدل‌ها</a>
  </div>
  <p style="padding:0 18px;color:var(--muted);font-size:13.5px;line-height:1.9">
    رجیستریِ ارائه‌دهنده — پایهٔ دروازه. کلیدِ API هر ارائه‌دهنده مثلِ بقیهٔ سرویس‌ها در «تنظیمات»
    نگه‌داری می‌شود (رمزی؛ نامِ کلید: <code dir="ltr">ai_provider_{اسلگ}_key</code>) و هرگز این‌جا نمایش داده نمی‌شود.
    «فروش روشن» به معنای فروشِ مدل‌هایش به مشتری است؛ «فعال» صرفاً فعالِ فنی.
  </p>

  @if(session('ok'))
    <p style="padding:6px 18px;color:#34d399;font-size:13px">{{ session('ok') }}</p>
  @endif

  @if($providers->isEmpty())
    <p style="padding:16px;color:var(--dim)">هیچ ارائه‌دهنده‌ای ثبت نشده.</p>
  @else
    <table class="ad-table">
      <thead><tr>
        <th>ارائه‌دهنده</th><th>درایور</th><th>اولویت</th><th>مدل‌ها</th>
        <th>وضعیت‌ها</th><th>توافق</th><th>کلید</th><th>یادداشت</th>
      </tr></thead>
      <tbody>
        @foreach($providers as $p)
        <tr>
          <td>
            <b>{{ $p->name }}</b>
            <small dir="ltr" style="color:var(--dim);display:block">{{ $p->slug }}</small>
          </td>
          <td dir="ltr">{{ $p->driver }}</td>
          <td>{{ fa_num($p->priority) }}</td>
          <td>{{ fa_num($p->models_count) }} <span style="color:var(--dim)">/{{ fa_num($p->active_models) }}</span></td>
          <td>
            @if($p->enabled)<span class="ad-badge" style="background:rgba(52,211,153,.12);color:#34d399">فعال</span>@else<span class="ad-badge" style="background:rgba(148,163,184,.12);color:var(--muted)">خاموش</span>@endif
            @if($p->commercial_enabled)<span class="ad-badge" style="background:rgba(34,211,238,.14);color:#22d3ee">فروش</span>@endif
            @if($p->resale_allowed)<span class="ad-badge" style="background:rgba(139,92,246,.14);color:#a78bfa">فروشِ دوباره</span>@endif
            @if($p->live_calls_enabled)<span class="ad-badge" style="background:rgba(251,191,36,.14);color:#fbbf24">تماسِ زنده</span>@endif
          </td>
          <td>
            @if($p->agreement_status === 'signed')<span class="ad-badge" style="background:rgba(52,211,153,.12);color:#34d399">امضا</span>
            @elseif($p->agreement_status === 'requested')<span class="ad-badge" style="background:rgba(251,191,36,.14);color:#fbbf24">درخواست‌شده</span>
            @else<span style="color:var(--dim)">—</span>@endif
          </td>
          <td>
            @if($keyStates[$p->id] ?? false)<span class="ad-badge" style="background:rgba(52,211,153,.12);color:#34d399">تنظیم‌شده</span>
            @else<span class="ad-badge" style="background:rgba(239,68,68,.12);color:#f87171">تنظیم‌نشده</span>@endif
          </td>
          <td style="color:var(--dim);max-width:220px">{{ $p->notes }}</td>
        </tr>
        @endforeach
      </tbody>
    </table>
  @endif

  <div style="padding:14px 18px">
    <form method="get" action="/admin/ai/providers/edit" style="display:flex;gap:8px;align-items:center">
      <label style="font-size:12px;color:var(--muted)">ویرایشِ ارائه‌دهنده:</label>
      @if($providers->first() !== null)
        <select name="provider" class="ad-input" style="padding:6px 8px">
          @foreach($providers as $p)
            <option value="{{ $p->id }}" dir="ltr">{{ $p->slug }}</option>
          @endforeach
        </select>
        <button class="btn btn-primary" style="font-size:13px">ویرایش</button>
      @endif
    </form>
  </div>
</div>
@endsection

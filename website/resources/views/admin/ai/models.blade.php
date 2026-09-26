@extends('admin.layout')
@section('title', 'دروازهٔ AI — مدل‌ها')
@section('nav_ai_models', 'on')
@section('content')

<div class="ad-panel">
  <div class="ad-panel-h">
    <h2>مدل‌های AI</h2>
    <span style="display:flex;gap:8px">
      {{-- Route::has: نامِ روتِ تازه تا ریستِ opcache ناشناخته است و بی‌این نگهبان کلِ صفحه ۵۰۰ می‌داد --}}
      @if(Route::has('admin.ai.models.create') && auth()->user()->isAdmin())
        <a href="{{ route('admin.ai.models.create') }}" class="btn btn-primary" style="font-size:13px">افزودنِ مدل</a>
      @endif
      <a href="/admin/ai/pricing" class="btn btn-glass" style="font-size:13px">قیمت‌ها</a>
    </span>
  </div>

  @if(session('ok'))
    <p style="padding:6px 18px;color:#34d399;font-size:13px">{{ session('ok') }}</p>
  @endif

  <form method="get" action="/admin/ai/models" style="padding:10px 18px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <select name="category" class="ad-input" style="padding:6px 8px">
      <option value="">همهٔ دسته‌ها</option>
      @foreach(['chat' => 'چت', 'embedding' => 'امبِدینگ', 'image' => 'تصویر', 'audio' => 'صدا', 'rerank' => 'رِیتِنگ'] as $val => $label)
        <option value="{{ $val }}" @selected($category === $val)>{{ $label }}</option>
      @endforeach
    </select>
    <select name="status" class="ad-input" style="padding:6px 8px">
      <option value="">همهٔ وضعیت‌ها</option>
      @foreach(['active' => 'فعال', 'disabled' => 'خاموش', 'deprecated' => 'ازکارافتاده'] as $val => $label)
        <option value="{{ $val }}" @selected($status === $val)>{{ $label }}</option>
      @endforeach
    </select>
    <button class="btn btn-glass" style="font-size:13px">فیلتر</button>
  </form>

  @if($models->isEmpty())
    <p style="padding:16px;color:var(--dim)">مدلی ثبت نشده (یا فیلتر هیچ ردیفی برگردانده نمی‌کند).
      @if(Route::has('admin.ai.models.create') && auth()->user()->isAdmin())
        <a href="{{ route('admin.ai.models.create') }}" style="color:#22d3ee">نخستین مدل را بسازید</a>.
      @endif
    </p>
  @else
    <table class="ad-table">
      <thead><tr>
        <th>مدل</th><th>ارائه‌دهنده</th><th>دسته</th><th>وضعیت</th>
        <th>قیمت‌های فعال</th><th>قابلیت‌ها</th><th></th>
      </tr></thead>
      <tbody>
        @foreach($models as $m)
        <tr>
          <td>
            <b>{{ $m->name }}</b>
            <small dir="ltr" style="color:var(--dim);display:block">{{ $m->slug }}</small>
          </td>
          <td dir="ltr">{{ $m->provider->name ?? '—' }}</td>
          <td>{{ ['chat' => 'چت'][$m->category] ?? $m->category }}</td>
          <td>
            @if($m->status === 'active')<span class="ad-badge" style="background:rgba(52,211,153,.12);color:#34d399">فعال</span>
            @elseif($m->status === 'deprecated')<span class="ad-badge" style="background:rgba(251,191,36,.14);color:#fbbf24">ازکارافتاده</span>
            @else<span class="ad-badge" style="background:rgba(148,163,184,.12);color:var(--muted)">خاموش</span>@endif
          </td>
          {{-- شمارشِ کنترلر `active_prices` است؛ `active_models` نبود و null به fa_num ⇒ ۵۰۰ --}}
          <td>{{ fa_num($m->active_prices ?? 0) }}</td>
          <td>
            <span style="display:inline-flex;gap:4px;flex-wrap:wrap">
              @php $caps = $m->capabilities ?? []; @endphp
              @foreach(['reasoning' => 'استدلال', 'vision' => 'بینایی', 'streaming' => 'استریم', 'tool_calling' => 'ابزار', 'claude_code_compatible' => 'Claude Code'] as $k => $label)
                @if($caps[$k] ?? ($k === 'claude_code_compatible' ? $m->claude_code_compatible : false))
                  <span class="ad-badge" style="background:rgba(34,211,238,.1);color:#22d3ee">{{ $label }}</span>
                @endif
              @endforeach
            </span>
          </td>
          <td><a href="/admin/ai/models/{{ $m->id }}/edit" class="btn btn-glass" style="font-size:12px">ویرایش</a></td>
        </tr>
        @endforeach
      </tbody>
    </table>
    <div style="padding:12px 18px">{{ $models->links() }}</div>
  @endif
</div>
@endsection

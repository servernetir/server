@extends('admin.layout')
@section('title', $type === 'kb' ? 'پایگاه دانش' : 'بلاگ')
@section($type === 'kb' ? 'nav_kb' : 'nav_blog', 'on')
@section('content')
<div class="ad-toolbar">
  <div class="ad-tabs">
    <a href="/admin/posts?type=blog" class="{{ $type === 'blog' ? 'on' : '' }}">بلاگ</a>
    <a href="/admin/posts?type=kb" class="{{ $type === 'kb' ? 'on' : '' }}">پایگاه دانش</a>
  </div>
  <a class="btn btn-primary" href="/admin/posts/new?type={{ $type }}"><svg class="icon"><use href="#i-plus"/></svg>مطلب جدید</a>
</div>

<div class="ad-panel">
  <table class="ad-table">
    <thead><tr><th>عنوان</th><th>دسته</th><th>زبان‌ها</th><th>وضعیت</th><th>تاریخ</th><th></th></tr></thead>
    <tbody>
      @forelse($posts as $p)
      <tr>
        <td><a class="t" href="/admin/posts/{{ $p->id }}/edit">{{ optional($p->tr('fa'))->title ?? $p->slug }}</a><div style="font-size:12px;color:var(--dim)" dir="ltr">/{{ $p->slug }}</div></td>
        @php $catCfg = $type === 'kb' ? config('docs.sections.'.$p->category) : config('blog.categories.'.$p->category); @endphp
        <td>{{ $catCfg['fa']['t'] ?? $catCfg['fa'] ?? $p->category }}</td>
        <td style="font-size:12px;color:var(--muted)">{{ $p->translations->pluck('locale')->map(fn($l)=>strtoupper($l))->implode(' · ') }}</td>
        <td><span class="ad-badge {{ $p->status === 'published' ? 'pub' : 'draft' }}">{{ $p->status === 'published' ? 'منتشر' : 'پیش‌نویس' }}</span></td>
        <td style="font-size:12.5px;color:var(--muted)">{{ blog_date(optional($p->published_at ?? $p->created_at)->toDateString()) }}</td>
        <td class="ad-row-act">
          @if($p->status === 'published')<a href="/blog/{{ $p->slug }}" target="_blank">مشاهده</a>@endif
          <a href="/admin/posts/{{ $p->id }}/edit">ویرایش</a>
          <form method="post" action="/admin/posts/{{ $p->id }}/delete" onsubmit="return confirm('حذف این مطلب؟')" style="display:inline">@csrf<button class="del" type="submit">حذف</button></form>
        </td>
      </tr>
      @empty
      <tr><td colspan="6" style="text-align:center;color:var(--dim);padding:34px">هنوز مطلبی در این بخش نیست.</td></tr>
      @endforelse
    </tbody>
  </table>
</div>
<div style="margin-top:16px">{{ $posts->links() }}</div>
@endsection

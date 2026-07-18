@extends('admin.layout')
@section('title', 'داشبورد')
@section('nav_dash', 'on')
@section('content')
<div class="ad-stats">
  <div class="ad-stat"><b style="color:var(--cyan)">{{ $stats['blog'] }}</b><span>پست بلاگ</span></div>
  <div class="ad-stat"><b style="color:var(--violet)">{{ $stats['kb'] }}</b><span>مقاله دانش</span></div>
  <div class="ad-stat"><b style="color:var(--green)">{{ $stats['published'] }}</b><span>منتشرشده</span></div>
  <div class="ad-stat"><b style="color:var(--amber)">{{ $stats['draft'] }}</b><span>پیش‌نویس</span></div>
  <div class="ad-stat"><b style="color:var(--amber)">{{ $stats['comments'] }}</b><span>کامنت در انتظار</span></div>
  <div class="ad-stat"><b>{{ $stats['users'] }}</b><span>کاربر</span></div>
</div>

<div class="ad-panel">
  <div class="ad-panel-h"><h3>آخرین مطالب</h3><a class="btn btn-primary" href="/admin/posts/new?type=blog"><svg class="icon"><use href="#i-plus"/></svg>مطلب جدید</a></div>
  <table class="ad-table">
    <thead><tr><th>عنوان</th><th>نوع</th><th>وضعیت</th><th></th></tr></thead>
    <tbody>
      @forelse($recent as $p)
      <tr>
        <td><a class="t" href="/admin/posts/{{ $p->id }}/edit">{{ optional($p->tr('fa'))->title ?? $p->slug }}</a></td>
        <td>{{ $p->type === 'kb' ? 'دانش' : 'بلاگ' }}</td>
        <td><span class="ad-badge {{ $p->status === 'published' ? 'pub' : 'draft' }}">{{ $p->status === 'published' ? 'منتشر' : 'پیش‌نویس' }}</span></td>
        <td class="ad-row-act"><a href="/admin/posts/{{ $p->id }}/edit">ویرایش</a></td>
      </tr>
      @empty
      <tr><td colspan="4" style="text-align:center;color:var(--dim);padding:30px">هنوز مطلبی ندارید — اولین مطلب را بسازید.</td></tr>
      @endforelse
    </tbody>
  </table>
</div>
@endsection

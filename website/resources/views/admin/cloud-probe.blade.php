@extends('admin.layout')
@section('title', 'ساختارِ خامِ پاسخِ زیرساخت')
@section('content')

<div class="ad-panel">
  <div class="ad-panel-h"><h2>ساختارِ خامِ پاسخِ زیرساختِ ۲</h2></div>
  <p style="padding:0 18px 14px;color:var(--muted);font-size:13px;line-height:1.9">
    این صفحه <b>ابزارِ عیب‌یابی</b> است. داکیومنتِ این زیرساخت نمونهٔ کاملِ JSON نداشت،
    پس نگاشتِ فیلدها (هسته، رم، دیسک، مکان) بخشی حدسی است. با نگاه به خروجیِ زیر
    می‌شود نگاشت را دقیق کرد.
    <br>اگر پلنی در «عرضه‌های عمومی» نیامد، احتمالاً مشخصاتش از این کلیدها خوانده نشده.
  </p>

  <div style="padding:0 18px 18px">
    <pre dir="ltr" style="background:var(--surface2);border:1px solid var(--line);border-radius:11px;padding:14px;overflow:auto;max-height:70vh;font-size:11.5px;line-height:1.7;color:var(--text)">{{ json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
  </div>

  <div style="padding:0 18px 18px">
    <a class="btn btn-glass" style="font-size:12.5px" href="/admin/cloud">بازگشت</a>
  </div>
</div>
@endsection

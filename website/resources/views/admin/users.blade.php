@extends('admin.layout')
@section('title', 'کاربران')
@section('nav_users', 'on')
@section('content')
<div class="ad-editor">
  <div class="ad-panel" style="margin:0">
    <div class="ad-panel-h"><h3>کاربران و دسترسی‌ها</h3></div>
    <table class="ad-table">
      <thead><tr><th>نام</th><th>ایمیل</th><th>نقش</th><th>شمارهٔ تماس‌گیرنده</th><th></th></tr></thead>
      <tbody>
        @foreach($users as $u)
        <tr>
          <td class="t">{{ $u->name }}</td>
          <td dir="ltr" style="color:var(--muted)">{{ $u->email }}</td>
          <td><span class="ad-badge {{ $u->role === 'admin' ? 'pub' : 'draft' }}">{{ $u->role === 'admin' ? 'مدیر' : 'نویسنده' }}</span></td>
          {{-- شماره‌ای که موقعِ Click-to-Call **اول** زنگ می‌خورد.
               ⚠️ اختیاری: خالی یعنی پیش‌فرضِ سراسری (CLOUD_PHONE_AGENT_NUMBER).
               ذخیرهٔ درجا، چون تنها فیلدِ قابلِ ویرایشِ این جدول است. --}}
          <td>
            <form method="post" action="/admin/users/{{ $u->id }}/extension" style="display:flex;gap:6px;align-items:center">
              @csrf
              <input class="ad-input" type="text" name="phone_extension" dir="ltr" inputmode="numeric"
                     value="{{ $u->phone_extension }}" placeholder="پیش‌فرض" style="width:130px;padding:5px 8px">
              <button class="btn btn-glass" type="submit" style="padding:5px 10px">ثبت</button>
            </form>
          </td>
          <td class="ad-row-act">
            @if($u->id !== auth()->id())
            <form method="post" action="/admin/users/{{ $u->id }}/delete" data-confirm="حذف این کاربر؟" data-confirm-danger style="display:inline">@csrf<button class="del" type="submit">حذف</button></form>
            @else<span style="font-size:12px;color:var(--dim)">شما</span>@endif
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>
  </div>

  <div class="ad-ed-side">
    <div>
      <div class="sh"><svg class="icon"><use href="#i-plus"/></svg>افزودن کاربر</div>
      <form method="post" action="/admin/users">
        @csrf
        <div class="ad-field"><label>نام</label><input class="ad-input" type="text" name="name" required></div>
        <div class="ad-field"><label>ایمیل</label><input class="ad-input" type="email" name="email" dir="ltr" required></div>
        <div class="ad-field"><label>شمارهٔ تماس‌گیرنده <span style="color:var(--dim);font-weight:400">(اختیاری)</span></label><input class="ad-input" type="text" name="phone_extension" dir="ltr" inputmode="numeric" placeholder="خالی = پیش‌فرض سراسری"></div>
        <div class="ad-field"><label>نقش</label>
          <select class="ad-input" name="role">
            <option value="author">نویسنده (فقط محتوا)</option>
            <option value="admin">مدیر (دسترسی کامل)</option>
          </select>
        </div>
        <div class="ad-field"><label>رمز عبور</label><input class="ad-input" type="text" name="password" required minlength="8"></div>
        <button class="btn btn-primary" type="submit" style="width:100%;justify-content:center">ساخت کاربر</button>
        <p class="ad-hint">نویسنده فقط می‌تواند محتوا بسازد و ویرایش کند؛ مدیر به کاربران و همه‌چیز دسترسی دارد.</p>
      </form>
    </div>
  </div>
</div>
@endsection

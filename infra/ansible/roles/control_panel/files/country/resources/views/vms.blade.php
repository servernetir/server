<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ماشین‌ها — سرورنت</title>
<style>
  body{font-family:Tahoma,Vazirmatn,system-ui,sans-serif;background:#0f172a;color:#e2e8f0;margin:0;padding:24px}
  a{color:#38bdf8;text-decoration:none}
  .wrap{max-width:1060px;margin:0 auto}
  h1{font-size:20px;margin:0 0 16px}
  .card{background:#1e293b;border:1px solid #334155;border-radius:12px;padding:16px;margin-bottom:20px}
  label{display:block;font-size:13px;margin:8px 0 4px;color:#94a3b8}
  input,select{width:100%;padding:8px;border-radius:8px;border:1px solid #475569;background:#0f172a;color:#e2e8f0;box-sizing:border-box}
  .row{display:flex;gap:12px;flex-wrap:wrap}
  .row>div{flex:1;min-width:130px}
  button{background:#0ea5e9;color:#fff;border:0;padding:10px 18px;border-radius:8px;cursor:pointer;font-size:14px}
  button:hover{background:#0284c7}
  .primary{margin-top:14px}
  table{width:100%;border-collapse:collapse;font-size:13px}
  th,td{text-align:right;padding:8px;border-bottom:1px solid #334155;vertical-align:middle}
  th{color:#94a3b8;font-weight:normal}
  .mono{font-family:monospace;direction:ltr;display:inline-block}
  .badge{padding:2px 8px;border-radius:6px;font-size:11px}
  .on{background:#065f46;color:#d1fae5}.off{background:#7f1d1d;color:#fee2e2}
  .flag{background:#1e3a8a;color:#dbeafe}
  .ok{background:#064e3b;border:1px solid #10b981;padding:12px;border-radius:8px;margin-bottom:16px;line-height:1.9}
  .err{background:#7f1d1d;border:1px solid #ef4444;padding:12px;border-radius:8px;margin-bottom:16px}
  .muted{color:#64748b;font-size:12px}
  nav{margin-bottom:16px;display:flex;justify-content:space-between;align-items:center}
  .act{display:inline-flex;gap:6px}.act button{padding:5px 12px;font-size:12px}
  .bstop{background:#b45309}.bstop:hover{background:#92400e}
  .bstart{background:#15803d}.bstart:hover{background:#166534}
  .logout{background:#334155}.logout:hover{background:#475569}
</style>
</head>
<body>
<div class="wrap">
  <nav>
    <span><a href="/">کنترل اکسیت</a> · <b>ماشین‌ها</b> · <a href="{{ route('diag') }}">تشخیص</a></span>
    <form method="post" action="{{ route('logout') }}">@csrf<button class="logout" type="submit">خروج</button></form>
  </nav>
  <h1>ماشین‌های مشتریان</h1>
  @if($errors->any())
    <div class="err">خطا: {{ $errors->first() }}</div>
  @endif
  @if($created)
    <div class="ok">
      ✅ ماشین <b>{{ $created['vmid'] }}</b> ({{ $created['os_label'] ?? '' }}) ساخته شد.<br>
      دسترسی (@if(!empty($created['is_win']))RDP@else SSH @endif): <span class="mono">{{ $created['access'] }}</span><br>
      کاربر: <span class="mono">{{ $created['ciuser'] }}</span> — رمز: <span class="mono">{{ $created['password'] }}</span><br>
      @if(!empty($created['exit_country']))کشور خروجی: <b>{{ strtoupper($created['exit_country']) }}</b> <span class="muted">(تا ~۱ دقیقه توسط ایجنت اعمال می‌شود؛ خروجی از این کشور، ورودی از همین دامنه:پورت)</span><br>@endif
      <span class="muted">این رمز فقط همین یک‌بار نمایش داده می‌شود؛ ذخیره‌اش کنید.</span>
    </div>
  @endif
  <div class="card">
    <form method="post" action="{{ route('vms.store') }}">
      @csrf
      <div class="row">
        <div><label>نام</label><input name="name" value="{{ old('name') }}" placeholder="vps-01" required></div>
        <div><label>سیستم‌عامل</label>
          <select name="os">
            @foreach($catalog as $key => $os)
              <option value="{{ $key }}" @if(old('os')===$key)selected @elseif($key==='ubuntu2204' && !old('os'))selected @endif>{{ $os['label'] }}</option>
            @endforeach
          </select>
        </div>
        <div><label>کشور خروجی</label>
          <select name="country">
            <option value="">— بدون (آی‌پی ایران) —</option>
            @foreach(($countries ?? []) as $cc => $co)
              <option value="{{ $cc }}" @if(old('country')===$cc)selected @endif>{{ $co['label'] }} ({{ strtoupper($cc) }})</option>
            @endforeach
          </select>
        </div>
        <div><label>هسته (CPU)</label><input name="cores" type="number" value="{{ old('cores', 2) }}" min="1" max="16"></div>
        <div><label>رم (MB)</label><input name="memory" type="number" value="{{ old('memory', 2048) }}" min="512" step="512"></div>
      </div>
      <button class="primary" type="submit">ساخت خودکار VM + دامنه:پورت</button>
      <div class="muted">آی‌پی داخلی و پورتِ عمومی خودکار تخصیص می‌یابد؛ پورت‌فوروارد را ایجنتِ هاست تا ۳۰ثانیه اعمال می‌کند. اگر کشور انتخاب شود، خروجیِ VM از آن کشور می‌رود (ورودی/مدیریت از همان دامنه:پورت).</div>
    </form>
  </div>
  <div class="card">
    <table>
      <thead>
        <tr><th>VMID</th><th>نام</th><th>OS</th><th>خروج</th><th>دسترسی (دامنه:پورت)</th><th>آی‌پی داخلی</th><th>وضعیت</th><th>عملیات</th></tr>
      </thead>
      <tbody>
      @forelse($records as $r)
        @php $vm = $live->get($r->vmid); $st = is_array($vm) ? ($vm['status'] ?? null) : null; @endphp
        <tr>
          <td>{{ $r->vmid }}</td>
          <td>{{ $r->name }}</td>
          <td>{{ $r->osLabel() }}</td>
          <td>@if(!empty($r->exit_country))<span class="badge flag">{{ strtoupper($r->exit_country) }}</span>@else<span class="muted">IR</span>@endif</td>
          <td>@if($r->access())<span class="mono">{{ $r->access() }}</span>@else <span class="muted">—</span>@endif</td>
          <td><span class="mono">{{ $r->ip }}</span></td>
          <td>
            @if($st === 'running')<span class="badge on">running</span>
            @elseif($st)<span class="badge off">{{ $st }}</span>
            @else <span class="muted">—</span>@endif
          </td>
          <td>
            <span class="act">
              @if($st !== 'running')
                <form method="post" action="{{ route('vms.action', $r->vmid) }}">@csrf<input type="hidden" name="action" value="start"><button class="bstart" type="submit">روشن</button></form>
              @else
                <form method="post" action="{{ route('vms.action', $r->vmid) }}">@csrf<input type="hidden" name="action" value="stop"><button class="bstop" type="submit">خاموش</button></form>
              @endif
              <form method="post" action="{{ route('vms.destroy', $r->vmid) }}" onsubmit="return confirm('حذف قطعی؟ برگشت‌پذیر نیست.');" style="display:inline">@csrf<button class="bstop" type="submit">حذف</button></form>
            </span>
          </td>
        </tr>
      @empty
        <tr><td colspan="8" class="muted">هنوز ماشینی از طریقِ پنل ساخته نشده.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</div>
</body>
</html>

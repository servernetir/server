@php
  /** @var \App\Models\Server|null $server */
  $isEdit = $server !== null;
  $types = ['whm'=>'WHM / cPanel (خودکار)','directadmin'=>'DirectAdmin (خودکار)','plesk'=>'Plesk (دستی)','vps'=>'VPS (دستی)','dedicated'=>'سرور اختصاصی (دستی)','generic'=>'عمومی (دستی)'];
@endphp
<form method="post" action="{{ $action }}" class="srv-f">
  @csrf
  <label>نام نمایشی
    <input type="text" name="name" value="{{ old('name', $server->name ?? '') }}" required maxlength="80" placeholder="WHM-DE-01">
  </label>
  <label>نوع
    <select name="type">
      @foreach($types as $v => $t)
        <option value="{{ $v }}" @selected(old('type', $server->type ?? 'whm') === $v)>{{ $t }}</option>
      @endforeach
    </select>
  </label>
  <label>میزبان (hostname)
    <input type="text" name="hostname" dir="ltr" value="{{ old('hostname', $server->hostname ?? '') }}" maxlength="190" placeholder="server1.servernet.cloud">
  </label>
  <label>پورت API
    <input type="number" name="port" dir="ltr" value="{{ old('port', $server->port ?? '') }}" min="1" max="65535" placeholder="۲۰۸۷ برای WHM">
  </label>
  <label>کاربر API
    <input type="text" name="username" dir="ltr" value="{{ old('username', $server->username ?? 'root') }}" maxlength="60" placeholder="root">
  </label>
  <label>توکن API
    <input type="password" name="api_token" dir="ltr" autocomplete="new-password" maxlength="400"
           placeholder="{{ $isEdit ? 'برای تغییر، توکن جدید بزنید' : 'توکن WHM (Manage API Tokens)' }}">
  </label>
  <label>IP سرور (اختیاری)
    <input type="text" name="server_ip" dir="ltr" value="{{ old('server_ip', $server->server_ip ?? '') }}" maxlength="45" placeholder="برای IP اختصاصی">
  </label>
  <label>نیم‌سرورها (اختیاری)
    <input type="text" name="nameservers" dir="ltr" value="{{ old('nameservers', $server->nameservers ?? '') }}" maxlength="190" placeholder="ns1.x,ns2.x">
  </label>
  <label>وضعیت
    <select name="status">
      @foreach(['active'=>'فعال','maintenance'=>'تعمیر','full'=>'پر'] as $v => $t)
        <option value="{{ $v }}" @selected(old('status', $server->status ?? 'active') === $v)>{{ $t }}</option>
      @endforeach
    </select>
  </label>
  <label>سقف حساب (اختیاری)
    <input type="number" name="max_accounts" dir="ltr" value="{{ old('max_accounts', $server->max_accounts ?? '') }}" min="0" placeholder="ظرفیت">
  </label>
  <label class="chk col2">
    <input type="checkbox" name="verify_tls" value="1" @checked(old('verify_tls', $server->verify_tls ?? true))>
    بررسیِ گواهیِ TLS (برای گواهیِ self-signed خاموش کنید)
  </label>
  <label class="col2">یادداشت (اختیاری)
    <input type="text" name="note" value="{{ old('note', $server->note ?? '') }}" maxlength="1000">
  </label>
  <div class="col2" style="display:flex;justify-content:flex-end">
    <button type="submit" class="btn btn-primary"><svg class="icon"><use href="#i-check"/></svg>{{ $isEdit ? 'ذخیرهٔ تغییرات' : 'افزودن سرور' }}</button>
  </div>
</form>

@extends('admin.layout')
@section('title', 'الگوی پیام‌ها')
@section('nav_templates', 'active')
@section('content')

<div class="ad-panel">
  <div class="ad-panel-h"><h2>الگوی پیام‌ها</h2></div>

  <p style="padding:0 18px 14px;color:var(--muted);font-size:13px;line-height:1.9">
    متنِ پیام‌هایی که سرورنت به کاربر می‌فرستد — ایمیل، بله و اعلان پنل — این‌جا
    ویرایش می‌شود. تا پیش از این، هر جمله در کد بود و عوض‌کردنش دپلوی می‌خواست.
    <br>⚠️ <b>متن پیامک این‌جا نیست و نمی‌تواند باشد:</b> اپراتور متن الگو را در پنل خودش
    نگه می‌دارد و تأیید می‌کند؛ ما فقط کد الگو و متغیرها را می‌فرستیم. برای هر پیام،
    کد الگویی که استفاده می‌شود نشان داده شده تا بدانید کدام را در پنل اپراتور ویرایش کنید.
    <br>ستون <b>کانال‌ها</b> می‌گوید هر الگو واقعاً روی کدام کانال اثر دارد. الگویی که
    کانالی ندارد یعنی متنش هنوز جایی مصرف نمی‌شود — ویرایشش بی‌اثر است.
  </p>

  @if(session('ok'))<div class="ad-flash ok" style="margin:0 18px 14px">{{ session('ok') }}</div>@endif

  @if($groups->isEmpty())
    <p style="padding:0 18px 18px;color:var(--muted);font-size:13px">
      هنوز الگویی ثبت نشده. پس از اجرای مهاجرت، کاتالوگ پیام‌ها خودکار پر می‌شود.
    </p>
  @endif

  @foreach($labels as $key => $label)
    @php $rows = $groups->get($key); @endphp
    @if($rows)
      <div style="padding:0 18px 6px">
        <h3 style="font-size:13.5px;color:var(--cyan);margin:14px 0 10px">{{ $label }}</h3>
      </div>

      <div style="padding:0 18px 10px;overflow-x:auto">
        <table class="ad-table">
          <thead><tr>
            <th>پیام</th><th>کانال‌ها</th><th>الگوی پیامک</th><th>وضعیت</th><th></th>
          </tr></thead>
          <tbody>
            @foreach($rows as $t)
              <tr>
                <td><b>{{ $t->title }}</b>
                  <div style="font-size:11.5px;color:var(--dim)" dir="ltr">{{ $t->key }}</div></td>
                {{-- کانال‌های **واقعی**، نه صرفاً «فیلدش پر است». الگویی که به
                     هیچ فراخوانی وصل نیست، هرچقدر هم متن داشته باشد به مشتری
                     نمی‌رسد و باید صریح گفته شود. --}}
                <td style="font-size:12px;color:var(--muted)">
                  @php $ch = $t->wiredChannels(); @endphp
                  @if($ch)
                    @if(in_array('sms', $ch))پیامک · @endif
                    بله و اعلان
                    @if(in_array('email', $ch) && filled($t->email_body)) · ایمیل@endif
                  @else
                    <span style="color:#fbbf24">هنوز جایی مصرف نمی‌شود</span>
                  @endif
                </td>
                {{-- 🔴 ستونِ خامِ `sms_event` دروغ می‌گفت: کدی را نشان می‌داد که
                     برای رویدادهای بی‌الگو هرگز به اپراتور فرستاده نمی‌شد، و مدیر
                     می‌رفت ساعت‌ها روی متنی در پنلِ اپراتور کار می‌کرد که هیچ
                     مشتری‌ای نمی‌دید. حالا از سیمِ واقعی می‌آید. --}}
                <td dir="ltr" style="font-size:12px;color:var(--muted)">
                  @if(in_array('sms', $ch)){{ $t->sms_event ?: $t->key }}@else<span dir="rtl">—</span>@endif
                </td>
                <td>
                  @if($t->is_active)
                    <span class="ad-badge" style="background:#34d39922;color:#34d399">فعال</span>
                  @else
                    <span class="ad-badge" style="background:#ff6b6b22;color:#ff6b6b">خاموش</span>
                  @endif
                </td>
                <td style="text-align:end">
                  <a class="btn btn-glass" style="font-size:12px;padding:6px 12px"
                     href="/admin/templates/{{ $t->id }}">ویرایش</a>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  @endforeach
</div>
@endsection

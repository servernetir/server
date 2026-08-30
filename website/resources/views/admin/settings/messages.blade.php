{{-- تبِ الگوی پیام‌ها — از منوی «سیستم» به این‌جا آمد.
     ویرایشِ هر الگو هنوز صفحهٔ خودش را دارد (/admin/templates/{id})، چون
     ویرایشگرِ متنِ ایمیل است و داخلِ یک تب جا نمی‌شود. --}}

<div class="ad-panel">
  <div class="ad-panel-h"><h2>الگوی پیام‌ها</h2></div>

  <p class="set-lead">
    متنِ پیام‌هایی که سرورنت به کاربر می‌فرستد — ایمیل، بله و اعلان پنل — این‌جا
    ویرایش می‌شود. تا پیش از این، هر جمله در کد بود و عوض‌کردنش دپلوی می‌خواست.
    <br>⚠️ <b>متن پیامک این‌جا نیست و نمی‌تواند باشد:</b> اپراتور متن الگو را در پنل خودش
    نگه می‌دارد و تأیید می‌کند؛ ما فقط کد الگو و متغیرها را می‌فرستیم. برای هر پیام،
    کد الگویی که استفاده می‌شود نشان داده شده تا بدانید کدام را در پنل اپراتور ویرایش کنید.
    <br>ستون <b>کانال‌ها</b> می‌گوید هر الگو واقعاً روی کدام کانال اثر دارد. الگویی که
    کانالی ندارد یعنی متنش هنوز جایی مصرف نمی‌شود — ویرایشش بی‌اثر است.
  </p>

  @if($tplNotReady || $groups->isEmpty())
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

{{-- ═══════════════ اعلان‌های خودِ مدیر ═══════════════

     🔴 چرا پنلِ جدا و نه یک ستون در جدولِ بالا:

     «پیامی که به مشتری می‌رود» و «اعلانی که به من می‌رسد» دو تصمیمِ متفاوت‌اند —
     اولی متنِ برند است، دومی صدای آژیر. یک‌کاسه‌کردنشان یعنی برای خاموش‌کردنِ یک
     اعلانِ پرتکرار باید لای متن‌های مشتری بگردی، و آن‌قدر بگردی که بی‌خیال شوی.

     ⚠️ سوییچ همین‌جاست نه در صفحهٔ ویرایش: «این را دیگر نفرست» تصمیمِ یک‌کلیکی
     است. اگر برای هر خاموش‌کردن یک صفحه باز شود، عملاً هیچ‌وقت انجام نمی‌شود. --}}
@if(!$tplNotReady && $adminGroups->isNotEmpty())
  <div class="ad-panel" style="margin-top:18px">
    <div class="ad-panel-h"><h2>اعلان‌های من (بله و ایمیل)</h2></div>

    <p class="set-lead">
      این‌ها اعلان‌هایی‌اند که سرورنت به <b>خودِ شما</b> می‌فرستد، نه به مشتری.
      هرکدام را می‌توانید خاموش کنید یا متنش را خودتان بنویسید.
      <br>در متن می‌توانید از تگ‌های همان رویداد استفاده کنید — مثلاً
      <code dir="ltr">{مشتری}</code> یا <code dir="ltr">{مبلغ}</code>. فهرستِ تگ‌های
      هر رویداد در صفحهٔ ویرایشش نوشته شده.
      <br>⚠️ متنِ خالی یعنی «همان متنِ پیش‌فرض» — و اگر تگی بنویسید که آن رویداد
      ندارد، به‌جای چاپِ <code dir="ltr">{تگ}</code> کلِ متن نادیده گرفته می‌شود و
      پیش‌فرض می‌رود.
      <br>🔴 کدِ تأییدِ اتصالِ کنسولِ مدیر عمداً این‌جا نیست: خاموش‌کردنش یعنی
      خودتان را از کنسول بیرون بیندازید بی‌آنکه راهی برای برگشت بماند.
    </p>

    @foreach($adminGroups as $g => $rows)
      <div class="ad-panel-h" style="border-top:1px solid var(--line)">
        <h3 style="font-size:14px;margin:0">{{ $labels[$g] ?? $g }}</h3>
      </div>
      <div style="padding:0 18px 10px;overflow-x:auto">
        <table class="ad-table">
          <thead><tr><th>رویداد</th><th>متن</th><th>وضعیت</th><th></th></tr></thead>
          <tbody>
            @foreach($rows as $t)
              <tr>
                <td>{{ $t->title }}</td>
                <td style="font-size:12px;color:var(--muted)">
                  {{ $t->bale_body ? 'متنِ دلخواه' : 'پیش‌فرض' }}
                </td>
                <td>
                  <form method="post" action="/admin/templates/{{ $t->id }}/toggle" style="display:inline">@csrf
                    @if($t->is_active)
                      <button class="del" style="color:#34d399" type="submit" title="کلیک = دیگر برایم نفرست">فعال</button>
                    @else
                      <button class="del" style="color:#ff6b6b" type="submit" title="کلیک = دوباره برایم بفرست">خاموش</button>
                    @endif
                  </form>
                </td>
                <td style="text-align:end">
                  <a class="btn btn-glass" style="font-size:12px;padding:6px 12px"
                     href="/admin/templates/{{ $t->id }}">ویرایشِ متن</a>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endforeach
  </div>
@endif

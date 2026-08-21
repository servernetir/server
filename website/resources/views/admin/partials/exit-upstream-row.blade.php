{{--
    یک ردیفِ جدولِ آپ‌استریمِ اکسیت — هم برای رله‌ها و هم اکسیت‌های کشوری.

    ورودی:
      $u            ExitUpstream
      $showCountry  bool — رله کشور ندارد، پس آن‌جا ستونِ «کاربر» می‌آید.

    ⚠️ هر دو جدول دقیقاً ۸ ستون دارند؛ اگر ستونی این‌جا اضافه/کم شود، سرستون‌های
    exit-upstreams.blade.php هم باید همان‌جا عوض شوند وگرنه جدول می‌لغزد.

    🔴 `secret` هرگز این‌جا چاپ نمی‌شود — نه کامل نه بریده. فقط «دارد/ندارد».
--}}
@php
    [$healthLabel, $healthColor] = $u->healthBadge();
@endphp
<tr>
    <td>
        <strong>{{ $u->name }}</strong>
        @if($u->note)
            <div style="font-size:11px;color:var(--muted);margin-top:2px">{{ $u->note }}</div>
        @endif
    </td>

    @if($showCountry)
        <td dir="ltr">{{ $u->flag() }} {{ $u->countryName() }}</td>
    @endif

    <td>{{ $u->typeLabel() }}</td>

    <td dir="ltr" style="font-family:ui-monospace,monospace;font-size:12px">
        {{ $u->endpointLabel() }}
    </td>

    @unless($showCountry)
        <td dir="ltr">{{ $u->username ?: '—' }}</td>
    @endunless

    <td>{{ fa_num((string) $u->priority) }}</td>

    <td>
        <span class="ad-badge" style="background:{{ $healthColor }}22;color:{{ $healthColor }}">
            {{ $healthLabel }}
        </span>
        @if($u->last_latency_ms)
            <span style="font-size:11px;color:var(--muted)">{{ fa_num((string) $u->last_latency_ms) }}ms</span>
        @endif
    </td>

    <td>
        <form method="post" action="{{ route('admin.exit-upstreams.toggle', $u) }}" style="display:inline">
            @csrf
            <button class="ad-badge" type="submit"
                    style="background:{{ $u->enabled ? 'rgba(52,211,153,.18)' : 'rgba(148,163,184,.18)' }};
                           color:var(--text);border:0;cursor:pointer"
                    title="{{ $u->enabled ? 'غیرفعال کن' : 'فعال کن' }}">
                {{ $u->enabled ? 'فعال' : 'خاموش' }}
            </button>
        </form>
    </td>

    <td style="text-align:left;white-space:nowrap">
        <a class="ad-badge" href="{{ route('admin.exit-upstreams.edit', $u) }}"
           style="text-decoration:none;color:var(--text)">ویرایش</a>

        {{-- حذف عمداً یک POSTِ جدا با تأیید است: آپ‌استریمِ حذف‌شده یعنی قطعِ
             خروجِ همان کشور تا پیمایشِ بعدیِ ایجنت.
             ⚠️ تأیید با `data-confirm` است نه دیالوگِ بومیِ مرورگر — قاعدهٔ
             پروژه که BrandedDialogTest نگهش می‌دارد. --}}
        <form method="post" action="{{ route('admin.exit-upstreams.delete', $u) }}"
              style="display:inline"
              data-confirm="«{{ $u->name }}» حذف شود؟ خروجِ وابسته به آن تا اعمالِ بعدیِ ایجنت قطع می‌شود."
              data-confirm-title="حذفِ آپ‌استریم"
              data-confirm-ok="بله، حذف کن">
            @csrf
            <button class="ad-badge" type="submit"
                    style="background:rgba(255,107,107,.16);color:#ff6b6b;border:0;cursor:pointer">
                حذف
            </button>
        </form>
    </td>
</tr>

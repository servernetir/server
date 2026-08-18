{{--
  یک ردیفِ آپ‌استریمِ اکسیت. ورودی: $u (ExitUpstream)، $showCountry (bool).
  🔴 هرگز $u->secret را چاپ نمی‌کند — فقط «دارد/ندارد».
--}}
@php
  [$hLabel, $hColor] = $u->healthBadge();
@endphp
<tr>
  <td style="font-size:12.5px">
    {{ $u->name }}
    @if($u->hasSecret())
      <span title="اعتبارنامه ذخیره شده" style="color:var(--dim);font-size:11px"> 🔑</span>
    @else
      <span title="بدونِ اعتبارنامه" style="color:#fbbf24;font-size:11px"> ⚠</span>
    @endif
    @if($u->note)
      <div style="font-size:11px;color:var(--dim);max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $u->note }}</div>
    @endif
  </td>

  @if($showCountry)
    <td style="white-space:nowrap;font-size:12.5px">{{ $u->flag() }} {{ $u->countryName('fa') }}</td>
  @endif

  <td><span class="ad-badge" style="background:rgba(34,211,238,.14);color:var(--text);font-size:12px">{{ $u->typeLabel() }}</span></td>
  <td dir="ltr" style="font-size:12px;color:var(--muted)">{{ $u->endpointLabel() }}@if($u->sni)<div style="font-size:11px;color:var(--dim)">SNI: {{ $u->sni }}</div>@endif</td>
  <td style="font-size:12.5px">{{ fa_num($u->priority) }}</td>
  <td><span class="ad-badge" style="background:{{ $hColor }}22;color:{{ $hColor }};font-size:12px">{{ $hLabel }}</span></td>

  <td>
    <form method="post" action="{{ route('admin.exit-upstreams.toggle', $u->id) }}" style="display:inline">
      @csrf
      @php $ecol = $u->enabled ? '#34d399' : '#96a3ba'; @endphp
      <button type="submit" class="ad-badge"
              style="background:{{ $ecol }}22;color:{{ $ecol }};border:0;cursor:pointer;font-size:12px;padding:5px 11px"
              title="{{ $u->enabled ? 'کلیک برای غیرفعال‌کردن' : 'کلیک برای فعال‌کردن' }}">
        {{ $u->enabled ? 'فعال' : 'غیرفعال' }}
      </button>
    </form>
  </td>

  <td style="white-space:nowrap">
    <a href="{{ route('admin.exit-upstreams.edit', $u->id) }}" class="ad-badge"
       style="background:rgba(148,163,184,.14);color:var(--muted);text-decoration:none;font-size:12px;padding:5px 10px">ویرایش</a>
    <form method="post" action="{{ route('admin.exit-upstreams.delete', $u->id) }}" style="display:inline"
          data-confirm="آپ‌استریمِ «{{ $u->name }}» حذف شود؟" data-confirm-danger data-confirm-ok="حذف">
      @csrf
      <button type="submit" class="ad-badge"
              style="background:rgba(255,107,107,.14);color:#ff6b6b;border:0;cursor:pointer;font-size:12px;padding:5px 10px">حذف</button>
    </form>
  </td>
</tr>

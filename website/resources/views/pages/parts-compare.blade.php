@extends('layouts.site')
@section('title', __('ui.parts_compare').' — '.__('ui.parts_title').' — '.__('ui.brand'))
@section('description', __('ui.parts_desc'))
{{-- 🔴 صفحهٔ مقایسه noindex است: محتوایش ترکیبِ انتخابیِ کاربر است، پس بی‌نهایت
     آدرسِ تقریباً یکسان می‌سازد. ایندکس‌شدنشان یعنی بودجهٔ خزشِ سایت صرفِ
     جدول‌های تکراری شود به‌جای صفحاتِ محصول. --}}
@section('noindex', true)
@section('content')

@php
    $condLbl = [
        'new'    => __('ui.parts_cond_new'),
        'refurb' => __('ui.parts_cond_refurb'),
        'used'   => __('ui.parts_cond_used'),
    ];

    /*
    | بهترین مقدارِ هر ردیف — برای برجسته‌کردن.
    |
    | ⚠️ فقط وقتی **بیش از یک** مقدارِ متمایز هست. اگر هر دو ستون ۱۲ هسته
    | دارند، رنگ‌کردنِ هر دو یعنی «برنده» بی‌معنا شود؛ و رنگ‌کردنِ یکی از آن دو
    | یعنی دروغ.
    */
    $best = [];
    foreach ($rows as $key => $row) {
        $nums = collect($row['values'])
            ->filter(fn ($v) => is_numeric($v))
            ->map(fn ($v) => (float) $v);

        if ($nums->count() < 2 || $nums->unique()->count() < 2) {
            continue;
        }

        $best[$key] = ($row['label']['higher_better'] ?? true) ? $nums->max() : $nums->min();
    }
@endphp

<section class="sp-hero">
  <div class="container">
    <nav class="blog-crumbs">
      <a href="{{ lroute('home') }}">{{ __('ui.brand') }}</a><span>/</span>
      <a href="{{ lroute('parts.index') }}">{{ __('ui.parts_title') }}</a><span>/</span>
      <span>{{ __('ui.parts_compare') }}</span>
    </nav>
    <h1>{{ __('ui.parts_compare') }}</h1>
  </div>
</section>

<div class="container sp-shell">
  @include('partials.parts-sidebar')

  <div class="sp-main">

    @if($parts->isEmpty())
      <p class="sp-empty">{{ __('ui.parts_compare_empty') }}</p>
      <p><a class="btn btn-primary" href="{{ lroute('parts.index') }}">{{ __('ui.parts_browse') }}</a></p>
    @else
      {{-- جدولِ عریض روی موبایل باید **خودش** اسکرول شود، نه کلِ صفحه. --}}
      <div class="sp-cmp-scroll">
        <table class="sp-cmp-table">
          <thead>
            <tr>
              <th class="sp-cmp-corner"></th>
              @foreach($parts as $p)
                <th>
                  <a href="{{ lroute('parts.show', [$p->category, $p->slug]) }}">{{ $p->label() }}</a>
                  <span class="sp-cat">{{ $p->categoryLabel() }}</span>
                </th>
              @endforeach
            </tr>
          </thead>
          <tbody>
            <tr>
              {{-- ⚠️ `parts_sort` نه — آن برچسبِ «مرتب‌سازی» است و این ردیفِ
                   قیمت را «Sort by» صدا می‌زد. --}}
              <th>{{ __('ui.parts_price') }}</th>
              @foreach($parts as $p)
                @php($price = $p->displayPrice())
                <td>
                  @if($price === null)
                    <span class="sp-price contact">{{ __('ui.parts_quote') }}</span>
                  @else
                    <span class="sp-price">{{ $price }}</span>
                  @endif
                </td>
              @endforeach
            </tr>
            <tr>
              <th>{{ __('ui.parts_filter_cond') }}</th>
              @foreach($parts as $p)
                <td>{{ $condLbl[$p->condition] ?? $p->condition }}</td>
              @endforeach
            </tr>
            <tr>
              <th>{{ __('ui.parts_compat') }}</th>
              @foreach($parts as $p)
                <td>
                  @php($gens = (array) ($p->compat_gens ?? []))
                  {{ $gens ? implode(' · ', array_map(fn ($g) => str_replace('gen', 'Gen', $g), $gens)) : __('ui.parts_compat_any') }}
                </td>
              @endforeach
            </tr>
            <tr>
              <th>{{ __('ui.parts_in_stock') }}</th>
              @foreach($parts as $p)
                <td>{{ $p->in_stock ? __('ui.parts_yes') : __('ui.parts_no') }}</td>
              @endforeach
            </tr>

            @foreach($rows as $key => $row)
              <tr>
                <th>{{ $row['label'][app()->getLocale()] ?? $row['label']['fa'] }}</th>
                @foreach($row['values'] as $v)
                  @php($win = isset($best[$key]) && is_numeric($v) && (float) $v === $best[$key])
                  <td @class(['sp-best' => $win]) @if($win) title="{{ __('ui.parts_compare_best') }}" @endif>
                    @if($v === null || $v === '')
                      <span class="muted">—</span>
                    @else
                      {{ fa_num((string) $v) }}{{ $row['label']['unit'] ? ' '.$row['label']['unit'] : '' }}
                    @endif
                  </td>
                @endforeach
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>

      <p class="sp-note">{{ __('ui.parts_eur_note') }}</p>
    @endif

  </div>
</div>

@include('partials.parts-compare-bar')
@endsection

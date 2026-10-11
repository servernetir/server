@if ($paginator->hasPages())
  @php
    $rtl = app()->getLocale() === 'fa';
    $num = fn ($value) => $rtl ? fa_num((string) $value) : (string) $value;
    $previousArrow = $rtl ? '›' : '‹';
    $nextArrow = $rtl ? '‹' : '›';
  @endphp

  @once
    <style>
      /* Self-contained because this component is shared by admin.css, panel.css
         and site.css. No SVG is used: a global svg rule can never enlarge it. */
      .sn-pagination{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin:18px 0 6px;padding:10px 12px;border:1px solid var(--line);border-radius:12px;background:var(--surface);font-size:12.5px}
      .sn-pagination__summary{margin:0;color:var(--muted);white-space:nowrap}
      .sn-pagination__links{display:flex;align-items:center;justify-content:center;gap:5px;direction:ltr;min-width:0}
      .sn-pagination__item{display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:34px;padding:0 10px;border:1px solid var(--line);border-radius:9px;background:var(--surface-2,var(--surface));color:var(--text);text-decoration:none;font:inherit;font-weight:600;line-height:1;white-space:nowrap;transition:border-color .15s,background .15s,color .15s}
      a.sn-pagination__item:hover{border-color:var(--cyan);color:var(--cyan);background:var(--info-bg,rgba(8,145,178,.08))}
      .sn-pagination__item[aria-current="page"]{border-color:var(--cyan);background:var(--cyan);color:#fff;cursor:default}
      .sn-pagination__item.is-disabled{opacity:.42;cursor:not-allowed}
      .sn-pagination__item.is-gap{min-width:24px;padding:0;border-color:transparent;background:transparent;color:var(--muted)}
      .sn-pagination__arrow{font-size:19px;line-height:0;transform:translateY(-1px)}
      .sn-pagination__label{margin:0 3px}
      @media(max-width:640px){
        .sn-pagination{justify-content:center;padding:9px 8px}
        .sn-pagination__summary{width:100%;text-align:center;white-space:normal}
        .sn-pagination__links{max-width:100%;overflow-x:auto;padding-bottom:2px}
        .sn-pagination__page:not([aria-current="page"]){display:none}
        .sn-pagination__item{min-width:38px;height:38px}
      }
    </style>
  @endonce

  <nav class="sn-pagination" role="navigation" aria-label="{{ __('pagination.navigation') }}">
    <p class="sn-pagination__summary">
      {{ __('pagination.showing', [
          'from' => $num($paginator->firstItem()),
          'to' => $num($paginator->lastItem()),
          'total' => $num($paginator->total()),
      ]) }}
    </p>

    <div class="sn-pagination__links">
      @if ($paginator->onFirstPage())
        <span class="sn-pagination__item is-disabled" aria-disabled="true">
          <span class="sn-pagination__arrow" aria-hidden="true">{{ $previousArrow }}</span>
          <span class="sn-pagination__label">{{ __('pagination.previous') }}</span>
        </span>
      @else
        <a class="sn-pagination__item" href="{{ $paginator->previousPageUrl() }}" rel="prev">
          <span class="sn-pagination__arrow" aria-hidden="true">{{ $previousArrow }}</span>
          <span class="sn-pagination__label">{{ __('pagination.previous') }}</span>
        </a>
      @endif

      @foreach ($elements as $element)
        @if (is_string($element))
          <span class="sn-pagination__item is-gap" aria-hidden="true">…</span>
        @endif

        @if (is_array($element))
          @foreach ($element as $page => $url)
            @if ($page == $paginator->currentPage())
              <span class="sn-pagination__item sn-pagination__page" aria-current="page">{{ $num($page) }}</span>
            @else
              <a class="sn-pagination__item sn-pagination__page" href="{{ $url }}" aria-label="{{ __('pagination.go_to_page', ['page' => $num($page)]) }}">{{ $num($page) }}</a>
            @endif
          @endforeach
        @endif
      @endforeach

      @if ($paginator->hasMorePages())
        <a class="sn-pagination__item" href="{{ $paginator->nextPageUrl() }}" rel="next">
          <span class="sn-pagination__label">{{ __('pagination.next') }}</span>
          <span class="sn-pagination__arrow" aria-hidden="true">{{ $nextArrow }}</span>
        </a>
      @else
        <span class="sn-pagination__item is-disabled" aria-disabled="true">
          <span class="sn-pagination__label">{{ __('pagination.next') }}</span>
          <span class="sn-pagination__arrow" aria-hidden="true">{{ $nextArrow }}</span>
        </span>
      @endif
    </div>
  </nav>
@endif

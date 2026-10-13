@push('head')
@php
  $atlasContactPage = $atlasContactPage ?? false;
  $atlasPageUrl = lroute($atlasContactPage ? 'contact' : 'home');
  $atlasSiteUrl = rtrim((string) config('app.url'), '/');
  $atlasPageSchema = [
    '@'.'context' => 'https://schema.org',
    '@type' => $atlasContactPage ? 'ContactPage' : 'WebPage',
    '@id' => $atlasPageUrl.'#webpage',
    'url' => $atlasPageUrl,
    'name' => $atlasContactPage ? __('ui.ct_title') : __('ui.meta_title'),
    'description' => $atlasContactPage ? __('ui.ct_sub') : __('ui.meta_desc'),
    'inLanguage' => app()->getLocale(),
    'isPartOf' => ['@id' => $atlasSiteUrl.'/#website'],
    'about' => ['@id' => $atlasSiteUrl.'/#organization'],
  ];
  if ($atlasContactPage) {
    $atlasPageSchema['breadcrumb'] = ['@id' => $atlasPageUrl.'#breadcrumb'];
    $atlasBreadcrumbSchema = [
      '@'.'context' => 'https://schema.org', '@type' => 'BreadcrumbList',
      '@id' => $atlasPageUrl.'#breadcrumb',
      'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => __('ui.brand'), 'item' => lroute('home')],
        ['@type' => 'ListItem', 'position' => 2, 'name' => __('ui.ct_title'), 'item' => $atlasPageUrl],
      ],
    ];
  }
@endphp
<script type="application/ld+json">{!! schema_ld($atlasPageSchema, $atlasContactPage ? 'ContactPage' : 'WebPage') !!}</script>
@if($atlasContactPage)
<script type="application/ld+json">{!! schema_ld($atlasBreadcrumbSchema, 'BreadcrumbList') !!}</script>
@endif
@endpush

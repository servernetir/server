@push('head')
@php
$workspaceCssPath = public_asset_path('assets/css/ai-workspace.css');
$workspaceCss = $workspaceCssPath ? file_get_contents($workspaceCssPath) : false;
@endphp
@if($workspaceCss !== false)<style id="ai-workspace-style">{!! preg_replace('/\s+/', ' ', $workspaceCss) !!}</style>
@else<link rel="stylesheet" href="{{ asset_ver('assets/css/ai-workspace.css') }}">@endif
@php
$experienceConfig = ['compareUrl' => lroute('ai.compare'), 'pick' => __('ai_experience.pick'), 'picked' => __('ai_experience.picked'), 'limit' => __('ai_experience.compare_limit'), 'count' => __('ai_experience.compare_count'), 'copied' => __('ai_experience.copied'), 'copyFailed' => __('ai_experience.copy_failed'), 'invalid' => __('ai_experience.estimate_invalid'), 'locale' => app()->getLocale()];
@endphp
<script type="application/json" id="ai-experience-config">@json($experienceConfig)</script>
<script src="{{ asset_ver('assets/js/ai-workspace.js') }}" defer></script>
@endpush

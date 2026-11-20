<section class="gc-card" aria-label="{{ __('daily-blog.title') }}">
  <h3>{{ __('daily-blog.title') }}</h3>
  <p>{{ __('daily-blog.note') }}</p>
  <p>{{ __('growth.'.($dailyBlog['enabled'] ? 'active' : 'paused')) }}</p>
  <a href="/admin/ai-growth/google-discovery">{{ __('google-discovery.title') }}</a>
  @forelse($dailyBlog['recent'] as $run)
    <article class="gc-source">
      <strong>{{ $run['key'] }}</strong> · {{ __('daily-blog.stage_'.$run['stage']) }}
      <p>{{ $run['source']['title'] ?? '' }}</p>
      @if(!empty($run['code']))<code>{{ $run['code'] }}</code>@endif
      @foreach(($run['validation_failures'] ?? []) as $locale => $reason)
        <p><b>{{ $locale }}</b> · <code>{{ $reason }}</code></p>
      @endforeach
      @if(($run['source']['trend']['status'] ?? '') === 'observed')
        <p>Hacker News · {{ $run['source']['trend']['points'] }} {{ __('daily-blog.points') }} · {{ $run['source']['trend']['comments'] }} {{ __('daily-blog.comments') }}</p>
      @else
        <p>{{ __('daily-blog.no_trend') }}</p>
      @endif
      @if($run['stage'] === 'published')
        @foreach(($run['urls'] ?? []) as $url)
          @if(\App\Services\Growth\SearchConsoleClient::allowedUrl($url))<a href="{{ $url }}" target="_blank" rel="noopener noreferrer">{{ $url }}</a>@endif
        @endforeach
      @endif
    </article>
  @empty
    <p>{{ __('growth.empty') }}</p>
  @endforelse
</section>

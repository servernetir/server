@push('head')
<style>
  #part-finder,#part-results{scroll-margin-top:8rem}
  #part-finder .hw-selects{grid-template-columns:repeat(auto-fit,minmax(min(100%,13rem),1fr));max-width:42rem}
  #part-finder .hw-search{margin-block:8px 12px}
</style>
@endpush
<section class="hw-guide" id="part-finder" aria-labelledby="part-finder-heading">
  <h2 id="part-finder-heading">{{ __('part_finder.heading') }}</h2>
  <p id="part-finder-help">{{ __('part_finder.intro') }}</p>
  <form method="get" action="{{ lroute('parts.index').'#part-finder' }}" role="search" aria-labelledby="part-finder-heading">
    <label for="part-finder-q">{{ __('part_finder.label') }}</label>
    <div class="hw-search">
      <input id="part-finder-q" type="search" name="q" dir="auto" maxlength="60" value="{{ $finderQuery }}"
             placeholder="{{ __('part_finder.example') }}" aria-describedby="part-finder-help">
      <button class="btn btn-primary" type="submit">
        <svg class="icon" aria-hidden="true"><use href="#i-search"/></svg>{{ __('part_finder.search') }}
      </button>
    </div>
    <div class="hw-selects">
      <label>{{ __('part_finder.category') }}
        <select name="category">
          <option value="">{{ __('part_finder.all_categories') }}</option>
          @foreach($categories as $key => $entry)
            <option value="{{ $key }}" @selected($finderCategory === $key)>{{ $entry['label'] }}</option>
          @endforeach
        </select>
      </label>
      <label>{{ __('part_finder.generation') }}
        <select name="gen">
          <option value="">{{ __('part_finder.all_generations') }}</option>
          @foreach($generations as $key => $entry)
            <option value="{{ $key }}" @selected($finderGeneration === $key)>{{ lc($entry['name']) }}</option>
          @endforeach
        </select>
      </label>
    </div>
  </form>
  @if($finderFiltered)
    <nav class="hw-related-links" aria-label="{{ __('part_finder.heading') }}">
      <a href="{{ lroute('parts.index').'#part-finder' }}">{{ __('part_finder.reset') }}</a>
    </nav>
  @endif
  @if($finderInvalid)
    <p class="hw-note" role="status">{{ __('part_finder.too_short') }}</p>
  @elseif($finderResults === null)
    <nav class="hw-related-links" aria-label="{{ __('part_finder.shortcuts') }}">
      <a href="{{ lroute('servers.generation', 'gen9') }}">HPE Gen9</a>
      <a href="{{ lroute('servers.generation', 'gen10') }}">HPE Gen10</a>
      <a href="{{ lroute('parts.category', 'ram') }}">{{ $categories['ram']['label'] }}</a>
      <a href="{{ lroute('parts.category', 'disk') }}">{{ $categories['disk']['label'] }}</a>
      <a href="{{ lroute('parts.category', 'gpu') }}">{{ $categories['gpu']['label'] }}</a>
    </nav>
  @endif
</section>
@if($finderResults !== null)
  <section id="part-results" aria-labelledby="part-results-heading">
    <h2 class="sp-h2" id="part-results-heading">{{ __('part_finder.results', ['count' => fa_num($finderResults->total())]) }}</h2>
    @if($finderGeneration)
      <p class="hw-note">{{ __('part_finder.generation_note') }}</p>
    @endif
    @forelse($finderResults as $part)
      @if($loop->first)<div class="sp-grid">@endif
      @include('partials.part-card', ['part' => $part, 'compare' => true])
      @if($loop->last)</div>@endif
    @empty
      <p class="sp-empty">{{ __('part_finder.no_results') }}
        <a href="{{ lroute('contact').'#form' }}">{{ __('part_finder.ask_sourcing') }}</a>
      </p>
    @endforelse
    @include('partials.hardware-pagination', ['pagination' => $finderResults])
  </section>
@endif

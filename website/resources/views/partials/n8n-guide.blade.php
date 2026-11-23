<section class="n8n-guide" aria-labelledby="n8n-hosting-heading">
  <div class="n8n-guide-intro"><span class="n8n-eyebrow">ServerNet × n8n</span><h2 id="n8n-hosting-heading">{{ $copy['heading'] }}</h2><p>{{ $copy['intro'] }}</p></div>
  <h3>{{ $copy['difference_title'] }}</h3>
  <div class="n8n-guide-cards">@foreach($copy['differences'] as $item)<article><span class="n8n-guide-number">{{ fa_num($loop->iteration) }}</span><h4>{{ $item['title'] }}</h4><p>{{ $item['text'] }}</p></article>@endforeach</div>
  @if($rates)
  <div class="n8n-rate-section" id="n8n-rates"><h3>{{ $copy['rates_title'] }}</h3><p>{{ $copy['rates_note'] }}</p>
    <div class="n8n-rate-scroll"><table class="n8n-rate-table"><thead><tr><th scope="col">{{ __('n8n.location') }}</th><th scope="col">{{ __('n8n.plan') }}</th><th scope="col">{{ __('n8n.resources') }}</th><th scope="col">{{ $copy['starting'] }}</th><th scope="col">{{ $copy['hourly'] }}</th></tr></thead><tbody>
      @foreach($rates as $card)<tr><th scope="row"><img src="{{ asset_ver('assets/flags/'.strtolower($card['country']).'.svg') }}" alt="" width="24" height="18">{{ __('n8n.'.$card['country']) }}</th><td>{{ $card['name'] }}</td><td><bdi>{{ $card['cpu'] }} vCPU · {{ $card['ram'] }} · {{ $card['disk'] }}</bdi></td><td>{{ cloud_price($card['cycles']['monthly']) }}</td><td>{{ $card['hourly'] > 0 ? cloud_hourly_price($card['hourly']) : $copy['periodic'] }}</td></tr>@endforeach
    </tbody></table></div>
  </div>
  @endif
  <div class="n8n-sizing" id="n8n-sizing"><h3>{{ $copy['sizing_title'] }}</h3><p>{{ $copy['sizing_intro'] }}</p><div class="n8n-guide-cards">@foreach($copy['sizing'] as $item)<article><h4>{{ $item['title'] }}</h4><p>{{ $item['text'] }}</p></article>@endforeach</div></div>
</section>
<section class="n8n-faq" id="n8n-faq" aria-labelledby="n8n-faq-heading"><div><span class="n8n-eyebrow">n8n</span><h2 id="n8n-faq-heading">{{ $copy['faq_title'] }}</h2></div><div class="n8n-faq-list">@foreach($copy['faqs'] as $item)<details><summary>{{ $item['q'] }}<span aria-hidden="true">+</span></summary><p>{{ $item['a'] }}</p></details>@endforeach</div></section>
<aside class="n8n-guide-cta"><div><h2>{{ $copy['guide_title'] }}</h2><p>{{ $copy['guide_text'] }}</p></div><div><a class="n8n-button" href="{{ lroute('blog', ['slug' => 'n8n-backup-migration-guide']) }}">{{ $copy['guide_link'] }}</a><a class="n8n-guide-contact" href="{{ lroute('contact') }}">{{ $copy['contact'] }}</a></div></aside>

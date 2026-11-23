@php($n8nCountries = array_values(array_unique(array_column($n8nCards, 'country'))))
@forelse($n8nCountries as $country)
<section class="n8n-country">
  <h3>{{ __('n8n.'.$country) }}</h3>
  <div class="n8n-table-wrap"><table class="n8n-table">
    <thead><tr><th>{{ __('n8n.plan') }}</th><th>{{ __('n8n.cpu') }}</th><th>{{ __('n8n.ram') }}</th><th>{{ __('n8n.disk') }}</th><th>{{ __('n8n.monthly') }}</th><th>{{ __('n8n.hourly') }}</th><th></th></tr></thead>
    <tbody>
    @foreach($n8nCards as $card)
      @continue($card['country'] !== $country)
      <tr>
        <td><strong>{{ $card['name'] }}</strong></td><td>{{ fa_num($card['cpu']) }} vCPU</td><td>{{ $card['ram'] }}</td><td>{{ $card['disk'] }}</td>
        <td>{{ cloud_price($card['cycles']['monthly']) }}</td><td>{{ $card['hourly'] > 0 ? cloud_price($card['hourly']) : '—' }}</td>
        <td>@if($n8nEnabled)<a class="n8n-select" href="{{ lroute('account.cloud.store', ['application'=>'n8n', 'location'=>$card['location'], 'plan'=>$card['slug']]) }}">{{ __('n8n.choose') }}</a>@endif</td>
      </tr>
    @endforeach
    </tbody>
  </table></div>
</section>
@empty
  <p class="n8n-notice">{{ __('n8n.unavailable') }}</p>
@endforelse

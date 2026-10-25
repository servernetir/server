@if($featured)
<section class="section hv-sec" id="hourly-comparison" data-hourly-enhance hidden aria-labelledby="hourly-compare-title">
  <div class="container"><div class="hv-sec-h"><h2 id="hourly-compare-title">{{ $hvUx['compare_title'] }}</h2><p>{{ $hvUx['compare_note'] }}</p></div><p id="hourly-compare-status" role="status" aria-live="polite">{{ $hvUx['compare_empty'] }}</p><div id="hourly-compare-cards" class="hv-compare-cards"></div></div>
</section>
@endif

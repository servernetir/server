<section class="section hv-sec" id="buying-guide" aria-labelledby="hourly-guide-title">
  <div class="container"><div class="hv-sec-h"><h2 id="hourly-guide-title">{{ $hvUx['guide_title'] }}</h2><p>{{ $hvUx['guide_intro'] }}</p></div><div class="hv-guide-grid">@foreach($hvUx['checks'] as $i => $check)<article><span class="hv-guide-n">{{ $isFa ? fa_num($i + 1) : $i + 1 }}</span><h3>{{ $check['title'] }}</h3><p>{{ $check['text'] }}</p></article>@endforeach</div></div>
</section>

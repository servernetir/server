@extends('layouts.site')
@section('title',__('identity.title'))
@section('description',__('identity.description'))
@include('pages.identity.style')
@section('content')
<div class="id-wrap">@include('pages.identity.nav')
<section class="id-hero"><span class="id-pill">ServerNet API</span><h1>{{ __('identity.title') }}</h1><p class="id-lead">{{ __('identity.description') }}</p><div class="id-actions"><a class="btn btn-primary" href="{{ lroute('account.identity') }}">{{ __('identity.start') }}</a><a class="btn btn-glass" href="{{ lroute('identity.docs') }}">{{ __('identity.docs') }}</a></div></section>
<div class="id-note">{{ __('identity.availability_notice') }}</div>
<h2>{{ __('identity.catalog') }}</h2><div class="id-grid">
@foreach(['authentication','bank','utility'] as $category)
<section class="id-card"><h3>{{ __('identity.category_'.$category) }}</h3><p>{{ __('identity.category_'.$category.'_description') }}</p>
@foreach($services as $service) @if($service['category']===$category)
<p><strong>{{ $service['name'] }}</strong><br><span class="id-muted">{{ __('identity.'.$service['status']) }} · {{ $service['price']===null ? __('identity.price_pending') : number_format($service['price']).' '.__('identity.irt') }}</span></p>
@endif @endforeach
</section>@endforeach
</div><h2>{{ __('identity.how') }}</h2><ol>@foreach([1,2,3] as $step)<li><p>{{ __('identity.step_'.$step) }}</p></li>@endforeach</ol>
<h2>{{ __('identity.faq') }}</h2>@foreach([1,2,3] as $i)<details class="id-card"><summary>{{ __('identity.faq_'.$i.'_q') }}</summary><p>{{ __('identity.faq_'.$i.'_a') }}</p></details>@endforeach
</div>
@endsection

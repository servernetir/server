@extends('layouts.site')
@section('title',__('identity.terms'))
@section('description',__('identity.description'))
@include('pages.identity.style')
@section('content')<div class="id-wrap">@include('pages.identity.nav')<h1>{{ __('identity.terms') }}</h1><p>{{ __('identity.version') }}: {{ config('identity_api.terms_version') }}</p>
@foreach(['purpose','consent','billing','unknown','privacy','limits'] as $section)<h2>{{ __('identity.terms_'.$section.'_title') }}</h2><p class="id-lead">{{ __('identity.terms_'.$section) }}</p>@endforeach
</div>@endsection

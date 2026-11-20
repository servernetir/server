@extends('auth.shell')
@section('title', __('studio.title').' — ServerNet')
@section('heading', __('studio.title'))
@section('sub', __('studio.consent'))
@section('form')
<form method="POST" action="{{ lroute('account.studio.approve') }}" class="auth-f">
    @csrf
    @foreach($data as $key => $value)
        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
    @endforeach
    <p>{{ __('studio.billing') }}</p>
    <button type="submit" class="auth-btn">{{ __('studio.continue') }}</button>
    <a href="{{ lroute('account.home') }}" class="auth-ghost">{{ __('studio.cancel') }}</a>
</form>
@endsection

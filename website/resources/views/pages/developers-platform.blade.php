@extends('layouts.site')
@section('title', __('customer-api.title').' — ServerNet')
@section('description', __('customer-api.intro'))
@section('content')
<section class="container" style="padding-block:48px">
@include('account.partials.api-reference')
</section>
@endsection

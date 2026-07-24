@extends('emails.layout')

@php $heading = ($title !== null && $title !== '') ? $title : __('ui.email_announce_heading'); @endphp

@section('title', $heading)
@section('preview', $heading)

@section('content')
<h1 style="margin:0 0 14px; font-size:19px; font-weight:800; color:#0b1220;">{{ $heading }}</h1>

<p style="margin:0; color:#3b4658; line-height:1.9;">{!! nl2br(e($body)) !!}</p>
@endsection

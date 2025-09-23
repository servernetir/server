@extends('master')

@section('title', 'home')

@section('content')
<h2 class="page-title">Available services</h2>
<div class="cards">
    <div class="card">
      <div class="card__head">Virtual server</div>
      <a href="{{ route('vps') }}" class="pill">Order</a>
    </div>
    <div class="card">
      <div class="card__head">Hi-CPU server</div>
      <a href="{{ route('hi-cpu') }}" class="pill">Order</a>
    </div>
    <div class="card">
      <div class="card__head">Dedicated Server</div>
      <a href="{{ route('dedicated') }}" class="pill">Order</a>
    </div>
    <div class="card">
      <div class="card__head">Domain</div>
      <a href="{{ route('domain') }}" class="pill">Order</a>
    </div>
    <div class="card">
      <div class="card__head">VPN</div>
      <a href="{{ route('vpn') }}" class="pill">Order</a>
    </div>
    <div class="card">
      <div class="card__head">License</div>
      <a href="{{ route('license') }}" class="pill">Order</a>
    </div>
    <div class="card">
      <div class="card__sub">Scan account for data leaks</div>
      <div class="card__actions">
        <button class="pill">Scan</button>
        <a href="#" class="link">leakcheck.io</a>
      </div>
    </div>
  </div>
@endsection
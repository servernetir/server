@extends('master')

@section('title', 'home')
<style>
    .home-services{padding:75px 31px}
    .home-title{margin:0 0 16px 0;font-size:20px;font-weight:700;color:#dfe3ea;}
    .cards{display:grid;grid-template-columns: repeat(5, 1fr);gap:28px;}
    .card{background:#191b22;border-radius:22px;padding:22px;min-height:150px;display:flex;flex-direction:column;justify-content:space-between;box-shadow:0 2px 0 rgba(0,0,0,.2) inset;}
    .card:hover{box-shadow: inset 0px 1px 20px rgba(24, 201, 225, .2);}
    .card-head{font-size:26px;line-height:1.15;color:#cfd3db;letter-spacing:.2px;}
    .card-sub{font-size:16px;color:#c9cdd6;}
    .pill{align-self:flex-start;background:#2b2f37;color:#d9dee7;border:none;border-radius:12px;padding:8px 14px;font-size:14px;cursor:pointer;}
    .pill:hover{background:rgb(24, 201, 225, .51)}
    .card-actions{display:flex; align-items:center; gap:14px}
    .link{color:#cfd3db;text-decoration:underline;}
    .link:hover{opacity:.8}
    @media (max-width: 1280px){.cards{grid-template-columns: repeat(3, 1fr);}}
    @media (max-width: 800px){.cards{grid-template-columns: repeat(2, 1fr);}}
</style>

@section('content')
<section class="home-services">
  <h2 class="home-title">Available services</h2>
  <div class="cards">
    <div class="card">
      <div class="card-head">Virtual server</div>
      <button class="pill">Order</button>
    </div>
    <div class="card">
      <div class="card-head">Hi-CPU server</div>
      <button class="pill">Order</button>
    </div>
    <div class="card">
      <div class="card-head">Dedicated Server</div>
      <button class="pill">Order</button>
    </div>
    <div class="card">
      <div class="card-head">Domain</div>
      <button class="pill">Order</button>
    </div>
    <div class="card">
      <div class="card-head">VPN</div>
      <button class="pill">Order</button>
    </div>
    <div class="card">
      <div class="card-head">License</div>
      <button class="pill">Order</button>
    </div>
    <div class="card">
      <div class="card-sub">Scan account for data leaks</div>
      <div class="card-actions">
        <button class="pill">Scan</button>
        <a href="#" class="link">leakcheck.io</a>
      </div>
    </div>
  </div>
</section>
@endsection
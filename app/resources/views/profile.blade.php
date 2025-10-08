@extends('master')
@section('title','profile')

@section('content')
<header class="header"><h1 class="title">Profile and settings</h1></header>

<fieldset class="section section--plans" data-section="plans">
  <legend class="legend"> Account and interface</legend>
  <section class="section">
    <dl class="info-list">
      <dt>Contract №</dt><dd>{{ $view['contract_no'] }}</dd>
      <dt>E-mail</dt><dd>{{ $view['email'] }}</dd>
      <dt>Name</dt><dd>{{ $view['name'] }}</dd>
      <dt>Phone</dt><dd>{{ $view['phone'] }}</dd>
      <dt>Type</dt><dd>{{ $view['type'] }}</dd>

      @if($view['type']==='Company')
        <dt>Company</dt><dd>{{ $view['company_name'] }}</dd>
        <dt>Register No.</dt><dd>{{ $view['company_reg_no'] }}</dd>
        <dt>National ID</dt><dd>{{ $view['company_nid'] }}</dd>
      @endif

      <dt>Verification</dt><dd>{{ $view['verification'] }}</dd>
      <dt>Status</dt><dd>{{ $view['status'] }}</dd>
      <dt>Wallet</dt><dd>{{ $view['wallet_balance'] }}</dd>

      @if($view['referral_code'])
        <dt>Referral code</dt><dd>{{ $view['referral_code'] }}</dd>
      @endif
      @if($view['invited_by'])
        <dt>Invited by</dt><dd>{{ $view['invited_by'] }}</dd>
      @endif

      <dt>Last login</dt><dd>{{ $view['last_login'] }}</dd>
      <dt>Member since</dt><dd>{{ $view['member_since'] }}</dd>
    </dl>
  </section>
</fieldset>

<fieldset class="section section--plans" data-section="plans">
  <legend class="legend"> Security</legend>
  <section class="section section--name">
    <label for="currentpassword" class="label">Change password</label>
    <div class="input-wrap"><input id="currentpassword" name="currentpassword" class="input" type="password" placeholder="Current password"></div>
    <p class="help">The password must be between 8 and 32 characters long</p>
    <div class="input-wrap"><input id="newpassword" name="newpassword" class="input" type="password" placeholder="New password"></div>
  </section>
  <button type="submit" class="btn btn--primary">Change password</button>
</fieldset>
@endsection
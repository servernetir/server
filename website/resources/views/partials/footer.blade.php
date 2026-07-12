<footer>
  <div class="container">
    <div class="f-grid">
      <div class="f-about">
        <a href="{{ $homeUrl }}" class="logo"><span class="logo-mark"><svg class="icon"><use href="#i-server"/></svg></span> {{ $isFa ? 'سرورنت' : 'ServerNet' }}</a>
        <p>{{ __('ui.f_about') }}</p>
        <div class="f-social">
          <a href="{{ $social['linkedin'] }}" target="_blank" rel="noopener" aria-label="LinkedIn"><svg class="icon"><use href="#i-linkedin"/></svg></a>
          <a href="{{ $social['instagram'] }}" target="_blank" rel="noopener" aria-label="Instagram"><svg class="icon"><use href="#i-instagram"/></svg></a>
          <a href="https://wa.me/{{ $contact['whatsapp'] }}" target="_blank" rel="noopener" aria-label="WhatsApp"><svg class="icon"><use href="#i-message"/></svg></a>
        </div>
      </div>
      <div class="f-col">
        <h5 class="f-head">{{ __('ui.f_products') }}<svg class="icon chev"><use href="#i-chev"/></svg></h5>
        <div class="f-links"><div class="f-in">
          <a href="{{ $homeUrl }}#products">{{ __('ui.f_p1') }}</a>
          <a href="{{ $homeUrl }}#pricing">{{ __('ui.f_p2') }}</a>
          <a href="{{ $homeUrl }}#products">{{ __('ui.f_p3') }}</a>
          <a href="{{ $homeUrl }}#products">{{ __('ui.f_p4') }}</a>
          <a href="{{ $homeUrl }}#products">{{ __('ui.f_p5') }}</a>
        </div></div>
      </div>
      <div class="f-col">
        <h5 class="f-head">{{ __('ui.f_solutions') }}<svg class="icon chev"><use href="#i-chev"/></svg></h5>
        <div class="f-links"><div class="f-in">
          <a href="{{ $homeUrl }}#enterprise">{{ __('ui.f_s1') }}</a>
          <a href="{{ $homeUrl }}#enterprise">{{ __('ui.f_s2') }}</a>
          <a href="{{ $homeUrl }}#enterprise">{{ __('ui.f_s3') }}</a>
          <a href="{{ $homeUrl }}#enterprise">{{ __('ui.f_s4') }}</a>
          <a href="{{ $homeUrl }}#enterprise">{{ __('ui.f_s5') }}</a>
        </div></div>
      </div>
      <div class="f-col">
        <h5 class="f-head">{{ __('ui.f_company') }}<svg class="icon chev"><use href="#i-chev"/></svg></h5>
        <div class="f-links"><div class="f-in">
          <a href="{{ $homeUrl }}#contact">{{ __('ui.f_c2') }}</a>
          <a href="#">{{ __('ui.f_c1') }}</a>
          <a href="#">{{ __('ui.f_c3') }}</a>
          <a href="#">{{ __('ui.f_c4') }}</a>
          <a href="{{ whmcs_url('clientarea.php') }}">{{ __('ui.f_c5') }}</a>
        </div></div>
      </div>
      <div class="f-col f-contact">
        <h5 class="f-head">{{ __('ui.f_contact') }}<svg class="icon chev"><use href="#i-chev"/></svg></h5>
        <div class="f-links"><div class="f-in">
          <a class="fc" href="tel:{{ $contact['phone_link'] }}"><svg class="icon"><use href="#i-phone"/></svg>{{ $contact['phone'] }}</a>
          <a class="fc" href="mailto:{{ $contact['email'] }}"><svg class="icon"><use href="#i-mail"/></svg>{{ $contact['email'] }}</a>
          <a class="fc" href="mailto:{{ $contact['sales_email'] }}"><svg class="icon"><use href="#i-mail"/></svg>{{ $contact['sales_email'] }}</a>
          <a class="fc" href="https://wa.me/{{ $contact['whatsapp'] }}" target="_blank" rel="noopener"><svg class="icon"><use href="#i-message"/></svg>WhatsApp</a>
          {{-- جای نماد اعتماد الکترونیکی (enamad) --}}
        </div></div>
      </div>
    </div>
    <div class="f-bottom">
      <span>{{ __('ui.f_copy') }}</span>
      <span>servernet.cloud · servernet.ir</span>
    </div>
  </div>
</footer>

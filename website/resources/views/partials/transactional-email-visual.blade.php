<figure class="sn-mail-visual" dir="ltr" aria-label="{{ __('transactional-email.visual_caption') }}">
  <div class="sn-mail-plate-grid" aria-hidden="true"></div>
  <svg class="sn-mail-art" viewBox="0 0 640 420" fill="none" aria-hidden="true">
    <defs>
      <linearGradient id="mailGlass" x1="78" y1="65" x2="490" y2="345" gradientUnits="userSpaceOnUse">
        <stop stop-color="#8DECF7" stop-opacity=".4"/><stop offset=".5" stop-color="#275E80" stop-opacity=".65"/><stop offset="1" stop-color="#102F50" stop-opacity=".91"/>
      </linearGradient>
      <linearGradient id="mailEdge" x1="130" y1="82" x2="515" y2="300" gradientUnits="userSpaceOnUse">
        <stop stop-color="#A2F9FF"/><stop offset=".43" stop-color="#469DC4" stop-opacity=".45"/><stop offset="1" stop-color="#4BD8EC" stop-opacity=".8"/>
      </linearGradient>
      <linearGradient id="mailRoute"><stop stop-color="#46A8FF" stop-opacity=".1"/><stop offset=".5" stop-color="#83EDF6"/><stop offset="1" stop-color="#3CBAED" stop-opacity=".12"/></linearGradient>
      <radialGradient id="mailAura"><stop stop-color="#31B5F0" stop-opacity=".21"/><stop offset="1" stop-color="#31B5F0" stop-opacity="0"/></radialGradient>
    </defs>
    <ellipse cx="320" cy="226" rx="300" ry="187" fill="url(#mailAura)"/>
    <g stroke="#63B8D8" stroke-opacity=".2">
      <ellipse cx="320" cy="215" rx="282" ry="120" transform="rotate(-15 320 215)"/>
      <ellipse cx="320" cy="215" rx="246" ry="93" transform="rotate(21 320 215)"/>
      <ellipse cx="320" cy="215" rx="184" ry="132" transform="rotate(-36 320 215)" stroke-dasharray="2 8"/>
      <path d="M50 137H132L179 188M462 246l45 58h86" stroke-dasharray="3 6"/>
    </g>
    <path class="sn-mail-route" d="M39 141C112 141 148 89 231 107S378 298 601 273" stroke="url(#mailRoute)" stroke-width="2.4" stroke-dasharray="5 9"/>
    <circle cx="49" cy="141" r="5" fill="#70E6F4"/><circle cx="594" cy="273" r="5" fill="#74DFF0"/>
    <g transform="translate(320 216) rotate(-15)">
      <rect x="-169" y="-93" width="338" height="222" rx="27" fill="#113C5F" fill-opacity=".49" stroke="#60B4DA" stroke-opacity=".26"/>
      <rect x="-173" y="-104" width="346" height="222" rx="25" fill="url(#mailGlass)" stroke="url(#mailEdge)" stroke-width="1.4"/>
      <rect x="-185" y="-122" width="370" height="229" rx="30" fill="url(#mailGlass)" stroke="url(#mailEdge)" stroke-width="1.5"/>
      <rect x="-174" y="-111" width="348" height="207" rx="22" fill="#71D7F4" fill-opacity=".04" stroke="#A9F8FF" stroke-opacity=".16"/>
      <path d="M-168 82-34-21M168 82 34-21" stroke="#80E7F4" stroke-opacity=".48" stroke-width="2"/>
      <path d="M-175-82-20 9Q0 28 20 9L175-82" stroke="#B0F9FF" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
      <path d="M-172-82-19 6Q0 22 19 6L172-82" stroke="#4FBAE0" stroke-opacity=".5" stroke-width="13" stroke-linecap="round" stroke-linejoin="round"/>
      <path d="M-141-94h282" stroke="#D1FDFF" stroke-opacity=".33" stroke-linecap="round"/>
      <g transform="translate(0 5)">
        <circle r="39" fill="#0D2B43" stroke="#8DECF7" stroke-opacity=".52"/>
        <circle r="31" fill="#55BBD6" fill-opacity=".13" stroke="#62D7E5" stroke-opacity=".3"/>
        <path d="M0-19 17-12v13c0 10-9 18-17 23-8-5-17-13-17-23v-13z" fill="#367D97" fill-opacity=".6" stroke="#9FF6FF" stroke-width="1.8"/>
        <path d="m-7 1 5 6 11-13" stroke="#BCFCFF" stroke-width="2.7" stroke-linecap="round" stroke-linejoin="round"/>
      </g>
    </g>
    <g fill="#A7F3FF"><circle cx="120" cy="303" r="2"/><circle cx="485" cy="86" r="2.5"/><circle cx="520" cy="236" r="3"/><circle cx="232" cy="46" r="2"/></g>
  </svg>
  <div class="sn-mail-signal sn-mail-sender">
    <span class="sn-mail-signal-icon"><svg class="icon" aria-hidden="true"><use href="#i-code"/></svg></span>
    <div><small dir="auto">{{ __('transactional-email.visual_source') }}</small><b>SMTP / API</b></div>
  </div>
  <div class="sn-mail-signal sn-mail-message">
    <span class="sn-mail-signal-icon"><svg class="icon" aria-hidden="true"><use href="#i-mail"/></svg></span>
    <div><small dir="auto">{{ __('transactional-email.visual_label') }}</small><b dir="auto">{{ __('transactional-email.visual_message') }}</b></div>
  </div>
  <div class="sn-mail-signal sn-mail-receiver">
    <span class="sn-mail-signal-icon"><svg class="icon" aria-hidden="true"><use href="#i-send"/></svg></span>
    <div><small dir="auto">{{ __('transactional-email.visual_destination') }}</small><b>Gmail · Outlook</b></div>
  </div>
  <div class="sn-mail-auth" aria-hidden="true"><svg class="icon"><use href="#i-shield"/></svg><span>SPF · DKIM · DMARC</span></div>
  <figcaption dir="auto">{{ __('transactional-email.visual_caption') }}</figcaption>
</figure>

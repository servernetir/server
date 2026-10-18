{{-- Progressive enhancement; the existing ChatController and conversation session stay intact. --}}
<link rel="stylesheet" href="{{ asset_ver('assets/css/chat-morph.css') }}">
<script src="{{ asset_ver('assets/js/chat-morph.js') }}" defer></script>
<button type="button" class="chat-fab" id="chat-fab" data-morph-chat aria-label="{{ __('ui.chat_open') }}" aria-expanded="false" aria-controls="chat-panel">
  <span class="chat-fab-orb" aria-hidden="true"></span>
  <svg class="icon"><use href="#i-headset"/></svg>
  <span class="dot"></span>
</button>

<div class="chat-panel" id="chat-panel" data-morph-chat data-phase="idle" dir="{{ app()->getLocale() === 'fa' ? 'rtl' : 'ltr' }}" role="dialog" aria-modal="false" aria-labelledby="chat-title" inert>
  <div class="chat-head">
    <div class="avatar" aria-hidden="true"><svg class="icon"><use href="#i-headset"/></svg></div>
    <div>
      <h4 id="chat-title">{{ __('ui.chat_title') }}</h4>
      <p>{{ __('ui.chat_online') }}</p>
    </div>
    <button type="button" class="chat-close" id="chat-close" aria-label="{{ __('ui.close') }}"><svg class="icon"><use href="#i-x"/></svg></button>
  </div>
  <div class="chat-scroll" id="chat-scroll">
    <div class="chat-welcome" id="chat-welcome">
      <div class="chat-welcome-orbit" aria-hidden="true"><span class="chat-orb"><i></i><i></i><i></i></span><span class="chat-orbit-star">✦</span></div>
      <span class="chat-eyebrow">{{ __('chat.eyebrow') }}</span>
      <h3>{{ __('chat.welcome') }}</h3>
      <p>{{ __('chat.intro') }}</p>
      <div class="chat-suggestions">
        <button type="button" data-chat-prompt="{{ __('chat.server_prompt') }}"><svg class="icon" aria-hidden="true"><use href="#i-server"/></svg>{{ __('chat.server') }}<span aria-hidden="true">↗</span></button>
        <button type="button" data-chat-prompt="{{ __('chat.hosting_prompt') }}"><svg class="icon" aria-hidden="true"><use href="#i-globe"/></svg>{{ __('chat.hosting') }}<span aria-hidden="true">↗</span></button>
      </div>
    </div>
  <div class="chat-body" id="chat-body"
       data-endpoint="{{ route($routePrefix.'chat') }}"
       data-hello="{{ __('ui.chat_hello') }}"
       data-error="{{ __('ui.chat_error') }}" role="log" aria-live="polite" aria-relevant="additions"></div>
  </div>
  <div class="chat-dock">
    <div class="chat-thinking" id="chat-thinking" hidden aria-hidden="true">
      <span class="chat-orb"><i></i><i></i><i></i></span>
      <span class="chat-think-label" id="chat-think-label"></span>
      <span class="chat-think-dots"><i></i><i></i><i></i></span>
    </div>
  <form class="chat-input" id="chat-form">
    <svg class="icon chat-input-spark" aria-hidden="true"><use href="#i-zap"/></svg>
    <input id="chat-text" type="text" dir="auto" aria-label="{{ __('ui.chat_ph') }}" placeholder="{{ __('ui.chat_ph') }}" autocomplete="off" maxlength="1000" enterkeyhint="send">
    <button type="submit" aria-label="{{ __('ui.chat_send') }}"><svg class="icon"><use href="#i-send"/></svg></button>
  </form>
    <p class="chat-footnote">{{ __('chat.footnote') }}</p>
  </div>
  <span class="chat-sr" id="chat-status" role="status" aria-live="polite"
        data-thinking="{{ __('chat.thinking') }}" data-reviewing="{{ __('chat.reviewing') }}"
        data-composing="{{ __('chat.composing') }}" data-waiting="{{ __('chat.waiting') }}"
        data-done="{{ __('chat.done') }}" data-retry="{{ __('chat.retry') }}"></span>
</div>

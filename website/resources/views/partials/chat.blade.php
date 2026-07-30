{{-- دستیار هوشمند — بک‌اند: ChatController --}}
<button class="chat-fab" id="chat-fab" aria-label="{{ __('ui.chat_open') }}" aria-expanded="false">
  <svg class="icon"><use href="#i-headset"/></svg>
  <span class="dot"></span>
</button>

<div class="chat-panel" id="chat-panel" role="dialog" aria-label="{{ __('ui.chat_title') }}">
  <div class="chat-head">
    <div class="avatar"><svg class="icon"><use href="#i-headset"/></svg></div>
    <div>
      <h4>{{ __('ui.chat_title') }}</h4>
      <p>{{ __('ui.chat_online') }}</p>
    </div>
    <button class="chat-close" id="chat-close" aria-label="{{ __('ui.close') }}"><svg class="icon"><use href="#i-x"/></svg></button>
  </div>
  <div class="chat-body" id="chat-body"
       data-endpoint="{{ route($routePrefix.'chat') }}"
       data-hello="{{ __('ui.chat_hello') }}"
       data-error="{{ __('ui.chat_error') }}"></div>
  <form class="chat-input" id="chat-form">
    <input id="chat-text" type="text" placeholder="{{ __('ui.chat_ph') }}" autocomplete="off" maxlength="1000">
    <button type="submit" aria-label="{{ __('ui.chat_send') }}"><svg class="icon"><use href="#i-send"/></svg></button>
  </form>
</div>

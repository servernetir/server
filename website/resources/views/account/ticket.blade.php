@extends('panel.layout')
@section('title', $ticket->number.' — ServerNet')

@section('panel')

<div class="pnl-head">
  <div>
    <h1 class="dash-h">{{ $ticket->subject }}</h1>
    <p>
      <span dir="ltr">{{ $ticket->number }}</span> ·
      {{ __('ui.tk_dep_'.$ticket->department) }} ·
      @if($ticket->status === 'answered')<span class="pnl-pill ok">{{ __('ui.tk_st_answered') }}</span>
      @elseif($ticket->status === 'closed')<span class="pnl-pill mute">{{ __('ui.tk_st_closed') }}</span>
      @else<span class="pnl-pill warn">{{ __('ui.tk_st_open') }}</span>@endif
    </p>
  </div>
  <div class="pnl-acts">
    <a class="pnl-btn" href="{{ lroute('account.tickets') }}"><svg class="icon"><use href="#i-arrow"/></svg>{{ __('ui.auth_back') }}</a>
    @if($ticket->isOpen())
      <form method="POST" action="{{ lroute('account.ticket.close', $ticket) }}">
        @csrf
        <button type="submit" class="pnl-btn"><svg class="icon"><use href="#i-x"/></svg>{{ __('ui.tk_close') }}</button>
      </form>
    @endif
  </div>
</div>

@if(session('ok'))
  <div class="pnl-sec" style="border-color:var(--ok-line)">
    <div class="pnl-sec-b" style="color:var(--ok);font-size:13.5px">{{ session('ok') }}</div>
  </div>
@endif

{{-- ══ رشتهٔ گفتگو ══ --}}
<div class="tk-thread">
  @foreach($messages as $m)
    <div class="tk-msg {{ $m->fromStaff() ? 'staff' : 'me' }}">
      <div class="tk-msg-h">
        <span class="tk-msg-who">{{ $m->fromStaff() ? __('ui.tk_staff') : __('ui.tk_you') }}</span>
        <span class="tk-msg-t">{{ fa_num($m->created_at->format('Y/m/d H:i')) }}</span>
      </div>
      <div class="tk-msg-b">{!! nl2br(e($m->body)) !!}</div>
      @if($m->relationLoaded('attachments') && $m->attachments->isNotEmpty())
        <div class="tk-atts">
          @foreach($m->attachments as $att)
            @php $url = lroute('account.ticket.attachment', [$ticket, $att]); @endphp
            @if($att->isImage())
              <a class="tk-att-img" href="{{ $url }}" target="_blank" title="{{ $att->original_name }}">
                <img src="{{ $url }}" alt="{{ $att->original_name }}" loading="lazy">
              </a>
            @else
              <a class="tk-att" href="{{ $url }}" target="_blank">
                <svg class="icon"><use href="#i-file"/></svg>
                <span>{{ $att->original_name }}</span><i>{{ $att->humanSize() }}</i>
              </a>
            @endif
          @endforeach
        </div>
      @endif
    </div>
  @endforeach
</div>

{{-- ══ پاسخ ══ --}}
@if($errors->any())
  <div class="pnl-sec" style="border-color:var(--danger-line)">
    <div class="pnl-sec-b" style="color:var(--danger);font-size:13.5px">{{ $errors->first() }}</div>
  </div>
@endif

@if($ticket->isClosed())
  <div class="pnl-sec" style="border-color:var(--line)">
    <div class="pnl-sec-b" style="font-size:13px;color:var(--muted);line-height:2">{{ __('ui.tk_closed_note') }}</div>
  </div>
@endif

<section class="pnl-sec">
  <div class="pnl-sec-b">
    <form method="POST" action="{{ lroute('account.ticket.reply', $ticket) }}" class="tk-form" enctype="multipart/form-data">
      @csrf
      <div class="tk-field">
        <textarea name="body" rows="5" maxlength="5000" required
                  placeholder="{{ __('ui.tk_reply_ph') }}">{{ old('body') }}</textarea>
        @if($ticket->isClosed())<small style="color:var(--dim)">{{ __('ui.tk_reopen_hint') }}</small>@endif
      </div>
      <label class="tk-file">
        <svg class="icon"><use href="#i-paperclip"/></svg>
        <span>افزودن تصویر یا PDF (حداکثر ۵ فایل، هرکدام تا ۵ مگابایت)</span>
        <input type="file" name="attachments[]" multiple accept="image/*,application/pdf" onchange="tkFiles(this)">
      </label>
      <div class="tk-file-list" id="tk-file-list"></div>
      <button type="submit" class="pnl-btn primary" style="justify-content:center;align-self:flex-start">
        <svg class="icon"><use href="#i-send"/></svg>{{ __('ui.tk_reply') }}
      </button>
    </form>
    <script>
      function tkFiles(inp){
        var box = document.getElementById('tk-file-list'); box.innerHTML='';
        for (var i=0;i<inp.files.length;i++){
          var s=document.createElement('span'); s.className='tk-file-chip';
          s.textContent=inp.files[i].name; box.appendChild(s);
        }
      }
    </script>
  </div>
</section>

@include('account.ticket-styles')

@endsection

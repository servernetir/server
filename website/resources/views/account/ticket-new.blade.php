@extends('panel.layout')
@section('title', __('ui.tk_new').' — ServerNet')

@section('panel')

<div class="pnl-head">
  <div>
    <h1 class="dash-h">{{ __('ui.tk_new') }}</h1>
    <p>{{ __('ui.tk_sub') }}</p>
  </div>
  <div class="pnl-acts">
    <a class="pnl-btn" href="{{ lroute('account.tickets') }}"><svg class="icon"><use href="#i-arrow"/></svg>{{ __('ui.auth_back') }}</a>
  </div>
</div>

@if($errors->any())
  <div class="pnl-sec" style="border-color:var(--danger-line)">
    <div class="pnl-sec-b" style="color:var(--danger);font-size:13.5px;line-height:2">
      @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
    </div>
  </div>
@endif

<section class="pnl-sec">
  <div class="pnl-sec-b">
    <form method="POST" action="{{ lroute('account.ticket.store') }}" class="tk-form" enctype="multipart/form-data">
      @csrf

      <div class="tk-field">
        <label for="subject">{{ __('ui.tk_subject') }}</label>
        <input type="text" id="subject" name="subject" maxlength="200" required value="{{ old('subject') }}">
      </div>

      <div class="tk-two">
        <div class="tk-field">
          <label for="department">{{ __('ui.tk_department') }}</label>
          <select id="department" name="department">
            @foreach(['technical','billing','sales'] as $d)
              <option value="{{ $d }}" @selected(old('department')===$d)>{{ __('ui.tk_dep_'.$d) }}</option>
            @endforeach
          </select>
        </div>
        <div class="tk-field">
          <label for="priority">{{ __('ui.tk_priority') }}</label>
          <select id="priority" name="priority">
            @foreach(['low','normal','high','urgent'] as $p)
              <option value="{{ $p }}" @selected(old('priority', 'normal')===$p)>{{ __('ui.tk_pri_'.$p) }}</option>
            @endforeach
          </select>
        </div>
      </div>

      <div class="tk-field">
        <label for="body">{{ __('ui.tk_message') }}</label>
        <textarea id="body" name="body" rows="8" maxlength="5000" required
                  placeholder="{{ __('ui.tk_body_ph') }}">{{ old('body') }}</textarea>
      </div>

      <label class="tk-file">
        <svg class="icon"><use href="#i-paperclip"/></svg>
        <span>{{ __('ui.tk_attach_opt') }}</span>
        <input type="file" name="attachments[]" multiple accept="image/*,application/pdf" onchange="tkFiles(this)">
      </label>
      <div class="tk-file-list" id="tk-file-list"></div>

      <button type="submit" class="pnl-btn primary" style="justify-content:center;align-self:flex-start">
        <svg class="icon"><use href="#i-send"/></svg>{{ __('ui.tk_send') }}
      </button>
    </form>
    <script>
      function tkFiles(inp){
        var box=document.getElementById('tk-file-list'); box.innerHTML='';
        for(var i=0;i<inp.files.length;i++){var s=document.createElement('span');s.className='tk-file-chip';s.textContent=inp.files[i].name;box.appendChild(s);}
      }
    </script>
  </div>
</section>

@include('account.ticket-styles')

@endsection

@extends('panel.layout')
@section('title', __('ui.tk_title').' — ServerNet')

@section('panel')

<div class="pnl-head">
  <div>
    <h1 class="dash-h">{{ __('ui.tk_title') }}</h1>
    <p>{{ __('ui.tk_sub') }}</p>
  </div>
  <div class="pnl-acts">
    <a class="pnl-btn primary" href="{{ lroute('account.ticket.new') }}">
      <svg class="icon"><use href="#i-plus"/></svg>{{ __('ui.tk_new') }}
    </a>
  </div>
</div>

@if(session('ok'))
  <div class="pnl-sec" style="border-color:var(--ok-line)">
    <div class="pnl-sec-b" style="color:var(--ok);font-size:13.5px">{{ session('ok') }}</div>
  </div>
@endif

<section class="pnl-sec">
  <div class="pnl-sec-h"><h2>{{ __('ui.tk_title') }}</h2></div>

  @if($tickets->isEmpty())
    <div class="pnl-sec-b">
      <p style="margin:0 0 14px;font-size:13.5px;color:var(--muted);line-height:2">{{ __('ui.tk_none_cta') }}</p>
      <a class="pnl-btn" href="{{ lroute('account.ticket.new') }}">
        <svg class="icon"><use href="#i-plus"/></svg>{{ __('ui.tk_new') }}
      </a>
    </div>
  @else
    <div class="pnl-sec-b flush">
      <div class="pnl-tw">
        <table class="pnl-table">
          <thead>
            <tr>
              <th>{{ __('ui.tk_number') }}</th><th>{{ __('ui.tk_subject') }}</th>
              <th>{{ __('ui.tk_department') }}</th><th>{{ __('ui.tk_status') }}</th>
              <th>{{ __('ui.tk_last_reply') }}</th><th></th>
            </tr>
          </thead>
          <tbody>
            @foreach($tickets as $t)
              <tr>
                <td dir="ltr">{{ $t->number }}</td>
                <td>{{ $t->subject }}
                  @if($t->priority === 'urgent')<span class="pnl-pill danger" style="font-size:10px">{{ __('ui.tk_pri_urgent') }}</span>
                  @elseif($t->priority === 'high')<span class="pnl-pill warn" style="font-size:10px">{{ __('ui.tk_pri_high') }}</span>@endif
                </td>
                <td>{{ __('ui.tk_dep_'.$t->department) }}</td>
                <td>
                  @if($t->status === 'answered')<span class="pnl-pill ok">{{ __('ui.tk_st_answered') }}</span>
                  @elseif($t->status === 'closed')<span class="pnl-pill mute">{{ __('ui.tk_st_closed') }}</span>
                  @else<span class="pnl-pill warn">{{ __('ui.tk_st_open') }}</span>@endif
                </td>
                <td>{{ stime($t->last_reply_at) }}</td>
                <td><a class="pnl-btn" href="{{ lroute('account.ticket', $t) }}">{{ __('ui.pnl_open') }}</a></td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>
  @endif
</section>

{{ $tickets->links() }}

@endsection

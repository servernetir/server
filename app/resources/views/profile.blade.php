@extends('master')

@section('title')
  profile
@endsection

@section('content')
  <main role="main" class="main-content">
      <div class="container-fluid">
          <div class="row justify-content-center">
              <div class="col-12">
                  <ul class="nav nav-tabs mb-4" id="myTab" role="tablist">
                      <li class="nav-item">
                          <a class="nav-link active" id="Account-tab" data-toggle="tab" href="" role="tab" aria-controls="Account" aria-selected="true">Account and interface</a>
                      </li>
                      <li class="nav-item">
                          <a class="nav-link" id="Security-tab" data-toggle="tab" href="" role="tab" aria-controls="Security" aria-selected="false">Security</a>
                      </li>
                      <li class="nav-item">
                          <a class="nav-link" id="API-tab" data-toggle="tab" href="" role="tab" aria-controls="API" aria-selected="false">API keys</a>
                      </li>
                  </ul>
                  <div class="card card-fill">
                      <div class="card-header d-flex align-items-center justify-content-between">
                          <strong class="card-title mb-0">Sessions</strong>
                          <form method="POST" action="{{ route('profile.sessions.logoutOthers') }}" onsubmit="return {{ $hasOtherSessions ? 'confirm(\'Close all other sessions?\')' : 'false' }};">
                              @csrf
                              <button type="submit" class="btn btn-sm btn-secondary" {{ $hasOtherSessions ? '' : 'disabled' }} title="{{ $hasOtherSessions ? '' : 'No other sessions' }}">
                                  Close all other sessions
                              </button>
                          </form>
                      </div>
                      <div class="card-body p-0">
                          <div class="list-group list-group-flush">
                              @forelse($sessions as $s)
                                  <div class="list-group-item d-flex align-items-center justify-content-between" style="background:#1d1f23; color:#fff; border-color:#2a2d32;">
                                      <div class="d-flex flex-column">
                                          <span class="font-weight-bold">{{ $s['device'] }}</span>
                                          <small class="text-muted" style="color:#bdbdbd !important;">{{ $s['ip'] }}</small>
                                      </div>
                                      <div class="d-flex align-items-center">
                                          <small class="text-muted mr-3" style="color:#bdbdbd !important;" title="{{ $s['ua'] }}">{{ $s['is_current'] ? 'Current' : $s['date'] }}</small>
                                          @unless ($s['is_current'])
                                              <form method="POST" action="{{ route('profile.sessions.destroy', $s['id']) }}" onsubmit="return confirm('Close this session?');">
                                                  @csrf
                                                  @method('DELETE')
                                                  <button type="submit" class="btn p-0" style="line-height:1; color:#ff4d4f; background:none; border:none; font-size:22px;" title="Close">
                                                    &times;
                                                  </button>
                                              </form>
                                          @endunless
                                      </div>
                                  </div>
                              @empty
                                  <div class="list-group-item" style="background:#1d1f23; color:#fff; border-color:#2a2d32;">
                                      No active sessions.
                                  </div>
                              @endforelse
                          </div>
                      </div>
                  </div>
              </div>
          </div>
      </div>
  </main>
@endsection
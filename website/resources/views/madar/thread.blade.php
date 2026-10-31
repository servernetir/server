<section class="mdr-thread"><h2>{{ __('madar.conversation') }}</h2>
  <ol class="mdr-messages">
    @foreach($messages as $message)
    <li class="mdr-message is-{{ $message->sender_type }}">
      <header><b>{{ __('madar.'.(['customer' => 'you', 'staff' => 'team', 'system' => 'system'][$message->sender_type])) }}</b><time datetime="{{ $message->created_at->toIso8601String() }}">{{ blog_date($message->created_at) }} · <bdi>{{ $message->created_at->timezone('Asia/Tehran')->format('H:i') }}</bdi></time></header>
      @if($message->event)<strong class="mdr-event">{{ __('madar.events.'.$message->event) }}</strong>
@endif

      @if($message->body)<p class="mdr-message-body">{{ $message->body }}</p>
@endif

      @if($message->evidence_url)<a class="mdr-evidence-link" href="{{ $message->evidence_url }}" target="_blank" rel="noopener noreferrer nofollow ugc">{{ __('madar.evidence_url') }} ↗<small dir="ltr">{{ $message->evidence_url }}</small></a>
@endif

      @if($message->attachment_path)<a class="mdr-attachment" href="{{ $admin ? route('admin.madar.attachment', [$participation, $message]) : lroute('account.madar.attachment', [$participation, $message]) }}"><svg class="icon"><use href="#i-image"/></svg>{{ __('madar.attachment') }} <bdi>{{ number_format($message->attachment_bytes / 1024) }} KB</bdi></a>
@endif

    </li>

@endforeach

  </ol>
  {{ $messages->links() }}
  <form method="post" enctype="multipart/form-data" action="{{ $admin ? route('admin.madar.message', $participation) : lroute('account.madar.message', $participation) }}" class="mdr-message-form">

@csrf
<label>{{ __('madar.body') }}<textarea name="body" required minlength="5" maxlength="5000" rows="4">{{ old('body') }}</textarea></label>
    <label>{{ __('madar.attachment') }}<input type="file" name="attachment" accept="image/jpeg,image/png,image/webp"><small>{{ __('madar.attachment_hint') }}</small></label>
    <button class="{{ $admin ? 'ad-btn' : 'pnl-btn primary' }}" type="submit">{{ __('madar.send_message') }}</button>
  </form>
</section>

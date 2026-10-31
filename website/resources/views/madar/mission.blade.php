@if($participation->flow_version === 2)
@php $missionCopy = $participation->reward_snapshot['mission']['copy'][app()->getLocale()] ?? $participation->reward_snapshot['mission']['copy']['fa']; @endphp
<details class="mdr-action-card mdr-mission-snapshot">
  <summary>{{ __('madar.requirements') }} · {{ $missionCopy['title'] }}</summary>
  <p>{{ $missionCopy['requirements'] }}</p>
  <p>{{ __('madar.channel') }}: {{ $missionCopy['channel'] }}</p>
  <p>{{ __('madar.example') }}: {{ $missionCopy['example'] }}</p>
  @foreach($participation->reward_snapshot['mission']['terms_copy'][app()->getLocale()] ?? [] as $term)<p>{{ $term }}</p>@endforeach
</details>
@endif

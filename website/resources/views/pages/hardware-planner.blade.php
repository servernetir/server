@extends('layouts.site')
@section('title', __('equipment.planner_title').' | '.__('ui.brand'))
@section('description', __('equipment.planner_meta'))
@include('partials.hardware-assets')
@push('head')
<script defer src="{{ asset('assets/js/datacenter-planner.js') }}?v=20261124-1"></script>
@endpush
@section('content')
@php
    // Input constraints are also enforced in the pure calculator functions.
    $field = fn ($key, $value, $min, $max, $step = 1) => compact('key','value','min','max','step');
    $tools = [
        'raid' => ['fields'=>[$field('drives', 4, 2, 128),$field('disk_tb', 4, .01, 100, .01)], 'select'=>['0','1','5','6','10'], 'results'=>['raw','usable'], 'links'=>['storage','disk','raid']],
        'rack' => ['fields'=>[$field('used_u', 18, 1, 192),$field('growth', 20, 0, 100)], 'results'=>['minimum_u','rack_option'], 'links'=>['rack','ups','pdu']],
        'ups' => ['fields'=>[$field('load', 1000, 1, 1000000),$field('pf', .8, .1, 1, .01),$field('growth', 20, 0, 100)], 'results'=>['minimum_w','minimum_va'], 'links'=>['ups','pdu']],
        'vm' => ['fields'=>[$field('vms', 10, 1, 10000),$field('ram_per', 4, .25, 1024, .25),$field('vcpu_per', 2, 1, 256),$field('growth', 20, 0, 100)], 'results'=>['ram_sum','vcpu_sum'], 'links'=>['ram','cpu']],
        'cost' => ['fields'=>[$field('purchase', 1000, 0, 100000000000, .01),$field('maintenance', 40, 0, 100000000000, .01),$field('rent', 90, 0, 100000000000, .01),$field('months', 36, 1, 120)], 'results'=>['owned_total','rent_total'], 'links'=>[]],
    ];
@endphp
<section class="sp-hero">
  <div class="container">
    <nav class="blog-crumbs"><a href="{{ lroute('home') }}">{{ __('ui.brand') }}</a><span>/</span><a href="{{ lroute('parts.index') }}">{{ __('ui.parts_title') }}</a></nav>
    <span class="dc-eyebrow">{{ __('equipment.eyebrow') }}</span>
    <h1>{{ __('equipment.planner_title') }}</h1>
    <p class="sp-lead">{{ __('equipment.planner_intro') }}</p>
  </div>
</section>
<div class="container">
    <div class="dc-planner" data-planner data-invalid="{{ __('equipment.invalid') }}" data-out-of-range="{{ __('equipment.out_of_range') }}">
        <noscript><p>{{ __('equipment.no_script') }}</p></noscript>
        <div class="dc-planner-grid">
        @foreach($tools as $key => $tool)
            <section class="dc-tool" data-calculator="{{ $key }}">
                <h2>{{ __('equipment.'.$key.'_title') }}</h2>
                <p>{{ __('equipment.'.$key.'_desc') }}</p>
                <form>
                    <div class="dc-tool-fields">
                        @foreach($tool['fields'] as $f)
                            <label>{{ __('equipment.'.$f['key']) }}
                                <input type="number" name="{{ $f['key'] }}" value="{{ $f['value'] }}" min="{{ $f['min'] }}" max="{{ $f['max'] }}" step="{{ $f['step'] }}" required>
                            </label>
                        @endforeach
                        @if(isset($tool['select']))
                            <label>{{ __('equipment.raid_mode') }}
                                <select name="raid_mode">@foreach($tool['select'] as $mode)<option value="{{ $mode }}" @selected($mode === '5')>RAID {{ $mode }}</option>@endforeach</select>
                            </label>
                        @endif
                    </div>
                    <div class="dc-results" aria-live="polite">
                        @foreach($tool['results'] as $result)
                            <div class="dc-result"><small>{{ __('equipment.'.$result) }}</small><output data-result="{{ $result }}">—</output></div>
                        @endforeach
                    </div>
                    <p class="dc-error" data-error role="status" hidden></p>
                </form>
                <p>{{ __('equipment.'.$key.'_note') }}</p>
                <div class="dc-tool-links">
                    @foreach($tool['links'] as $cat)
                        <a href="{{ lroute('parts.category', $cat) }}">{{ lc(\App\Models\ServerPart::CATEGORIES[$cat]) }}</a>
                    @endforeach
                    @if(in_array($key, ['cost','vm'], true))
                        <a href="{{ lroute('servers.index') }}">{{ __('ui.srv_title') }}</a>
                        <a href="{{ lroute('vps.hourly') }}">{{ __('shop_seo.rent_vps') }}</a>
                    @endif
                </div>
            </section>
        @endforeach
        </div>
        <section class="sp-ask">
            <div><h2>{{ __('equipment.quote') }}</h2><p>{{ __('equipment.hub_desc') }}</p></div>
            <a class="btn btn-primary" href="{{ lroute('contact') }}">{{ __('equipment.quote') }}</a>
        </section>
    </div>
</div>
@endsection

<section class="dc-showcase">
    <div class="dc-header">
        <div>
            <span class="dc-eyebrow">{{ __('equipment.eyebrow') }}</span>
            <h2>{{ __('equipment.hub_title') }}</h2>
            <p>{{ __('equipment.hub_desc') }}</p>
        </div>
        <a class="btn btn-primary" href="{{ lroute('parts.planner') }}">{{ __('equipment.planner_cta') }} <svg class="icon dir"><use href="#i-arrow"/></svg></a>
    </div>
    <div class="dc-category-grid">
        @foreach(\App\Models\ServerPart::EQUIPMENT_CATEGORIES as $equipmentKey => $equipmentMeta)
            @continue(($activeEquipment ?? null) === $equipmentKey)
            <a class="dc-category dc-{{ $equipmentKey }}" href="{{ lroute('parts.category', $equipmentKey) }}">
                <span class="dc-category-icon"><svg class="icon"><use href="#i-{{ $equipmentMeta['icon'] }}"/></svg></span>
                <span class="dc-category-name">{{ lc($equipmentMeta) }}</span>
                <span class="dc-category-desc">{{ __('equipment.category_desc.'.$equipmentKey) }}</span>
                @if(!empty($categories[$equipmentKey]['count']))
                    <span class="dc-count">{{ __('ui.parts_count', ['count' => fa_num($categories[$equipmentKey]['count'])]) }}</span>
                @else
                    <span class="dc-count">{{ __('ui.parts_quote') }}</span>
                @endif
            </a>
        @endforeach
    </div>
</section>

<div class="dc-schematic dc-{{ $part->category }}" aria-label="{{ __('equipment.schematic') }}">
    <svg class="icon" aria-hidden="true"><use href="#i-{{ \App\Models\ServerPart::CATEGORIES[$part->category]['icon'] }}"/></svg>
    <span>{{ $part->brand }}</span>
    <small>{{ __('equipment.schematic') }}</small>
</div>

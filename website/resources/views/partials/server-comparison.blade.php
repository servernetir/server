<section class="hw-comparison" id="server-comparison" aria-labelledby="hw-compare-title">
    <h2 id="hw-compare-title">{{ \App\Services\Shop\HardwarePresentation::text('compare_servers') }}</h2>
    <p class="hw-note">{{ \App\Services\Shop\HardwarePresentation::text('compare_note') }}</p>
    <form method="get" action="{{ lroute('servers.index') }}">
        <div class="hw-selects">
            @for($hardwareSlot = 0; $hardwareSlot < 4; $hardwareSlot++)
            <label>{{ \App\Services\Shop\HardwarePresentation::text('choose_server') }} {{ fa_num($hardwareSlot + 1) }}
                <select name="models[]">
                    <option value="">—</option>
                    @foreach($catalogModels as $hardwareOptionSlug => $hardwareOption)
                    <option value="{{ $hardwareOptionSlug }}" @selected(($comparison->keys()->all()[$hardwareSlot] ?? null) === $hardwareOptionSlug)>{{ lc($hardwareOption)['name'] }}</option>
                    @endforeach
                </select>
            </label>
            @endfor
        </div>
        <button class="btn btn-primary" type="submit">{{ \App\Services\Shop\HardwarePresentation::text('compare_servers') }}</button>
    </form>
    @if($comparison->isNotEmpty())
    @php
        $comparisonLabels = [];
        $comparisonValues = [];
        foreach($comparison as $comparisonSlug => $comparisonModel) {
            foreach($comparisonModel['specs'] ?? [] as $comparisonSpec) {
                $comparisonKey = mb_strtolower(trim((string) ($comparisonSpec['label']['en'] ?? $comparisonSpec['label']['fa'] ?? '')));
                if ($comparisonKey === '' || $comparisonKey === 'model / identifier') continue;
                $comparisonLabels[$comparisonKey] = lc($comparisonSpec['label']);
                $comparisonValues[$comparisonSlug][$comparisonKey] = lc($comparisonSpec);
            }
        }
    @endphp
    <div class="hw-table-scroll" tabindex="0" aria-label="{{ \App\Services\Shop\HardwarePresentation::text('compare_servers') }}">
    <table class="hw-table">
        <thead><tr><th scope="col">{{ \App\Services\Shop\HardwarePresentation::text('model') }}</th>
            @foreach($comparison as $comparisonSlug => $comparisonModel)
            <th scope="col"><a href="{{ lroute('servers.show', $comparisonSlug) }}">{{ lc($comparisonModel)['name'] }}</a></th>
            @endforeach
        </tr></thead>
        <tbody>
        @foreach($comparisonLabels as $comparisonKey => $comparisonLabel)
        <tr><th scope="row">{{ $comparisonLabel }}</th>
            @foreach($comparison as $comparisonSlug => $comparisonModel)
            <td dir="auto">{{ $comparisonValues[$comparisonSlug][$comparisonKey] ?? '—' }}</td>
            @endforeach
        </tr>
        @endforeach
        <tr><th scope="row">{{ __('ui.parts_price') }}</th>
            @foreach($comparison as $comparisonModel)
            <td>{{ empty($comparisonModel['price_from']['contact']) ? site_price($comparisonModel['price_from']) : __('ui.srv_quote') }}</td>
            @endforeach
        </tr>
        </tbody>
    </table>
    </div>
    @endif
</section>

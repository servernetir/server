<?php

return [
    // Keep closed until delivery, pricing, traffic and release are reviewed.
    'sales_enabled' => env('LIGHTNODE_SALES_ENABLED', false),
    // Production pricing must come from a supported official API price feed.
    // The current package API has no monetary fields. Saved rates are fixtures
    // for isolated acceptance tests only; no environment switch enables them.
    'allow_fixture_rates' => false,
    // Public OpenAPI has no prices or stock. Regions verified on 2026-10-10;
    // IDs are opaque: bg-dhaka-1 is Bangladesh, not Bulgaria.
    'manifest' => [
        'regions' => [
            'fr-marseille-1' => ['country' => 'FR', 'city' => 'Marseille', 'location_code' => 'fr-marseille'],
            'us-washington-1' => ['country' => 'US', 'city' => 'Washington', 'location_code' => 'us-washington'],
            'cn-taiwan-2' => ['country' => 'TW', 'city' => 'Taipei II', 'location_code' => 'tw-taipei-ii'],
            'ec-quito-1' => ['country' => 'EC', 'city' => 'Quito', 'location_code' => 'ec-quito'],
            'ro-bucharest-1' => ['country' => 'RO', 'city' => 'Bucharest', 'location_code' => 'ro-bucharest'],
            'cn-hongkong-3' => ['country' => 'HK', 'city' => 'Hong Kong', 'location_code' => 'hk-hong-kong'],
            'gb-london-1' => ['country' => 'GB', 'city' => 'London', 'location_code' => 'gb-london'],
            'ger-frankfurt-1' => ['country' => 'DE', 'city' => 'Frankfurt', 'location_code' => 'de-frankfurt'],
            'id-jakarta-1' => ['country' => 'ID', 'city' => 'Jakarta', 'location_code' => 'id-jakarta'],
            'mm-yangon-1' => ['country' => 'MM', 'city' => 'Yangon', 'location_code' => 'mm-yangon'],
            'bg-sofia-1' => ['country' => 'BG', 'city' => 'Sofia', 'location_code' => 'bg-sofia'],
            'us-siliconvalley-2' => ['country' => 'US', 'city' => 'Silicon Valley II', 'location_code' => 'us-silicon-valley-ii'],
            'us-siliconvalley-1' => ['country' => 'US', 'city' => 'Silicon Valley', 'location_code' => 'us-silicon-valley'],
            'ca-toronto-1' => ['country' => 'CA', 'city' => 'Toronto', 'location_code' => 'ca-toronto'],
            'tha-bangkok-1' => ['country' => 'TH', 'city' => 'Bangkok', 'location_code' => 'th-bangkok'],
            'vn-hochiminh-1' => ['country' => 'VN', 'city' => 'Ho Chi Minh', 'location_code' => 'vn-ho-chi-minh'],
            'kr-seoul-2' => ['country' => 'KR', 'city' => 'Seoul II', 'location_code' => 'kr-seoul-ii'],
            'uae-dubai-1' => ['country' => 'AE', 'city' => 'Dubai', 'location_code' => 'ae-dubai'],
            'ru-moscow-1' => ['country' => 'RU', 'city' => 'Moscow', 'location_code' => 'ru-moscow'],
            'gr-athens-1' => ['country' => 'GR', 'city' => 'Athens', 'location_code' => 'gr-athens'],
            'tur-istanbul-3' => ['country' => 'TR', 'city' => 'Istanbul II', 'location_code' => 'tr-istanbul-ii'],
            'khn-phnompenh-1' => ['country' => 'KH', 'city' => 'Phnom Penh', 'location_code' => 'kh-phnom-penh'],
            'qa-doha-1' => ['country' => 'QA', 'city' => 'Doha', 'location_code' => 'qa-doha'],
            'my-kualalumpur-1' => ['country' => 'MY', 'city' => 'Kuala Lumpur', 'location_code' => 'my-kuala-lumpur'],
            'jp-tokyo-1' => ['country' => 'JP', 'city' => 'Tokyo', 'location_code' => 'jp-tokyo'],
            'vn-henei-1' => ['country' => 'VN', 'city' => 'Hanoi', 'location_code' => 'vn-hanoi'],
            'bh-askar-1' => ['country' => 'BH', 'city' => 'Bahrain', 'location_code' => 'bh-bahrain'],
            'br-saopaulo-1' => ['country' => 'BR', 'city' => 'Sao Paulo', 'location_code' => 'br-sao-paulo'],
            'cl-santiago-1' => ['country' => 'CL', 'city' => 'Santiago', 'location_code' => 'cl-santiago'],
            'co-bogota-2' => ['country' => 'CO', 'city' => 'Bogota', 'location_code' => 'co-bogota'],
            'ar-buenosaires-1' => ['country' => 'AR', 'city' => 'Buenos Aires', 'location_code' => 'ar-buenos-aires'],
            'kw-kuwait-1' => ['country' => 'KW', 'city' => 'Kuwait City', 'location_code' => 'kw-kuwait-city'],
            'bg-dhaka-1' => ['country' => 'BD', 'city' => 'Dhaka', 'location_code' => 'bd-dhaka'],
            'iq-baghdad-1' => ['country' => 'IQ', 'city' => 'Baghdad', 'location_code' => 'iq-baghdad'],
            'ph-manila-1' => ['country' => 'PH', 'city' => 'Manila', 'location_code' => 'ph-manila'],
            'eg-cairo-1' => ['country' => 'EG', 'city' => 'Cairo', 'location_code' => 'eg-cairo'],
            'np-kathmandu-1' => ['country' => 'NP', 'city' => 'Kathmandu', 'location_code' => 'np-kathmandu'],
            'mx-mexico-1' => ['country' => 'MX', 'city' => 'Mexico City', 'location_code' => 'mx-mexico-city'],
            'sgp-singapore-1' => ['country' => 'SG', 'city' => 'Singapore', 'location_code' => 'sg-singapore'],
            'sau-riyadh-3' => ['country' => 'SA', 'city' => 'Riyadh II', 'location_code' => 'sa-riyadh-ii'],
        ],
        'packages' => [],
    ],
];

<?php

return [
'title' => ':place VPS Hosting | Current Plans & Prices | ServerNet',
'description' => 'Compare :place VPS plans by CPU, RAM, storage and network terms. Check current prices and capacity, location selection guidance and plan conditions before ordering.',
'h1' => ':place VPS hosting',
'intro' => 'Choose a :place VPS by identifying your audience and application requirements, then comparing current resources, storage and network terms. Final specifications and the amount are checked again during ordering.',
'profile_default' => 'If your users or an application dependency are in :country, include this location in your network tests. Your own location alone is not a selection criterion: check actual user routes and application resource requirements.',
'network_title' => 'How to test latency and network quality',
'network' => 'We have not published a public measured latency result for this location. Ask support for a verified test IP, then run ping and traceroute or mtr from actual user connections at different times. ICMP alone is insufficient: also measure HTTP response time, packet loss and routes to application dependencies. A dedicated IP does not guarantee acceptance by every website or identical country classification in every geolocation database.',
'check_title' => 'What to compare before buying a :country VPS',
'country_link' => 'All :country VPS locations',
'checks' => ['CPU and RAM: measure application load and concurrency. Shared vCPUs, dedicated CPU resources and a physical dedicated server are different products.',
'Storage: read the capacity and disk type of the selected plan; do not assume every plan includes NVMe or backups.',
'Network: fixed Mbps bandwidth and a GB/TB transfer quota are different. Check speed, allowance and overage terms.',
'Operating system: use only the compatible options shown for the selected plan. Check versions and licensing before choosing Windows or a specific application.',
'Cost and operations: read billing periods, stop/delete terms and current capacity, and plan your own backups before confirming an order.'],
'faq' => [[
'q' => 'Does a :country VPS guarantee low latency to Iran?',
'a' => 'Geographic proximity does not guarantee latency. ISP routing, test time and the actual destination matter. Test from your target users before choosing; we do not advertise a guaranteed latency figure for this location.'
],
[
'q' => 'How do I choose a plan in :place?',
'a' => 'Compare CPU, RAM, disk and network terms on this page against your workload. For critical services, test application response times and maintain independent backups. More resources alone do not fix network routing.'
],
[
'q' => 'Do all :country plans include Windows or NVMe?',
'a' => 'No. Operating systems, disk types and network models depend on the plan. Check its specifications and compatible order options rather than applying one plan’s features to every plan.'
],
[
'q' => 'Will the :country server price and capacity stay fixed?',
'a' => 'Current prices and capacity come from the catalog and may change. The final amount, billing period and specifications are shown again before you confirm an order.'
]],
'profiles' => [
'AE' => 'Consider Dubai for a store or API serving UAE users. For an Iranian audience, compare Dubai and Istanbul routes using HTTP tests from user ISPs rather than distance on a map.',
'TR' => 'Istanbul is a candidate for applications serving Türkiye and accessing European services. Test the route to each actual dependency; a Tehran ping does not represent every user.',
'SA' => 'Compare Riyadh and Dubai for a store or API serving Saudi users. Hosting in Saudi Arabia alone does not certify regulatory compliance, licensing or acceptance of the IP by a destination service.',
'PH' => 'For Philippine users, test Manila against Singapore and Hong Kong. If your database or API is elsewhere, also measure round trips between application components; proximity of the web server alone is insufficient.',
'SG' => 'Evaluate Singapore for APIs or sites serving several Southeast Asian countries. For mainly Philippine, Indonesian or Malaysian users, test a local city too; no location wins on every ISP.',
'HK' => 'Evaluate Hong Kong for users in the region or Asian application dependencies. Reachability varies by network; this location alone does not guarantee access or speed to mainland China.',
'DE' => 'For German users, compare available cities against database and dependency locations. Choosing Frankfurt by name alone is insufficient: compare CPU type, cost and application response time.',
'US' => 'For a US audience, coast and city matter: east- and west-coast user routes differ. Compare orderable cities with your API and database locations; the country name alone cannot select the best site.',
'VN' => 'For Vietnamese users, evaluate Hanoi and Ho Chi Minh City separately. Interactive apps, stores and APIs depend on actual user routes; one ISP test cannot represent both regions.',
'FR' => 'Check the actual plan city for French users: Marseille and Paris are different locations. Measure response times to European databases before moving an application.',
'FI' => 'Evaluate Helsinki for Finnish users or dependencies in northern Europe. If cost is the main goal, compare current resources, prices and user routes with Germany; a cheaper choice is not always faster.',
'GB' => 'Evaluate London for UK users or applications using UK APIs. In financial use cases, a VPS does not grant destination-service permission or guarantee trade execution; assess compatibility and routes separately.',
'IQ' => 'Test Baghdad from actual Iraqi user networks and read this page’s outage notices. For critical services, plan an alternative route and independent backups; proximity is not a recovery plan.',
'AR' => 'For an audience in Argentina, compare Buenos Aires with São Paulo. For a store, API or development environment, user-network response time and the route to your database matter more than the country label. The second location is a network comparison candidate; check its own page for capacity and price.',
'BD' => 'For an audience in Bangladesh, compare Dhaka with Singapore. For a store, API or development environment, user-network response time and the route to your database matter more than the country label. The second location is a network comparison candidate; check its own page for capacity and price.',
'BG' => 'For an audience in Bulgaria, compare Sofia with Bucharest. For a store, API or development environment, user-network response time and the route to your database matter more than the country label. The second location is a network comparison candidate; check its own page for capacity and price.',
'BH' => 'For an audience in Bahrain, compare Bahrain with Dubai. For a store, API or development environment, user-network response time and the route to your database matter more than the country label. The second location is a network comparison candidate; check its own page for capacity and price.',
'BR' => 'For an audience in Brazil, compare São Paulo with Buenos Aires. For a store, API or development environment, user-network response time and the route to your database matter more than the country label. The second location is a network comparison candidate; check its own page for capacity and price.',
'CA' => 'For an audience in Canada, compare Toronto with US cities. For a store, API or development environment, user-network response time and the route to your database matter more than the country label. The second location is a network comparison candidate; check its own page for capacity and price.',
'CL' => 'For an audience in Chile, compare Santiago with Buenos Aires. For a store, API or development environment, user-network response time and the route to your database matter more than the country label. The second location is a network comparison candidate; check its own page for capacity and price.',
'CO' => 'For an audience in Colombia, compare Bogotá with São Paulo. For a store, API or development environment, user-network response time and the route to your database matter more than the country label. The second location is a network comparison candidate; check its own page for capacity and price.',
'EC' => 'For an audience in Ecuador, compare Quito with Bogotá. For a store, API or development environment, user-network response time and the route to your database matter more than the country label. The second location is a network comparison candidate; check its own page for capacity and price.',
'EG' => 'For an audience in Egypt, compare Cairo with European cities. For a store, API or development environment, user-network response time and the route to your database matter more than the country label. The second location is a network comparison candidate; check its own page for capacity and price.',
'GR' => 'For an audience in Greece, compare Athens with Sofia. For a store, API or development environment, user-network response time and the route to your database matter more than the country label. The second location is a network comparison candidate; check its own page for capacity and price.',
'ID' => 'For an audience in Indonesia, compare Jakarta with Singapore. For a store, API or development environment, user-network response time and the route to your database matter more than the country label. The second location is a network comparison candidate; check its own page for capacity and price.',
'JP' => 'For an audience in Japan, compare Tokyo with Seoul. For a store, API or development environment, user-network response time and the route to your database matter more than the country label. The second location is a network comparison candidate; check its own page for capacity and price.',
'KH' => 'For an audience in Cambodia, compare Phnom Penh with Bangkok. For a store, API or development environment, user-network response time and the route to your database matter more than the country label. The second location is a network comparison candidate; check its own page for capacity and price.',
'KR' => 'For an audience in South Korea, compare Seoul with Tokyo. For a store, API or development environment, user-network response time and the route to your database matter more than the country label. The second location is a network comparison candidate; check its own page for capacity and price.',
'KW' => 'For an audience in Kuwait, compare Kuwait with Bahrain. For a store, API or development environment, user-network response time and the route to your database matter more than the country label. The second location is a network comparison candidate; check its own page for capacity and price.',
'MM' => 'For an audience in Myanmar, compare Yangon with Bangkok. For a store, API or development environment, user-network response time and the route to your database matter more than the country label. The second location is a network comparison candidate; check its own page for capacity and price.',
'MX' => 'For an audience in Mexico, compare Mexico City with US cities. For a store, API or development environment, user-network response time and the route to your database matter more than the country label. The second location is a network comparison candidate; check its own page for capacity and price.',
'MY' => 'For an audience in Malaysia, compare Kuala Lumpur with Singapore. For a store, API or development environment, user-network response time and the route to your database matter more than the country label. The second location is a network comparison candidate; check its own page for capacity and price.',
'NP' => 'For an audience in Nepal, compare Kathmandu with Singapore. For a store, API or development environment, user-network response time and the route to your database matter more than the country label. The second location is a network comparison candidate; check its own page for capacity and price.',
'QA' => 'For an audience in Qatar, compare Doha with Dubai. For a store, API or development environment, user-network response time and the route to your database matter more than the country label. The second location is a network comparison candidate; check its own page for capacity and price.',
'RO' => 'For an audience in Romania, compare Bucharest with Sofia. For a store, API or development environment, user-network response time and the route to your database matter more than the country label. The second location is a network comparison candidate; check its own page for capacity and price.',
'RU' => 'For an audience in Russia, compare Moscow with Helsinki. For a store, API or development environment, user-network response time and the route to your database matter more than the country label. The second location is a network comparison candidate; check its own page for capacity and price.',
'TH' => 'For an audience in Thailand, compare Bangkok with Singapore. For a store, API or development environment, user-network response time and the route to your database matter more than the country label. The second location is a network comparison candidate; check its own page for capacity and price.',
'TW' => 'For an audience in Taiwan, compare Taipei with Tokyo. For a store, API or development environment, user-network response time and the route to your database matter more than the country label. The second location is a network comparison candidate; check its own page for capacity and price.',
'IR' => 'For an audience in Iran, compare available Iranian cities with Istanbul. For a store, API or development environment, user-network response time and the route to your database matter more than the country label. The second location is a network comparison candidate; check its own page for capacity and price.',
'NL' => 'For an audience in the Netherlands, compare available Dutch cities with Frankfurt. For a store, API or development environment, user-network response time and the route to your database matter more than the country label. The second location is a network comparison candidate; check its own page for capacity and price.',
'SE' => 'For an audience in Sweden, compare available Swedish cities with Helsinki. For a store, API or development environment, user-network response time and the route to your database matter more than the country label. The second location is a network comparison candidate; check its own page for capacity and price.',
'AT' => 'For an audience in Austria, compare available Austrian cities with Frankfurt. For a store, API or development environment, user-network response time and the route to your database matter more than the country label. The second location is a network comparison candidate; check its own page for capacity and price.'
]
];

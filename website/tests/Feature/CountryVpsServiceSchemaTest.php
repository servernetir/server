<?php

namespace Tests\Feature;

use App\Http\Controllers\CountryVpsController;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Services\Cloud\PublicVpsCatalog;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\SeedsCatalogPricing;
use Tests\TestCase;

class CountryVpsServiceSchemaTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCatalogPricing;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->seedCatalogPricing(100000);
    }

    public function test_country_list_describes_services_while_retaining_offers_order_and_checkout_links(): void
    {
        $first = $this->seedPlan('ph-manila', 'Manila', 'CV-PH-2', 810000);
        $second = $this->seedPlan('ph-cebu', 'Cebu', 'CV-PH-4', 1290000);
        $blocked = $this->seedPlan('ph-manila', 'Manila', 'CV-NOT-AVAILABLE', 700000, ['in_stock' => false]);
        $original = [$first->fresh()->getRawOriginal(), $second->fresh()->getRawOriginal(), $blocked->fresh()->getRawOriginal()];

        foreach (['fa' => '', 'en' => '/en', 'tr' => '/tr'] as $locale => $prefix) {
            $response = $this->get($prefix.'/vps/philippines')->assertOk()->assertViewIs('pages.vps-country');
            $html = $response->getContent();
            $rows = $response->viewData('vpsRows');
            $this->assertSame(['CV-PH-2', 'CV-PH-4'], array_column($rows, 'name'));
            $this->assertSame([810000, 1290000], array_column($rows, 'monthly'));
            $list = $this->itemList($html);
            $this->assertNotNull($list);
            $this->assertCount(2, $list['itemListElement']);
            $country = CloudLocation::COUNTRIES['PH'][$locale];
            $expectedPrices = $locale === 'fa' ? ['8100000', '12900000'] : [8.1, 12.9];

            foreach ($list['itemListElement'] as $index => $entry) {
                $this->assertSame([
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'item' => [
                        '@type' => 'Service',
                        'name' => $rows[$index]['name'].' — '.$country.' — '.$rows[$index]['city'],
                        'offers' => [
                            '@type' => 'Offer',
                            'price' => $expectedPrices[$index],
                            'priceCurrency' => $locale === 'fa' ? 'IRR' : 'EUR',
                            'url' => url($prefix.'/vps/philippines'),
                            'availability' => 'https://schema.org/InStock',
                        ],
                    ],
                ], $entry);
            }

            $xpath = $this->xpath($html);
            $tableRows = $xpath->query('//tr[@data-vps-row]');
            $this->assertSame(2, $tableRows->length);
            foreach ($rows as $index => $row) {
                $this->assertSame((string) $row['monthly'], $tableRows->item($index)->getAttribute('data-monthly'));
                $link = $xpath->query('.//a', $tableRows->item($index))->item(0);
                $this->assertSame($row['monthly_url'], $link->getAttribute('href'));
                $this->assertStringContainsString($prefix.'/account/cloud-store?', $link->getAttribute('href'));
                $this->assertStringContainsString('plan='.$row['slug'].'&cycle=monthly', $link->getAttribute('href'));
            }
            $this->assertSame(1, $xpath->query('//form[@data-vps-filters]')->length);
            $this->assertSame(1, $xpath->query('//h1')->length);
            $this->assertStringNotContainsString('CV-NOT-AVAILABLE', $html);
            $this->assertSame($original, [$first->fresh()->getRawOriginal(), $second->fresh()->getRawOriginal(), $blocked->fresh()->getRawOriginal()]);
        }
        Http::assertNothingSent();
    }

    public function test_empty_country_retains_noindex_and_does_not_advertise_schema_offers(): void
    {
        foreach (['', '/en', '/tr'] as $prefix) {
            $response = $this->get($prefix.'/vps/philippines')->assertOk();
            $this->assertSame([], $response->viewData('vpsRows'));
            $this->assertNull($this->itemList($response->getContent()));
            $xpath = $this->xpath($response->getContent());
            $robots = $xpath->query('//meta[@name="robots"]')->item(0);
            $this->assertNotNull($robots);
            $this->assertStringContainsString('noindex', $robots->getAttribute('content'));
            $this->assertSame(0, $xpath->query('//tr[@data-vps-row]')->length);
        }
    }

    public function test_nonpositive_prices_stay_out_of_schema_and_remaining_positions_are_contiguous(): void
    {
        $rows = [
            ['name' => 'Zero', 'city' => 'Manila', 'monthly' => 0],
            ['name' => 'First valid', 'city' => 'Manila', 'monthly' => 1234567],
            ['name' => 'Negative', 'city' => 'Manila', 'monthly' => -1],
            ['name' => 'Second valid', 'city' => 'Cebu', 'monthly' => 9876543],
        ];
        $this->mock(PublicVpsCatalog::class)->shouldReceive('rows')->with('PH')->times(3)->andReturn($rows);

        foreach (['fa', 'en', 'tr'] as $locale) {
            app()->setLocale($locale);
            $list = app(CountryVpsController::class)->show('PH')->getData()['ld']['list']['itemListElement'];
            $this->assertSame([1, 2], array_column($list, 'position'));
            $this->assertSame(['Service', 'Service'], array_column(array_column($list, 'item'), '@type'));
            $this->assertSame($locale === 'fa' ? ['12345670', '98765430'] : [12.35, 98.77], array_column(array_column(array_column($list, 'item'), 'offers'), 'price'));
        }
    }

    private function seedPlan(string $code, string $city, string $name, int $price, array $overrides = []): CloudPlan
    {
        CloudLocation::firstOrCreate(['code' => $code], ['country' => 'PH', 'city' => $city, 'is_active' => true]);

        return CloudPlan::create(array_merge([
            'provider' => 'hetzner', 'provider_ref' => $name, 'location_code' => $code, 'public_name' => $name,
            'slug' => strtolower($name).'-'.$code, 'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40,
            'disk_type' => 'nvme', 'cpu_kind' => 'shared', 'arch' => 'x86', 'traffic_gb' => 2048,
            'cost_eur_cents' => 400, 'price_eur_cents' => 800, 'price_irt' => $price,
            'is_active' => true, 'in_stock' => true, 'admin_disabled' => false,
        ], $overrides));
    }

    private function itemList(string $html): ?array
    {
        preg_match_all('~<script\b[^>]*type="application/ld\+json"[^>]*>(.*?)</script>~s', $html, $matches);
        $list = null;
        foreach ($matches[1] as $raw) {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (($data['@type'] ?? null) === 'ItemList') {
                $this->assertNull($list, 'Only one country plan ItemList should be emitted.');
                $list = $data;
            }
        }

        return $list;
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }
}

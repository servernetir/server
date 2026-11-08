<?php

namespace Tests\Feature;

use App\Services\InternalLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\SeedsCatalogPricing;
use Tests\TestCase;

class InternalLinkCommercialTargetsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCatalogPricing;

    public function test_cloud_article_inventory_can_link_directly_to_hourly_and_gpu_pages_in_all_languages(): void
    {
        Http::preventStrayRequests();
        $this->seedCatalogPricing();
        $links = app(InternalLinks::class);
        $rows = collect($links->inventory('cloud'))->keyBy('url');

        foreach (['/vps/hourly', '/gpu'] as $path) {
            $this->assertTrue($rows->has($path));
            $html = '<a href="'.$path.'">'.$rows[$path]['anchor'].'</a>';
            foreach (['fa', 'en', 'tr'] as $locale) {
                $target = ($locale === 'fa' ? '' : '/'.$locale).$path;
                $localized = $links->localize($html, $locale);
                $this->assertStringContainsString('href="'.$target.'"', $localized);
                $this->assertSame($localized, $links->sanitize($localized));
                $this->get($target)->assertOk();
            }
        }
    }
}

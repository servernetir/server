<?php

namespace Tests\Feature;

use App\Http\Controllers\SiteController;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

class HomePricingPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_quotes_show_the_real_term_and_missing_price_state_in_every_locale(): void
    {
        Http::preventStrayRequests();
        config(['services.gtm.enabled' => false]);
        $plan = new CloudPlan([
            'public_name' => 'CV-1-1', 'slug' => 'cv-1c-1g-25d-de-frankfurt',
            'location_code' => 'de-frankfurt', 'price_irt' => 2490000,
            'vcpu' => 1, 'ram_mb' => 1024, 'disk_gb' => 25, 'traffic_gb' => 1000,
        ]);
        $plan->setRelation('location', new CloudLocation([
            'code' => 'de-frankfurt', 'country' => 'DE', 'city' => 'Frankfurt',
            'label_fa' => 'آلمان — فرانکفورت', 'label_en' => 'Germany — Frankfurt',
            'label_tr' => 'Almanya — Frankfurt',
        ]));
        $mapped = [];
        View::composer('pages.home', function ($view) use (&$mapped) {
            $product = ['icon' => 'globe', 'pid' => 1, 'link' => ['hosting', 'linux'],
                'fa' => ['t' => 'نمونه', 'd' => 'نمونه'],
                'en' => ['t' => 'Example', 'd' => 'Example'],
                'tr' => ['t' => 'Örnek', 'd' => 'Örnek']];
            $view->with('products', [
                $product + ['contact' => true, 'quote' => true],
                $product + ['irt' => 1290000, 'final_irt' => true, 'tld' => '.com', 'min_years' => 1],
                $product + ['irt' => 4290000, 'final_irt' => true, 'tld' => '.ai', 'min_years' => 2],
                $product + ['irt' => 490000, 'final_irt' => true],
            ])->with('plans', $mapped);
        });

        foreach (['' => 'fa', '/en' => 'en', '/tr' => 'tr'] as $prefix => $locale) {
            app()->setLocale($locale);
            $mapped = (new \ReflectionMethod(SiteController::class, 'homeCloudPlans'))
                ->invoke(app(SiteController::class), collect([$plan]));
            $this->assertSame(2490000, $mapped[0]['irt']);
            $this->assertSame($plan->location->label($locale), $mapped[0]['location_label']);
            $html = $this->get($prefix ?: '/')->assertOk()->getContent();
            $dom = new \DOMDocument;
            @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
            $xpath = new \DOMXPath($dom);
            $prices = $xpath->query('//section[@id="products"]//div[contains(concat(" ",normalize-space(@class)," ")," price ")]');
            $this->assertCount(4, $prices);
            $missing = trim($prices->item(0)->textContent);
            $this->assertSame(__('ui.hp_contact_price'), $missing);
            $this->assertStringNotContainsString('—', $missing);
            $this->assertStringContainsString('.com', $prices->item(1)->textContent);
            $this->assertStringContainsString(__('ui.domain_year'), $prices->item(1)->textContent);
            $this->assertStringContainsString(__('ui.domain_period_years', ['years' => fa_num('2')]), $prices->item(2)->textContent);
            $this->assertStringNotContainsString(__('ui.mo'), $prices->item(1)->textContent);
            $this->assertStringNotContainsString(__('ui.mo'), $prices->item(2)->textContent);
            $this->assertStringContainsString(__('ui.mo'), $prices->item(3)->textContent);

            $pricing = $xpath->query('//section[@id="pricing"]')->item(0);
            $this->assertStringContainsString($plan->location->label($locale), $pricing->textContent);
            $link = $xpath->query('//section[@id="pricing"]//article//a')->item(0)->getAttribute('href');
            parse_str(parse_url($link, PHP_URL_QUERY), $query);
            $this->assertSame('de-frankfurt', $query['location']);
            $this->assertSame($plan->slug, $query['plan']);
            $this->assertStringContainsString(__('ui.mo'), $pricing->textContent);
            $this->assertSame(0, $xpath->query('//section[@id="pricing"]//*[@data-bill="yearly"]')->length);
            $this->assertSame(0, $xpath->query('//section[@id="pricing"]//*[contains(concat(" ",normalize-space(@class)," ")," pr-y ")]')->length);
        }
        Http::assertNothingSent();
    }
}

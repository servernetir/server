<?php

namespace Tests\Feature;

use App\Http\Controllers\SiteController;
use App\Providers\AppServiceProvider;
use App\Support\InternationalMarket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class InternationalMarketPilotTest extends TestCase
{
    use RefreshDatabase;

    public function test_pilot_is_closed_by_default_and_unready_locales_cannot_be_enabled(): void
    {
        $this->assertSame([], InternationalMarket::publishedLocales());
        config(['international-market.enabled_locales' => 'fr,ar,unknown']);
        $this->assertSame([], InternationalMarket::publishedLocales());
        $this->get('/ru')->assertNotFound();
        $this->get('/az/gpu')->assertNotFound();
        $this->get('/fr')->assertNotFound();
        $this->get('/ar')->assertNotFound();
        $this->assertSame(['fa', 'en', 'tr'], array_keys(AppServiceProvider::LOCALES));
    }

    public function test_all_ready_pages_have_complete_localized_text_and_reciprocal_metadata(): void
    {
        Http::preventStrayRequests();
        config(['international-market.enabled_locales' => 'ru,az']);
        $ru = InternationalMarket::content('ru');
        $az = InternationalMarket::content('az');
        $this->assertSame(array_keys(InternationalMarket::PAGES), array_keys($ru['pages']));
        $this->assertSame(array_keys(Arr::dot($ru)), array_keys(Arr::dot($az)));

        foreach (InternationalMarket::publishedLocales() as $locale) {
            $content = InternationalMarket::content($locale);
            $titles = [];
            foreach (Arr::dot($content) as $value) {
                $this->assertIsString($value);
                $this->assertNotSame('', trim($value));
            }
            foreach (InternationalMarket::PAGES as $key => $definition) {
                $path = InternationalMarket::path($locale, $key);
                $res = $this->get($path)->assertOk();
                $html = $res->getContent();
                $canonical = InternationalMarket::url($locale, $key);
                $res->assertSee('lang="'.$locale.'" dir="ltr"', false);
                $res->assertSee('<link rel="canonical" href="'.$canonical.'">', false);
                $res->assertSee('href="'.route($definition['catalog'], $definition['params']).'"', false);
                $res->assertSee($content['pages'][$key]['title']);
                $res->assertSee($content['catalog_note']);
                $res->assertDontSee('hreflang="en"', false);
                $res->assertDontSee('hreflang="fr"', false);
                $res->assertDontSee('noindex', false);
                $res->assertDontSee('ui.', false);
                $this->assertSame(1, preg_match_all('~<h1[\s>]~i', $html));
                foreach (InternationalMarket::publishedLocales() as $alternate) {
                    $res->assertSee('hreflang="'.$alternate.'" href="'.InternationalMarket::url($alternate, $key).'"', false);
                }
                preg_match('~<title>(.*?)</title>~s', $html, $title);
                $titles[] = $title[1] ?? '';
                preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $html, $json);
                $schema = json_decode($json[1] ?? '', true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame('WebPage', $schema['@type']);
                $this->assertSame($canonical, $schema['url']);
                $this->assertSame($locale, $schema['inLanguage']);
            }
            $this->assertCount(count(InternationalMarket::PAGES), array_unique($titles));
        }
    }

    public function test_only_published_pages_are_discoverable_and_legacy_hreflang_is_unchanged(): void
    {
        Http::preventStrayRequests();
        config(['international-market.enabled_locales' => 'ru']);
        $ru = $this->get('/ru/cloud/n8n')->assertOk();
        $ru->assertDontSee('hreflang="az"', false);
        $this->get('/ru/cloud/n8n?utm_source=example')->assertOk()
            ->assertSee('<link rel="canonical" href="'.url('/ru/cloud/n8n').'">', false);
        $this->get('/az/cloud/n8n')->assertNotFound();
        $this->get('/fr/cloud/n8n')->assertNotFound();
        $this->get('/ru/account')->assertNotFound();
        $this->get('/ru/login')->assertNotFound();

        $this->get('/en')
            ->assertOk()
            ->assertSee('href="'.url('/ru').'" lang="ru"', false)
            ->assertDontSee('rel="alternate" hreflang="ru"', false)
            ->assertDontSee('href="'.url('/az').'" lang="az"', false);

        $sitemap = $this->get('/sitemap.xml')->assertOk()->getContent();
        $products = $this->get('/sitemaps/products.xml')->assertOk()->getContent();
        $pages = $this->get('/sitemaps/pages.xml')->assertOk()->getContent();
        foreach (array_keys(InternationalMarket::PAGES) as $page) {
            $url = InternationalMarket::url('ru', $page);
            $this->assertStringContainsString('<loc>'.$url.'</loc>', $sitemap);
            $this->assertStringContainsString('<loc>'.$url.'</loc>', $page === 'home' ? $pages : ($page === 'ai' ? $this->get('/sitemaps/ai.xml')->assertOk()->getContent() : $products));
            $this->assertStringNotContainsString('<loc>'.InternationalMarket::url('az', $page).'</loc>', $sitemap);
        }
        $this->assertSame('products', SiteController::sitemapSectionFor(url('/ru/cloud/n8n')));
        $this->assertSame('ai', SiteController::sitemapSectionFor(url('/az/ai')));
        $this->assertSame('fa', InternationalMarket::localeFromPath('/cloud/ru'));
        $this->assertSame('ru', InternationalMarket::localeFromPath('/ru/cloud?source=campaign'));

        config(['international-market.enabled_locales' => '']);
        $this->get('/ru')->assertNotFound();
        $this->get('/sitemap.xml')->assertOk()->assertDontSee('<loc>'.url('/ru').'</loc>', false);
    }

    public function test_new_routes_are_read_only_and_use_the_canonical_host(): void
    {
        config(['international-market.enabled_locales' => 'ru,az']);
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_starts_with((string) $route->getName(), 'international.'));
        $this->assertCount(count(InternationalMarket::PAGES) * count(InternationalMarket::PILOT_LOCALES), $routes);
        foreach ($routes as $route) {
            $this->assertEqualsCanonicalizing(['GET', 'HEAD'], $route->methods());
        }

        $this->get('https://mail.servernet.cloud/ru/gpu')->assertStatus(301)->assertRedirect('https://servernet.cloud/ru/gpu');
        $this->get('https://console.servernet.cloud/az/cloud/n8n')->assertStatus(301)->assertRedirect('https://servernet.cloud/az/cloud/n8n');
    }
}

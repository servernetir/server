<?php

namespace Tests\Feature;

use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Post;
use App\Models\PostTranslation;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\SeedsCatalogPricing;
use Tests\TestCase;

class GpuDecisionGuideTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCatalogPricing;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->seedCatalogPricing();
    }

    public function test_decision_content_has_localized_existing_routes_even_without_offers(): void
    {
        foreach ([
            'fa' => ['', 'پیش از اجاره GPU، نیاز پروژه را مشخص کنید', 'RAM سیستم با VRAM کارت متفاوت است'],
            'en' => ['/en', 'Define your workload before renting a GPU', 'System RAM and GPU VRAM are different'],
            'tr' => ['/tr', 'GPU kiralamadan önce iş yükünüzü belirleyin', 'Sistem RAM’i ile GPU VRAM’i farklıdır'],
        ] as $locale => [$prefix, $title, $memory]) {
            $html = $this->get($prefix.'/gpu')->assertOk()->getContent();
            $xpath = $this->xpath($html);
            $section = $xpath->query('//*[@id="gpu-decision-guide"]')->item(0);

            $this->assertNotNull($section, $locale);
            $this->assertStringContainsString($title, $section->textContent);
            $this->assertStringContainsString($memory, $section->textContent);
            $this->assertSame(1, $xpath->query('//h1')->length);
            $this->assertSame(1, $xpath->query('.//h2[@id="gpu-decision-title"]', $section)->length);
            $this->assertSame(3, $xpath->query('.//h3', $section)->length);
            $this->assertSame(0, $xpath->query('.//form | .//input | .//button', $section)->length);
            $this->assertSame(0, $xpath->query('ancestor::form', $section)->length);
            $this->assertSame([
                url($prefix.'/ai/models'),
                url($prefix.'/ai/docs'),
                url($prefix.'/vps/hourly'),
            ], $this->links($xpath, $section));
            $this->assertStringContainsString('checkpoint', $section->textContent);
            $this->assertStringNotContainsString('gpu-server-selection', $section->textContent);
            $this->assertSame(0, $xpath->query('.//a[contains(@href, "/blog/")]', $section)->length);
        }
    }

    public function test_missing_translations_and_drafts_never_become_guide_links(): void
    {
        $this->seedGuidePost('jupyter-hourly-gpu-guide', 'published', ['fa']);
        $this->seedGuidePost('comfyui-hourly-gpu-guide', 'draft', ['fa', 'en', 'tr']);

        foreach (['fa' => '', 'en' => '/en', 'tr' => '/tr'] as $locale => $prefix) {
            $xpath = $this->xpath($this->get($prefix.'/gpu')->assertOk()->getContent());
            $section = $xpath->query('//*[@id="gpu-decision-guide"]')->item(0);
            $guideLinks = $xpath->query('.//a[contains(@href, "/blog/")]', $section);

            $this->assertSame($locale === 'fa' ? 1 : 0, $guideLinks->length, $locale);
            if ($locale === 'fa') {
                $this->assertSame(url('/blog/jupyter-hourly-gpu-guide'), $guideLinks->item(0)->getAttribute('href'));
            }
            $this->assertNotContains(url($prefix.'/blog/comfyui-hourly-gpu-guide'), $this->links($xpath, $section));
        }
    }

    public function test_published_guides_are_localized_without_changing_order_fields_or_plan_state(): void
    {
        foreach (['jupyter-hourly-gpu-guide', 'comfyui-hourly-gpu-guide'] as $slug) {
            $this->seedGuidePost($slug, 'published', ['fa', 'en', 'tr']);
        }
        CloudLocation::create(['code' => 'global-gpu', 'country' => 'XX', 'is_active' => true, 'sort' => 1]);
        $plan = CloudPlan::create([
            'provider' => 'salad', 'provider_ref' => 'gc-decision-test', 'provider_location' => 'global',
            'location_code' => 'global-gpu', 'public_name' => 'Decision Test GPU', 'slug' => 'decision-test-gpu',
            'vcpu' => 8, 'ram_mb' => 30720, 'disk_gb' => 100, 'disk_type' => 'ssd', 'traffic_gb' => 0,
            'cpu_kind' => 'shared', 'arch' => 'x86', 'cost_eur_cents' => 4000, 'price_eur_cents' => 6000,
            'price_irt' => 7_200_000, 'is_active' => true, 'in_stock' => true, 'admin_disabled' => false,
            'gpu_model' => 'Decision Test GPU', 'gpu_count' => 1, 'gpu_vram_mb' => null, 'is_interruptible' => true,
        ]);
        $before = $plan->fresh()->getRawOriginal();

        foreach (['fa' => '', 'en' => '/en', 'tr' => '/tr'] as $locale => $prefix) {
            $html = $this->get($prefix.'/gpu')->assertOk()->getContent();
            $xpath = $this->xpath($html);
            $section = $xpath->query('//*[@id="gpu-decision-guide"]')->item(0);
            $form = $xpath->query('//form[@id="gpu-configure"]')->item(0);

            $this->assertNotNull($form, $locale);
            $this->assertSame(url($prefix.'/gpu/order'), $form->getAttribute('action'));
            $this->assertSame('post', $form->getAttribute('method'));
            $this->assertSame(1, $xpath->query('.//input[@name="_token"]', $form)->length);
            $this->assertSame(1, $xpath->query('.//input[@name="plan" and @value="decision-test-gpu" and @checked]', $form)->length);
            $this->assertSame(1, $xpath->query('.//button[@id="gpu-cta" and @type="submit"]', $form)->length);
            $this->assertSame(0, $xpath->query('.//*[@id="gpu-decision-guide"]', $form)->length);
            $this->assertCount(5, $this->links($xpath, $section));
            $this->assertContains(url($prefix.'/blog/jupyter-hourly-gpu-guide'), $this->links($xpath, $section));
            $this->assertContains(url($prefix.'/blog/comfyui-hourly-gpu-guide'), $this->links($xpath, $section));
            $this->assertLessThan(strpos($html, 'id="gpu-configure"'), strpos($html, '<div class="gpu-warn">'));
            $this->assertSame($before, $plan->fresh()->getRawOriginal());
        }
        Http::assertNothingSent();
    }

    private function seedGuidePost(string $slug, string $status, array $locales): void
    {
        $post = Post::create(['slug' => $slug, 'type' => 'blog', 'category' => 'tutorial', 'status' => $status, 'published_at' => now()]);
        foreach ($locales as $locale) {
            PostTranslation::create(['post_id' => $post->id, 'locale' => $locale, 'title' => 'Synthetic '.$locale.' guide', 'excerpt' => 'Synthetic test guide', 'content' => '<p>Fixture only.</p>']);
        }
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

    private function links(DOMXPath $xpath, \DOMNode $section): array
    {
        return array_map(fn ($link) => $link->getAttribute('href'), iterator_to_array($xpath->query('.//a', $section)));
    }
}

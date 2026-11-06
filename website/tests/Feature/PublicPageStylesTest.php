<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\PostTranslation;
use App\Services\PublicPageStyles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublicPageStylesTest extends TestCase
{
    use RefreshDatabase;

    private const EMBEDDED = [400 => 'IRANSans-web', 700 => 'IRANSans-Bold-web'];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_current_minified_and_expanded_css_preserve_every_rule_except_the_two_guarded_font_changes(): void
    {
        foreach (['assets/css/site.min.css', 'assets/css/site.css'] as $site) {
            $this->request('/gpu');
            $styles = app(PublicPageStyles::class)->inline($site);
            $this->assertSame(['site', 'server-menu', 'site-select'], array_keys($styles));
            foreach (['site' => $site, 'server-menu' => 'assets/css/server-menu.css', 'site-select' => 'assets/css/site-select.css'] as $key => $asset) {
                $expected = str_replace(["\r\n", '../font/'], ["\n", '/assets/font/'], file_get_contents(public_asset_path($asset)));
                if ($key === 'site') {
                    foreach (self::EMBEDDED as $weight => $font) {
                        $expected = str_replace('/assets/font/woff2/'.$font.'.woff2', 'data:font/woff2;base64,'.base64_encode(file_get_contents(public_asset_path('assets/font/woff2/'.$font.'.woff2'))), $expected);
                        $display = $weight === 400 ? 'swap' : 'optional';
                        $expected = str_replace('font-weight:'.$weight.';font-style:normal;font-display:'.$display, 'font-weight:'.$weight.';font-style:normal;font-display:block', $expected);
                    }
                }
                $this->assertTrue($expected === $styles[$key], 'Unexpected CSS change in '.$asset);
            }
            foreach ($this->embeddedBytes($styles['site']) as $weight => $bytes) {
                $original = file_get_contents(public_asset_path('assets/font/woff2/'.self::EMBEDDED[$weight].'.woff2'));
                $this->assertSame(hash('sha256', $original), hash('sha256', $bytes));
                $this->assertSame($original, $bytes);
            }
            $this->assertStringContainsString('/assets/font/woff/IRANSans-web.woff', $styles['site']);
            $this->assertStringContainsString('/assets/font/woff/IRANSans-Bold-web.woff', $styles['site']);
            $this->assertStringContainsString('font-weight:500;font-style:normal;font-display:optional', $styles['site']);
        }
    }

    public function test_only_explicit_public_paths_are_eligible_in_three_locales(): void
    {
        foreach (['', '/en', '/tr'] as $prefix) {
            foreach (['', '/gpu', '/vps/hourly', '/vps/philippines', '/vps/uae', '/hosting/linux', '/blog/php-version-upgrade-guide'] as $path) {
                $this->request(($prefix.$path) ?: '/');
                $styles = $this->fixture();
                $this->assertCount(3, $styles->inline('assets/css/site.min.css'), $prefix.$path);
                $this->assertCount(str_starts_with($path, '/vps/') ? 5 : 4, $styles->reads);
                $this->assertCount(2, $styles->fontReads);
            }
            foreach (['/account/cloud-store', '/admin', '/login', '/cart', '/checkout', '/payment', '/cloud', '/cloud/ae-dubai', '/cloud/ph-manila', '/vps/germany', '/hosting/python', '/blog/other-guide', '/gpu-extra'] as $path) {
                $this->request($prefix.$path);
                $styles = $this->fixture();
                $this->assertNull($styles->inline('assets/css/site.min.css'));
                $this->assertNull($styles->auxiliary('assets/css/chat-morph.css'));
                $this->assertSame([], $styles->reads);
                $this->assertSame([], $styles->fontReads);
            }
        }
        $this->request('/gpu');
        $styles = $this->fixture();
        $this->assertNull($styles->inline('../site.css'));
        $this->assertNull($styles->auxiliary('assets/css/unknown.css'));
        $this->assertSame([], $styles->reads);
    }

    public function test_auxiliary_before_layout_and_layout_before_auxiliary_share_one_atomic_request_bundle(): void
    {
        foreach ([true, false] as $auxiliaryFirst) {
            $this->request('/vps/philippines');
            $styles = $this->fixture();
            $site = $this->selectedSite();
            if ($auxiliaryFirst) {
                $this->assertSame('.vpsc-table{color:green}', $styles->auxiliary('assets/css/vps-catalog.css'));
                $this->assertNotNull($styles->inline($site));
            } else {
                $this->assertNotNull($styles->inline($site));
                $this->assertSame('.vpsc-table{color:green}', $styles->auxiliary('assets/css/vps-catalog.css'));
            }
            $this->assertSame('.chat-panel{color:orange}', $styles->auxiliary('assets/css/chat-morph.css'));
            $this->assertCount(5, $styles->reads);
            $this->assertCount(2, $styles->fontReads);
        }
    }

    public function test_next_request_reads_current_css_and_font_bytes_instead_of_sharing_a_public_cache(): void
    {
        $this->request('/gpu');
        $first = $this->fixture();
        $expected = $first->inline('assets/css/site.min.css');
        $replacement = file_get_contents(public_asset_path('assets/font/woff2/IRANSans-Bold-web.woff2'));
        $second = $this->fixture(['assets/css/site.min.css' => $this->fontFaces().'.changed{color:red}'], ['assets/font/woff2/IRANSans-web.woff2' => $replacement]);
        $this->assertSame($expected, $second->inline('assets/css/site.min.css'));
        $this->assertSame([], $second->reads);
        $this->assertSame([], $second->fontReads);
        $this->request('/gpu');
        $new = $second->inline('assets/css/site.min.css');
        $this->assertStringContainsString('.changed{color:red}', $new['site']);
        $this->assertSame($replacement, $this->embeddedBytes($new['site'])[400]);
        $this->assertCount(4, $second->reads);
        $this->assertCount(2, $second->fontReads);
    }

    public function test_expanded_font_declarations_also_block_and_unrelated_urls_are_preserved(): void
    {
        $this->request('/gpu');
        $faces = str_replace(['font-display:swap', 'font-display:optional'], ['font-display: swap', 'font-display: optional'], $this->fontFaces());
        $extra = '.a{background:url("/assets/example.svg?x=1#icon")} .b{background:url(https://example.test/image.png)} .c{background:url(\'data:image/svg+xml;base64,PHN2Zz4=\')}';
        $styles = $this->fixture(['assets/css/site.min.css' => $faces.$extra])->inline('assets/css/site.min.css');
        $this->assertStringContainsString($extra, $styles['site']);
        $this->assertCount(2, $this->embeddedBytes($styles['site']));
        $this->assertSame(2, substr_count($styles['site'], 'font-display:block'));
        $this->assertStringContainsString('font-weight:500;font-style:normal;font-display: optional', $styles['site']);
    }

    public function test_any_missing_or_unsafe_required_css_falls_back_as_a_complete_group(): void
    {
        foreach (['assets/css/site.min.css', 'assets/css/server-menu.css', 'assets/css/site-select.css', 'assets/css/chat-morph.css', 'assets/css/vps-catalog.css'] as $asset) {
            foreach ([null, '', '.choice::before{content:"</StYlE >"}', '.a{background:url(../img/new.svg)}', '.a{background:url("../font/woff2/new-font.woff2")}', '.a{background:url("../font/woff2/IRANSans-web.woff2)}', '.a{background:url(\'..\\font\\new.woff2\')}', '@import url("/assets/another.css");', ".a{color:red}\0"] as $invalid) {
                $this->request('/vps/philippines');
                $styles = $this->fixture([$asset => $invalid]);
                $this->assertNull($styles->inline('assets/css/site.min.css'));
                $this->assertNull($styles->auxiliary('assets/css/vps-catalog.css'));
                $this->assertNull($styles->auxiliary('assets/css/chat-morph.css'));
            }
        }
    }

    public function test_missing_corrupt_or_unknown_font_faces_keep_the_external_fallback(): void
    {
        foreach (self::EMBEDDED as $font) {
            $asset = 'assets/font/woff2/'.$font.'.woff2';
            $bytes = file_get_contents(public_asset_path($asset));
            foreach ([null, '', 'invalid-font', substr($bytes, 0, -1), substr_replace($bytes, pack('N', 1), 8, 4)] as $invalid) {
                $this->request('/gpu');
                $styles = $this->fixture([], [$asset => $invalid]);
                $this->assertNull($styles->inline('assets/css/site.min.css'));
                $this->assertNull($styles->auxiliary('assets/css/chat-morph.css'));
            }
        }
        foreach ([str_replace('font-family:\'IRANSans\'', 'font-family:\'Other\'', $this->fontFaces()), $this->fontFaces().'@font-face{font-family:Extra;src:url(/assets/extra.woff2)}', $this->fontFaces().$this->fontFaces(), str_replace('font-style:normal', 'font-style:italic', $this->fontFaces())] as $invalid) {
            $this->request('/gpu');
            $this->assertNull($this->fixture(['assets/css/site.min.css' => $invalid])->inline('assets/css/site.min.css'));
        }
    }

    public function test_rendered_targets_preserve_cascade_scripts_and_only_remove_redundant_external_font_preloads(): void
    {
        $post = Post::create(['slug' => 'php-version-upgrade-guide', 'type' => 'blog', 'category' => 'hosting', 'status' => 'published', 'published_at' => now()]);
        foreach (['fa', 'en', 'tr'] as $locale) {
            PostTranslation::create(['post_id' => $post->id, 'locale' => $locale, 'title' => 'Synthetic PHP guide', 'excerpt' => 'Fixture only', 'content' => '<p>Fixture.</p>']);
        }
        foreach (['', '/en', '/tr'] as $prefix) {
            foreach (['', '/gpu', '/vps/hourly', '/vps/philippines', '/vps/uae', '/hosting/linux', '/blog/php-version-upgrade-guide'] as $path) {
                $html = $this->get(($prefix.$path) ?: '/')->assertOk()->getContent();
                $head = explode('</head>', $html)[0];
                foreach (['site', 'server-menu', 'site-select'] as $style) {
                    $this->assertSame(1, substr_count($head, 'id="public-page-'.$style.'-css"'));
                }
                $this->assertLessThan(strpos($head, 'id="public-page-server-menu-css"'), strpos($head, 'id="public-page-site-css"'));
                $this->assertLessThan(strpos($head, 'id="public-page-site-select-css"'), strpos($head, 'id="public-page-server-menu-css"'));
                $this->assertLessThan(strpos($head, 'id="sn-public-css"'), strpos($head, 'id="public-page-site-select-css"'));
                $this->assertDoesNotMatchRegularExpression('~<link rel="stylesheet"[^>]*(?:site(?:\.min)?|server-menu|site-select|chat-morph|vps-catalog)\.css~', $html);
                foreach (self::EMBEDDED as $font) {
                    $this->assertStringNotContainsString('<link rel="preload" href="'.asset('assets/font/woff2/'.$font.'.woff2').'"', $head);
                }
                $this->assertStringContainsString('<script src="'.asset_ver('assets/js/chat-morph.js').'" defer></script>', $html);
                $this->assertStringContainsString('data-endpoint="'.lroute('chat').'"', $html);
                $this->assertLessThan(strpos($html, 'id="public-page-chat-morph-css"'), strpos($html, '</main>'));
                $this->assertLessThan(strpos($html, '<script src="'.asset_ver('assets/js/chat-morph.js')), strpos($html, 'id="public-page-chat-morph-css"'));
                if (str_starts_with($path, '/vps/')) {
                    $this->assertStringContainsString('id="public-page-vps-catalog-css"', $head);
                    $this->assertLessThan(strpos($head, 'id="sn-public-css"'), strpos($head, 'id="public-page-vps-catalog-css"'));
                    $this->assertStringContainsString('<script src="'.asset_ver('assets/js/vps-catalog.js').'" defer></script>', $html);
                }
                $this->assertStringContainsString('assets/js/site-select.js', $html);
                $this->assertSame(1, preg_match_all('~<h1(?:\s|>)~i', $html));
            }
        }
        Http::assertNothingSent();
    }

    public function test_group_failure_keeps_original_links_preloads_and_auxiliary_positions(): void
    {
        foreach (['assets/css/server-menu.css', 'assets/css/chat-morph.css', 'assets/css/vps-catalog.css', 'assets/font/woff2/IRANSans-web.woff2'] as $missing) {
            $fake = str_starts_with($missing, 'assets/font/') ? $this->fixture([], [$missing => null]) : $this->fixture([$missing => null]);
            $this->app->instance(PublicPageStyles::class, $fake);
            foreach (['', '/en', '/tr'] as $prefix) {
                $html = $this->get($prefix.'/vps/philippines')->assertOk()->getContent();
                $head = explode('</head>', $html)[0];
                $this->assertStringNotContainsString('id="public-page-', $html);
                $this->assertMatchesRegularExpression('~<link rel="stylesheet" href="[^\"]*/assets/css/site(?:\.min)?\.css\?v=[^\"]+">~', $head);
                foreach (['server-menu', 'site-select', 'vps-catalog'] as $asset) {
                    $this->assertStringContainsString('<link rel="stylesheet" href="'.asset_ver('assets/css/'.$asset.'.css').'">', $head);
                }
                foreach (self::EMBEDDED as $font) {
                    $this->assertStringContainsString('<link rel="preload" href="'.asset('assets/font/woff2/'.$font.'.woff2').'" as="font"', $head);
                }
                $this->assertStringContainsString('<link rel="stylesheet" href="'.asset_ver('assets/css/chat-morph.css').'">', $html);
            }
        }
    }

    private function selectedSite(): string
    {
        return filemtime(public_asset_path('assets/css/site.min.css')) >= filemtime(public_asset_path('assets/css/site.css')) ? 'assets/css/site.min.css' : 'assets/css/site.css';
    }

    private function request(string $path): void
    {
        $this->app->instance('request', Request::create('https://example.test'.$path));
    }

    private function fontFaces(): string
    {
        preg_match_all('~@font-face\{[^}]*\}~', file_get_contents(public_asset_path('assets/css/site.min.css')), $matches);

        return implode('', $matches[0]);
    }

    private function embeddedBytes(string $css): array
    {
        preg_match_all('~@font-face\{[^}]*data:font/woff2;base64,([^\'\"]+)[^}]*font-weight:(400|700);[^}]*font-display:block[^}]*\}~', $css, $matches, PREG_SET_ORDER);
        $this->assertCount(2, $matches);
        $bytes = [];
        foreach ($matches as $match) {
            $bytes[(int) $match[2]] = base64_decode($match[1], true);
        }

        return $bytes;
    }

    private function fixture(array $overrides = [], array $fontOverrides = []): PublicPageStylesFixture
    {
        $fonts = [];
        foreach (self::EMBEDDED as $font) {
            $asset = 'assets/font/woff2/'.$font.'.woff2';
            $fonts[$asset] = file_get_contents(public_asset_path($asset));
        }

        return new PublicPageStylesFixture(array_replace([
            'assets/css/site.min.css' => $this->fontFaces().'.site{font-family:IRANSans}',
            'assets/css/site.css' => $this->fontFaces().'.site{font-family:IRANSans}',
            'assets/css/server-menu.css' => '.menu{color:red}',
            'assets/css/site-select.css' => '.sn-select{color:blue}',
            'assets/css/chat-morph.css' => '.chat-panel{color:orange}',
            'assets/css/vps-catalog.css' => '.vpsc-table{color:green}',
        ], $overrides), array_replace($fonts, $fontOverrides));
    }
}

class PublicPageStylesFixture extends PublicPageStyles
{
    public array $reads = [];

    public array $fontReads = [];

    public function __construct(private array $files, private array $fonts) {}

    protected function readStylesheet(string $asset): ?string
    {
        $this->reads[] = $asset;

        return $this->files[$asset] ?? null;
    }

    protected function readFont(string $asset): ?string
    {
        $this->fontReads[] = $asset;

        return $this->fonts[$asset] ?? null;
    }
}

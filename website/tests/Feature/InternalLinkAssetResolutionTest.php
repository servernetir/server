<?php

namespace Tests\Feature;

use App\Console\Commands\CheckContentLinks;
use App\Models\Setting;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class InternalLinkAssetResolutionTest extends TestCase
{
    private string $sandbox;

    /** @var list<string> */
    private array $files = [];

    /** @var list<string> */
    private array $directories = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['app.url' => 'https://links-audit.test']);
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        $this->sandbox = sys_get_temp_dir().'/servernet-link-assets-'.getmypid().'-'.bin2hex(random_bytes(6));
        $this->directory($this->sandbox);
        $this->directory($this->sandbox.'/public');
        $this->app->usePublicPath($this->sandbox.'/public');
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->files) as $file) {
            @unlink($file);
        }
        foreach (array_reverse($this->directories) as $directory) {
            @rmdir($directory);
        }
        parent::tearDown();
    }

    public function test_existing_favicon_stylesheet_and_font_resolve_without_a_laravel_request(): void
    {
        $this->directory(public_path('assets'));
        $this->directory(public_path('assets/css'));
        $this->directory(public_path('assets/fonts'));
        foreach (['favicon.svg', 'assets/css/site.css', 'assets/fonts/site.woff2'] as $path) {
            $this->file(public_path($path));
        }

        $kernel = Mockery::mock(Kernel::class);
        $kernel->shouldNotReceive('handle');
        $this->app->instance(Kernel::class, $kernel);

        foreach (['/favicon.svg', '/assets/css/site.css', '/assets/fonts/site.woff2'] as $path) {
            $this->assertTrue(CheckContentLinks::resolves($path, []), $path);
        }
    }

    public function test_missing_asset_still_uses_the_kernel_and_is_reported_broken(): void
    {
        $kernel = Mockery::mock(Kernel::class);
        $kernel->shouldReceive('handle')->once()
            ->withArgs(fn (Request $request) => $request->getPathInfo() === '/assets/missing.css')
            ->andReturn(new Response('', 404));
        $this->app->instance(Kernel::class, $kernel);

        $this->assertFalse(CheckContentLinks::resolves('/assets/missing.css', []));
    }

    public function test_a_configured_served_root_does_not_accept_an_asset_only_in_the_app_copy(): void
    {
        $this->directory($this->sandbox.'/served');
        $this->file(public_path('app-only.css'));
        Setting::put('link_audit_public_root', $this->sandbox.'/served');
        $kernel = Mockery::mock(Kernel::class);
        $kernel->shouldReceive('handle')->once()->andReturn(new Response('', 404));
        $this->app->instance(Kernel::class, $kernel);

        $this->assertFalse(CheckContentLinks::resolves('/app-only.css', []));
    }

    public function test_an_asset_only_in_the_configured_served_root_resolves(): void
    {
        $this->directory($this->sandbox.'/served');
        $this->file($this->sandbox.'/served/served-only.css');
        Setting::put('link_audit_public_root', $this->sandbox.'/served');
        $kernel = Mockery::mock(Kernel::class);
        $kernel->shouldNotReceive('handle');
        $this->app->instance(Kernel::class, $kernel);

        $this->assertTrue(CheckContentLinks::resolves('/served-only.css', []));
        $this->assertFalse(is_file(public_path('served-only.css')));
    }

    public function test_an_invalid_configured_root_does_not_fall_back_to_the_app_copy(): void
    {
        $this->file(public_path('app-only.css'));
        $kernel = Mockery::mock(Kernel::class);
        $kernel->shouldNotReceive('handle');
        $this->app->instance(Kernel::class, $kernel);

        foreach ([$this->sandbox.'/missing', 'relative/public', $this->sandbox."/invalid\0root"] as $root) {
            Setting::put('link_audit_public_root', $root);
            $this->assertFalse(CheckContentLinks::resolves('/app-only.css', []));
        }
    }

    public function test_a_symlink_outside_the_configured_served_root_is_rejected(): void
    {
        $this->directory($this->sandbox.'/served');
        $outside = public_path('app-only.css');
        $this->file($outside);
        $link = $this->sandbox.'/served/linked.css';
        if (! @symlink($outside, $link)) {
            $this->markTestSkipped('This runtime cannot create a symlink fixture.');
        }
        $this->files[] = $link;
        Setting::put('link_audit_public_root', $this->sandbox.'/served');
        $kernel = Mockery::mock(Kernel::class);
        $kernel->shouldNotReceive('handle');
        $this->app->instance(Kernel::class, $kernel);

        $this->assertFalse(CheckContentLinks::resolves('/linked.css', []));
    }

    public function test_dynamic_routes_keep_the_existing_kernel_resolution(): void
    {
        $kernel = Mockery::mock(Kernel::class);
        $kernel->shouldReceive('handle')->once()
            ->withArgs(fn (Request $request) => $request->getPathInfo() === '/blog/synthetic-link-fixture')
            ->andReturn(new Response('article', 200));
        $this->app->instance(Kernel::class, $kernel);

        $this->assertTrue(CheckContentLinks::resolves('/blog/synthetic-link-fixture', []));
    }

    public function test_a_known_route_keeps_its_fast_path(): void
    {
        $kernel = Mockery::mock(Kernel::class);
        $kernel->shouldNotReceive('handle');
        $this->app->instance(Kernel::class, $kernel);

        $this->assertTrue(CheckContentLinks::resolves('/known-page/', ['/known-page' => true]));
    }

    public function test_an_existing_directory_is_not_treated_as_a_served_asset(): void
    {
        $this->directory(public_path('assets'));
        $kernel = Mockery::mock(Kernel::class);
        $kernel->shouldReceive('handle')->once()->andReturn(new Response('', 404));
        $this->app->instance(Kernel::class, $kernel);

        $this->assertFalse(CheckContentLinks::resolves('/assets', []));
    }

    public function test_a_php_file_must_pass_kernel_execution_instead_of_file_existence(): void
    {
        $this->file(public_path('index.php'));
        $kernel = Mockery::mock(Kernel::class);
        $kernel->shouldReceive('handle')->once()
            ->withArgs(fn (Request $request) => $request->getPathInfo() === '/index.php')
            ->andReturn(new Response('', 500));
        $this->app->instance(Kernel::class, $kernel);

        $this->assertFalse(CheckContentLinks::resolves('/index.php', []));
    }

    public function test_hidden_files_and_hidden_directories_do_not_bypass_the_kernel(): void
    {
        $this->directory(public_path('.private'));
        foreach (['.hidden.css', '.htaccess', '.private/site.css'] as $path) {
            $this->file(public_path($path));
        }
        $kernel = Mockery::mock(Kernel::class);
        $kernel->shouldReceive('handle')->times(3)->andReturn(new Response('', 404));
        $this->app->instance(Kernel::class, $kernel);

        foreach (['/.hidden.css', '/.htaccess', '/.private/site.css'] as $path) {
            $this->assertFalse(CheckContentLinks::resolves($path, []), $path);
        }
    }

    public function test_unknown_file_types_do_not_bypass_the_kernel(): void
    {
        $this->file(public_path('synthetic.custom'));
        $kernel = Mockery::mock(Kernel::class);
        $kernel->shouldReceive('handle')->once()->andReturn(new Response('', 404));
        $this->app->instance(Kernel::class, $kernel);

        $this->assertFalse(CheckContentLinks::resolves('/synthetic.custom', []));
    }

    public function test_traversal_and_ambiguous_paths_are_rejected_before_kernel_dispatch(): void
    {
        $this->file($this->sandbox.'/private.css');
        $kernel = Mockery::mock(Kernel::class);
        $kernel->shouldNotReceive('handle');
        $this->app->instance(Kernel::class, $kernel);

        foreach (['/../private.css', '/%2e%2e/private.css', '/assets/../../private.css',
            '/assets/%2e%2e/%2e%2e/private.css', '/assets\\private.css',
            '/assets/%00private.css', '/assets/%0aprivate.css', '/assets/%7fprivate.css',
            '//foreign.test/asset.css', 'assets/site.css'] as $path) {
            $this->assertFalse(CheckContentLinks::resolves($path, []), $path);
        }
    }

    public function test_a_symlink_outside_the_public_root_is_rejected_even_with_a_shared_prefix(): void
    {
        $this->directory($this->sandbox.'/public-private');
        $outside = $this->sandbox.'/public-private/secret.css';
        $this->file($outside);
        $link = public_path('linked.css');
        if (! @symlink($outside, $link)) {
            $this->markTestSkipped('This runtime cannot create a symlink fixture.');
        }
        $this->files[] = $link;

        $kernel = Mockery::mock(Kernel::class);
        $kernel->shouldNotReceive('handle');
        $this->app->instance(Kernel::class, $kernel);

        $this->assertFalse(CheckContentLinks::resolves('/linked.css', []));
    }

    public function test_encoded_spaces_in_an_existing_public_filename_resolve(): void
    {
        $this->file(public_path('release notes.pdf'));
        $kernel = Mockery::mock(Kernel::class);
        $kernel->shouldNotReceive('handle');
        $this->app->instance(Kernel::class, $kernel);

        $this->assertTrue(CheckContentLinks::resolves('/release%20notes.pdf', []));
    }

    public function test_external_protocol_relative_assets_are_excluded_from_internal_links(): void
    {
        $this->assertNull(CheckContentLinks::internalPath('//cdn.example.test/assets/site.css'));
        $this->assertNull(CheckContentLinks::internalPath('//links-audit.test.evil.test/assets/site.css'));
        $this->assertNull(CheckContentLinks::internalPath('//links-audit.test@evil.test/assets/site.css'));
        $this->assertNull(CheckContentLinks::internalPath('///invalid-host/asset.css'));
        $this->assertNull(CheckContentLinks::internalPath('//links-audit.test:99999/assets/site.css'));
    }

    public function test_same_host_protocol_relative_urls_keep_the_local_path_and_drop_query_and_fragment(): void
    {
        $this->assertSame('/assets/site.css', CheckContentLinks::internalPath('//LINKS-AUDIT.TEST/assets/site.css?v=1#font'));
        $this->assertSame('/', CheckContentLinks::internalPath('//links-audit.test'));
        $this->assertSame('/blog/entry', CheckContentLinks::internalPath('https://links-audit.test/blog/entry?x=1&amp;y=2#top'));
    }

    private function directory(string $path): void
    {
        mkdir($path, 0700);
        $this->directories[] = $path;
    }

    private function file(string $path): void
    {
        file_put_contents($path, 'synthetic asset fixture');
        $this->files[] = $path;
    }
}

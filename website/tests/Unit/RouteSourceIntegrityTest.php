<?php

namespace Tests\Unit;

use App\Http\Controllers\Admin\SeoOutreachController;
use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/** A binary blob without a PHP opening tag passes php -l as inline HTML. */
class RouteSourceIntegrityTest extends TestCase
{
    private function routeSource(): string
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/routes/web.php');
        $this->assertIsString($source);

        return $source;
    }

    private static function sourceProblem(string $source): ?string
    {
        if (preg_match('//u', $source) !== 1) {
            return 'invalid_utf8';
        }
        if (preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $source)) {
            return 'binary_control_bytes';
        }
        if (! preg_match('/\A<\?php(?:\s|$)/D', $source)) {
            return 'missing_php_opening_tag';
        }
        try {
            $tokens = token_get_all($source, TOKEN_PARSE);
        } catch (\ParseError) {
            return 'invalid_php_syntax';
        }
        foreach ($tokens as $token) {
            if (is_array($token) && $token[0] === T_INLINE_HTML) {
                return 'unexpected_inline_output';
            }
        }

        return null;
    }

    public function test_web_routes_are_utf8_php_source_without_binary_or_inline_output(): void
    {
        $this->assertNull(self::sourceProblem($this->routeSource()),
            'routes/web.php must be executable PHP source, not a decoded or truncated binary payload.');
    }

    public function test_guard_rejects_payloads_that_lint_can_accept_without_registering_routes(): void
    {
        foreach ([
            "\xff\xfe\x00broken" => 'invalid_utf8',
            "<?php\n// bad\x00payload\n" => 'binary_control_bytes',
            'not PHP source' => 'missing_php_opening_tag',
            "Warning: truncated output\n<?php\n" => 'missing_php_opening_tag',
            "<?php\n?>not a route" => 'unexpected_inline_output',
            "<?php\nif (" => 'invalid_php_syntax',
        ] as $source => $problem) {
            $this->assertSame($problem, self::sourceProblem($source));
        }
        $this->assertNull(self::sourceProblem("<?php\n// مسیرهای معتبر\n\$value = 1;\n"));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_real_bootstrap_registers_localized_core_and_protected_report_routes(): void
    {
        // Validate before require: never print malformed route bytes into CI output.
        $this->assertNull(self::sourceProblem($this->routeSource()));
        $root = dirname(__DIR__, 2);
        $this->assertFileDoesNotExist($root.'/bootstrap/cache/config.php');
        $this->assertSame('testing', getenv('APP_ENV'));

        ob_start();
        try {
            $app = require $root.'/bootstrap/app.php';
            $app->make(Kernel::class)->bootstrap();
        } finally {
            $output = ob_get_clean();
        }
        $this->assertTrue($output === '', 'Application route bootstrap must not emit raw source bytes.');
        $routes = $app->make('router')->getRoutes();

        foreach (['' => '', 'en.' => 'en/', 'tr.' => 'tr/'] as $namePrefix => $pathPrefix) {
            foreach (['home' => '/', 'login' => 'login', 'account.home' => 'account',
                'report' => 'report/{token}'] as $name => $uri) {
                $route = $routes->getByName($namePrefix.$name);
                $this->assertNotNull($route, 'Missing named route: '.$namePrefix.$name);
                $this->assertSame($pathPrefix === '' ? $uri : rtrim($pathPrefix.ltrim($uri, '/'), '/'), $route->uri());
                $this->assertContains('GET', $route->methods());
            }
        }

        $errors = $routes->getByName('admin.errors');
        $this->assertNotNull($errors);
        $this->assertSame('admin/errors', $errors->uri());
        foreach (['web', 'auth:web', 'admin'] as $middleware) {
            $this->assertContains($middleware, $errors->middleware());
        }

        $revoke = $routes->getByName('admin.seo.reports.revoke');
        $this->assertNotNull($revoke, 'Report revocation must be registered by the real web route file.');
        $this->assertSame('admin/seo/reports/{report}/revoke', $revoke->uri());
        $this->assertSame(['POST'], $revoke->methods());
        $this->assertSame(SeoOutreachController::class.'@revokeReport', $revoke->getActionName());
        foreach (['web', 'auth:web', 'admin'] as $middleware) {
            $this->assertContains($middleware, $revoke->middleware());
        }
    }
}

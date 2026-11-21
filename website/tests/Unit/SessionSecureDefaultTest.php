<?php

namespace Tests\Unit;

use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

class SessionSecureDefaultTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The session config uses storage_path(); construct only the container,
        // not the application's environment loader or service bootstrappers.
        new Application(dirname(__DIR__, 2));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_missing_environment_uses_the_production_default(): void
    {
        $this->setEnvironment('APP_ENV', null);
        $this->setEnvironment('SESSION_SECURE_COOKIE', null);

        $app = require dirname(__DIR__, 2).'/config/app.php';
        $session = require dirname(__DIR__, 2).'/config/session.php';

        $this->assertSame('production', $app['env']);
        $this->assertTrue($session['secure']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_explicit_environments_and_cookie_setting_are_preserved(): void
    {
        foreach ([
            ['production', null, true],
            ['local', null, false],
            ['testing', null, false],
            ['production', 'false', false],
            ['local', 'true', true],
        ] as [$environment, $override, $expected]) {
            $this->setEnvironment('APP_ENV', $environment);
            $this->setEnvironment('SESSION_SECURE_COOKIE', $override);

            $session = require dirname(__DIR__, 2).'/config/session.php';

            $this->assertSame($expected, $session['secure']);
        }
    }

    // Read the actual configuration in a fresh process, without loading .env
    // or running the application, and update each Laravel env adapter.
    private function setEnvironment(string $key, ?string $value): void
    {
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);

        if ($value !== null) {
            $_ENV[$key] = $_SERVER[$key] = $value;
            putenv($key.'='.$value);
        }
    }
}

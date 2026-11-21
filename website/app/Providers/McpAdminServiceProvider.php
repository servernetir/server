<?php

namespace App\Providers;

use App\Services\Mcp\AdminAccess;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

final class McpAdminServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Expose only our guarded authorization-code/refresh routes.
        Passport::ignoreRoutes();
    }

    public function boot(): void
    {
        Passport::tokensCan(AdminAccess::scopes());
        Passport::useAccessTokenEntity(\App\Services\Mcp\ResourceAccessToken::class);
        Passport::tokensExpireIn(now()->addHour());
        Passport::refreshTokensExpireIn(now()->addDays(30));
        Passport::authorizationView('mcp-admin.authorize');
        RateLimiter::for('mcp-register', fn ($r) => Limit::perHour(10)->by($r->ip()));
        RateLimiter::for('mcp-token', fn ($r) => Limit::perMinute(30)->by($r->ip()));
        RateLimiter::for('mcp-tools', fn ($r) => Limit::perMinute(60)->by($r->user('mcp_admin')?->id ?? $r->ip()));
        $this->loadRoutesFrom(base_path('routes/mcp-admin.php'));
    }
}

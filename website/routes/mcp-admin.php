<?php

use App\Http\Controllers\Mcp\AdminController;
use App\Http\Controllers\Mcp\OAuthController;
use App\Http\Middleware\McpAdminAccess;
use App\Http\Middleware\McpConsentOwner;
use App\Http\Middleware\McpOAuthRequest;
use App\Http\Middleware\McpTokenAuthentication;
use App\Mcp\AdminInfrastructureServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Passport\Http\Controllers\AccessTokenController;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController;
use Laravel\Passport\Http\Controllers\AuthorizationController;
use Laravel\Passport\Http\Controllers\DenyAuthorizationController;

Route::get('/.well-known/oauth-authorization-server', [OAuthController::class, 'metadata']);
Route::get('/.well-known/oauth-protected-resource', [OAuthController::class, 'resource']);
Route::get('/.well-known/oauth-protected-resource/api/mcp/admin', [OAuthController::class, 'resource']);
Route::post('/api/mcp/oauth/register', [OAuthController::class, 'register'])->middleware('throttle:mcp-register');
Route::post('/api/mcp/oauth/token', [AccessTokenController::class, 'issueToken'])
    ->middleware(['throttle:mcp-token', McpOAuthRequest::class.':token'])->name('passport.token');

Route::middleware(['web', 'auth:web', 'admin'])->prefix('admin/mcp')->group(function () {
    Route::get('/', [AdminController::class, 'index'])->name('admin.mcp');
    Route::post('/enable', [AdminController::class, 'enable'])->middleware('throttle:forms');
    Route::post('/disable', [AdminController::class, 'disable'])->middleware('throttle:forms');
    Route::post('/connections/{client}/revoke', [AdminController::class, 'revoke'])->middleware('throttle:forms');
    Route::middleware(McpAdminAccess::class.':web')->group(function () {
        Route::post('/command-policy', [AdminController::class, 'commandPolicy'])->middleware('throttle:forms');
        Route::get('/oauth/authorize', [AuthorizationController::class, 'authorize'])
            ->middleware(McpOAuthRequest::class)->name('passport.authorizations.authorize');
        Route::post('/oauth/authorize', [ApproveAuthorizationController::class, 'approve'])
            ->middleware(McpConsentOwner::class)->name('passport.authorizations.approve');
        Route::delete('/oauth/authorize', [DenyAuthorizationController::class, 'deny'])
            ->middleware(McpConsentOwner::class)->name('passport.authorizations.deny');
        Route::get('/actions/{action}', [AdminController::class, 'action'])->name('admin.mcp.action');
        Route::post('/actions/{action}/approve', [AdminController::class, 'approve'])->middleware('throttle:forms');
    });
});

Route::middleware([McpTokenAuthentication::class, McpAdminAccess::class, 'throttle:mcp-tools'])->group(function () {
    Mcp::web('/api/mcp/admin', AdminInfrastructureServer::class)
        ->withoutMiddleware(\Laravel\Mcp\Server\Middleware\AddWwwAuthenticateHeader::class);
});

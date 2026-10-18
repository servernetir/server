<?php

namespace App\Http\Controllers;

use App\Services\IdentityApi\Catalog;
use App\Services\IdentityApi\Engine;

class IdentityStorefrontController extends Controller
{
    public function home(Catalog $catalog)
    {
        return response()->view('pages.identity.home', ['services' => $catalog->publicList()])
            ->header('X-Identity-Usage-Policy', defined(Engine::class.'::USAGE_POLICY') ? Engine::USAGE_POLICY : 'pending');
    }

    public function docs()
    {
        return view('pages.identity.docs');
    }

    public function terms()
    {
        return view('pages.identity.terms');
    }

    public function openapi()
    {
        return response()->file(resource_path('identity/openapi.json'), ['Content-Type' => 'application/json']);
    }
}

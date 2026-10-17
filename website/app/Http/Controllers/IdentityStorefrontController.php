<?php

namespace App\Http\Controllers;

use App\Services\IdentityApi\Catalog;

class IdentityStorefrontController extends Controller
{
    public function home(Catalog $catalog)
    {
        return view('pages.identity.home', ['services' => $catalog->publicList()]);
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

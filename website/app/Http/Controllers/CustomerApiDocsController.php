<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Account\AccountController;
use App\Services\CustomerApi\Catalog;

class CustomerApiDocsController extends Controller
{
    public function account()
    {
        return response()->view('account.api', AccountController::shell('api'))
            ->header('Cache-Control', 'no-store, private');
    }

    public function publicDocs()
    {
        return view('pages.developers-platform');
    }

    public function openapi()
    {
        return response()->json(app(Catalog::class)->openapi(), 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}

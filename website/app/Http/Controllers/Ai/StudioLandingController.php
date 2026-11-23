<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Services\Studio\StudioAuthorization;
use Illuminate\View\View;

final class StudioLandingController extends Controller
{
    public function __invoke(StudioAuthorization $authorization): View
    {
        abort_unless($authorization->configured(), 404);

        return view('pages.ai.studio');
    }
}

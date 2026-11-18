<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Services\Studio\StudioAuthorization;
use App\Services\Studio\StudioDownloads;

final class StudioDownloadsController extends Controller
{
    public function __invoke(StudioDownloads $downloads, StudioAuthorization $authorization)
    {
        return response()->view('studio.download', [
            'release' => $downloads->release(),
            'studioAvailable' => $authorization->configured(),
        ], 200, ['Cache-Control' => 'no-store']);
    }
}

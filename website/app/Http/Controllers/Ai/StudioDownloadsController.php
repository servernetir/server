<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Services\Studio\StudioDownloads;

final class StudioDownloadsController extends Controller
{
    public function __invoke(StudioDownloads $downloads)
    {
        return response()->view('studio.download', ['release' => $downloads->release()], 200,
            ['Cache-Control' => 'no-store']);
    }
}

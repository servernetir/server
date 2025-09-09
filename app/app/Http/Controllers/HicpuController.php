<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class HicpuController extends Controller
{
    public function index(Request $request)
    {
        return view('order-hicpu');
    }
}
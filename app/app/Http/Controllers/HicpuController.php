<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class HicpuController extends Controller
{
    public function index()
    {
        return view('hi-cpu');
    }
}
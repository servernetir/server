<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class CpController extends Controller
{
    public function index()
    {
        return view('cp-manager');
    }
}